<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    private const REDACT = ['password', 'secret', 'token', 'recovery_codes', 'remember_token'];

    public function log(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null, ?string $reason = null, ?string $actorId = null): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actorId ?? request()?->user()?->id ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'old_values' => $old ? $this->redact($old) : null,
            'new_values' => $new ? $this->redact($new) : null,
            'reason' => $reason,
            'ip_address' => request()?->ip(),
        ]);
    }

    private function redact(array $values): array
    {
        foreach ($values as $k => $v) {
            foreach (self::REDACT as $needle) {
                if (str_contains(strtolower((string) $k), $needle)) {
                    $values[$k] = '[redacted]';
                }
            }
        }

        return $values;
    }
}
