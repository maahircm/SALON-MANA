<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\Employee;
use App\Services\CommissionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionController extends Controller
{
    public function __construct(private CommissionService $commissions) {}

    public function index(Request $request): View
    {
        $this->authorize('employees.commissions');

        return view('hr.commissions', $this->statement($request, $request->query('employee') ? (int) $request->query('employee') : null) + [
            'employees' => Employee::orderBy('name')->get(['id', 'name']), 'canAdjust' => true, 'own' => false,
        ]);
    }

    /** A stylist's own statement. */
    public function mine(Request $request): View
    {
        $this->authorize('commissions.own');
        $employee = Employee::where('user_id', $request->user()->id)->first();

        // Without a linked employee record there is nothing to show (employee id 0 matches no rows).
        return view('hr.commissions', $this->statement($request, $employee?->id ?? 0) + ['employees' => collect($employee ? [$employee] : []), 'canAdjust' => false, 'own' => true, 'unlinked' => $employee === null]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        $this->authorize('employees.commissions');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'amount' => ['required', 'string', 'max:15'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $this->commissions->adjustManually(Employee::findOrFail($data['employee_id']), $data['amount'], $data['reason'], $request->user());

        return back()->with('success', 'Adjustment recorded.');
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('employees.commissions');
        $statement = $this->statement($request, $request->query('employee') ? (int) $request->query('employee') : null, paginate: false);

        return response()->streamDownload(function () use ($statement) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Employee', 'Type', 'Invoice / reason', 'Basis', 'Rule', 'Amount']);
            foreach ($statement['lines'] as $line) {
                fputcsv($out, [$line['date']->format('Y-m-d H:i'), $line['employee'], $line['type'], $this->csvSafe($line['reference']), $line['basis'] ?? '', $line['rule'] ?? '', $line['amount']]);
            }
            fputcsv($out, ['', '', '', 'NET TOTAL', '', '', $statement['totals']['net']]);
            fclose($out);
        }, 'commissions-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function statement(Request $request, ?int $employeeId, bool $paginate = true): array
    {
        $from = CarbonImmutable::parse($request->query('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse($request->query('to', now()->toDateString()))->endOfDay();

        $earned = Commission::with('employee:id,name', 'invoice:id,invoice_number')->whereBetween('accrued_at', [$from, $to])->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))->get();
        $adjustments = CommissionAdjustment::with('employee:id,name', 'commission.invoice:id,invoice_number')->whereBetween('created_at', [$from, $to])->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))->get();

        $lines = $earned->map(fn (Commission $c) => ['date' => $c->accrued_at, 'employee' => $c->employee->name, 'type' => 'Earned', 'reference' => $c->invoice->invoice_number,
            'basis' => (string) $c->basis_amount, 'rule' => $c->rule_type === 'percentage' ? $c->rate.'%' : money($c->rate).' per unit', 'amount' => (string) $c->amount])
            ->concat($adjustments->map(fn (CommissionAdjustment $a) => ['date' => $a->created_at, 'employee' => $a->employee->name, 'type' => ucfirst($a->type).' adjustment',
                'reference' => trim(($a->commission?->invoice?->invoice_number ?? '').' '.$a->reason), 'amount' => (string) $a->amount]))
            ->sortByDesc(fn ($l) => $l['date']->timestamp)->values();

        $earnedTotal = Money::add(...$earned->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $adjustTotal = Money::add(...$adjustments->pluck('amount')->map(fn ($a) => (string) $a)->all());

        return [
            'from' => $from, 'to' => $to, 'employeeId' => $employeeId,
            'lines' => $lines,
            'totals' => ['earned' => $earnedTotal, 'adjustments' => $adjustTotal, 'net' => Money::add($earnedTotal, $adjustTotal)],
        ];
    }

    private function csvSafe(string $value): string
    {
        return $value !== '' && str_contains('=+-@', $value[0]) ? "'".$value : $value;
    }
}
