<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'api_key', 'stripe_customer_id', 'stripe_subscription_id'];

    private const REDACTED_TEXT_VALUE = '[redacted]';

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function log(?User $user, string $entityType, int $entityId, string $action, ?array $before = null, ?array $after = null, ?string $ipAddress = null): AuditLog
    {
        $auditLog = new AuditLog([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_json' => $this->sanitize($before),
            'after_json' => $this->sanitize($after),
            'ip_address' => $ipAddress,
        ]);
        $auditLog->user_id = $user?->id;
        $auditLog->save();

        return $auditLog;
    }

    /** @param array<string, mixed>|null $values @return array<string, mixed>|null */
    public function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                continue;
            }
            $sanitized[$key] = match (true) {
                is_array($value) => $this->sanitize($value),
                is_string($value) => self::REDACTED_TEXT_VALUE,
                default => $value,
            };
        }

        return $sanitized;
    }
}
