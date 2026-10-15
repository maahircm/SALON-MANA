<?php

/**
 * Child process used by ConcurrencyMysqlTest: waits for the start signal, then performs ONE business operation against
 * the shared MySQL test database and prints a single JSON line ({"ok": true, ...} or {"ok": false, "error": "..."}).
 * Usage: php concurrency_worker.php <mode> <n> <barrier-file> [argument]
 */

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\CheckoutService;
use App\Services\RefundService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$mode, $n, $barrier] = [$argv[1], (int) $argv[2], $argv[3]];
$argument = $argv[4] ?? null;
$cash = PaymentMethod::where('code', 'cash')->value('id');
$deadline = microtime(true) + 30;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(500);
}

try {
    $cashier = User::where('email', "cashier{$n}@example.test")->firstOrFail();
    $service = Service::firstOrFail();
    $result = match ($mode) {
        'sell' => ['invoice' => app(CheckoutService::class)->checkout(['idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => Product::where('sku', 'LAST-1')->value('id'), 'quantity' => 1]],
            'payments' => [['payment_method_id' => $cash, 'amount' => '10.00']]], $cashier)['invoice']->invoice_number],
        'duplicate' => (function () use ($service, $cash, $argument) {
            $r = app(CheckoutService::class)->checkout(['idempotency_key' => $argument, 'items' => [['service_id' => $service->id, 'quantity' => 1]], 'payments' => [['payment_method_id' => $cash, 'amount' => '30.00']]], User::where('email', 'cashier1@example.test')->firstOrFail());

            return ['invoice' => $r['invoice']->invoice_number, 'replay' => (bool) $r['replay']];
        })(),
        'coupon' => ['invoice' => app(CheckoutService::class)->checkout(['idempotency_key' => (string) Str::uuid(), 'coupon_code' => 'ONLYONE', 'items' => [['service_id' => $service->id, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $cash, 'amount' => '27.00']], 'customer_id' => Customer::skip($n - 1)->firstOrFail()->id], $cashier)['invoice']->invoice_number],
        'book' => ['appointment' => app(AppointmentBookingService::class)->create(['customer_id' => Customer::skip($n - 1)->firstOrFail()->id, 'start_at' => $argument,
            'lines' => [['service_id' => $service->id, 'employee_id' => Employee::firstOrFail()->id]]], User::where('email', 'admin@example.test')->firstOrFail())->appointment_number],
        'refund' => (function () use ($argument, $cash) {
            $invoice = Invoice::findOrFail((int) $argument);

            return ['refund' => app(RefundService::class)->refund($invoice, [$invoice->items()->value('id') => 1], PaymentMethod::findOrFail($cash), 'race', null, (string) Str::uuid(), User::where('email', 'manager@example.test')->firstOrFail())->refund_number];
        })(),
    };
    echo json_encode(['ok' => true] + $result), PHP_EOL;
} catch (Throwable $e) {
    $message = $e instanceof ValidationException ? implode(' | ', array_merge(...array_values($e->errors()))) : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => class_basename($e).': '.mb_substr(strip_tags($message), 0, 300)]), PHP_EOL;
}
