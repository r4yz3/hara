<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Commands\RoleGrantService;
use App\Application\Commands\StaffInvitations;
use App\Application\Queries\AccessChecker;
use App\Domain\Identity\AccessDenied;
use App\Domain\Identity\InvitationConflict;
use App\Domain\Identity\Scope;
use App\Http\Controllers\Controller;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff invitations for the "Roles and access" screen (backlog U-05).
 *
 * Inviting needs `users.roles.assign` and the entitlement rules in
 * RoleGrantService; the answer never says whether the address already has an
 * account. The list shows pending invitations within the caller's
 * `users.read` reach and never carries token data. Revoking follows the rule
 * for revoking the grant itself.
 */
class StaffInvitationController extends Controller
{
    private const ID = '/\A[0-9a-z]{26}\z/';

    /** Invitation emails per address per day, whether or not it has an account. */
    private const MAILS_PER_ADDRESS = 5;

    public function __construct(
        private readonly AccessChecker $access,
        private readonly RoleGrantService $grants,
        private readonly StaffInvitations $invitations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $reach = $this->access->cityReach($this->user(), 'users.read');
        if (! $reach['all'] && $reach['city_ids'] === []) {
            throw ApiError::forbidden();
        }

        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $after = null;
        if (isset($input['cursor'])) {
            $after = Cursor::decode($input['cursor']) ?? throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }
        $limit = (int) ($input['limit'] ?? 25);

        $query = $this->visible($reach)
            ->leftJoin('cities as c', fn ($join) => $join->on('c.id', '=', 'i.scope_id')->where('i.scope_type', '=', 'city'))
            ->orderBy('i.id');
        if ($after !== null) {
            $query->where('i.id', '>', $after);
        }

        $rows = $query->limit($limit + 1)->get(['i.id', 'i.name', 'i.email', 'i.scope_type', 'i.scope_id', 'i.invited_by', 'i.created_at', 'i.expires_at', 'r.slug', 'c.name as city_name']);
        $page = $rows->take($limit);
        $next = $rows->count() > $limit ? Cursor::encode((string) $page->last()?->id) : null;

        $actor = $this->user();

        $items = $page->map(function (object $row) use ($actor): array {
            $scope = Scope::of($row->scope_type, $row->scope_id);
            // Whoever could invite them anyway sees the address; anyone else a masked one.
            $showEmail = $this->access->allows($actor, 'users.roles.assign', $scope, false);

            return [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $showEmail ? $row->email : $this->mask((string) $row->email),
                'email_masked' => ! $showEmail,
                'role' => $row->slug,
                'role_label' => (string) config("rbac.roles.{$row->slug}.label", $row->slug),
                'scope_type' => $row->scope_type,
                'scope_id' => $row->scope_id,
                'city_name' => $row->city_name,
                'invited_by_me' => $row->invited_by === $actor->getKey(),
                'created_at' => $this->time($row->created_at),
                'expires_at' => $this->time($row->expires_at),
                'can_revoke' => $this->grants->mayRevoke($actor, '', $row->slug, $scope),
            ];
        })->values()->all();

        return ApiResponse::page($request, $items, $next);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->user();

        $reach = $this->access->cityReach($actor, 'users.roles.assign');
        if (! $reach['all'] && $reach['city_ids'] === []) {
            // Logged as rbac.denied by the checker; the caller gets a plain 403.
            $this->access->allows($actor, 'users.roles.assign', Scope::global());

            throw ApiError::forbidden();
        }

        $email = $request->input('email');
        $request->merge(['email' => is_string($email) ? strtolower(trim($email)) : $email]);

        $input = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            // No "unique" rule on purpose: it would reveal that an account exists.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', 'string', 'max:40', 'regex:/\A[a-z_]+\z/', Rule::in((array) config('surfaces.staff_roles'))],
            'scope_type' => ['required', 'string', Rule::in(['global', 'city'])],
            'scope_id' => ['required_if:scope_type,city', 'prohibited_if:scope_type,global', 'nullable', 'string', 'regex:/\A[0-9a-zA-Z]{26}\z/'],
        ]);

        $scope = $input['scope_type'] === 'global'
            ? Scope::global()
            : Scope::city(strtolower((string) $input['scope_id']));

        // Counted for every address, so the limit says nothing about accounts.
        $key = 'staff-invite-mail:'.hash('sha256', (string) $input['email']);
        if (RateLimiter::tooManyAttempts($key, self::MAILS_PER_ADDRESS)) {
            throw ApiError::rateLimited(RateLimiter::availableIn($key));
        }

        try {
            $id = $this->invitations->invite(
                $actor,
                (string) $input['name'],
                (string) $input['email'],
                (string) $input['role'],
                $scope,
                (string) config('surfaces.origins.admin'),
            );
        } catch (AccessDenied $denied) {
            throw $this->refusal($denied);
        } catch (InvitationConflict) {
            throw new ApiError(409, 'CONFLICT', 'An invitation for that person, role and place is already waiting.');
        }

        RateLimiter::hit($key, 86400);

        // The same answer whether or not the address already has an account.
        return ApiResponse::data($request, ['id' => $id, 'status' => 'invitation_sent'], 202);
    }

    public function destroy(string $invitation): Response
    {
        $reach = $this->access->cityReach($this->user(), 'users.read');
        if (! $reach['all'] && $reach['city_ids'] === []) {
            throw ApiError::notFound();
        }

        $id = strtolower($invitation);
        if (preg_match(self::ID, $id) !== 1 || ! $this->visible($reach)->where('i.id', $id)->exists()) {
            throw ApiError::notFound();
        }

        try {
            $this->invitations->revoke($this->user(), $id);
        } catch (AccessDenied $denied) {
            throw $this->refusal($denied);
        }

        return response()->noContent();
    }

    /**
     * Pending invitations the caller may see: global reach sees all, city
     * reach only those anchored to its cities.
     *
     * @param  array{all: bool, city_ids: list<string>}  $reach
     */
    private function visible(array $reach): Builder
    {
        $query = DB::table('staff_invitations as i')
            ->join('roles as r', 'r.id', '=', 'i.role_id')
            ->whereNull('i.accepted_at')->whereNull('i.revoked_at')->where('i.expires_at', '>', now());

        if (! $reach['all']) {
            $query->where('i.scope_type', 'city')->whereIn('i.scope_id', $reach['city_ids']);
        }

        return $query;
    }

    private function refusal(AccessDenied $denied): ApiError|ValidationException
    {
        return match ($denied->reason) {
            AccessDenied::SELF_GRANT => ValidationException::withMessages(['email' => ['You cannot invite yourself.']]),
            AccessDenied::SCOPE_TARGET_MISSING, AccessDenied::GRANT_NOT_FOUND => ApiError::notFound(),
            AccessDenied::UNKNOWN_ROLE => ValidationException::withMessages(['role' => ['That role does not exist.']]),
            default => ApiError::forbidden(),
        };
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function time(mixed $value): string
    {
        return Carbon::parse((string) $value)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
