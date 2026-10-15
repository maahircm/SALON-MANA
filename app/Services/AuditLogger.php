<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /** Keys that must never be written to the audit log. */
    private const REDACT = ['password', 'password_confirmation', 'current_password', 'remember_token', 'token',
        'two_factor_secret', 'two_factor_recovery_codes', 'secret', 'api_key', 'card_number', 'cvv'];

    public function log(string $action, Model|string|null $entity = null, ?array $before = null, ?array $after = null, ?string $reason = null): AuditLog
    {
        $actor = Auth::user();
        $request = request();

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'actor_label' => $actor ? $actor->name.' <'.$actor->email.'>' : null,
            'action' => $action,
            'entity_type' => $entity instanceof Model ? $entity->getMorphClass() : $entity,
            'entity_id' => $entity instanceof Model ? (string) $entity->getKey() : null,
            'correlation_id' => $request->attributes->get('correlation_id'),
            'reason' => $reason,
            'before' => $before === null ? null : $this->redact($before),
            'after' => $after === null ? null : $this->redact($after),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    /** Diff helper: returns [before, after] containing only changed attributes. */
    public function diff(Model $model, array $original): array
    {
        $after = [];
        $before = [];
        foreach ($model->getChanges() as $key => $value) {
            if ($key === 'updated_at') {
                continue;
            }
            $before[$key] = $original[$key] ?? null;
            $after[$key] = $value;
        }

        return [$before, $after];
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
