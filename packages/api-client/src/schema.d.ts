// GENERATED from backend/openapi/openapi.yaml. Do not edit.
// Regenerate: pnpm --filter @food-delivery/api-client generate

export interface paths {
    "/health/live": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Liveness
         * @description Touches no dependency. Public.
         */
        get: operations["healthLive"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/health/ready": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Readiness
         * @description Reports dependency state. Answers only to loopback and private network addresses; anyone else receives 404.
         */
        get: operations["healthReady"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/csrf": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Get the CSRF token for this app's session */
        get: operations["authCsrf"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/register": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Register a customer
         * @description Available only from the customer app and only when public
         *     registration is switched on; otherwise 404. Always creates a
         *     customer: any role, scope or status in the request is ignored. The
         *     answer is the same whether or not the address was already registered.
         */
        post: operations["authRegister"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/login": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Sign in
         * @description Starts a session on the calling app. A wrong password, an unknown
         *     account, a suspended account and an account not entitled to this app
         *     all produce the same 401. Five failures for one account from one
         *     address within a minute produce 429. On the admin app, staff with a
         *     confirmed second factor are not signed in by a correct password: the
         *     answer is `{"data":{"mfa_required":true}}` and `POST /auth/mfa/verify`
         *     finishes sign-in. In deployed environments, staff without a second
         *     factor get an enrolment-only session (see `POST /me/mfa/totp`).
         */
        post: operations["authLogin"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/mfa/verify": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Finish staff sign-in with the second step
         * @description Admin app only. Follows a login that answered `mfa_required`. Send a
         *     six-digit `code` from the authenticator app or one `recovery_code`.
         *     A code works once; so does a recovery code. A wrong code, an expired
         *     pending sign-in (5 minutes) and a missing one all produce the same 401.
         *     Five failures for one account from one address within a minute produce
         *     429. On success it answers like login.
         */
        post: operations["authMfaVerify"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/me/mfa/totp": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Start two-step sign-in setup
         * @description Admin app only. Creates an unconfirmed authenticator secret and returns
         *     it once, with an otpauth URI for a QR code. Calling again before
         *     confirming replaces the secret. Once confirmed, 409. In deployed
         *     environments a staff session without a confirmed second factor may call
         *     only this, the confirm route and logout; everything else answers 403.
         */
        post: operations["meMfaTotpStart"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/me/mfa/totp/confirm": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Confirm two-step sign-in setup
         * @description Admin app only. Send a current code from the authenticator app. Returns
         *     ten single-use recovery codes, once; they are stored only as hashes.
         *     In deployed environments the session ends afterwards and the person
         *     signs in again with the second step. Five failures in a minute produce 429.
         */
        post: operations["meMfaTotpConfirm"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/me/mfa/recovery-codes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Replace the recovery codes
         * @description Admin app only, for staff with a confirmed second factor. Send a
         *     current 6-digit code from the authenticator app (one that has not
         *     been used yet). Returns ten new single-use recovery codes, once, and
         *     invalidates every old one. A wrong code is 422, like confirming
         *     setup; five failures in a minute produce 429. 409 when no second
         *     factor is set up. The audit row `auth.mfa_recovery_codes_regenerated`
         *     holds no code.
         */
        post: operations["meMfaRecoveryCodesRegenerate"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/password/forgot": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Ask for a password reset link
         * @description Always answers 202 with the same body, whether or not the address
         *     belongs to an account. If it does, and the account is active, an
         *     email is sent with a link that works once and expires after 60
         *     minutes; a newer request cancels an older link. At most three emails
         *     are sent per address in 15 minutes.
         */
        post: operations["authPasswordForgot"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/password/reset": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Set a new password with a reset link's token
         * @description An unknown, used or expired token gives one 422 with the same
         *     message. On success the token is consumed. Existing browser sessions
         *     are rejected on their next protected request. The user must sign in
         *     with the new password.
         */
        post: operations["authPasswordReset"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/invitations/inspect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Look at a staff invitation before accepting it
         * @description Public; admin app only. The emailed token goes in the body, never in
         *     a URL (the link carries it in the fragment). Shows only the invitee's
         *     name, the role label, a place label and the expiry. An unknown, used,
         *     revoked or expired token all give the same 404. Looking does not use
         *     the invitation up.
         */
        post: operations["authInvitationsInspect"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/invitations/accept": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Accept a staff invitation
         * @description Public; admin app only. In one transaction: the account is created
         *     (or, when the address already has one, the role is attached to it),
         *     the role is granted through the same audited path as `POST
         *     /admin/users/{user}/roles` with the inviter as grantor, and the
         *     invitation is marked used. The password is the new account's
         *     password (at least 10 characters). When the address already has an
         *     account it must be that account's current password, which is not
         *     changed; this is never revealed in any response. Anything that fails
         *     (unknown, used, revoked or expired token, wrong password for an
         *     existing account, an inviter who no longer has the right, an inactive
         *     account, a role already held) gives the same 404. Five failures per
         *     token in 15 minutes give 429. Nobody is signed in: sign in as usual;
         *     in deployed environments the admin console then needs two-step
         *     sign-in set up first (ADR-011).
         */
        post: operations["authInvitationsAccept"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/mobile/token": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Sign in from the rider app
         * @description Issues a bearer token for an approved rider on a named device. The
         *     token carries only the `rider:profile` ability and expires after 60
         *     minutes. A wrong password, an unknown address, a customer, a rider who
         *     is not approved and a suspended account all get the same 401. Five
         *     failures for one account from one address within a minute give 429.
         *     Send no Origin header; this endpoint is not for browsers.
         */
        post: operations["authMobileToken"];
        /**
         * Sign out of the rider app
         * @description Deletes the token and marks its device revoked.
         */
        delete: operations["authMobileRevoke"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/mobile/token/refresh": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Swap a valid token for a new one
         * @description The old token is deleted as the new one is issued, on the same device.
         *     An expired token cannot be refreshed; the rider signs in again.
         *     One sign-in lasts at most 30 days from when the device signed in:
         *     refreshing never goes past that, the last token's `expires_at` stops
         *     at it, and after it refresh answers 401 and the device is revoked.
         */
        post: operations["authMobileRefresh"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/rider/me": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The signed-in rider's own profile
         * @description Refused if the rider is no longer approved, the account is suspended or the device was revoked.
         */
        get: operations["riderMe"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/auth/logout": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Sign out */
        post: operations["authLogout"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/me": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * The signed-in user
         * @description Lists live role grants only. Contains no secret fields.
         */
        get: operations["meShow"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/public/config": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Deployment settings any client may read
         * @description No sign-in needed and nothing secret. `service_open` is true only when
         *     an active zone exists in an active city. `map_style_url` is null until
         *     a licensed map style is configured. Every feature flag is off by
         *     default; in Phase 1 the later-phase flags change nothing when on.
         */
        get: operations["publicConfig"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/public/cities": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Cities where the service is open
         * @description Active cities only. Empty until a city has been activated.
         */
        get: operations["publicCities"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/locations/serviceability": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Can this point be delivered to?
         * @description Polygon containment only: it does not check that a route exists. A
         *     point on a zone boundary is inside. `NO_ACTIVE_ZONE` means nothing is
         *     open anywhere; `OUTSIDE_COVERAGE` means zones exist but none covers
         *     the point. Limited to 60 requests a minute per address.
         *
         *     This is a POST although it changes nothing: the position is personal
         *     data, and a query string is written to access logs by servers and
         *     proxies. A position sent in the URL is refused with 422, and the
         *     position is neither stored nor logged by the API (ADR-013). Browser
         *     clients send the usual `X-CSRF-TOKEN` header.
         */
        post: operations["serviceability"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/cities": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List cities the caller may read
         * @description Admin app only. Filtered to the caller's scope; needs `cities.read`.
         */
        get: operations["adminCitiesIndex"];
        put?: never;
        /**
         * Create a city
         * @description Needs `cities.write` with global scope. The city starts as a draft; `status` and `id` in the request are ignored.
         */
        post: operations["adminCitiesStore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/cities/{city}": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description City id. Letter case is ignored. */
                city: string;
            };
            cookie?: never;
        };
        /**
         * One city
         * @description 404 if it does not exist or the caller may not read it.
         */
        get: operations["adminCitiesShow"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Edit a city or move its status
         * @description Needs `cities.write`. Only `name`, `timezone` and `status` can change.
         *     Status moves along: draft to active or archived; active to paused;
         *     paused to active or archived. Anything else, or any change to an
         *     archived city, is 409.
         */
        patch: operations["adminCitiesUpdate"];
        trace?: never;
    };
    "/api/v1/admin/cities/{city}/zones": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description City id. Letter case is ignored. */
                city: string;
            };
            cookie?: never;
        };
        /**
         * List a city's delivery zones
         * @description Needs `zones.read` for the city; 404 otherwise. Geometry is left out of lists.
         */
        get: operations["adminZonesIndex"];
        put?: never;
        /**
         * Create a delivery zone
         * @description Needs `zones.write` for the city. The zone starts as a draft at
         *     version 1; `status`, `version` and `id` in the request are ignored.
         *     Geometry is checked for structure, the vertex and extent caps, and
         *     then by PostGIS for self-intersection. 409 if the city is archived.
         */
        post: operations["adminZonesStore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/zones/{zone}": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        /**
         * One delivery zone, with its geometry
         * @description 404 if it does not exist or the caller may not read its city.
         */
        get: operations["adminZonesShow"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Edit a delivery zone
         * @description Needs `zones.write` for the zone's city. Send the `version` you
         *     loaded; if the zone has changed since, the edit is refused with 409
         *     and nothing is written. Only `name`, `priority` and `geometry` can
         *     change; each successful edit adds 1 to `version`. Status has its own
         *     routes. Any change to an archived zone is 409.
         */
        patch: operations["adminZonesUpdate"];
        trace?: never;
    };
    "/api/v1/admin/zones/{zone}/activate": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Put a delivery zone live
         * @description Needs `zones.write` for the zone's city. Allowed from draft or paused,
         *     only while the city is active, and only with `confirm: true`. 409 for
         *     any other status, an inactive city or a stale `version`.
         */
        post: operations["adminZonesActivate"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/zones/{zone}/pause": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Pause an active delivery zone
         * @description Needs `zones.write` for the zone's city. Allowed from active only; a
         *     reason is required. 409 for any other status or a stale `version`.
         */
        post: operations["adminZonesPause"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/zones/{zone}/archive": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Archive a delivery zone
         * @description Needs `zones.write` for the zone's city. Allowed from draft or paused;
         *     an active zone must be paused first. Archiving is final. 409 for any
         *     other status or a stale `version`.
         */
        post: operations["adminZonesArchive"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/zones/{zone}/exclusions": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        /**
         * List a zone's coverage exclusions
         * @description Needs `zones.read` for the zone's city; 404 otherwise. Includes exclusions whose window has ended or not started.
         */
        get: operations["adminExclusionsIndex"];
        put?: never;
        /**
         * Exclude an area from a zone
         * @description Needs `zones.write` for the zone's city. Points inside the shape,
         *     its boundary included, stop being serviceable while the exclusion is
         *     in force: from `active_from` (or at once) until `active_until` (or
         *     until it is deleted). The shape gets the same checks as a zone and
         *     must overlap the zone. Exclusions are not edited: delete and add.
         *     409 if the zone is archived.
         */
        post: operations["adminExclusionsStore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/exclusions/{exclusion}": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Exclusion id. Letter case is ignored. */
                exclusion: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Remove a coverage exclusion
         * @description Needs `zones.write` for the city of the exclusion's zone. The removal is audited with the reason and window.
         */
        delete: operations["adminExclusionsDestroy"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/users/{user}/mfa/reset": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Id of a staff member. Letter case is ignored. */
                user: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Reset a staff member's second factor
         * @description For a staff member who lost their authenticator and recovery codes.
         *     Needs `users.mfa.reset` (platform owner only) with global scope. 403
         *     when the target is the caller (use a recovery code instead) and when
         *     the target is a platform owner and the caller is not one. 404 for an
         *     unknown id or a user without a live staff grant. Deletes the factor
         *     and every recovery code in one transaction with the audit row
         *     `auth.mfa_reset` (reason and target id, never a code), and ends the
         *     person's stored sessions; any other session ends on its next
         *     request. They sign in with the password and must set up two-step
         *     sign-in again (deployed environments), or sign in with the password
         *     alone (local and testing).
         */
        post: operations["adminUsersMfaReset"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/users/{user}/suspend": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Id of a staff member. Letter case is ignored. */
                user: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Suspend a staff account
         * @description Product owner decision 2026-11-14: only global administrators. Needs
         *     `users.suspend` through a GLOBAL grant (platform owner, platform
         *     admin); a city-scoped holder gets 403 whatever the target. 403 when
         *     the target is the caller, and when the target is a platform owner.
         *     404 for an unknown id, a user without a live staff grant, or when the
         *     caller cannot see them. 409 when the account is already suspended.
         *     Sets `users.status` to `suspended`, deletes the person's stored web
         *     sessions and rider bearer tokens, and writes the audit row
         *     `user.suspended` (reason included) in one transaction. Any other
         *     session ends on its next request. Pending invitations they sent can
         *     no longer be accepted.
         */
        post: operations["adminUsersSuspend"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/users/{user}/reactivate": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Id of a staff member. Letter case is ignored. */
                user: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Reactivate a suspended staff account
         * @description Same permission and visibility rules as suspend. 409 when the account
         *     is not suspended. Sets `users.status` back to `active` and writes the
         *     audit row `user.reactivated`. It does not restore sessions or rider
         *     tokens: the person signs in again.
         */
        post: operations["adminUsersReactivate"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/users": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Staff members and their grants
         * @description Needs `users.read`. Lists people who hold a live staff role, by id,
         *     with the grants the caller may see: global reach sees every grant,
         *     city reach sees only grants anchored to those cities (so global
         *     grants and other cities are absent, not hidden). Support sees the
         *     address masked (`email_masked`). Accounts without a staff role are
         *     never listed. `can_revoke` says whether a revoke would be allowed; it
         *     is advice for the screen and the revoke request is checked in full.
         */
        get: operations["adminUsersIndex"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/roles": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Roles the caller may hand out, and where
         * @description Needs `users.read`. Lists only roles one of the caller's own grants
         *     is entitled to assign, with the scopes that grant covers: `global`
         *     (a deployment-wide grant), `all_cities`, or specific `city_ids`. The
         *     list is empty for a caller who may assign nothing. `platform_owner`
         *     and onboarding roles never appear. This only fills a form; the grant
         *     request is checked in full.
         */
        get: operations["adminRolesIndex"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/users/{user}/roles": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Id of a staff member the caller can see. Letter case is ignored. */
                user: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Grant a role to a staff member
         * @description Needs `users.roles.assign`, and the caller must hold a role entitled
         *     to hand out the requested one over the same scope (ADR-005), so a
         *     city manager can grant only the dispatcher role in their own city.
         *     404 when the person is not a staff member within the caller's view
         *     (including a caller without `users.read`). 403 for a role or scope
         *     the caller may not hand out, for `platform_owner` and onboarding
         *     roles whoever asks, and for the caller's own account. 409 when the
         *     person already holds that role there or the account is not active.
         *     404 for a city that does not exist. Written with the audit row
         *     `role.granted` in one transaction. To bring in a
         *     person with no staff role yet, use `POST /admin/invitations`.
         */
        post: operations["adminUsersRolesStore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/users/{user}/roles/{grant}": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                user: string;
                /** @description Id of one of the person's grants, from `GET /admin/users`. */
                grant: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Revoke a role from a staff member
         * @description Whoever could not have granted it cannot revoke it: needs
         *     `users.roles.assign` and an entitled role over the same scope. 404
         *     when the person or the grant is not within the caller's view, or the
         *     grant is not that person's. 403 for `platform_owner` (never
         *     revocable here) and for the caller's own grants. A person who loses
         *     their last staff role loses admin access on their next request.
         *     Written with the audit row `role.revoked` in one transaction.
         */
        delete: operations["adminUsersRolesDestroy"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/invitations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Pending staff invitations
         * @description Needs `users.read`. Lists invitations that are neither accepted,
         *     revoked nor expired, within the caller's reach (global reach sees all,
         *     city reach only those anchored to its cities). Never carries token
         *     data. The address is masked unless the caller could grant that role
         *     there. Oldest id first.
         */
        get: operations["adminInvitationsIndex"];
        put?: never;
        /**
         * Invite a person to the admin team
         * @description Needs `users.roles.assign`, and the caller must be allowed to grant
         *     that role at that scope under the same rules as `POST
         *     /admin/users/{user}/roles` (ADR-005); staff roles only. 403 when not
         *     allowed (including `platform_owner`), 404 for a city that does not
         *     exist, 422 for an invalid field or an invitation to the caller's own
         *     address, 409 when a live invitation for that address, role and scope
         *     already exists, 429 after five invitation emails to one address in a
         *     day or twenty requests a minute. Always answers 202 with the same
         *     body, whether or not the address already has an account; no lookup
         *     is made. An email with a single-use link (token in the URL fragment)
         *     valid for 72 hours is sent to the address. Nothing is granted until
         *     the person accepts. Audit `staff_invitation.created`, without the
         *     address.
         */
        post: operations["adminInvitationsStore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/invitations/{invitation}": {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Id of a pending invitation from `GET /admin/invitations`. Letter case is ignored. */
                invitation: string;
            };
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Withdraw a pending invitation
         * @description Whoever could not have granted the role cannot withdraw the
         *     invitation: needs `users.read` to see it (404 otherwise, as for any
         *     invitation outside the caller's reach, or already accepted, revoked
         *     or expired) and then `users.roles.assign` with an entitled role over
         *     the same scope (403). Written with the audit row
         *     `staff_invitation.revoked`. The link stops working at once.
         */
        delete: operations["adminInvitationsDestroy"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/audit-logs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Audit trail, newest first
         * @description Needs `audit.read`. Staff holding it everywhere see every entry;
         *     staff holding it for certain cities see only entries tied to those
         *     cities. Sensitive values in `before` and `after` are redacted, and
         *     the address hash is never returned.
         */
        get: operations["adminAuditLogsIndex"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/v1/admin/system/status": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Deployment status summary
         * @description Needs `system.status.read` with global scope. Dependency checks are
         *     up or down only. No secret, host name or connection detail is
         *     returned.
         */
        get: operations["adminSystemStatus"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        RiderToken: {
            /** @description Shown once. Store it in the device keystore. */
            token: string;
            /** @constant */
            token_type: "Bearer";
            abilities: string[];
            /** Format: date-time */
            expires_at: string;
        };
        RiderTokenResponse: {
            data: components["schemas"]["RiderToken"];
            meta: components["schemas"]["Meta"];
        };
        RiderProfile: {
            id: string;
            user_id: string;
            name: string;
            email: string;
            /** @enum {string} */
            status: "approved";
            /** Format: date-time */
            approved_at: string | null;
        };
        PageMeta: {
            request_id: string;
            next_cursor: string | null;
        };
        /** @enum {string} */
        CityStatus: "draft" | "active" | "paused" | "archived";
        City: {
            id: string;
            slug: string;
            name: string;
            country_code: string;
            currency_code: string;
            timezone: string;
            status: components["schemas"]["CityStatus"];
            /** Format: date-time */
            created_at: string;
            /** Format: date-time */
            updated_at: string;
        };
        CityResponse: {
            data: components["schemas"]["City"];
            meta: components["schemas"]["Meta"];
        };
        /** @description GeoJSON Polygon or MultiPolygon, positions as [longitude, latitude]. Caps from config/geo.php. */
        ZoneGeometryInput: {
            /** @enum {string} */
            type: "Polygon" | "MultiPolygon";
            coordinates: unknown[];
        };
        Zone: {
            id: string;
            city_id: string;
            name: string;
            slug: string;
            status: components["schemas"]["CityStatus"];
            priority: number;
            version: number;
            /** @description GeoJSON MultiPolygon. Present on single-zone responses only. */
            geometry?: {
                /** @enum {string} */
                type: "MultiPolygon";
                coordinates: unknown[];
            };
            /** Format: date-time */
            created_at: string;
            /** Format: date-time */
            updated_at: string;
        };
        ZoneResponse: {
            data: components["schemas"]["Zone"];
            meta: components["schemas"]["Meta"];
        };
        Exclusion: {
            id: string;
            zone_id: string;
            reason: string;
            /** Format: date-time */
            active_from: string | null;
            /** Format: date-time */
            active_until: string | null;
            /** @description GeoJSON MultiPolygon. */
            geometry: {
                /** @enum {string} */
                type: "MultiPolygon";
                coordinates: unknown[];
            };
            /** Format: date-time */
            created_at: string;
            /** Format: date-time */
            updated_at: string;
        };
        StaffGrant: {
            id: string;
            role: string;
            role_label: string;
            /** @enum {string} */
            scope_type: "global" | "city";
            scope_id: string | null;
            /** @description Name of the city for a city grant. */
            city_name: string | null;
            /** Format: date-time */
            granted_at: string;
            can_revoke: boolean;
        };
        StaffMember: {
            id: string;
            name: string;
            /** @description Masked (first letter, then asterisks) when `email_masked` is true. */
            email: string;
            email_masked: boolean;
            /** @enum {string} */
            status: "active" | "suspended";
            mfa_enrolled: boolean;
            is_self: boolean;
            /** @description True when the caller may suspend this active account (global `users.suspend`, not themselves, not a platform owner). */
            can_suspend: boolean;
            /** @description True when the caller may reactivate this suspended account. */
            can_reactivate: boolean;
            grants: components["schemas"]["StaffGrant"][];
        };
        StaffInvitation: {
            id: string;
            name: string;
            /** @description Masked (first letter, then asterisks) when `email_masked` is true. */
            email: string;
            email_masked: boolean;
            role: string;
            role_label: string;
            /** @enum {string} */
            scope_type: "global" | "city";
            scope_id: string | null;
            city_name: string | null;
            invited_by_me: boolean;
            /** Format: date-time */
            created_at: string;
            /** Format: date-time */
            expires_at: string;
            can_revoke: boolean;
        };
        InvitationPreview: {
            name: string;
            role_label: string;
            /** @description The city's name, or `All cities` for a deployment-wide role. */
            scope_label: string;
            /** Format: date-time */
            expires_at: string;
        };
        AssignableRole: {
            slug: string;
            label: string;
            scope_types: ("global" | "city")[];
            /** @description The caller may grant it deployment-wide. */
            global: boolean;
            /** @description The caller may grant it in any city. */
            all_cities: boolean;
            /** @description Cities where the caller may grant it, when not all. */
            city_ids: string[];
        };
        AuditEntry: {
            id: string;
            /** @description Null for actions taken by the system. */
            actor_user_id: string | null;
            action: string;
            subject_type: string;
            subject_id: string | null;
            city_id: string | null;
            before: Record<string, never> | null;
            after: Record<string, never> | null;
            correlation_id: string | null;
            /** Format: date-time */
            created_at: string;
        };
        PublicCity: {
            id: string;
            name: string;
            country_code: string;
            currency_code: string;
            timezone: string;
        };
        NamedRef: {
            id: string;
            name: string;
        };
        Serviceability: {
            serviceable: boolean;
            /** @enum {string|null} */
            reason: "NO_ACTIVE_ZONE" | "OUTSIDE_COVERAGE" | null;
            city: components["schemas"]["NamedRef"] | null;
            zone: components["schemas"]["NamedRef"] | null;
        };
        Meta: {
            request_id: string;
        };
        Readiness: {
            /** @enum {string} */
            status: "ready" | "unavailable";
            checks: {
                database: boolean;
                redis: boolean;
            };
        };
        ErrorResponse: {
            error: {
                /** @enum {string} */
                code: "VALIDATION_ERROR" | "AUTH_REQUIRED" | "FORBIDDEN" | "NOT_FOUND" | "METHOD_NOT_ALLOWED" | "CONFLICT" | "RATE_LIMITED" | "PROVIDER_UNAVAILABLE" | "BAD_REQUEST" | "SERVER_ERROR";
                message: string;
                fields?: {
                    [key: string]: string[];
                };
                request_id: string;
            };
        };
        CurrentUser: {
            id: string;
            name: string;
            email: string;
            email_verified: boolean;
            mfa_enabled: boolean;
            /** @enum {string} */
            surface: "customer" | "merchant" | "admin";
            grants: {
                role: string;
                /** @enum {string} */
                scope_type: "global" | "city" | "merchant" | "branch";
                scope_id: string | null;
                permissions: string[];
            }[];
        };
        MfaRequiredResponse: {
            data: {
                /** @constant */
                mfa_required: true;
            };
            meta: components["schemas"]["Meta"];
        };
        CurrentUserResponse: {
            data: components["schemas"]["CurrentUser"];
            meta: components["schemas"]["Meta"];
        };
    };
    responses: {
        /** @description Error envelope. */
        Error: {
            headers: {
                "X-Request-Id": components["headers"]["RequestId"];
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["ErrorResponse"];
            };
        };
    };
    parameters: {
        CsrfToken: string;
        Limit: number;
        /** @description Opaque value from `meta.next_cursor` of the previous page. */
        Cursor: string;
    };
    requestBodies: never;
    headers: {
        /** @description Identifier of this request, also present in the body. */
        RequestId: string;
    };
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    healthLive: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The process is serving requests. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @constant */
                        status: "ok";
                    };
                };
            };
        };
    };
    healthReady: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Every dependency is reachable. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Readiness"];
                };
            };
            /** @description Caller is not on an internal network. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description At least one dependency is unreachable. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Readiness"];
                };
            };
        };
    };
    authCsrf: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Token bound to the caller's session. */
            200: {
                headers: {
                    "X-Request-Id": components["headers"]["RequestId"];
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            csrf_token: string;
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            403: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    authRegister: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name: string;
                    /** Format: email */
                    email: string;
                    /** Format: password */
                    password: string;
                };
            };
        };
        responses: {
            /** @description Registration received. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            /** @constant */
                            status: "registration_received";
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    authLogin: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    email: string;
                    /** Format: password */
                    password: string;
                };
            };
        };
        responses: {
            /** @description Signed in, or a second step is required. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CurrentUserResponse"] | components["schemas"]["MfaRequiredResponse"];
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    authMfaVerify: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    code?: string;
                    recovery_code?: string;
                };
            };
        };
        responses: {
            /** @description Signed in. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CurrentUserResponse"];
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    meMfaTotpStart: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Setup started. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            secret: string;
                            otpauth_uri: string;
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
        };
    };
    meMfaTotpConfirm: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    code: string;
                };
            };
        };
        responses: {
            /** @description Confirmed. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            recovery_codes: string[];
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    meMfaRecoveryCodesRegenerate: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    code: string;
                };
            };
        };
        responses: {
            /** @description New codes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            recovery_codes: string[];
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    authPasswordForgot: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    email: string;
                };
            };
        };
        responses: {
            /** @description Request received. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            /** @constant */
                            status: "reset_requested";
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            403: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    authPasswordReset: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    token: string;
                    /** Format: password */
                    password: string;
                };
            };
        };
        responses: {
            /** @description Password changed. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            /** @constant */
                            status: "password_reset";
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            403: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    authInvitationsInspect: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    token: string;
                };
            };
        };
        responses: {
            /** @description A live invitation. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["InvitationPreview"];
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    authInvitationsAccept: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    token: string;
                    /** Format: password */
                    password: string;
                };
            };
        };
        responses: {
            /** @description Accepted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            /** @constant */
                            status: "invitation_accepted";
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    authMobileToken: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    email: string;
                    /** Format: password */
                    password: string;
                    device_name: string;
                    /** @enum {string} */
                    platform: "android";
                };
            };
        };
        responses: {
            /** @description Token issued. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["RiderTokenResponse"];
                };
            };
            401: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    authMobileRevoke: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Signed out. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Error"];
        };
    };
    authMobileRefresh: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description New token. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["RiderTokenResponse"];
                };
            };
            401: components["responses"]["Error"];
        };
    };
    riderMe: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Rider profile. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["RiderProfile"];
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            401: components["responses"]["Error"];
        };
    };
    authLogout: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Session ended. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
        };
    };
    meShow: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Current user. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CurrentUserResponse"];
                };
            };
            401: components["responses"]["Error"];
        };
    };
    publicConfig: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The settings. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            /** @description ISO 4217 code. One per deployment. */
                            currency: string;
                            /** @description For screens with no city. Each city has its own. */
                            display_timezone: string;
                            service_open: boolean;
                            /** Format: uri */
                            map_style_url: string | null;
                            features: {
                                public_registration: boolean;
                                live_dispatch: boolean;
                                online_payments: boolean;
                                rider_tracking: boolean;
                            };
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            429: components["responses"]["Error"];
        };
    };
    publicCities: {
        parameters: {
            query?: {
                limit?: components["parameters"]["Limit"];
                /** @description Opaque value from `meta.next_cursor` of the previous page. */
                cursor?: components["parameters"]["Cursor"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page of active cities, sorted by id. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["PublicCity"][];
                        meta: components["schemas"]["PageMeta"];
                    };
                };
            };
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    serviceability: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @description Decimal degrees, -90 to 90. */
                    latitude: string | number;
                    /** @description Decimal degrees, -180 to 180. */
                    longitude: string | number;
                };
            };
        };
        responses: {
            /** @description The decision. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Serviceability"];
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            403: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    adminCitiesIndex: {
        parameters: {
            query?: {
                limit?: components["parameters"]["Limit"];
                /** @description Opaque value from `meta.next_cursor` of the previous page. */
                cursor?: components["parameters"]["Cursor"];
                status?: components["schemas"]["CityStatus"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page of cities, sorted by id. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["City"][];
                        meta: components["schemas"]["PageMeta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminCitiesStore: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name: string;
                    slug: string;
                    country_code: string;
                    /** @description Must equal the deployment currency. */
                    currency_code: string;
                    /** @description IANA name. Required; never defaulted. */
                    timezone: string;
                };
            };
        };
        responses: {
            /** @description Created. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CityResponse"];
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminCitiesShow: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description City id. Letter case is ignored. */
                city: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The city. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CityResponse"];
                };
            };
            401: components["responses"]["Error"];
            404: components["responses"]["Error"];
        };
    };
    adminCitiesUpdate: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description City id. Letter case is ignored. */
                city: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name?: string;
                    timezone?: string;
                    status?: components["schemas"]["CityStatus"];
                };
            };
        };
        responses: {
            /** @description Updated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CityResponse"];
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminZonesIndex: {
        parameters: {
            query?: {
                limit?: components["parameters"]["Limit"];
                /** @description Opaque value from `meta.next_cursor` of the previous page. */
                cursor?: components["parameters"]["Cursor"];
            };
            header?: never;
            path: {
                /** @description City id. Letter case is ignored. */
                city: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page of zones, sorted by id. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Zone"][];
                        meta: components["schemas"]["PageMeta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminZonesStore: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description City id. Letter case is ignored. */
                city: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name: string;
                    /** @description Unique within the city. */
                    slug: string;
                    /** @default 0 */
                    priority?: number;
                    geometry: components["schemas"]["ZoneGeometryInput"];
                };
            };
        };
        responses: {
            /** @description Created. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ZoneResponse"];
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminZonesShow: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The zone. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ZoneResponse"];
                };
            };
            401: components["responses"]["Error"];
            404: components["responses"]["Error"];
        };
    };
    adminZonesUpdate: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    version: number;
                    name?: string;
                    priority?: number;
                    geometry?: components["schemas"]["ZoneGeometryInput"];
                };
            };
        };
        responses: {
            /** @description Updated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ZoneResponse"];
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminZonesActivate: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    version: number;
                    /** @enum {boolean} */
                    confirm: true;
                };
            };
        };
        responses: {
            /** @description The zone in its new status; `version` has gone up by 1. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ZoneResponse"];
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminZonesPause: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    version: number;
                    /** @description Kept in the audit log. */
                    reason: string;
                };
            };
        };
        responses: {
            /** @description The zone in its new status; `version` has gone up by 1. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ZoneResponse"];
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminZonesArchive: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    version: number;
                    /** @description Kept in the audit log. */
                    reason?: string;
                };
            };
        };
        responses: {
            /** @description The zone in its new status; `version` has gone up by 1. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ZoneResponse"];
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminExclusionsIndex: {
        parameters: {
            query?: {
                limit?: components["parameters"]["Limit"];
                /** @description Opaque value from `meta.next_cursor` of the previous page. */
                cursor?: components["parameters"]["Cursor"];
            };
            header?: never;
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page of exclusions, sorted by id. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Exclusion"][];
                        meta: components["schemas"]["PageMeta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminExclusionsStore: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Zone id. Letter case is ignored. */
                zone: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    reason: string;
                    geometry: components["schemas"]["ZoneGeometryInput"];
                    /**
                     * Format: date-time
                     * @description With an offset or Z. Stored in UTC.
                     */
                    active_from?: string | null;
                    /**
                     * Format: date-time
                     * @description Must be after `active_from`.
                     */
                    active_until?: string | null;
                };
            };
        };
        responses: {
            /** @description Created. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Exclusion"];
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminExclusionsDestroy: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Exclusion id. Letter case is ignored. */
                exclusion: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Removed. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
        };
    };
    adminUsersMfaReset: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Id of a staff member. Letter case is ignored. */
                user: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @description Why the reset was approved, for the audit trail. Never put a secret here. */
                    reason: string;
                };
            };
        };
        responses: {
            /** @description Reset. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminUsersSuspend: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Id of a staff member. Letter case is ignored. */
                user: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @description Why the account is suspended, for the audit trail. Never put a secret here. */
                    reason: string;
                };
            };
        };
        responses: {
            /** @description Suspended. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminUsersReactivate: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Id of a staff member. Letter case is ignored. */
                user: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                "application/json": {
                    /** @description Optional note for the audit trail. Never put a secret here. */
                    reason?: string;
                };
            };
        };
        responses: {
            /** @description Reactivated. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminUsersIndex: {
        parameters: {
            query?: {
                limit?: components["parameters"]["Limit"];
                /** @description Opaque value from `meta.next_cursor` of the previous page. */
                cursor?: components["parameters"]["Cursor"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page of staff members, by id. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["StaffMember"][];
                        meta: components["schemas"]["PageMeta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminRolesIndex: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Assignable roles. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["AssignableRole"][];
                        meta: components["schemas"]["PageMeta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
        };
    };
    adminUsersRolesStore: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Id of a staff member the caller can see. Letter case is ignored. */
                user: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @description A role slug from `GET /admin/roles`. */
                    role: string;
                    /** @enum {string} */
                    scope_type: "global" | "city";
                    /** @description Required for `city`, forbidden for `global`. */
                    scope_id?: string | null;
                };
            };
        };
        responses: {
            /** @description Granted. Returns the person as the caller now sees them. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["StaffMember"];
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminUsersRolesDestroy: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                user: string;
                /** @description Id of one of the person's grants, from `GET /admin/users`. */
                grant: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Revoked. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
        };
    };
    adminInvitationsIndex: {
        parameters: {
            query?: {
                limit?: components["parameters"]["Limit"];
                /** @description Opaque value from `meta.next_cursor` of the previous page. */
                cursor?: components["parameters"]["Cursor"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of pending invitations. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["StaffInvitation"][];
                        meta: components["schemas"]["PageMeta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminInvitationsStore: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    name: string;
                    email: string;
                    /** @description A staff role slug from `GET /admin/roles`. */
                    role: string;
                    /** @enum {string} */
                    scope_type: "global" | "city";
                    /** @description Required for `city`, forbidden for `global`. */
                    scope_id?: string | null;
                };
            };
        };
        responses: {
            /** @description Invitation recorded and emailed. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            id: string;
                            /** @constant */
                            status: "invitation_sent";
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            409: components["responses"]["Error"];
            422: components["responses"]["Error"];
            429: components["responses"]["Error"];
        };
    };
    adminInvitationsDestroy: {
        parameters: {
            query?: never;
            header: {
                "X-CSRF-TOKEN": components["parameters"]["CsrfToken"];
            };
            path: {
                /** @description Id of a pending invitation from `GET /admin/invitations`. Letter case is ignored. */
                invitation: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Withdrawn. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
        };
    };
    adminAuditLogsIndex: {
        parameters: {
            query?: {
                limit?: components["parameters"]["Limit"];
                /** @description Opaque value from `meta.next_cursor` of the previous page. */
                cursor?: components["parameters"]["Cursor"];
                /** @description Exact match. */
                action?: string;
                subject_type?: string;
                subject_id?: string;
                actor_user_id?: string;
                city_id?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description One page of entries. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["AuditEntry"][];
                        meta: components["schemas"]["PageMeta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
            422: components["responses"]["Error"];
        };
    };
    adminSystemStatus: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The summary. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            environment: string;
                            /** @description Must be false in production. */
                            debug: boolean;
                            checks: {
                                database: boolean;
                                redis: boolean;
                            };
                            postgis: boolean;
                            cities: {
                                draft: number;
                                active: number;
                                paused: number;
                                archived: number;
                            };
                            zones: {
                                draft: number;
                                active: number;
                                paused: number;
                                archived: number;
                            };
                            features: {
                                [key: string]: boolean;
                            };
                            map_style_configured: boolean;
                            geometry_caps_approved: boolean;
                        };
                        meta: components["schemas"]["Meta"];
                    };
                };
            };
            401: components["responses"]["Error"];
            403: components["responses"]["Error"];
            404: components["responses"]["Error"];
        };
    };
}
