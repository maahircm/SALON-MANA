<?php

namespace App\Services;

use App\Models\LoginActivity;
use Illuminate\Http\Request;

class LoginRecorder
{
    public function record(string $event, ?int $userId, ?string $email, Request $request): void
    {
        LoginActivity::create([
            'user_id' => $userId,
            'email' => $email ? mb_substr($email, 0, 255) : null,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
