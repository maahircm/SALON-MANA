<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Opening hours and booking policy. (The wider settings area is extended in the administration phase.) */
class BookingSettingsController extends Controller
{
    public function edit(SettingsService $settings)
    {
        $this->authorize('settings.manage');

        return view('settings.booking', [
            'hours' => $settings->get('booking.business_hours'),
            'policy' => collect(['buffer_minutes', 'slot_minutes', 'max_advance_days', 'allow_stylist_overlap', 'cancellation_notice_hours', 'late_cancellation_action', 'reminder_hours_before'])
                ->mapWithKeys(fn ($k) => [$k => $settings->get("booking.$k")])->all(),
            'weekdays' => Employee::WEEKDAYS,
        ]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $audit)
    {
        $this->authorize('settings.manage');
        $data = $request->validate([
            'hours' => ['required', 'array'],
            'hours.*.open' => ['required', 'date_format:H:i'],
            'hours.*.close' => ['required', 'date_format:H:i'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'slot_minutes' => ['required', 'integer', 'in:5,10,15,20,30,60'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:730'],
            'cancellation_notice_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'late_cancellation_action' => ['required', 'in:flag,block'],
            'reminder_hours_before' => ['required', 'integer', 'min:1', 'max:336'],
        ]);

        $hours = [];
        foreach (range(0, 6) as $d) {
            $row = $data['hours'][$d] ?? null;
            if (! $row) {
                throw ValidationException::withMessages(['hours' => 'Opening hours are required for every weekday.']);
            }
            $closed = (bool) $request->input("closed.$d");
            if (! $closed && $row['open'] >= $row['close']) {
                throw ValidationException::withMessages(["hours.$d.close" => 'Closing time must be after opening time.']);
            }
            $hours[$d] = ['open' => $row['open'], 'close' => $row['close'], 'closed' => $closed];
        }

        $before = ['hours' => $settings->get('booking.business_hours')] + collect($data)->except('hours')->keys()->mapWithKeys(fn ($k) => [$k => $settings->get("booking.$k")])->all();
        $settings->set('booking.business_hours', $hours, $request->user()->id);
        foreach (collect($data)->except('hours')->all() as $k => $v) {
            $settings->set("booking.$k", $v, $request->user()->id);
        }
        $settings->set('booking.allow_stylist_overlap', $request->boolean('allow_stylist_overlap'), $request->user()->id);
        $audit->log('settings.booking_updated', 'settings', $before, ['hours' => $hours] + collect($data)->except('hours')->all() + ['allow_stylist_overlap' => $request->boolean('allow_stylist_overlap')]);

        return back()->with('success', 'Booking settings saved.');
    }
}
