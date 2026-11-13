<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Commands\AuditLogger;
use App\Application\Queries\AccessChecker;
use App\Domain\Geo\CityStatus;
use App\Domain\Identity\Scope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use App\Support\Http\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Read-only oversight for staff (spec 7.4, backlog C-10): the audit trail
 * and a deployment status summary. Neither returns a secret, an address or
 * a connection detail.
 */
class OversightController extends Controller
{
    private const ID = 'regex:/\A[0-9a-zA-Z]{26}\z/';

    public function __construct(
        private readonly AccessChecker $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Newest first. Staff with `audit.read` everywhere see every entry;
     * staff holding it for some cities see only entries tied to those
     * cities, so platform-level entries stay hidden from them.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $reach = $this->access->cityReach($this->user(), 'audit.read');
        if (! $reach['all'] && $reach['city_ids'] === []) {
            throw ApiError::forbidden();
        }

        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'action' => ['sometimes', 'string', 'max:120'],
            'subject_type' => ['sometimes', 'string', 'max:80'],
            'subject_id' => ['sometimes', 'string', self::ID],
            'actor_user_id' => ['sometimes', 'string', self::ID],
            'city_id' => ['sometimes', 'string', self::ID],
        ]);

        $before = null;
        if (isset($input['cursor'])) {
            $before = Cursor::decode($input['cursor']) ?? throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }
        $limit = (int) ($input['limit'] ?? 25);

        // Ids are ULIDs, so id order is time order.
        $query = DB::table('audit_logs')->orderByDesc('id');
        if (! $reach['all']) {
            $query->whereIn('scope_city_id', $reach['city_ids']);
        }
        foreach (['action' => 'action', 'subject_type' => 'subject_type', 'subject_id' => 'subject_id', 'actor_user_id' => 'actor_user_id', 'city_id' => 'scope_city_id'] as $param => $column) {
            if (isset($input[$param])) {
                $query->where($column, in_array($param, ['action', 'subject_type'], true) ? $input[$param] : strtolower((string) $input[$param]));
            }
        }
        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        $rows = $query->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $next = $rows->count() > $limit ? Cursor::encode((string) $page->last()?->id) : null;

        return ApiResponse::page($request, $page->map(fn (object $row): array => [
            'id' => $row->id,
            'actor_user_id' => $row->actor_user_id,
            'action' => $row->action,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'city_id' => $row->scope_city_id,
            // Redacted when written; redacted again here in case a row was not.
            'before' => $this->json($row->before_json),
            'after' => $this->json($row->after_json),
            'correlation_id' => $row->correlation_id,
            'created_at' => Carbon::parse((string) $row->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        ])->values()->all(), $next);
    }

    /**
     * Deployment-wide, so it needs `system.status.read` with global scope.
     * Built from a fixed list of facts; dependency checks report up or down
     * only, with details going to the log.
     */
    public function systemStatus(Request $request): JsonResponse
    {
        if (! $this->access->allows($this->user(), 'system.status.read', Scope::global())) {
            throw ApiError::forbidden();
        }

        $style = config('platform.map_style_url');

        return ApiResponse::data($request, [
            'environment' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'checks' => [
                'database' => $this->passes(fn () => DB::select('select 1')),
                'redis' => $this->passes(fn () => Redis::connection()->command('ping')),
            ],
            'postgis' => $this->passes(fn () => DB::select('select PostGIS_Version()')),
            'cities' => $this->countByStatus('cities'),
            'zones' => $this->countByStatus('delivery_zones'),
            'features' => [
                'public_registration' => (bool) config('features.public_registration'),
                'live_dispatch' => (bool) config('features.live_dispatch'),
                'online_payments' => (bool) config('features.online_payments'),
                'rider_tracking' => (bool) config('features.rider_tracking'),
            ],
            'map_style_configured' => is_string($style) && $style !== '',
            // Backlog C-13: the vertex and extent caps are proposals until a person approves them.
            'geometry_caps_approved' => (bool) config('geo.caps_approved', false),
        ]);
    }

    /** @return array<string, int> */
    private function countByStatus(string $table): array
    {
        $counts = array_fill_keys(CityStatus::values(), 0);
        foreach (DB::table($table)->selectRaw('status, count(*) AS n')->groupBy('status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->n;
        }

        return $counts;
    }

    /** @return array<array-key, mixed>|null */
    private function json(?string $value): ?array
    {
        $decoded = $value === null ? null : json_decode($value, true);

        return is_array($decoded) ? $this->audit->redact($decoded) : null;
    }

    private function passes(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
