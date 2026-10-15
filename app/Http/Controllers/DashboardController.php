<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\Product;
use App\Services\FinanceService;
use App\Services\ReportCatalog;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, FinanceService $finance, ReportCatalog $reports)
    {
        $user = $request->user();
        $range = DateRange::fromRequest($request);
        $data = ['user' => $user->load('roles'), 'range' => $range, 'operations' => [], 'upcoming' => collect(), 'finance' => null, 'charts' => null];

        if ($user->can('appointments.view')) {
            $today = Appointment::query()->whereBetween('start_at', [today(), today()->endOfDay()]);
            $counts = (clone $today)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
            $data['operations']['appointments_today'] = (int) $counts->sum();
            $data['operations']['appointments_open'] = (int) collect([AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InService])->sum(fn ($s) => $counts[$s->value] ?? 0);
            $data['upcoming'] = (clone $today)->whereIn('status', ['pending', 'confirmed', 'checked_in', 'in_service'])->where('end_at', '>=', now())->with('customer:id,name', 'lines:id,appointment_id,service_name')
                ->orderBy('start_at')->limit(6)->get();
        }
        if ($user->can('customers.view')) {
            $data['operations']['new_customers'] = Customer::where('registered_on', '>=', today()->subDays(29)->toDateString())->count();
        }
        if ($user->can('inventory.view')) {
            $data['operations']['low_stock'] = Product::where('is_active', true)->where('reorder_level', '>', 0)->whereColumn('current_stock', '<=', 'reorder_level')->count();
        }
        if ($user->can('expenses.approve')) {
            $data['operations']['pending_expenses'] = Expense::where('status', Expense::PENDING)->count();
        }
        if ($user->can('employees.leave')) {
            $data['operations']['pending_leave'] = LeaveRequest::where('status', 'pending')->count();
        }

        if ($user->can('dashboard.financials')) {
            $summary = $finance->summary($range);
            $series = $finance->dailySeries($range);
            $data['finance'] = $summary + ['net_after_refunds' => Money::sub($summary['payments_received'], $summary['refunds_paid'])];
            $data['charts'] = [
                'daily' => ['labels' => array_keys($series), 'revenue' => array_map(fn ($d) => (float) $d['revenue'], array_values($series)), 'collected' => array_map(fn ($d) => (float) $d['collected'], array_values($series))],
                'methods' => $reports->rows('payments_by_method', $range)->map(fn ($r) => ['label' => $r['method'], 'value' => (float) $r['net']])->values()->all(),
                'services' => $reports->rows('sales_by_service', $range)->take(5)->map(fn ($r) => ['label' => $r['item'], 'value' => (float) $r['revenue']])->values()->all(),
                'appointments' => $reports->rows('appointment_status', $range)->map(fn ($r) => ['label' => $r['status'], 'value' => $r['count']])->values()->all(),
            ];
        }

        return view('dashboard', $data);
    }
}
