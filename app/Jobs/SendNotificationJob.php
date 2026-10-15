<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\NotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $logId)
    {
        $this->afterCommit();
    }

    /** @return array<int, int> seconds to wait before attempt 2 and 3 */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(NotificationDelivery $delivery): void
    {
        $delivery->deliver($this->logId);
    }

    public function failed(Throwable $exception): void
    {
        NotificationLog::whereKey($this->logId)->whereIn('status', [NotificationLog::QUEUED, 'sending'])
            ->update(['status' => NotificationLog::FAILED, 'last_error' => mb_substr($exception->getMessage(), 0, 500)]);
    }
}
