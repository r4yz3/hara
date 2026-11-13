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
         *     address within a minute produce 429. Until two-step verification is
         *     available, the admin surface refuses sign-in outside local and testing.
         */
        post: operations["authLogin"];
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
