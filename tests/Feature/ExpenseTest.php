<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Concerns;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $clerk;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        Storage::fake('local');
        $this->clerk = User::factory()->withPermissions('expenses.view', 'expenses.create')->create();
        $this->approver = User::factory()->withPermissions('expenses.view', 'expenses.approve')->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return $override + ['expense_category_id' => ExpenseCategory::where('name', 'Rent')->value('id'), 'description' => 'October rent', 'amount' => '450.00', 'expense_date' => '2026-10-15'];
    }

    private function submit(?User $by = null, array $override = []): Expense
    {
        $this->actingAs($by ?? $this->clerk)->post('/expenses', $this->payload($override))->assertSessionDoesntHaveErrors();

        return Expense::latest('id')->firstOrFail();
    }

    public function test_the_default_categories_are_seeded(): void
    {
        $this->assertEqualsCanonicalizing(['Rent', 'Salaries', 'Utilities', 'Supplies', 'Marketing', 'Maintenance', 'Transport', 'Other'], ExpenseCategory::pluck('name')->all());
    }

    public function test_submitting_creates_a_pending_numbered_expense_with_history_and_audit(): void
    {
        $expense = $this->submit();

        $this->assertSame('EXP-000001', $expense->expense_number);
        $this->assertSame(Expense::PENDING, $expense->status);
        $this->assertSame('450.00', (string) $expense->amount);
        $this->assertSame(['submitted'], $expense->events->pluck('action')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.submitted', 'entity_id' => $expense->id]);
        $this->assertSame('EXP-000002', $this->submit()->expense_number);
    }

    public function test_validation_rejects_bad_amounts_dates_categories_and_files(): void
    {
        $inactive = ExpenseCategory::create(['name' => 'Old', 'is_active' => false]);
        $bad = [
            ['amount' => '0'], ['amount' => '-5'], ['amount' => '1.234'], ['amount' => 'abc'], ['expense_date' => '2026-12-31'], ['expense_date' => 'yesterday'],
            ['expense_category_id' => 9999], ['expense_category_id' => $inactive->id], ['description' => ''],
        ];
        foreach ($bad as $override) {
            $this->actingAs($this->clerk)->post('/expenses', $this->payload($override))->assertSessionHasErrors(array_keys($override));
        }
        $this->actingAs($this->clerk)->post('/expenses', $this->payload(['receipt' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]))->assertSessionHasErrors('receipt');
        $this->actingAs($this->clerk)->post('/expenses', $this->payload(['receipt' => UploadedFile::fake()->create('fake.jpg', 10, 'application/x-msdownload')]))->assertSessionHasErrors('receipt');
        $this->actingAs($this->clerk)->post('/expenses', $this->payload(['receipt' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]))->assertSessionHasErrors('receipt');
        $this->assertSame(0, Expense::count());
        $this->assertSame([], Storage::disk('local')->allFiles('expense-receipts'));
    }

    public function test_only_users_with_the_right_permission_can_view_create_and_decide(): void
    {
        $expense = $this->submit();
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get('/expenses')->assertForbidden();
        $this->actingAs($nobody)->post('/expenses', $this->payload())->assertForbidden();
        $this->actingAs($this->approver)->post('/expenses', $this->payload())->assertForbidden();
        $this->actingAs($this->clerk)->post("/expenses/{$expense->id}/approve")->assertForbidden();
        $this->actingAs($this->clerk)->post("/expenses/{$expense->id}/pay", [])->assertForbidden();
        $this->assertSame(Expense::PENDING, $expense->fresh()->status);
    }

    public function test_approval_records_who_and_when_and_makes_the_expense_immutable(): void
    {
        $expense = $this->submit();

        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/approve", ['note' => 'OK'])->assertSessionDoesntHaveErrors();

        $expense->refresh();
        $this->assertSame(Expense::APPROVED, $expense->status);
        $this->assertSame($this->approver->id, $expense->decided_by);
        $this->assertNotNull($expense->decided_at);
        $this->assertSame(['submitted', 'approved'], $expense->events->pluck('action')->all());
        $this->actingAs($this->clerk)->put("/expenses/{$expense->id}", $this->payload(['amount' => '1.00']))->assertSessionHasErrors('status');
        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/approve")->assertSessionHasErrors('status');
        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/reject", ['note' => 'late'])->assertSessionHasErrors('status');
        $this->assertSame('450.00', (string) $expense->fresh()->amount);
    }

    public function test_nobody_approves_their_own_expense_unless_self_approval_is_enabled(): void
    {
        $both = User::factory()->withPermissions('expenses.view', 'expenses.create', 'expenses.approve')->create();
        $expense = $this->submit($both);

        $this->actingAs($both)->post("/expenses/{$expense->id}/approve")->assertSessionHasErrors('status');
        $this->assertSame(Expense::PENDING, $expense->fresh()->status);
        $this->actingAs($both)->get("/expenses/{$expense->id}")->assertDontSee('Approve</button>', false);

        $this->setting('finance.expense_self_approval', true);
        $this->actingAs($both)->post("/expenses/{$expense->id}/approve")->assertSessionDoesntHaveErrors();
        $this->assertSame(Expense::APPROVED, $expense->fresh()->status);
    }

    public function test_rejection_needs_a_reason_and_cancellation_is_limited_to_the_submitter_or_an_approver(): void
    {
        $expense = $this->submit();
        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/reject", ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/reject", ['note' => 'No receipt'])->assertSessionDoesntHaveErrors();
        $this->assertSame(Expense::REJECTED, $expense->fresh()->status);

        $mine = $this->submit();
        $other = User::factory()->withPermissions('expenses.view', 'expenses.create')->create();
        $this->actingAs($other)->post("/expenses/{$mine->id}/cancel")->assertForbidden();
        $this->actingAs($this->clerk)->post("/expenses/{$mine->id}/cancel")->assertSessionDoesntHaveErrors();
        $this->assertSame(Expense::CANCELLED, $mine->fresh()->status);
    }

    public function test_only_approved_expenses_can_be_paid_and_only_once_never_by_loyalty_points(): void
    {
        $expense = $this->submit();
        $cash = $this->paymentMethod('cash');
        $pay = ['payment_method_id' => $cash->id, 'paid_on' => '2026-10-16', 'payment_reference' => 'CHQ-9'];

        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/pay", $pay)->assertSessionHasErrors('status');
        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/approve");
        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/pay", ['payment_method_id' => $this->paymentMethod('loyalty_points')->id] + $pay)->assertNotFound();
        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/pay", ['paid_on' => '2027-01-01'] + $pay)->assertSessionHasErrors('paid_on');
        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/pay", $pay)->assertSessionDoesntHaveErrors();
        $this->actingAs($this->approver)->post("/expenses/{$expense->id}/pay", $pay)->assertSessionHasErrors('payment_method_id');

        $expense->refresh();
        $this->assertSame('paid', $expense->payment_status);
        $this->assertSame('CHQ-9', $expense->payment_reference);
        $this->assertSame(['submitted', 'approved', 'paid'], $expense->events->pluck('action')->all());
    }

    public function test_receipts_are_stored_privately_under_a_random_name_and_served_only_to_authorised_users(): void
    {
        $file = UploadedFile::fake()->image('../../evil name.png', 20, 20);
        $expense = $this->submit(null, ['receipt' => $file]);

        $this->assertTrue($expense->hasReceipt());
        $this->assertMatchesRegularExpression('#^expense-receipts/[a-f0-9]{32}\.png$#', $expense->receipt_path);
        Storage::disk('local')->assertExists($expense->receipt_path);
        $this->assertStringNotContainsString('/', $expense->receipt_name);

        $response = $this->actingAs($this->approver)->get("/expenses/{$expense->id}/receipt")->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->actingAs(User::factory()->create())->get("/expenses/{$expense->id}/receipt")->assertForbidden();
        auth()->logout();
        $this->get("/expenses/{$expense->id}/receipt")->assertRedirect('/login');
        $this->assertFalse(file_exists(public_path($expense->receipt_path)));
    }

    public function test_replacing_a_receipt_deletes_the_old_file(): void
    {
        $expense = $this->submit(null, ['receipt' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
        $old = $expense->receipt_path;

        $this->actingAs($this->clerk)->put("/expenses/{$expense->id}", $this->payload(['receipt' => UploadedFile::fake()->image('b.jpg')]))->assertSessionDoesntHaveErrors();

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($expense->fresh()->receipt_path);
    }

    public function test_the_approval_history_is_append_only(): void
    {
        $this->submit();
        $event = ExpenseEvent::firstOrFail();
        foreach ([fn () => $event->update(['note' => 'x']), fn () => $event->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected immutability.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_categories_can_be_added_renamed_and_deactivated_by_approvers_only(): void
    {
        $this->actingAs($this->clerk)->post('/expenses/categories', ['name' => 'Insurance'])->assertForbidden();
        $this->actingAs($this->approver)->post('/expenses/categories', ['name' => 'Insurance'])->assertSessionDoesntHaveErrors();
        $this->actingAs($this->approver)->post('/expenses/categories', ['name' => 'insurance'])->assertSessionHasErrors('name');
        $category = ExpenseCategory::where('name', 'Insurance')->firstOrFail();
        $this->actingAs($this->approver)->put("/expenses/categories/{$category->id}", ['name' => 'Insurance & fees', 'is_active' => 0])->assertSessionDoesntHaveErrors();

        $this->assertFalse($category->fresh()->is_active);
        $this->actingAs($this->clerk)->post('/expenses', $this->payload(['expense_category_id' => $category->id]))->assertSessionHasErrors('expense_category_id');
    }
}
