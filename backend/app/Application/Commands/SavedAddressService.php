<?php

namespace App\Application\Commands;

use App\Application\Queries\ServiceabilityQuery;
use App\Domain\Geo\Coordinates;
use App\Models\User;
use App\Support\Errors\ApiError;
use App\Support\Http\PositionInput;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * A customer's saved delivery addresses. Each row holds an exact home
 * position, so nothing here logs or audits a value: audit rows carry the
 * address id and the NAMES of changed fields only (AuditLogger also redacts
 * position keys).
 *
 * Every write first takes a row lock on the owner (`users ... FOR UPDATE`),
 * as ProfileService does. That serialises one person's writes, so the
 * one-default partial unique index is never hit by two concurrent requests
 * and the limit of 20 cannot be passed by a race. Other people's writes do
 * not wait for each other.
 *
 * Ownership is part of every lookup (`id AND user_id`): another person's
 * address is indistinguishable from one that does not exist (ADR-005).
 */
class SavedAddressService
{
    public const LIMIT = 20;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ServiceabilityQuery $coverage,
    ) {}

    /** @return list<stdClass> */
    public function list(User $user): array
    {
        return array_values($this->select()
            ->where('a.user_id', $user->id)
            ->orderByDesc('a.is_default')->orderByDesc('a.created_at')->orderByDesc('a.id')
            ->limit(self::LIMIT)
            ->get()->all());
    }

    /**
     * @param  array<string, mixed>  $input  validated: address_line, landmark, latitude, longitude; tag and is_default optional
     */
    public function create(User $user, array $input, ?string $ip, ?string $correlationId): stdClass
    {
        $point = PositionInput::coordinates($input);

        return DB::transaction(function () use ($user, $input, $point, $ip, $correlationId): stdClass {
            $this->lockOwner($user);

            $held = DB::table('saved_addresses')->where('user_id', $user->id)->count();
            if ($held >= self::LIMIT) {
                throw ValidationException::withMessages(['address_line' => ['You can save up to '.self::LIMIT.' addresses. Delete one to add another.']]);
            }

            // The first address is the default; a later one only if asked.
            $default = $held === 0 || (bool) ($input['is_default'] ?? false);
            if ($default) {
                $this->clearDefault($user);
            }

            $id = strtolower((string) Str::ulid());
            DB::insert(
                'INSERT INTO saved_addresses (id, user_id, city_id, tag, address_line, landmark, point, is_default, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326), ?, NOW(), NOW())',
                [
                    $id, $user->id, $this->cityAt($point), $input['tag'] ?? null, $input['address_line'], $input['landmark'],
                    $point->longitude, $point->latitude, $default,
                ],
            );

            $this->audit->record(
                (string) $user->id, 'address.created', 'saved_address', $id,
                after: ['changed' => $this->names($input), 'is_default' => $default],
                ip: $ip, correlationId: $correlationId,
            );

            return $this->owned($user, $id);
        });
    }

    /**
     * @param  array<string, mixed>  $input  validated subset of the create fields
     */
    public function update(User $user, string $id, array $input, ?string $ip, ?string $correlationId): stdClass
    {
        $point = array_key_exists('latitude', $input) ? PositionInput::coordinates($input) : null;

        return DB::transaction(function () use ($user, $id, $input, $point, $ip, $correlationId): stdClass {
            $this->lockOwner($user);
            $current = $this->owned($user, $id, lock: true);

            $set = [];
            $changed = [];
            foreach (['address_line', 'landmark', 'tag'] as $field) {
                if (array_key_exists($field, $input) && $input[$field] !== $current->{$field}) {
                    $set[$field] = $input[$field];
                    $changed[] = $field;
                }
            }

            $moved = $point !== null
                && (abs($point->latitude - (float) $current->latitude) > 1e-12 || abs($point->longitude - (float) $current->longitude) > 1e-12);
            if ($moved) {
                $changed[] = 'latitude';
                $changed[] = 'longitude';
            }

            $wantsDefault = array_key_exists('is_default', $input) ? (bool) $input['is_default'] : null;
            $becomesDefault = $wantsDefault === true && ! (bool) $current->is_default;
            $stopsBeingDefault = $wantsDefault === false && (bool) $current->is_default;

            if ($changed === [] && ! $becomesDefault && ! $stopsBeingDefault) {
                return $current;
            }

            if ($becomesDefault) {
                $this->clearDefault($user);
                $set['is_default'] = true;
            } elseif ($stopsBeingDefault) {
                $set['is_default'] = false;
            }

            // The city is worked out again from the point at every write.
            $at = $point ?? Coordinates::fromLatitudeLongitude((float) $current->latitude, (float) $current->longitude);
            $set['city_id'] = $this->cityAt($at);
            $set['updated_at'] = DB::raw('NOW()');
            DB::table('saved_addresses')->where('id', $id)->where('user_id', $user->id)->update($set);
            if ($point !== null && $moved) {
                DB::update(
                    'UPDATE saved_addresses SET point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ? AND user_id = ?',
                    [$point->longitude, $point->latitude, $id, $user->id],
                );
            }

            if ($changed !== []) {
                $this->audit->record(
                    (string) $user->id, 'address.updated', 'saved_address', $id,
                    after: ['changed' => $changed], ip: $ip, correlationId: $correlationId,
                );
            }
            if ($becomesDefault || $stopsBeingDefault) {
                $this->audit->record(
                    (string) $user->id, 'address.default_changed', 'saved_address', $id,
                    after: ['is_default' => $becomesDefault], ip: $ip, correlationId: $correlationId,
                );
            }

            return $this->owned($user, $id);
        });
    }

    public function makeDefault(User $user, string $id, ?string $ip, ?string $correlationId): stdClass
    {
        return DB::transaction(function () use ($user, $id, $ip, $correlationId): stdClass {
            $this->lockOwner($user);
            $current = $this->owned($user, $id, lock: true);

            if ((bool) $current->is_default) {
                return $current;
            }

            $this->clearDefault($user);
            DB::table('saved_addresses')->where('id', $id)->where('user_id', $user->id)
                ->update(['is_default' => true, 'updated_at' => DB::raw('NOW()')]);
            $this->audit->record(
                (string) $user->id, 'address.default_changed', 'saved_address', $id,
                after: ['is_default' => true], ip: $ip, correlationId: $correlationId,
            );

            return $this->owned($user, $id);
        });
    }

    public function delete(User $user, string $id, ?string $ip, ?string $correlationId): void
    {
        DB::transaction(function () use ($user, $id, $ip, $correlationId): void {
            $this->lockOwner($user);
            $current = $this->owned($user, $id, lock: true);

            DB::table('saved_addresses')->where('id', $id)->where('user_id', $user->id)->delete();
            $this->audit->record(
                (string) $user->id, 'address.deleted', 'saved_address', $id,
                after: ['was_default' => (bool) $current->is_default], ip: $ip, correlationId: $correlationId,
            );
        });
    }

    /** Accepts any case; anything that is not a 26-character id is "not found". */
    public static function normaliseId(string $id): string
    {
        $id = strtolower($id);

        return preg_match('/\A[0-9a-z]{26}\z/', $id) === 1 ? $id : throw ApiError::notFound();
    }

    private function lockOwner(User $user): void
    {
        User::query()->lockForUpdate()->findOrFail($user->id);
    }

    private function clearDefault(User $user): void
    {
        DB::table('saved_addresses')->where('user_id', $user->id)->where('is_default', true)->update(['is_default' => false]);
    }

    private function cityAt(Coordinates $point): ?string
    {
        return $this->coverage->at($point)['city']['id'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function names(array $input): array
    {
        return array_values(array_intersect(['address_line', 'landmark', 'tag', 'latitude', 'longitude'], array_keys($input)));
    }

    private function owned(User $user, string $id, bool $lock = false): stdClass
    {
        $query = $this->select()->where('a.id', $id)->where('a.user_id', $user->id);
        if ($lock) {
            // FOR UPDATE OF the address row only; the joined city is not locked.
            $query->lock('for update of a');
        }

        return $query->first() ?? throw ApiError::notFound();
    }

    private function select(): Builder
    {
        return DB::table('saved_addresses as a')
            ->leftJoin('cities as c', 'c.id', '=', 'a.city_id')
            ->selectRaw('a.id, a.tag, a.address_line, a.landmark, ST_Y(a.point) AS latitude, ST_X(a.point) AS longitude, a.is_default, a.created_at, a.updated_at, c.id AS city_id, c.name AS city_name');
    }
}
