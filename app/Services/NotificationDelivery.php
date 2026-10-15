<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use App\Mail\SalonMessage;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Performs one delivery attempt for a notification log row. Safe to call twice: only one caller can claim a queued row. */
class NotificationDelivery
{
    public function deliver(int $logId): void
    {
        $claimed = NotificationLog::whereKey($logId)->where('status', NotificationLog::QUEUED)->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1')]);
        if (! $claimed) {
            return;
        }
        $log = NotificationLog::findOrFail($logId);

        try {
            if ($log->channel === 'email') {
                Mail::to($log->destination)->send(new SalonMessage((string) $log->subject, $log->body));
                $fields = ['status' => NotificationLog::SENT, 'provider' => (string) config('mail.default')];
            } else {
                $gateway = app(SmsGateway::class);
                $reference = $gateway->send($log->destination, $log->body);
                $fields = ['status' => $gateway->delivers() ? NotificationLog::SENT : NotificationLog::LOGGED, 'provider' => $gateway->name(), 'provider_reference' => $reference];
            }
            $log->forceFill($fields + ['sent_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $e) {
            // Back to "queued" so the queue's retry/backoff can try again; the job marks it "failed" when attempts run out.
            $log->forceFill(['status' => NotificationLog::QUEUED, 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            throw $e;
        }
    }
}
