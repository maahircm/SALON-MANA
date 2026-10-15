<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

/** Development / default driver: writes the message to the application log. Nothing is delivered. */
class LogSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function delivers(): bool
    {
        return false;
    }

    public function send(string $to, string $message): ?string
    {
        Log::info('SMS (log driver, not delivered)', ['to' => $to, 'message' => $message]);

        return null;
    }
}
