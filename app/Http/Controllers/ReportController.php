<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\ReportCatalog;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private ReportCatalog $catalog) {}

    public function index(Request $request): View
    {
        $this->authorize('reports.view');

        return view('reports.index', ['reports' => collect($this->catalog->visibleTo($request->user()))->groupBy('group')]);
    }

    public function show(Request $request, string $report): View
    {
        $definition = $this->authorizedDefinition($request, $report);
        $range = DateRange::fromRequest($request);
        $period = $this->period($request);
        $rows = $this->catalog->rows($report, $range, $period);
        $print = $request->boolean('print');
        $page = $print ? null : new LengthAwarePaginator($rows->forPage(max(1, (int) $request->query('page', 1)), self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, max(1, (int) $request->query('page', 1)), ['path' => $request->url(), 'query' => $request->query()]);

        return view('reports.show', [
            'key' => $report, 'definition' => $definition, 'range' => $range, 'period' => $period, 'print' => $print,
            'rows' => $print ? $rows : $page->getCollection(), 'paginator' => $page,
            'totals' => $this->totals($definition['columns'], $rows),
            'truncated' => $rows->count() >= ReportCatalog::MAX_ROWS,
        ]);
    }

    public function export(Request $request, string $report, AuditLogger $audit): StreamedResponse
    {
        $definition = $this->authorizedDefinition($request, $report);
        $this->authorize('reports.export');
        $range = DateRange::fromRequest($request);
        $rows = $this->catalog->rows($report, $range, $this->period($request));
        $audit->log('report.exported', 'report', null, ['report' => $report, 'rows' => $rows->count()] + $range->query());

        return response()->streamDownload(function () use ($definition, $rows, $range): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$definition['title'], $definition['dated'] ? $range->label() : 'Current position']);
            fputcsv($out, array_map(fn ($c) => $c[0], $definition['columns']));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($key, $col) => $this->cell($row[$key] ?? '', $col[1]), array_keys($definition['columns']), $definition['columns']));
            }
            fclose($out);
        }, "{$report}-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function authorizedDefinition(Request $request, string $report): array
    {
        $definition = $this->catalog->definitions()[$report] ?? null;
        abort_if($definition === null, 404);
        abort_unless($request->user()->can($definition['permission']), 403);

        return $definition;
    }

    private function period(Request $request): string
    {
        return in_array($request->query('period'), ['day', 'week', 'month'], true) ? $request->query('period') : 'day';
    }

    /** Spreadsheet formula injection guard: text that starts with a formula character is prefixed with an apostrophe. */
    private function cell(mixed $value, string $type): string
    {
        $text = (string) $value;
        if ($type === 'text' && $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $columns
     * @return array<string, string|int>
     */
    private function totals(array $columns, $rows): array
    {
        $totals = [];
        foreach ($columns as $key => [$label, $type]) {
            if (in_array($type, ['int', 'money'], true) && $rows->isNotEmpty() && ! in_array($key, ['age', 'share', 'cost', 'average', 'visits'], true)) {
                $totals[$key] = $type === 'int' ? (int) $rows->sum($key) : number_format(round((float) $rows->sum(fn ($r) => (float) $r[$key]), 2), 2, '.', '');
            }
        }

        return $totals;
    }
}
