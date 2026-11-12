# ADR-006: Payments behind an adapter (future)

- Status: accepted as a boundary; no implementation in Phase 1
- Date: 2026-11-12

## Decision
- The Orders domain will depend on a `PaymentGateway` interface owned by the Payments module. Provider names, payloads and status codes do not appear outside `Infrastructure/Payments`.
- No payment processor is selected. PayMongo is named in the master plan as a candidate only.
- Phase 1 registers no payment routes, creates no payment tables and ships no fake provider on any production path. `FEATURE_ONLINE_PAYMENTS` stays `false`.
- Phase 1 delivers only the `Money` value object (integer centavos plus currency code, no floats) because other modules need it.
- Before Phase 3 enables anything: unique constraint on gateway event IDs, signature and replay checks on webhooks, idempotency keys on intents, and a balanced-ledger constraint, each with concurrency tests.

## Consequences
Merchant-of-record, settlement flow and processor choice remain human decisions and do not block Phase 1.
