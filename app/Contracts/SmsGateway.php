<?php

namespace App\Contracts;

/** Sends one text message. Implementations throw on failure so the queued job can retry. */
interface SmsGateway
{
    /** Driver name stored on the notification log (e.g. "log"). */
    public function name(): string;

    /** True when the message really leaves the system; false for drivers that only record it (the "log" driver). */
    public function delivers(): bool;

    /** @return string|null provider message reference */
    public function send(string $to, string $message): ?string;
}
