<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only consistency audit of the data the application is supposed to keep consistent by itself. Every check compares
 * a stored figure with the figure recomputed from the underlying records. A healthy database reports no findings;
 * a finding means data was changed outside the application (or a bug), and is never auto-corrected.
 */
class IntegrityChecker
{
    private const SAMPLE = 25;

    public function __construct(private InventoryService $inventory, private LoyaltyService $loyalty, private SettingsService $settings) {}

    /**
     * @return array<string, array{title: string, count: int, samples: array<int, string>}> only checks that found problems
     */
    public function run(): array
    {
        $findings = [];
        foreach ($this->checks() as $key => [$title, $check]) {
            $issues = $check();
            if ($issues !== []) {
                $findings[$key] = ['title' => $title, 'count' => count($issues), 'samples' => array_slice($issues, 0, self::SAMPLE)];
            }
        }

        return $findings;
    }

    /** @return array<string, string> key => title of every check that is performed */
    public function catalogue(): array
    {
        return array_map(fn ($c) => $c[0], $this->checks());
    }

    /** @return array<string, array{0: string, 1: callable(): array<int, string>}> */
    private function checks(): array
    {
        return [
            'stock_ledger' => ['Product stock equals the sum of its stock ledger', fn () => array_map(fn ($r) => "product {$r['sku']}: balance {$r['balance']} but ledger {$r['ledger']}", $this->inventory->reconcile())],
            'negative_stock' => ['No product is below zero while negative stock is disallowed', fn () => $this->negativeStock()],
            'loyalty_ledger' => ['Loyalty balance equals the sum of its points ledger', fn () => array_map(fn ($r) => "customer {$r['customer_id']}: balance {$r['balance']} but ledger {$r['ledger']}", $this->loyalty->reconcile())],
            'invoice_arithmetic' => ['Invoice total = subtotal − discounts − coupon + tax, and equals the sum of its lines', fn () => $this->invoiceArithmetic()],
            'invoice_payments' => ['Invoice amount paid / refunded equal the payments / refunds recorded against it', fn () => $this->invoicePayments()],
            'invoice_status' => ['Payment status, overpayment and voided invoices are consistent with the money recorded', fn () => $this->invoiceStatus()],
            'document_sequences' => ['Document counters are not behind the numbers already issued', fn () => $this->documentSequences()],
            'coupon_usage' => ['Coupon used_count equals its redeemed (not reversed) redemptions', fn () => $this->couponUsage()],
            'cash_drawer' => ['Closed drawer sessions: variance = counted − expected', fn () => $this->cashDrawer()],
            'appointment_overlap' => ['No employee or room is double-booked on active appointments', fn () => $this->appointmentOverlap()],
            'expenses' => ['Approved expenses have an approver; paid expenses have a payment date and method', fn () => $this->expenses()],
        ];
    }

    /** @return array<int, string> */
    private function negativeStock(): array
    {
        if ($this->settings->get('inventory.allow_negative_stock', false)) {
            return [];
        }

        return DB::table('products')->where('current_stock', '<', 0)->limit(1000)->get(['sku', 'current_stock'])->map(fn ($p) => "product {$p->sku}: {$p->current_stock}")->all();
    }

