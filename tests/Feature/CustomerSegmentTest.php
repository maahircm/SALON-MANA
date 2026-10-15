<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns;
use Tests\TestCase;

class CustomerSegmentTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        $this->cashier = $this->cashier();
        $this->openDrawer($this->cashier);
    }

    /** @return array<int, string> */
    private function names(string $segment, ?User $as = null): array
    {
        $viewer = $as ?? User::factory()->withPermissions('customers.view', 'pos.invoices')->create();

        return $this->actingAs($viewer)->get('/customers?segment='.$segment)->assertOk()
            ->viewData('customers')->pluck('name')->sort()->values()->all();
    }

    private function sale(Customer $customer, string $price, string $paid): void
    {
        $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, $price)->id, 'quantity' => 1]], $paid === '0.00' ? [] : [$this->cashPayment($paid)], ['customer_id' => $customer->id]);
    }

    public function test_segments_select_the_expected_customers(): void
    {
        $fresh = $this->customer(['name' => 'Fresh', 'registered_on' => today()]);
        $old = $this->customer(['name' => 'Old', 'registered_on' => today()->subYear()]);
        $returning = $this->customer(['name' => 'Returning', 'registered_on' => today()->subYear()]);
        $owing = $this->customer(['name' => 'Owing', 'registered_on' => today()->subYear()]);
        $vip = $this->customer(['name' => 'Vip', 'registered_on' => today()->subYear()]);

        $this->sale($returning, '10.00', '10.00');
        $this->sale($returning, '10.00', '10.00');
        $this->sale($owing, '30.00', '10.00');
        $this->sale($vip, '600.00', '600.00');

        $this->assertSame(['Fresh'], $this->names('new'));
        $this->assertSame(['Returning'], $this->names('returning'));
        $this->assertSame(['Owing'], $this->names('owing'));
        $this->assertSame(['Vip'], $this->names('vip'));
        $this->assertContains('Old', $this->names('inactive'));
        $this->assertNotContains('Returning', $this->names('inactive'));
        $this->assertNotContains('Vip', $this->names('inactive'));
        $this->assertSame([], $this->names('members'));
    }

    public function test_lifetime_value_is_only_computed_for_users_who_can_see_invoices(): void
    {
        $customer = $this->customer(['name' => 'Valued']);
        $this->sale($customer, '40.00', '40.00');

        $withInvoices = User::factory()->withPermissions('customers.view', 'pos.invoices')->create();
        $withoutInvoices = User::factory()->withPermissions('customers.view')->create();

        $row = $this->actingAs($withInvoices)->get('/customers')->assertOk()->viewData('customers')->first();
        $this->assertSame('40.00', number_format((float) $row->ltv, 2, '.', ''));

        $row = $this->actingAs($withoutInvoices)->get('/customers')->assertOk()->viewData('customers')->first();
        $this->assertNull($row->ltv ?? null);
    }
}
