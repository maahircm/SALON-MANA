<?php

namespace App\Services;

use App\Models\CashDrawer;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Opening, reconciling and closing cashier drawer sessions. */
class CashDrawerService
{
    public function __construct(private AuditLogger $audit, private SettingsService $settings) {}

    public function openSessionFor(User $user): ?CashDrawerSession
    {
        return CashDrawerSession::where('opened_by', $user->id)->where('status', 'open')->first();
    }

    /**
     * Payments and refunds are attached to the cashier's open session (reconciliation needs it).
     */
    public function sessionForTransaction(User $user): ?CashDrawerSession
    {
        $session = $this->openSessionFor($user);
        if (! $session && $this->settings->get('pos.require_open_drawer', true)) {
            throw ValidationException::withMessages(['session' => 'Open a cash drawer session before taking payments or refunds.']);
        }

        return $session;
    }

    public function open(User $user, CashDrawer $drawer, string $openingCash): CashDrawerSession
    {
        if (! $drawer->is_active) {
            throw ValidationException::withMessages(['cash_drawer_id' => 'This drawer is not active.']);
        }
        if (Money::compare($openingCash, '0') < 0) {
            throw ValidationException::withMessages(['opening_cash' => 'Opening cash cannot be negative.']);
        }

        try {
            return DB::transaction(function () use ($user, $drawer, $openingCash) {
                if ($this->openSessionFor($user)) {
                    throw ValidationException::withMessages(['session' => 'You already have an open drawer session.']);
                }
                $session = CashDrawerSession::create([
                    'cash_drawer_id' => $drawer->id,
                    'opened_by' => $user->id,
                    'opened_at' => now(),
                    'opening_cash' => Money::fromMinor(Money::toMinor($openingCash)),
                    'status' => 'open',
                    'open_drawer_key' => $drawer->id,
                    'open_user_key' => $user->id,
                ]);
                $this->audit->log('cash_drawer.opened', $session, null, ['drawer' => $drawer->name, 'opening_cash' => $session->opening_cash]);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['session' => 'That drawer is already open, or you already have an open session.']);
        }
    }

    public function addMovement(CashDrawerSession $session, string $type, string $amount, string $reason, User $actor): CashMovement
    {
        if (! in_array($type, ['paid_in', 'paid_out'], true) || Money::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount.']);
        }

        return DB::transaction(function () use ($session, $type, $amount, $reason, $actor) {
            $session = CashDrawerSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertCanOperate($session, $actor);
            $movement = CashMovement::create([
                'cash_drawer_session_id' => $session->id, 'type' => $type, 'amount' => Money::fromMinor(Money::toMinor($amount)),
                'reason' => $reason, 'created_by' => $actor->id,
            ]);
            $this->audit->log('cash_drawer.'.$type, $session, null, ['amount' => $movement->amount], $reason);

            return $movement;
        });
    }

    /**
     * opening + cash payments - cash refunds + paid in - paid out.
     *
     * @return array{opening: string, cash_sales: string, cash_refunds: string, paid_in: string, paid_out: string, expected: string}
     */
    public function reconciliation(CashDrawerSession $session): array
    {
        $cashSales = Payment::where('cash_drawer_session_id', $session->id)
            ->whereHas('method', fn ($q) => $q->where('type', 'cash'))->pluck('amount')->all();
        $cashRefunds = Refund::where('cash_drawer_session_id', $session->id)
            ->whereHas('method', fn ($q) => $q->where('type', 'cash'))->pluck('amount')->all();
        $in = CashMovement::where('cash_drawer_session_id', $session->id)->where('type', 'paid_in')->pluck('amount')->all();
        $out = CashMovement::where('cash_drawer_session_id', $session->id)->where('type', 'paid_out')->pluck('amount')->all();

        $sales = Money::add(...$cashSales);
        $refunds = Money::add(...$cashRefunds);
        $paidIn = Money::add(...$in);
        $paidOut = Money::add(...$out);

        return [
            'opening' => (string) $session->opening_cash,
            'cash_sales' => $sales,
            'cash_refunds' => $refunds,
            'paid_in' => $paidIn,
            'paid_out' => $paidOut,
            'expected' => Money::sub(Money::add((string) $session->opening_cash, $sales, $paidIn), Money::add($refunds, $paidOut)),
        ];
    }

    public function close(CashDrawerSession $session, string $countedCash, ?string $notes, User $actor): CashDrawerSession
    {
        if (Money::compare($countedCash, '0') < 0) {
            throw ValidationException::withMessages(['counted_cash' => 'Counted cash cannot be negative.']);
        }

        return DB::transaction(function () use ($session, $countedCash, $notes, $actor) {
            $session = CashDrawerSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertCanOperate($session, $actor);

            $recon = $this->reconciliation($session);
            $counted = Money::fromMinor(Money::toMinor($countedCash));
            $variance = Money::sub($counted, $recon['expected']);
            if (Money::compare($variance, '0') !== 0 && blank($notes)) {
                throw ValidationException::withMessages(['closing_notes' => "The count differs from the expected {$recon['expected']} by {$variance}. Explain the difference in the notes."]);
            }

            $summary = $recon + [
                'payments_by_method' => $this->totalsByMethod(Payment::where('cash_drawer_session_id', $session->id)->with('method')->get()),
                'refunds_by_method' => $this->totalsByMethod(Refund::where('cash_drawer_session_id', $session->id)->with('method')->get()),
                'payment_count' => Payment::where('cash_drawer_session_id', $session->id)->count(),
                'refund_count' => Refund::where('cash_drawer_session_id', $session->id)->count(),
            ];
            $session->update([
                'status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now(), 'expected_cash' => $recon['expected'], 'counted_cash' => $counted,
                'variance' => $variance, 'closing_notes' => $notes, 'summary' => $summary, 'open_drawer_key' => null, 'open_user_key' => null,
            ]);
            $this->audit->log('cash_drawer.closed', $session, null, ['expected' => $recon['expected'], 'counted' => $counted, 'variance' => $variance], $notes);

            return $session;
        });
    }

    /** Owners operate their own session; users with pos.close may operate any. */
    public function assertCanOperate(CashDrawerSession $session, User $actor): void
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages(['session' => 'This drawer session is already closed.']);
        }
        if ($session->opened_by !== $actor->id && ! $actor->can('pos.close')) {
            abort(403, 'This drawer session belongs to another cashier.');
        }
    }

    /**
     * @param  iterable<int, Payment|Refund>  $rows
     * @return array<string, string>
     */
    private function totalsByMethod(iterable $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->method->name] = Money::add($totals[$row->method->name] ?? '0', $row->amount);
        }

        return $totals;
    }
}
