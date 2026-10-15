<?php

namespace App\Http\Controllers;

use App\Contracts\SmsGateway;
use App\Models\NotificationLog;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Delivery status of outbound email and SMS, with manual retry for failures. */
class NotificationLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('notifications.manage');
        $filters = $request->validate([
            'status' => ['nullable', 'in:queued,sending,sent,logged,failed,skipped'], 'type' => ['nullable', 'in:'.implode(',', array_keys(NotificationLog::TYPES))],
            'channel' => ['nullable', 'in:email,sms'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $logs = NotificationLog::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('id')->paginate(25)->withQueryString();

        return view('notifications.logs', [
            'logs' => $logs, 'counts' => NotificationLog::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'smsDelivers' => app(SmsGateway::class)->delivers(),
        ]);
    }

    public function retry(Request $request, NotificationLog $log, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('notifications.manage');
        if (! $notifications->retry($log)) {
            return back()->with('error', 'Only failed messages can be retried.');
        }
        $audit->log('notification.retried', $log, null, ['type' => $log->type, 'channel' => $log->channel]);

        return back()->with('success', 'Message queued again.');
    }
}
