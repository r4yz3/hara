# ADR-010: API conventions

- Status: accepted
- Date: 2026-11-12

## Decision
- Base path `/api/v1`. Health routes sit outside it (`/health/live` public and minimal; `/health/ready` reachable only from the internal network).
- JSON, `snake_case` keys, ULID strings, ISO 8601 UTC timestamps.
- Success: `{"data": ..., "meta": {"request_id": ...}}`. Errors: `{"error": {"code", "message", "fields"?, "request_id"}}` with codes `VALIDATION_ERROR` 422, `AUTH_REQUIRED` 401, `FORBIDDEN` 403, `NOT_FOUND` 404, `CONFLICT` 409, `RATE_LIMITED` 429, `PROVIDER_UNAVAILABLE` 503. One exception handler produces every error; no SQL, stack trace, key or environment value reaches a response.
- Every response carries a request ID header, also written to logs.
- Lists use an opaque `cursor` and `limit` (maximum 100) with an explicit, stable sort.
- Updates to versioned resources send `version`; a stale value returns 409.
- Mutating business endpoints accept `Idempotency-Key` where a retry could duplicate an effect. It becomes mandatory for ordering and financial endpoints in later phases.
- **One source of truth for the contract:** `backend/openapi/openapi.yaml` (OpenAPI 3.1) is written by hand first. Contract tests validate real responses against it, and CI fails on an unreviewed diff. The TypeScript client is generated from it.
- Future endpoints are documented under a clearly marked planned section and are not registered as routes. They never return 200 with empty mock data.
- Public endpoints are rate limited; serviceability has its own limiter.
- Each throttled route has its own named limiter in `AppServiceProvider` (same limits as before, keyed by limiter name and address; the signed-in invite route by account). An inline `throttle:N,M` is not allowed: for signed-out requests Laravel keys it by domain and IP only, so every such route shared one bucket and csrf or sign-in calls could lock out a valid emailed invitation link with 429. `RateLimitBucketsTest` proves the buckets are independent and fails on any inline numeric throttle.

## Consequences
The contract is reviewed before the code. A route that exists without a spec entry, or the reverse, fails CI.
