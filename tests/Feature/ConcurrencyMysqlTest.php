<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\CashDrawer;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Refund;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CashDrawerService;
use App\Services\CheckoutService;
use App\Services\IntegrityChecker;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Real parallel processes against a real MySQL database (the guarantees below cannot be proven on SQLite or inside a single
 * test transaction). Skipped unless the default connection is MySQL/MariaDB. WARNING: it rebuilds the configured database
 * (migrate:fresh), so it refuses to run unless the database name ends in "_test".
 *
 * Run: DB_CONNECTION=mysql DB_DATABASE=salon_mana_test ... php artisan test --group=mysql
 */
#[Group('mysql')]
class ConcurrencyMysqlTest extends TestCase
{
    private const WORKERS = 10;

    private string $barrier;

    private bool $rebuilt = false;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.default');
        if (! in_array(config("database.connections.{$connection}.driver"), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Concurrency tests need MySQL/MariaDB.');
        }
        if (! str_ends_with((string) config("database.connections.{$connection}.database"), '_test')) {
            $this->markTestSkipped('Refusing to rebuild a database whose name does not end in _test.');
        }
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled.');
        }
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->rebuilt = true;
        $this->barrier = sys_get_temp_dir().'/salon-race-'.bin2hex(random_bytes(4));
        $this->seedWorld();
    }

    protected function tearDown(): void
    {
        @unlink($this->barrier ?? '');
        if ($this->rebuilt) {
            // Leave a clean, migrated database behind: other tests expect to start from empty tables.
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    private function seedWorld(): void
    {
        User::factory()->superAdmin()->create(['email' => 'admin@example.test']);
        $manager = User::factory()->withPermissions('pos.use', 'pos.invoices', 'pos.drawer', 'pos.refund')->create(['email' => 'manager@example.test']);
        app(CashDrawerService::class)->open($manager, CashDrawer::create(['name' => 'Manager drawer', 'is_active' => true]), '0.00');
        for ($i = 1; $i <= self::WORKERS; $i++) {
            $cashier = User::factory()->withPermissions('pos.use', 'pos.drawer', 'pos.invoices')->create(['email' => "cashier{$i}@example.test"]);
            app(CashDrawerService::class)->open($cashier, CashDrawer::create(['name' => "Drawer {$i}", 'is_active' => true]), '0.00');
        }
        Service::factory()->create(['service_category_id' => ServiceCategory::factory()->create()->id, 'duration_minutes' => 60, 'base_price' => '30.00', 'tax_rate' => '0.00']);
        Employee::factory()->workingAllWeek()->create();
        Customer::factory()->count(self::WORKERS)->create();
        $product = Product::factory()->create(['sku' => 'LAST-1', 'selling_price' => '10.00', 'tax_rate' => '0.00']);
        app(InventoryService::class)->record($product->id, StockMovement::ADJUSTMENT_IN, '1', null, 'opening', 'opening', $product->id, '2.00', 'open:1', true);
    }

    /**
     * Starts $count worker processes, releases them together and returns their decoded results.
     *
     * @return array<int, array<string, mixed>>
     */
    private function race(string $mode, int $count = self::WORKERS, ?callable $argument = null): array
    {
        @unlink($this->barrier);
        $processes = [];
        for ($n = 1; $n <= $count; $n++) {
            $command = [PHP_BINARY, base_path('tests/Support/concurrency_worker.php'), $mode, (string) $n, $this->barrier];
            if ($argument) {
                $command[] = (string) $argument($n);
            }
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null);
            $processes[] = [$process, $pipes];
        }
        usleep(700_000);   // let every worker boot and reach the barrier
        touch($this->barrier);
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($process);
            $line = trim((string) strrchr("\n".trim($out), "\n"));
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "worker produced no result. stdout: {$out} stderr: {$err}");
            $results[] = $decoded;
        }

        return $results;
    }

    private function successes(array $results): int
    {
        return count(array_filter($results, fn ($r) => $r['ok']));
    }

    public function test_ten_cashiers_racing_for_the_last_unit_sell_it_exactly_once(): void
    {
        $results = $this->race('sell');

        $this->assertSame(1, $this->successes($results), json_encode($results));
        $this->assertSame('0.000', (string) Product::where('sku', 'LAST-1')->value('current_stock'));
        $this->assertSame(1, StockMovement::where('type', StockMovement::SALE)->count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame([], app(InventoryService::class)->reconcile());
        $this->assertSame([], app(IntegrityChecker::class)->run());
    }

    public function test_ten_receptionists_booking_the_same_stylist_slot_create_exactly_one_appointment(): void
    {
        $slot = now()->addDays(2)->setTime(10, 0);
        while (in_array($slot->dayOfWeek, [5], true)) {   // Friday is closed in the default opening hours
            $slot = $slot->addDay();
        }
        $results = $this->race('book', self::WORKERS, fn () => $slot->format('Y-m-d H:i'));

        $this->assertSame(1, $this->successes($results), json_encode($results));
        $this->assertSame(1, Appointment::count());
        $this->assertSame([], app(IntegrityChecker::class)->run(), 'no overlapping bookings exist');
    }

    public function test_one_checkout_submitted_ten_times_at_once_creates_one_invoice_and_one_payment(): void
    {
        $key = (string) Str::uuid();
        $results = $this->race('duplicate', self::WORKERS, fn () => $key);

        $this->assertSame(self::WORKERS, $this->successes($results), json_encode($results));
        $this->assertCount(1, array_unique(array_column($results, 'invoice')), 'every submission resolved to the same invoice');
        $this->assertSame(1, count(array_filter($results, fn ($r) => ! $r['replay'])), 'exactly one submission did the work');
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame([], app(IntegrityChecker::class)->run());
    }

    public function test_a_single_use_coupon_is_redeemed_once_when_ten_customers_try_together(): void
    {
        $promotion = Promotion::create(['name' => 'Once', 'discount_type' => 'percent', 'discount_value' => '10.00', 'min_purchase' => '0.00', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'is_active' => true]);
        (new Coupon(['code' => 'ONLYONE', 'max_uses' => 1, 'is_active' => true]))->forceFill(['promotion_id' => $promotion->id, 'used_count' => 0])->save();

        $results = $this->race('coupon');

        $this->assertSame(1, $this->successes($results), json_encode($results));
        $this->assertSame(1, Coupon::firstOrFail()->used_count);
        $this->assertSame(1, Invoice::count());
        $this->assertSame([], app(IntegrityChecker::class)->run());
    }

    public function test_ten_simultaneous_refunds_of_one_item_refund_it_once(): void
    {
        $manager = User::where('email', 'manager@example.test')->firstOrFail();
        $cash = PaymentMethod::where('code', 'cash')->firstOrFail();
        $invoice = app(CheckoutService::class)->checkout(['idempotency_key' => (string) Str::uuid(), 'items' => [['service_id' => Service::firstOrFail()->id, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => '30.00']]], $manager)['invoice'];

        $results = $this->race('refund', self::WORKERS, fn () => $invoice->id);

        $this->assertSame(1, $this->successes($results), json_encode($results));
        $this->assertSame(1, Refund::count());
        $this->assertSame('30.00', (string) $invoice->fresh()->refunded_total);
        $this->assertSame([], app(IntegrityChecker::class)->run());
        $this->assertGreaterThan(0, DB::table('audit_logs')->count());
    }
}
