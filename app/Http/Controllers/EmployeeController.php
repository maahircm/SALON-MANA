<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Requests\EmployeeRequest;
use App\Models\AppointmentService;
use App\Models\Employee;
use App\Models\Skill;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('employees.view');
        $employees = Employee::with('skills:id,name')
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('employee_number', 'like', "%{$s}%")
                ->orWhere('job_title', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('skill'), fn ($q, $v) => $q->whereHas('skills', fn ($s) => $s->where('skills.id', $v)))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('employees.index', ['employees' => $employees, 'skills' => Skill::orderBy('name')->get()]);
    }

    public function create()
    {
        $this->authorize('employees.manage');

        return view('employees.form', ['employee' => new Employee(['status' => 'active', 'is_bookable' => true, 'calendar_color' => '#8b3a62', 'commission_type' => 'none', 'commission_rate' => 0]), ...$this->lookups(null)]);
    }

    public function store(EmployeeRequest $request)
    {
        $employee = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $employee = new Employee(collect($data)->only((new Employee)->getFillable())->all());
            $employee->is_bookable = $request->boolean('is_bookable');
            $employee->forceFill([
                'status' => $data['status'], 'termination_date' => $data['termination_date'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ] + ($request->user()->can('employees.sensitive') ? ['salary_amount' => $data['salary_amount'] ?? null] : []))->save();
            $employee->forceFill(['employee_number' => sprintf('EMP-%04d', $employee->id)])->save();
            $employee->skills()->sync($data['skills'] ?? []);
            $this->audit->log('employee.created', $employee, null, ['employee_number' => $employee->employee_number, 'status' => $employee->status]);

            return $employee;
        });

        return redirect()->route('employees.show', $employee)->with('success', "Employee {$employee->employee_number} registered. Set their working hours next.");
    }

    public function show(Employee $employee, Request $request)
    {
        $this->authorize('employees.view');
        $employee->load('skills', 'user:id,name,email', 'schedules');
        $base = AppointmentService::where('employee_id', $employee->id)->join('appointments', 'appointments.id', '=', 'appointment_services.appointment_id');
        $metrics = [
            'completed' => (clone $base)->where('appointments.status', AppointmentStatus::Completed->value)->count(),
            'upcoming' => (clone $base)->whereIn('appointments.status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])->where('appointment_services.start_at', '>=', now())->count(),
            'no_shows' => (clone $base)->where('appointments.status', AppointmentStatus::NoShow->value)->count(),
            'cancelled' => (clone $base)->where('appointments.status', AppointmentStatus::Cancelled->value)->count(),
            'revenue_booked' => (clone $base)->where('appointments.status', AppointmentStatus::Completed->value)->sum('appointment_services.unit_price'),
        ];

        return view('employees.show', ['employee' => $employee, 'metrics' => $metrics, 'canSensitive' => $request->user()->can('employees.sensitive')]);
    }

    public function edit(Employee $employee)
    {
        $this->authorize('employees.manage');

        return view('employees.form', ['employee' => $employee->load('skills'), ...$this->lookups($employee)]);
    }

    public function update(EmployeeRequest $request, Employee $employee)
    {
        DB::transaction(function () use ($request, $employee) {
            $data = $request->validated();
            $oldStatus = $employee->status;
            $original = $employee->getOriginal();

            if ($data['status'] === 'terminated' && $oldStatus !== 'terminated') {
                $open = AppointmentService::where('employee_id', $employee->id)->where('start_at', '>=', now())
                    ->whereHas('appointment', fn ($q) => $q->whereIn('status', AppointmentStatus::blockingValues()))->exists();
                if ($open) {
                    throw ValidationException::withMessages(['status' => 'Reassign or cancel this employee\'s upcoming appointments before terminating.']);
                }
            }
            $employee->fill(collect($data)->only($employee->getFillable())->all());
            $employee->is_bookable = $request->boolean('is_bookable');
            $employee->forceFill(['status' => $data['status'], 'termination_date' => $data['status'] === 'terminated' ? $data['termination_date'] : null,
                'user_id' => $data['user_id'] ?? null, 'updated_by' => $request->user()->id]);
            if ($request->user()->can('employees.sensitive')) {
                $employee->salary_amount = $data['salary_amount'] ?? null;
            }
            $employee->save();
            $employee->skills()->sync($data['skills'] ?? []);

            $changes = $employee->getChanges();
            unset($changes['updated_at'], $changes['updated_by']);
            $before = $after = [];
            foreach ($changes as $k => $v) {
                $sensitive = in_array($k, ['salary_amount', 'phone', 'email', 'emergency_contact_name', 'emergency_contact_phone'], true);
                $before[$k] = $sensitive ? '[changed]' : ($original[$k] ?? null);
                $after[$k] = $sensitive ? '[changed]' : $v;
            }
            if ($changes) {
                $this->audit->log($oldStatus !== $employee->status ? 'employee.status_changed' : 'employee.updated', $employee, $before, $after, $data['status_reason'] ?? null);
            }
        });

        return redirect()->route('employees.show', $employee)->with('success', 'Employee updated.');
    }

    /** Link only users that are not already attached to another employee. */
    private function lookups(?Employee $employee): array
    {
        $linked = Employee::whereNotNull('user_id')->when($employee, fn ($q) => $q->where('id', '!=', $employee->id))->pluck('user_id');

        return [
            'skills' => Skill::orderBy('name')->get(),
            'users' => User::where('is_active', true)->whereNotIn('id', $linked)->orderBy('name')->get(['id', 'name', 'email']),
            'canSensitive' => auth()->user()->can('employees.sensitive'),
        ];
    }
}
