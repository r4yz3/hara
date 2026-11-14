<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Commands\RoleGrantService;
use App\Application\Commands\StaffSuspension;
use App\Application\Queries\AccessChecker;
use App\Domain\Identity\AccessDenied;
use App\Domain\Identity\Scope;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignRequestId;
use App\Models\User;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use App\Support\Http\Cursor;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff directory and role grants for the "Roles and access" screen
 * (backlog U-05). Every change goes through RoleGrantService, which checks
 * the rules against stored data and audits in the same transaction (ADR-005).
 *
 * Who is visible: `users.read` decides, and only the grants inside the
 * caller's reach are shown. A person outside that reach is 404, as if they
 * did not exist. Granting needs `users.roles.assign` and the entitlement
 * rules in the service; nobody can change their own roles. Accounts that hold
 * no staff role cannot be reached from here: bringing a new person in goes
 * through StaffInvitationController.
 */
class StaffAccessController extends Controller
{
    private const ID = '/\A[0-9a-z]{26}\z/';

    public function __construct(
        private readonly AccessChecker $access,
        private readonly RoleGrantService $grants,
        private readonly StaffSuspension $suspension,
    ) {}

    /** Staff members, by id, with the grants the caller may see. */
    public function index(Request $request): JsonResponse
    {
        $reach = $this->readReach();
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $after = null;
        if (isset($input['cursor'])) {
            $after = Cursor::decode($input['cursor']) ?? throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }
        $limit = (int) ($input['limit'] ?? 25);

        $query = DB::table('users')
            ->whereIn('id', $this->visibleGrants($reach)->select('g.user_id'))
            ->orderBy('id');
        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        $rows = $query->limit($limit + 1)->get(['id', 'name', 'email', 'status']);
        $page = $rows->take($limit);
        $next = $rows->count() > $limit ? Cursor::encode((string) $page->last()?->id) : null;

        return ApiResponse::page($request, $this->resources($page->all(), $reach), $next);
    }

    /** Roles the caller may hand out, and where. Empty when they may hand out none. */
    public function roles(Request $request): JsonResponse
    {
        $this->readReach();

        return ApiResponse::page($request, $this->grants->assignableRoles($this->user()), null);
    }

    public function grant(Request $request, string $user): JsonResponse
    {
        $reach = $this->readReach(hideRefusal: true);
        $target = $this->visibleTarget($user, $reach);

        $input = $request->validate([
            'role' => ['required', 'string', 'max:40', 'regex:/\A[a-z_]+\z/'],
            'scope_type' => ['required', 'string', Rule::in(['global', 'city'])],
            'scope_id' => ['required_if:scope_type,city', 'prohibited_if:scope_type,global', 'nullable', 'string', 'regex:/\A[0-9a-zA-Z]{26}\z/'],
        ]);

        $scope = $input['scope_type'] === 'global'
            ? Scope::global()
            : Scope::city(strtolower((string) $input['scope_id']));

        try {
            $this->grants->grant($this->user(), $this->targetModel($target), (string) $input['role'], $scope);
        } catch (AccessDenied $denied) {
            throw $this->refusal($denied);
        }

        $row = DB::table('users')->where('id', $target)->first(['id', 'name', 'email', 'status']);

        return ApiResponse::data($request, $this->resources([$row], $reach)[0], 201);
    }

