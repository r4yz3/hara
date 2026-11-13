<?php

namespace App\Application\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of audit_logs (spec 6.3). Call it inside the same database
 * transaction as the change it records, so neither can exist without the other.
 * The table itself refuses UPDATE, DELETE and TRUNCATE.
 */
class AuditLogger
{
    private const REDACTED = '[redacted]';

    /**
     * Key fragments whose values never enter the log: credentials, one-time
     * codes, encrypted columns and exact positions.
     */
    private const SENSITIVE = [
        'password', 'secret', 'token', 'otp', 'passcode', 'pin', 'verification', 'ciphertext', 'card', 'cvv',
        'latitude', 'longitude', 'lat', 'lng', 'lon', 'point', 'location', 'coordinates',
    ];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        ?string $actorUserId,
        string $action,
        string $subjectType,
        ?string $subjectId = null,
        ?string $scopeCityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $correlationId = null,
    ): string {
        $id = strtolower((string) Str::ulid());

        DB::table('audit_logs')->insert([
            'id' => $id,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'scope_city_id' => $scopeCityId,
            'before_json' => $before === null ? null : json_encode($this->redact($before), JSON_THROW_ON_ERROR),
            'after_json' => $after === null ? null : json_encode($this->redact($after), JSON_THROW_ON_ERROR),
            // Keyed hash: lets an investigator match two entries from one
            // address without the log holding the address itself.
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key')),
            'correlation_id' => $correlationId,
        ]);

        return $id;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redact(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $clean[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $clean[$key] = $this->redact($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        // Split snake_case and camelCase into words and match whole words, so
        // "otp_code" and "rememberToken" are caught while "country_code",
        // "pinned" and "platform" are not.
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key))) ?: [];

        foreach ($words as $word) {
            if (in_array($word, self::SENSITIVE, true)) {
                return true;
            }
        }

        return false;
    }
}
