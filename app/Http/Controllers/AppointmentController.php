<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Requests\AppointmentRequest;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Room;
use App\Models\Service;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentStatusService;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function __construct(
        private AppointmentBookingService $booking,
        private AppointmentStatusService $statuses,
        private AvailabilityService $availability,
    ) {}

    public function calendar(Request $request)
    {
        $this->authorize('appointments.view');

        return view('appointments.calendar', [
            'employees' => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'calendar_color']),
            'statuses' => AppointmentStatus::cases(),
            'canCreate' => $request->user()->can('appointments.create'),
            'canSeeAll' => $request->user()->can('appointments.all'),
        ]);
    }

    public function list(Request $request)
    {
        $this->authorize('appointments.view');
        $appointments = $this->scoped($request)->with('customer:id,name,customer_number', 'lines.employee:id,name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('start_at', '>=', $v.' 00:00:00'))
            ->when($request->query('to'), fn ($q, $v) => $q->where('start_at', '<=', $v.' 23:59:59'))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('employee_id', $v)))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('appointment_number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('customer_number', 'like', "%{$s}%"))))
            ->orderByDesc('start_at')->paginate(20)->withQueryString();

        return view('appointments.list', ['appointments' => $appointments, 'statuses' => AppointmentStatus::cases(), 'employees' => Employee::orderBy('name')->get(['id', 'name'])]);
    }

    /** JSON feed for FullCalendar (already filtered to what the user may see). */
    public function events(Request $request)
    {
        $this->authorize('appointments.view');
        $request->validate(['start' => ['required', 'date'], 'end' => ['required', 'date'], 'employee_id' => ['nullable', 'integer']]);
        $start = CarbonImmutable::parse($request->query('start'))->setTimezone(config('app.timezone'))->toDateTimeString();
        $end = CarbonImmutable::parse($request->query('end'))->setTimezone(config('app.timezone'))->toDateTimeString();

        $appointments = $this->scoped($request)->with('customer:id,name', 'lines.employee:id,name')
            ->where('start_at', '<', $end)->where('end_at', '>', $start)
            ->when($request->query('employee_id'), fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('employee_id', $v)))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->limit(1000)->get();

        return response()->json($appointments->map(fn (Appointment $a) => [
            'id' => $a->id,
            'title' => $a->customer->name.' · '.$a->lines->pluck('service_name')->join(', '),
            'start' => $a->start_at->toIso8601String(),
            'end' => $a->end_at->toIso8601String(),
            'url' => route('appointments.show', $a),
            'color' => $a->status->color(),
            'extendedProps' => ['status' => $a->status->label(), 'staff' => $a->lines->pluck('employee.name')->unique()->join(', ')],
        ]));
    }

    /** Free start times for one stylist on a date for the given services (or a raw duration). */
    public function availability(Request $request)
    {
        abort_unless($request->user()->canAny(['appointments.create', 'appointments.update']), 403);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:720'],
            'exclude' => ['nullable', 'integer'],
        ]);
        $employee = Employee::with('skills')->findOrFail($data['employee_id']);

        return response()->json([
            'slots' => $this->availability->freeSlots($employee, CarbonImmutable::parse($data['date']), (int) ($data['duration'] ?? 30), $data['exclude'] ?? null),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('appointments.create');
        $customer = $request->query('customer_id') ? Customer::find($request->query('customer_id')) : null;
        $appointment = new Appointment;

        return view('appointments.form', $this->formData($appointment, $customer, [
            'date' => $request->query('date', now()->toDateString()),
            'time' => $request->query('time', ''),
            'source' => $request->query('walk_in') ? 'walk_in' : 'booking',
            'lines' => [['service_id' => '', 'employee_id' => $request->query('employee_id', ''), 'room_id' => '']],
        ]));
    }

    public function store(AppointmentRequest $request)
    {
        $appointment = $this->booking->create($request->booking(), $request->user(), $request->user()->can('appointments.override'));

        return redirect()->route('appointments.show', $appointment)->with('success', "Appointment {$appointment->appointment_number} booked.");
    }

    public function show(Appointment $appointment, Request $request)
    {
        $this->authorize('view', $appointment);
        $appointment->load('customer', 'lines.service', 'lines.employee', 'lines.room', 'histories.user', 'creator:id,name');
        $user = $request->user();

        return view('appointments.show', [
            'appointment' => $appointment,
            'next' => collect($appointment->status->allowedNext())->filter(fn ($s) => $s === AppointmentStatus::Cancelled
                ? $user->can('cancel', $appointment)
                : $user->can('changeStatus', $appointment))->values(),
            'canEdit' => $user->can('update', $appointment) && ! in_array($appointment->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)
                && ($appointment->status === AppointmentStatus::Pending || $appointment->status === AppointmentStatus::Confirmed || $user->can('appointments.override')),
        ]);
    }

    public function edit(Appointment $appointment, Request $request)
    {
        $this->authorize('update', $appointment);
        $appointment->load('customer', 'lines');
        $this->booking->assertEditable($appointment, $request->user()->can('appointments.override'), 'preview');

        return view('appointments.form', $this->formData($appointment, $appointment->customer, [
            'date' => $appointment->start_at->toDateString(),
            'time' => $appointment->start_at->format('H:i'),
            'source' => $appointment->source,
            'lines' => $appointment->lines->map(fn ($l) => ['service_id' => $l->service_id, 'employee_id' => $l->employee_id, 'room_id' => $l->room_id])->all(),
        ]));
    }

    public function update(AppointmentRequest $request, Appointment $appointment)
    {
        $this->booking->reschedule($appointment, $request->booking(), $request->user(), $request->user()->can('appointments.override'), $request->input('reason'));

        return redirect()->route('appointments.show', $appointment)->with('success', 'Appointment updated.');
    }

    public function transition(Request $request, Appointment $appointment)
    {
        $data = $request->validate(['status' => ['required', Rule::enum(AppointmentStatus::class)], 'reason' => ['nullable', 'string', 'max:500']]);
        $to = AppointmentStatus::from($data['status']);
        $this->authorize($to === AppointmentStatus::Cancelled ? 'cancel' : 'changeStatus', $appointment);

        $this->statuses->transition($appointment, $to, $request->user(), $data['reason'] ?? null, $request->user()->can('appointments.override'));

        return redirect()->route('appointments.show', $appointment)->with('success', "Appointment marked {$to->label()}.");
    }

    private function scoped(Request $request)
    {
        $query = Appointment::query();
        if (! $request->user()->can('appointments.all')) {
            $employeeId = Employee::where('user_id', $request->user()->id)->value('id');
            $query->whereHas('lines', fn ($l) => $l->where('employee_id', $employeeId ?: 0));
        }

        return $query;
    }

    private function formData(Appointment $appointment, ?Customer $customer, array $values): array
    {
        return [
            'appointment' => $appointment,
            'customer' => $customer,
            'values' => $values,
            'services' => Service::with('requiredSkill')->where('is_active', true)->orderBy('name')->get(),
            'employees' => Employee::with('skills:id')->where('status', 'active')->where('is_bookable', true)->orderBy('name')->get(),
            'rooms' => Room::where('is_active', true)->orderBy('name')->get(),
        ];
    }
}
