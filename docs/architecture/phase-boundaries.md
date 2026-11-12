# Phase boundaries

Date: 2026-11-12. What Phase 1 delivers as working software, what it delivers as a labelled shell, and what it must not expose. Source: spec sections 1.1, 1.2, 7.6, 8.

## Working in Phase 1

| Area | Working behaviour |
|---|---|
| Identity | Register (flag-gated, customer only), login, logout, current user, safe profile update, password recovery, mobile token exchange for approved riders |
| Authorization | Roles, permissions, scoped grants, policies on every route, audit of privileged changes |
| Geography | City create/update/status; zone create/update/activate/pause with optimistic versioning; exclusions; serviceability lookup |
| Admin web | Login, readiness overview, cities, zones editor (map plus GeoJSON text), users lookup, audit log, settings |
| Customer web | Home, location picker with serviceability result, login, account |
| Platform | Health routes, public config, feature flags, outbox table, structured logs, CI, backup and restore script |

## Shells only (visible, labelled, no working actions)

| Surface | Shell | Rule |
|---|---|---|
| Customer | Restaurants, restaurant detail, cart, checkout, tracking | Labelled preview or behind a flag. No invented restaurants, fees or ETAs |
| Merchant | Overview, branches (read own), orders, menu, staff, settings | Disabled actions; no fabricated sales or orders |
| Rider | Shift toggle (disabled), offer sheet (widget tests only), routes, earnings | No location permission request |
| Admin | Merchants, riders, customers lists; dispatch, pricing, finance, promotions, support, reports | "Coming soon" labels; no KPIs |

## Must not exist as working routes or workflows in Phase 1

Merchant onboarding approval, menu CRUD, cart, checkout, payment intents and webhooks, ledger postings, dispatch scoring and offers, rider location intake, push notifications, ratings, geocoding, routing and ETA, payouts, realtime channels.

- Their routes are not registered. A request gets 404.
- Their tables are not migrated (spec 5.5), with the exception of the identity shells the spec lists in 5.2 (merchants, branches, staff, riders, vehicles).
- Feature flags `FEATURE_LIVE_DISPATCH`, `FEATURE_ONLINE_PAYMENTS`, `FEATURE_RIDER_TRACKING` stay false and switching them on changes nothing, because no code sits behind them yet.

## Deferred requirements to carry forward

| Requirement | Deferred to | Reason |
|---|---|---|
| Serviceability must also confirm a valid route | Phase 4 | Needs Valhalla and a launch city (ADR-008) |
| Branch-level service zones | Phase 2 | Needs merchant catalogue |
| DB constraints against double assignment, duplicate gateway events, unbalanced postings | Phases 3–5 | Tables do not exist yet |
| Channel authorization at subscription | Phase 4 | No realtime in Phase 1 |
| MFA factor implementation | Before any production admin access | ADR-011 |

## Decisions that stay with the human operator

Launch city and polygon, brand, tile provider, payment processor and merchant of record, rider engagement model and pay, fees and commission, retention periods, production release (spec section 16 and AGENTS.md rule 20).
