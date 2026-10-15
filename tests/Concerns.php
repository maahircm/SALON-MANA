<?php

namespace Tests;

use App\Models\CashDrawer;
use App\Models\CashDrawerSession;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CashDrawerService;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Str;

/** Shared builders for salon feature tests. "Now" is frozen to Monday 2026-10-19 08:00 salon time. */
trait Concerns
{
    protected function freezeSalonTime(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:00:00', config('app.timezone')));
    }

    protected function stylist(string $start = '09:00', string $end = '18:00'): Employee
    {
        return Employee::factory()->workingAllWeek($start, $end)->create();
    }

    protected function service(int $minutes = 60, string $price = '25.00', array $attrs = []): Service
    {
        return Service::factory()->create($attrs + ['duration_minutes' => $minutes, 'base_price' => $price]);
    }

    protected function customer(array $attrs = []): Customer
    {
        return Customer::factory()->create($attrs);
    }

    protected function receptionist(): User
    {
        return User::factory()->withPermissions('customers.view', 'customers.create', 'customers.update', 'appointments.view', 'appointments.all',
            'appointments.create', 'appointments.update', 'appointments.status', 'appointments.cancel', 'services.view', 'employees.view')->create();
    }

    /** Booking payload for a single service on 2026-10-20 (Tuesday). */
    protected function bookingPayload(Customer $c, Employee $e, Service $s, string $time = '10:00', string $date = '2026-10-20'): array
    {
        return ['customer_id' => $c->id, 'date' => $date, 'time' => $time, 'lines' => [['service_id' => $s->id, 'employee_id' => $e->id]]];
    }

    protected function cashier(string ...$extra): User
    {
        return User::factory()->withPermissions('pos.use', 'pos.invoices', 'pos.drawer', ...$extra)->create();
    }

    protected function manager(): User
    {
        return User::factory()->withPermissions('pos.use', 'pos.invoices', 'pos.drawer', 'pos.close', 'pos.refund', 'pos.void', 'pos.discount')->create();
    }

    protected function paymentMethod(string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::where('code', $code)->firstOrFail();
    }

    protected function openDrawer(User $user, string $opening = '100.00'): CashDrawerSession
    {
        $drawer = CashDrawer::firstOrCreate(['name' => 'Drawer '.$user->id], ['is_active' => true]);

        return app(CashDrawerService::class)->open($user, $drawer, $opening);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<string, mixed>
     */
    protected function checkoutPayload(array $items, array $payments = [], array $extra = []): array
    {
        return array_merge(['action' => 'checkout', 'idempotency_key' => (string) Str::uuid(), 'items' => $items, 'payments' => $payments], $extra);
    }

    protected function cashPayment(string $amount, ?string $tendered = null): array
    {
        return ['payment_method_id' => $this->paymentMethod('cash')->id, 'amount' => $amount, 'tendered' => $tendered];
    }

    protected function cardPayment(string $amount): array
    {
        return ['payment_method_id' => $this->paymentMethod('card')->id, 'amount' => $amount, 'reference' => 'TXN-1'];
    }

    /** Issues an invoice through the real checkout service and returns it. */
    protected function issueInvoice(User $user, array $items, array $payments = [], array $extra = []): Invoice
    {
        return app(CheckoutService::class)->checkout($this->checkoutPayload($items, $payments, $extra), $user)['invoice'];
    }

    /** Browsers silently merge nested forms (e.g. a stray _method=delete ends up in the outer form's POST). */
    protected function assertNoNestedForms(string $html, string $url): void
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $nested = (new \DOMXPath($document))->query('//form//form');
        $this->assertSame(0, $nested->length, "{$url} contains a <form> nested inside another <form>.");
    }

    /** User holding every inventory permission (plus any extras). */
    protected function stockKeeper(string ...$extra): User
    {
        return User::factory()->withPermissions('inventory.view', 'inventory.manage', 'inventory.adjust', 'inventory.purchasing', 'inventory.suppliers', 'inventory.costs', ...$extra)->create();
    }

    /** A product that already has stock on hand, entered through the ledger like real opening stock. */
    protected function stockedProduct(string $stock = '10', string $cost = '5.00', array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);
        app(InventoryService::class)->record($product->id, StockMovement::ADJUSTMENT_IN, $stock, null, 'Opening stock', 'opening', $product->id, $cost, "opening:{$product->id}", true);

        return $product->fresh();
    }

    protected function stockOf(Product $product): string
    {
        return (string) $product->fresh()->current_stock;
    }
}
