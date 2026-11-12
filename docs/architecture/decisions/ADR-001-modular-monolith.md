# ADR-001: Modular monolith

- Status: accepted
- Date: 2026-11-12

## Context
Five client surfaces share one set of rules for identity, geography, orders and money. The team is small and the MVP runs in one city. Distributed transactions across services would add failure modes to exactly the parts (money, assignment) that must not fail ambiguously.

## Decision
One deployable Laravel 13 service in `backend/`, split into domain modules under `app/Domain/`: Identity, Geo, Merchants, Catalog, Orders, Dispatch, Payments, Ledger, Support. Phase 1 implements Identity and Geo and creates shells for Merchants. The other directories hold only interfaces or nothing until their phase.

- `app/Domain/<Module>`: entities, value objects, enums, domain services. No HTTP, no framework facades where avoidable.
- `app/Application`: commands, queries and DTOs that orchestrate one use case in one transaction.
- `app/Infrastructure`: persistence, map, payment and event adapters.
- A module calls another module only through an interface published by that module's application layer. No circular dependencies.
- The boundary is enforced by an automated architecture test in `backend/tests`, added in Sprint 2 with the first module.

Workers and the future WebSocket server are separate processes of the same codebase, not separate services.

## Consequences
- One database transaction can cover a privileged change, its audit row and its outbox row.
- Module boundaries depend on discipline plus the architecture test; a failing boundary test is a stop condition.
- Extraction to a service later is possible per module because cross-module calls already go through interfaces and the outbox.
