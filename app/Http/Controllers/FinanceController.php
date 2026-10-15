<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\FinanceService;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function summary(Request $request, FinanceService $finance): View
    {
        $this->authorize('finance.view');
        $range = DateRange::fromRequest($request);

        return view('finance.summary', ['range' => $range, 'figures' => $finance->summary($range), 'print' => $request->boolean('print')]);
    }

    public function export(Request $request, FinanceService $finance, AuditLogger $audit): StreamedResponse
    {
        $this->authorize('finance.view');
        $this->authorize('reports.export');
        $range = DateRange::fromRequest($request);
        $f = $finance->summary($range);
        $audit->log('report.exported', 'report', null, ['report' => 'finance_summary'] + $range->query());

        $lines = [
            ['Invoices issued', $f['invoice_count']], ['Gross sales (incl. tax)', $f['gross_sales']], ['Tax collected', $f['tax_collected']], ['Net sales (excl. tax)', $f['net_sales']],
            ['Sales returns (excl. tax)', $f['sales_returns']], ['Net revenue', $f['net_revenue']], ['Cost of goods sold', $f['cogs']], ['Gross profit', $f['gross_profit']],
            ['Operating expenses (approved)', $f['expenses']], ['Staff commissions', $f['commissions']], ['Net operating result', $f['net_result']],
            ['Payments received (money)', $f['payments_received']], ['Refunds paid (money)', $f['refunds_paid']], ['Net cash collected', $f['net_cash_collected']],
            ['Settled with loyalty points', $f['points_settled']], ['Receivables at export time', $f['receivables']],
        ];
        foreach ($f['expenses_by_category'] as $name => $amount) {
            $lines[] = ["Expense: {$name}", $amount];
        }

        return response()->streamDownload(function () use ($lines, $range): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Period', $range->label()]);
            fputcsv($out, ['Line', 'Amount']);
            foreach ($lines as $line) {
                fputcsv($out, $line);
            }
            fclose($out);
        }, 'finance-summary-'.$range->from->toDateString().'_'.$range->to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