    /** @return array<int, string> */
    private function invoiceArithmetic(): array
    {
        $lineTotals = DB::table('invoice_items')->selectRaw('invoice_id, SUM(line_total) as total')->groupBy('invoice_id')->pluck('total', 'invoice_id');
        $issues = [];
        DB::table('invoices')->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_VOIDED])->orderBy('id')->each(function ($i) use ($lineTotals, &$issues): void {
            $expected = Money::add(Money::sub(Money::sub($this->m($i->subtotal), $this->m($i->discount_total)), $this->m($i->coupon_discount_total)), $this->m($i->tax_total));
            if (Money::compare($expected, $this->m($i->total)) !== 0) {
                $issues[] = "{$i->invoice_number}: total {$i->total} but components give {$expected}";
            }
            $lines = $this->m($lineTotals[$i->id] ?? 0);
            if (Money::compare($lines, $this->m($i->total)) !== 0) {
                $issues[] = "{$i->invoice_number}: total {$i->total} but lines add up to {$lines}";
            }
        });

        return $issues;
    }

    /** @return array<int, string> */
    private function invoicePayments(): array
    {
        $paid = DB::table('payments')->selectRaw('invoice_id, SUM(amount) as total')->groupBy('invoice_id')->pluck('total', 'invoice_id');
        $refunded = DB::table('refunds')->selectRaw('invoice_id, SUM(amount) as total')->groupBy('invoice_id')->pluck('total', 'invoice_id');
        $issues = [];
        DB::table('invoices')->orderBy('id')->each(function ($i) use ($paid, $refunded, &$issues): void {
            if (Money::compare($this->m($paid[$i->id] ?? 0), $this->m($i->amount_paid)) !== 0) {
                $issues[] = "{$i->invoice_number}: amount_paid {$i->amount_paid} but payments add up to {$this->m($paid[$i->id] ?? 0)}";
            }
            if (Money::compare($this->m($refunded[$i->id] ?? 0), $this->m($i->refunded_total)) !== 0) {
                $issues[] = "{$i->invoice_number}: refunded_total {$i->refunded_total} but refunds add up to {$this->m($refunded[$i->id] ?? 0)}";
            }
        });

        return $issues;
    }

    /** @return array<int, string> */
    private function invoiceStatus(): array
    {
        $issues = [];
        DB::table('invoices')->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_VOIDED])->orderBy('id')->each(function ($i) use (&$issues): void {
            $paid = $this->m($i->amount_paid);
            $total = $this->m($i->total);
            if ($i->status === Invoice::STATUS_ISSUED) {
                $expected = Money::compare($paid, $total) >= 0 ? 'paid' : (Money::compare($paid, '0') > 0 ? 'partially_paid' : 'unpaid');
                if ($i->payment_status !== $expected) {
                    $issues[] = "{$i->invoice_number}: payment_status {$i->payment_status} but amounts imply {$expected}";
                }
                if (Money::compare($paid, $total) > 0) {
                    $issues[] = "{$i->invoice_number}: overpaid ({$paid} paid on {$total})";
                }
            } elseif (Money::compare(Money::sub($paid, $this->m($i->refunded_total)), '0') !== 0) {
                $issues[] = "{$i->invoice_number}: voided but still holds ".Money::sub($paid, $this->m($i->refunded_total)).' of customer money';
            }
            if (Money::compare($this->m($i->refunded_total), $paid) > 0) {
                $issues[] = "{$i->invoice_number}: refunded more ({$i->refunded_total}) than was paid ({$paid})";
            }
        });

        return $issues;
    }

    /** @return array<int, string> */
    private function documentSequences(): array
    {
        $issues = [];
        $sequences = DB::table('document_sequences')->pluck('last_number', 'key');
        $sources = [
            'invoice' => ['invoices', 'invoice_number'], 'refund' => ['refunds', 'refund_number'], 'expense' => ['expenses', 'expense_number'],
            'purchase_order' => ['purchase_orders', 'po_number'],
        ];
        foreach ($sources as $key => [$table, $column]) {
            if (! isset($sequences[$key]) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $max = 0;
            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $number) {
                if (preg_match('/(\d+)$/', (string) $number, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            }
            if ($max > (int) $sequences[$key]) {
                $issues[] = "{$key}: highest issued number is {$max} but the counter is at {$sequences[$key]}";
            }
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function couponUsage(): array
    {
        $active = DB::table('coupon_redemptions')->where('status', 'redeemed')->selectRaw('coupon_id, COUNT(*) as n')->groupBy('coupon_id')->pluck('n', 'coupon_id');

        return DB::table('coupons')->get(['id', 'code', 'used_count'])->filter(fn ($c) => (int) $c->used_count !== (int) ($active[$c->id] ?? 0))
            ->map(fn ($c) => "coupon {$c->code}: used_count {$c->used_count} but ".($active[$c->id] ?? 0).' redeemed redemptions')->values()->all();
    }

    /** @return array<int, string> */
    private function cashDrawer(): array
    {
        return DB::table('cash_drawer_sessions')->where('status', 'closed')->get(['id', 'expected_cash', 'counted_cash', 'variance'])
            ->filter(fn ($s) => Money::compare(Money::sub($this->m($s->counted_cash), $this->m($s->expected_cash)), $this->m($s->variance)) !== 0)
            ->map(fn ($s) => "drawer session {$s->id}: variance {$s->variance} but counted {$s->counted_cash} − expected {$s->expected_cash}")->values()->all();
    }

    /** @return array<int, string> */
    private function appointmentOverlap(): array
    {
        $issues = [];
        foreach (['employee_id' => 'employee', 'room_id' => 'room'] as $column => $label) {
            $rows = DB::table('appointment_services as a')
                ->join('appointment_services as b', fn ($j) => $j->on("b.{$column}", '=', "a.{$column}")->on('a.id', '<', 'b.id'))
                ->join('appointments as pa', 'pa.id', '=', 'a.appointment_id')->join('appointments as pb', 'pb.id', '=', 'b.appointment_id')
                ->whereNotNull("a.{$column}")->whereNotIn('pa.status', ['cancelled', 'no_show'])->whereNotIn('pb.status', ['cancelled', 'no_show'])
                ->whereColumn('a.start_at', '<', 'b.end_at')->whereColumn('b.start_at', '<', 'a.end_at')
                ->limit(1000)->get(['pa.appointment_number as one', 'pb.appointment_number as two', "a.{$column} as who"]);
            foreach ($rows as $r) {
                if ($r->one !== $r->two) {
                    $issues[] = "{$label} {$r->who}: {$r->one} overlaps {$r->two}";
                }
            }
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function expenses(): array
    {
        return DB::table('expenses')->get(['expense_number', 'status', 'decided_by', 'payment_status', 'paid_on', 'payment_method_id'])
            ->flatMap(function ($e) {
                $out = [];
                if ($e->status === 'approved' && $e->decided_by === null) {
                    $out[] = "{$e->expense_number}: approved without an approver";
                }
                if ($e->payment_status === 'paid' && ($e->paid_on === null || $e->payment_method_id === null)) {
                    $out[] = "{$e->expense_number}: marked paid without a payment date or method";
                }
                if ($e->payment_status === 'paid' && $e->status !== 'approved') {
                    $out[] = "{$e->expense_number}: paid but not approved";
                }

                return $out;
            })->values()->all();
    }

    private function m(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
