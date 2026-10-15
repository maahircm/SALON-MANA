<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationSettingsController extends Controller
{
    private const CHANNELS = ['email' => 'Email', 'sms' => 'SMS', 'in_app' => 'In-app (staff bell)'];

    public function edit(SettingsService $settings): View
    {
        $this->authorize('notifications.manage');

        return view('settings.notifications', [
            'channels' => collect(self::CHANNELS)->map(fn ($label, $key) => ['label' => $label, 'on' => (bool) $settings->get("notifications.channels.{$key}")])->all(),
            'events' => collect(NotificationLog::TYPES)->map(fn ($label, $key) => ['label' => $label, 'on' => (bool) $settings->get("notifications.events.{$key}")])->all(),
            'afterDays' => $settings->get('notifications.payment_reminder_after_days'), 'repeatDays' => $settings->get('notifications.payment_reminder_repeat_days'), 'max' => $settings->get('notifications.payment_reminder_max'),
            'reminderHours' => $settings->get('booking.reminder_hours_before'),
            'smsDriver' => config('salon.notifications.sms_driver'), 'mailer' => config('mail.default'),
        ]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('notifications.manage');
        $data = $request->validate([
            'payment_reminder_after_days' => ['required', 'integer', 'min:1', 'max:90'], 'payment_reminder_repeat_days' => ['required', 'integer', 'min:1', 'max:90'], 'payment_reminder_max' => ['required', 'integer', 'min:1', 'max:10'],
            'reminder_hours_before' => ['required', 'integer', 'min:1', 'max:336'],
        ]);
        $values = [];
        foreach (array_keys(self::CHANNELS) as $channel) {
            $values["notifications.channels.{$channel}"] = $request->boolean("channels.{$channel}");
        }
        foreach (array_keys(NotificationLog::TYPES) as $event) {
            $values["notifications.events.{$event}"] = $request->boolean("events.{$event}");
        }
        $values += ['notifications.payment_reminder_after_days' => (int) $data['payment_reminder_after_days'], 'notifications.payment_reminder_repeat_days' => (int) $data['payment_reminder_repeat_days'],
            'notifications.payment_reminder_max' => (int) $data['payment_reminder_max'], 'booking.reminder_hours_before' => (int) $data['reminder_hours_before']];

        $before = collect($values)->mapWithKeys(fn ($v, $k) => [$k => $settings->get($k)])->all();
        foreach ($values as $key => $value) {
            $settings->set($key, $value, $request->user()->id);
        }
        $audit->log('settings.notifications_updated', 'settings', $before, $values);

        return back()->with('success', 'Notification settings saved.');
    }
}
