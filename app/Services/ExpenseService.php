<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseEvent;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Atomic;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Expense workflow: submitted (pending) -> approved | rejected, pending -> cancelled, approved -> paid.
 * Approved expenses are immutable; every step is written to the approval history and the audit log.
 */
class ExpenseService
{
    public const RECEIPT_DISK = 'local';

    public function __construct(private DocumentNumberService $numbers, private SettingsService $settings, private AuditLogger $audit) {}

    /** @param array{expense_category_id: int, supplier_id?: int|null, description: string, amount: string, expense_date: string} $data */
    public function submit(array $data, User $actor, ?UploadedFile $receipt = null): Expense
    {
        $path = $receipt ? $this->storeReceipt($receipt) : null;

        try {
            return Atomic::run(function () use ($data, $actor, $receipt, $path) {
                $expense = new Expense($data);
                $expense->forceFill([
                    'expense_number' => $this->numbers->next('expense', 'finance.expense_prefix', 'EXP-'),
                    'status' => Expense::PENDING,
                    'payment_status' => 'unpaid',
                    'created_by' => $actor->id,
                ] + $this->receiptFields($receipt, $path))->save();
                $this->event($expense, 'submitted', $actor);
                $this->audit->log('expense.submitted', $expense, null, ['number' => $expense->expense_number, 'amount' => (string) $expense->amount]);

                return $expense;
            });
        } catch (\Throwable $e) {
            $path && Storage::disk(self::RECEIPT_DISK)->delete($path);
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Expense $expense, array $data, User $actor, ?UploadedFile $receipt = null): Expense
    {
        $path = $receipt ? $this->storeReceipt($receipt) : null;
        $oldPath = null;

        $expense = Atomic::run(function () use ($expense, $data, $actor, $receipt, $path, &$oldPath) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->requireStatus($expense, Expense::PENDING, 'Only a pending expense can be edited.');
            $before = ['amount' => (string) $expense->amount, 'category' => $expense->expense_category_id, 'date' => $expense->expense_date->toDateString()];
            $expense->fill($data);
            if ($receipt) {
                $oldPath = $expense->receipt_path;
                $expense->forceFill($this->receiptFields($receipt, $path));
            }
            $expense->save();
            $this->event($expense, 'edited', $actor);
            $this->audit->log('expense.updated', $expense, $before, ['amount' => (string) $expense->amount, 'category' => $expense->expense_category_id, 'date' => $expense->expense_date->toDateString()]);

            return $expense;
        });
        $oldPath && Storage::disk(self::RECEIPT_DISK)->delete($oldPath);

        return $expense;
    }

    public function cancel(Expense $expense, User $actor, ?string $note = null): Expense
    {
        return $this->decide($expense, $actor, Expense::CANCELLED, 'cancelled', $note);
    }

    public function approve(Expense $expense, User $actor, ?string $note = null): Expense
    {
        return $this->decide($expense, $actor, Expense::APPROVED, 'approved', $note, true);
    }

    public function reject(Expense $expense, User $actor, string $note): Expense
    {
        return $this->decide($expense, $actor, Expense::REJECTED, 'rejected', $note, true);
    }

    public function markPaid(Expense $expense, PaymentMethod $method, string $paidOn, ?string $reference, User $actor): Expense
    {
        return Atomic::run(function () use ($expense, $method, $paidOn, $reference, $actor) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->requireStatus($expense, Expense::APPROVED, 'Only an approved expense can be marked as paid.');
            if ($expense->payment_status === 'paid') {
                throw ValidationException::withMessages(['payment_method_id' => 'This expense is already marked as paid.']);
            }
            $expense->forceFill(['payment_status' => 'paid', 'payment_method_id' => $method->id, 'paid_on' => $paidOn, 'payment_reference' => $reference])->save();
            $this->event($expense, 'paid', $actor, $reference);
            $this->audit->log('expense.paid', $expense, null, ['number' => $expense->expense_number, 'method' => $method->code, 'paid_on' => $paidOn]);

            return $expense;
        });
    }

    /** Approvers may not approve their own submissions unless self-approval is enabled (Super Administrators always may). */
    public function canDecide(Expense $expense, User $user): bool
    {
        if (! $user->can('expenses.approve')) {
            return false;
        }

        return $expense->created_by !== $user->id || $user->isSuperAdmin() || (bool) $this->settings->get('finance.expense_self_approval', false);
    }

    private function decide(Expense $expense, User $actor, string $to, string $action, ?string $note, bool $approverOnly = false): Expense
    {
        return Atomic::run(function () use ($expense, $actor, $to, $action, $note, $approverOnly) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->requireStatus($expense, Expense::PENDING, 'This expense has already been decided.');
            if ($approverOnly && ! $this->canDecide($expense, $actor)) {
                throw ValidationException::withMessages(['status' => 'You cannot approve or reject an expense you submitted yourself.']);
            }
            $expense->forceFill(['status' => $to, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->event($expense, $action, $actor, $note);
            $this->audit->log("expense.{$action}", $expense, ['status' => Expense::PENDING], ['status' => $to, 'amount' => (string) $expense->amount], $note);

            return $expense;
        });
    }

    private function requireStatus(Expense $expense, string $status, string $message): void
    {
        if ($expense->status !== $status) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function event(Expense $expense, string $action, User $actor, ?string $note = null): void
    {
        ExpenseEvent::create(['expense_id' => $expense->id, 'action' => $action, 'user_id' => $actor->id, 'note' => $note]);
    }

    private function storeReceipt(UploadedFile $file): string
    {
        // Random, extension-from-content name: the client's filename and extension are never trusted for the stored path.
        $extension = match ($file->getMimeType()) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            default => 'jpg',
        };

        return $file->storeAs('expense-receipts', bin2hex(random_bytes(16)).'.'.$extension, self::RECEIPT_DISK);
    }

    /** @return array<string, mixed> */
    private function receiptFields(?UploadedFile $file, ?string $path): array
    {
        if (! $file || ! $path) {
            return [];
        }

        return [
            'receipt_path' => $path,
            'receipt_name' => mb_substr(preg_replace('/[^\pL\pN._\- ]/u', '_', $file->getClientOriginalName()) ?: 'receipt', 0, 150),
            'receipt_mime' => $file->getMimeType(),
            'receipt_size' => $file->getSize(),
        ];
    }
}