    /**
     * Suspends a staff account (product owner decision 2026-11-14: global
     * administration only). The permission is checked first and does not
     * depend on the target, so a refusal says nothing about who exists; after
     * that a person outside the caller's view is 404.
     */
    public function suspend(Request $request, string $user): Response
    {
        $target = $this->suspendTarget($user);
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $reason = trim((string) $input['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['The reason is required.']]);
        }

        try {
            $this->suspension->suspend($this->user(), $target, $reason, $request->ip(), AssignRequestId::for($request));
        } catch (AccessDenied $denied) {
            throw $this->suspensionRefusal($denied);
        }

        return response()->noContent();
    }

    public function reactivate(Request $request, string $user): Response
    {
        $target = $this->suspendTarget($user);
        $input = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $reason = isset($input['reason']) ? trim((string) $input['reason']) : null;

        try {
            $this->suspension->reactivate($this->user(), $target, $reason === '' ? null : $reason, $request->ip(), AssignRequestId::for($request));
        } catch (AccessDenied $denied) {
            throw $this->suspensionRefusal($denied);
        }

        return response()->noContent();
    }

    private function suspendTarget(string $user): string
    {
        if (! $this->suspension->mayAct($this->user(), log: true)) {
            throw ApiError::forbidden();
        }

        // Global holders of users.read see every staff member; a holder without it sees none.
        return $this->visibleTarget($user, $this->readReach(hideRefusal: true));
    }

    private function suspensionRefusal(AccessDenied $denied): ApiError
    {
        return match ($denied->reason) {
            AccessDenied::SELF_SUSPEND => ApiError::forbidden('You cannot suspend or reactivate your own account.'),
            AccessDenied::PROTECTED_TARGET => ApiError::forbidden('A platform owner cannot be suspended here.'),
            AccessDenied::SCOPE_TARGET_MISSING => ApiError::notFound(),
            AccessDenied::ALREADY_SUSPENDED => new ApiError(409, 'CONFLICT', 'That account is already suspended.'),
            AccessDenied::NOT_SUSPENDED => new ApiError(409, 'CONFLICT', 'That account is not suspended.'),
            default => ApiError::forbidden(),
        };
    }

    public function revoke(string $user, string $grant): Response
    {
        $reach = $this->readReach(hideRefusal: true);
        $target = $this->visibleTarget($user, $reach);

        $grantId = strtolower($grant);
        $row = preg_match(self::ID, $grantId) === 1
            ? $this->visibleGrants($reach)->where('g.id', $grantId)->where('g.user_id', $target)->first(['g.id'])
            : null;

        // A grant that is not this person's, or not within the caller's view, does not exist for them.
        if ($row === null) {
            throw ApiError::notFound();
        }

        try {
            $this->grants->revoke($this->user(), $grantId);
        } catch (AccessDenied $denied) {
            throw $this->refusal($denied);
        }

        return response()->noContent();
    }

    /**
     * @return array{all: bool, city_ids: list<string>}
     */
    private function readReach(bool $hideRefusal = false): array
    {
        $reach = $this->access->cityReach($this->user(), 'users.read');
        if (! $reach['all'] && $reach['city_ids'] === []) {
            // On a named person or grant, "may not look" reads as "not there" (ADR-005).
            throw $hideRefusal ? ApiError::notFound() : ApiError::forbidden();
        }

        return $reach;
    }

    /**
     * Live staff grants the caller may see. Global reach sees them all; city
     * reach sees only grants anchored to those cities.
     *
     * @param  array{all: bool, city_ids: list<string>}  $reach
     */
    private function visibleGrants(array $reach): Builder
    {
        $query = DB::table('user_role_scopes as g')
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->whereNull('g.revoked_at')
            ->whereIn('r.slug', (array) config('surfaces.staff_roles'));

        if (! $reach['all']) {
            $query->where('g.scope_type', 'city')->whereIn('g.scope_id', $reach['city_ids']);
        }

        return $query;
    }

    /**
     * The person must be a staff member the caller can see. The id comes from
     * the path and is only ever matched against stored rows.
     *
     * @param  array{all: bool, city_ids: list<string>}  $reach
     */
    private function visibleTarget(string $id, array $reach): string
    {
        $id = strtolower($id);

        if (preg_match(self::ID, $id) !== 1
            || ! $this->visibleGrants($reach)->where('g.user_id', $id)->exists()) {
            throw ApiError::notFound();
        }

        return $id;
    }

    private function targetModel(string $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    /**
     * @param  list<object>  $users  rows with id, name, email, status
     * @param  array{all: bool, city_ids: list<string>}  $reach
     * @return list<array<string, mixed>>
     */
    private function resources(array $users, array $reach): array
    {
        if ($users === []) {
            return [];
        }

        $actor = $this->user();
        $ids = array_map(fn (object $u): string => (string) $u->id, $users);
        $full = $this->fullEmailReach();

        /** @var array<string, list<object>> $grants */
        $grants = [];
        $rows = $this->visibleGrants($reach)
            ->leftJoin('cities as c', fn ($join) => $join->on('c.id', '=', 'g.scope_id')->where('g.scope_type', '=', 'city'))
            ->whereIn('g.user_id', $ids)
            ->orderBy('g.created_at')->orderBy('g.id')
            ->get(['g.id', 'g.user_id', 'g.scope_type', 'g.scope_id', 'g.created_at', 'r.slug', 'c.name as city_name']);
        foreach ($rows as $row) {
            $grants[(string) $row->user_id][] = $row;
        }

        $mayAct = $this->suspension->mayAct($actor);
        $owners = DB::table('user_role_scopes as g')->join('roles as r', 'r.id', '=', 'g.role_id')
            ->whereNull('g.revoked_at')->where('r.slug', 'platform_owner')->whereIn('g.user_id', $ids)
            ->pluck('g.user_id')->all();

        $enrolled = DB::table('user_mfa_factors')->whereIn('user_id', $ids)->whereNotNull('confirmed_at')->pluck('user_id')->all();

        return array_map(function (object $u) use ($grants, $enrolled, $actor, $full, $mayAct, $owners): array {
            $mine = $grants[(string) $u->id] ?? [];
            $isSelf = $u->id === $actor->getKey();

            // `users.read` through support alone shows a masked address (permission matrix).
            $showEmail = $full['all'] || array_filter($mine, fn (object $g): bool => $g->scope_type === 'city' && in_array($g->scope_id, $full['city_ids'], true)) !== [];

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $showEmail ? $u->email : $this->mask((string) $u->email),
                'email_masked' => ! $showEmail,
                'status' => $u->status,
                'mfa_enrolled' => in_array($u->id, $enrolled, true),
                'is_self' => $isSelf,
                'can_suspend' => $mayAct && ! $isSelf && $u->status === 'active' && ! in_array($u->id, $owners, true),
                'can_reactivate' => $mayAct && ! $isSelf && $u->status === 'suspended',
                'grants' => array_map(function (object $g) use ($u, $actor, $isSelf): array {
                    $scope = Scope::of($g->scope_type, $g->scope_id);

                    return [
                        'id' => $g->id,
                        'role' => $g->slug,
                        'role_label' => (string) config("rbac.roles.{$g->slug}.label", $g->slug),
                        'scope_type' => $g->scope_type,
                        'scope_id' => $g->scope_id,
                        'city_name' => $g->city_name,
                        'granted_at' => Carbon::parse((string) $g->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
                        'can_revoke' => ! $isSelf && $this->grants->mayRevoke($actor, (string) $u->id, $g->slug, $scope),
                    ];
                }, $mine),
            ];
        }, $users);
    }

    /**
     * Where the caller holds `users.read` through a role other than support,
     * which is the only role that sees addresses masked.
     *
     * @return array{all: bool, city_ids: list<string>}
     */
    private function fullEmailReach(): array
    {
        $rows = DB::table('user_role_scopes as g')
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('g.user_id', $this->user()->getKey())
            ->whereNull('g.revoked_at')
            ->where('p.slug', 'users.read')
            ->where('r.slug', '!=', 'support')
            ->get(['g.scope_type', 'g.scope_id']);

        return [
            'all' => $rows->contains(fn (object $row): bool => $row->scope_type === 'global'),
            'city_ids' => $rows->where('scope_type', 'city')->pluck('scope_id')->map(fn ($id): string => (string) $id)->values()->all(),
        ];
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * The reason stays in the log; the caller gets a safe sentence.
     */
    private function refusal(AccessDenied $denied): ApiError|ValidationException
    {
        return match ($denied->reason) {
            AccessDenied::SELF_GRANT => ApiError::forbidden('You cannot change your own roles.'),
            AccessDenied::SCOPE_TARGET_MISSING, AccessDenied::GRANT_NOT_FOUND => ApiError::notFound(),
            AccessDenied::UNKNOWN_ROLE => ValidationException::withMessages(['role' => ['That role does not exist.']]),
            AccessDenied::TARGET_INACTIVE => new ApiError(409, 'CONFLICT', 'That account is not active.'),
            AccessDenied::ALREADY_GRANTED => new ApiError(409, 'CONFLICT', 'They already hold that role there.'),
            default => ApiError::forbidden(),
        };
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
