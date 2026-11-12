# Multi-Vendor Food Delivery Platform — Master Build Plan

**Planning baseline:** November 2026 | **Market:** Philippines-first | **Currency:** PHP | **Timezone:** Asia/Manila

## 0. Mission and scope
Create an original, production-oriented multi-vendor food delivery marketplace with five surfaces: customer responsive web/PWA, restaurant merchant portal/PWA, courier Flutter Android app, dispatcher console, and super-admin console. Support expansion to multiple cities and franchise/branch operations. Do not copy protected brands, copyrighted layouts, proprietary APIs, or data from competitors.

**Operating assumptions for MVP:** one restaurant per checkout; one delivery per order; one rider per active assignment; one currency (PHP); one launch city/zone at a time; scheduled orders and batching deferred; cash-on-delivery and one integrated online payment processor; motorcycles as initial transport mode. All are configurable to expand later.

**Success gate:** In a staging environment, a seeded customer can place an order, merchant accepts, dispatch assigns a rider, rider picks up and delivers, customer sees real-time status, and balances are reconciled with complete audit records, including failure and cancellation scenarios.

## 1. Roles and applications
| App | Roles | Features |
|---|---|---|
| Customer web/PWA | Guest, customer | Address, serviceability, search, filters, menus and modifiers, cart, checkout, order history, real-time progress, rider ETA, support, reviews, refunds |
| Merchant portal/PWA | Owner, manager, cashier, kitchen | Onboarding, branches, business hours, menu and stock, orders with accept/reject and prep times, packing, reporting, settlements, staff permissions |
| Courier app (Flutter Android first) | Rider | KYC onboarding, availability, location consent, offers, accept/decline, navigation, pickup proof, delivery OTP/POD, COD collection, earnings, cash wallet, support |
| Dispatch console | Dispatcher, city manager | Live map, unassigned queue, dispatch decisions, manual override, rider state, incident queue, delayed-order alerts, zones and SLA |
| Super admin | Owner, finance, operations, support, auditor | Global KPIs, approval workflows, user/merchant/rider management, zone configurations, commission and fee rules, payouts, promo budgets, disputes, audit trails, security and settings |

## 2. Product UX
### Customer
Landing with delivery address selector -> serviceable restaurant discovery -> restaurant details -> product customization -> cart -> transparent pricing -> payment/COD -> live order timeline/map -> completion/rating/support.
- Location permission optional; manual pin drop and address with landmark are mandatory.
- Filters: cuisine, dietary attributes when provided by restaurant, price, delivery fees, rating, open now.
- Show branch-specific availability, estimated preparation and delivery time, minimum order, fees, surge and taxes before final confirmation.
- Cart validates prices, availability, delivery zone, merchant status and promos again at checkout.
- Require explicit substitution policies and cancellation/refund notice.
- Accessibility: WCAG 2.2 AA target, clear forms, keyboard support, contrast, meaningful errors, 44px touch targets.

### Merchant
Responsive tablet-friendly order board: New -> Accepted -> Preparing -> Ready for pickup -> Picked up -> Completed; declined/expired and cancelled handled separately. Audible and push alerts with fallback; bulk inventory controls; prep time and pause store; no ability to retroactively rewrite settled order totals.

### Rider
One-screen assignment offer, countdown, expected trip distance and payout, pickup details, call/contact with masked identifiers if possible, arrival, pickup confirmation, route guidance, safe parked-state actions, destination OTP/proof, COD entry and discrepancy reporting. No rider tracking outside consented operational modes.

### Admin
Left navigation: Overview; Live Operations; Orders; Restaurants; Riders; Customers; Dispatch; Delivery Zones; Pricing and Commissions; Payments; Merchant Settlements; Rider Wallets; Refunds and Disputes; Promotions; Reviews; Reports; Notifications; Roles and Access; Security/Audit; Integrations; System Settings. Multi-level filters by city, zone, branch, merchant and date.

## 3. Technical choices
- Backend: Laravel 13 / PHP 8.3+, API-first JSON endpoints, domain modules, migrations and jobs.
- Customer: Next.js + TypeScript + Tailwind; SSR for restaurant discovery; PWA for order tracking.
- Merchant/admin: React + TypeScript + shadcn/ui; separate routing and authorization boundaries.
- Courier: Flutter Android application for dependable background GPS and push notifications; iOS later.
- DB: PostgreSQL with PostGIS (points, polygons, spatial indexes, geography-based distances).
- Redis: queues, rate limiting, locks, caches; Laravel Horizon for queue operations.
- Real-time: Laravel Reverb or compatible WebSocket server, private channels, heartbeat/reconnect logic.
- Storage: S3-compatible object store (MinIO development, production storage with backups and retention).
- Maps: MapLibre GL JS for web and MapLibre-compatible Flutter/Native SDK if suitable; OpenStreetMap geographical data, tile provider for MVP with commercial terms; self-hosted vector tiles at scale.
- Routing/ETA: self-hosted Valhalla, route-time matrices and cache; routing profile must be tested against real motorcycle constraints and local road rules; do not equate generic automobile routing with legal motorcycle routes.
- Geocoding: self-hosted Pelias or Photon with capacity planning, manual pin + reverse geocode fallback; no production reliance on public OSM Nominatim for unrestricted bulk queries.
- Payments PH: PayMongo or approved equivalent for GCash/Maya/cards; use hosted checkout/tokenized integration, signed webhooks, idempotency and reconciliation. Confirm processor capabilities and merchant-of-record / settlement requirements contractually; do not assume automatic marketplace split payouts.
- Notifications: FCM for app push; email and SMS via replaceable adapters (SMS gateway may be paid).
- Deployment: Docker Compose for development; managed or self-hosted Linux deployment with TLS, object storage, DB backups, WAF, monitoring and scalable workers; avoid premature Kubernetes.

### Suggested repo structure
```
food-delivery/
  apps/customer-web/
  apps/merchant-web/
  apps/admin-web/
  apps/rider-flutter/
  backend/               # Laravel modular monolith
    app/Domain/{Identity,Merchants,Catalog,Checkout,Orders,Dispatch,Tracking,Payments,Ledger,Settlements,Promos,Support,Analytics}/
    app/Http/Controllers/Api/V1/
    database/{migrations,seeders,factories}/
    tests/{Unit,Feature,Integration}/
  infra/{docker,nginx,monitoring,deploy}/
  docs/{architecture,api,adr,security,runbooks,qa}/
  .github/workflows/
```

## 4. Domain database design
Every table uses immutable UUID/ULID primary keys, created/updated timestamps, appropriate unique/foreign/check constraints. Use UTC in storage; localize to Asia/Manila in UI. Monetary amounts are signed integer centavos (PHP) with currency codes. Locations use PostGIS geography/geometry with SRID 4326 and GiST indexes.

### Identity / ownership
`users`, `roles`, `permissions`, `user_role_scopes`, `customer_profiles`, `saved_addresses`, `devices`, `sessions`, `consents`, `verification_challenges`, `audit_logs`.
Merchant management: `merchants`, `merchant_documents`, `branches`, `branch_hours`, `branch_service_zones`, `merchant_staff`, `merchant_bank_accounts`, `merchant_contracts`.
Courier management: `riders`, `rider_documents`, `rider_vehicles`, `rider_shifts`, `rider_last_locations`, `rider_location_events` (limited retention), `rider_status_events`, `rider_wallets`.

### Marketplace
`categories`, `menus`, `menu_sections`, `products`, `product_variants`, `option_groups`, `options`, `product_option_rules`, `branch_product_availability`, `inventory_movements`, `media_assets`, `restaurant_reviews`.

### Ordering and fulfillment
`carts`, `cart_items`, `orders`, `order_items`, `order_item_options`, `order_amount_components`, `order_status_events`, `fulfillments`, `delivery_assignments`, `assignment_offers`, `delivery_events`, `delivery_proofs`, `delivery_routes`, `delivery_eta_snapshots`, `cancellations`, `refund_requests`, `support_tickets`, `ticket_messages`.
Preserve order snapshot of restaurant, items, quantities, options, taxes, discounts, prices, pickup location and dropoff address, so future menu edits never mutate historical orders.

### Money
`payment_intents`, `payment_transactions`, `payment_webhook_events`, `refunds`, `ledger_accounts`, `ledger_entries`, `ledger_postings`, `merchant_settlement_batches`, `merchant_payouts`, `rider_earning_entries`, `rider_cash_collections`, `rider_remittances`, `reconciliation_runs`, `invoices`, `credit_notes`, `fee_rules`, `commission_rules`, `promotions`, `promotion_redemptions`, `promotion_budgets`.
Financial ledger must be append-only double-entry with balanced postings per transaction. Never compute payout solely from current order status or editable summary fields. Idempotency keys prevent payment/order/assignment duplications.

### Geographic/ops
`cities`, `delivery_zones` (polygons), `coverage_exclusions`, `delivery_fee_schedules`, `surge_rules`, `dispatch_policies`, `dispatch_runs`, `operational_incidents`, `feature_flags`, `system_settings`.

## 5. Order state machine
Separate order state, payment state, delivery state, and settlement state. Do not use one overloaded status field.
- ORDER: pending_payment -> placed -> merchant_accepted -> preparing -> ready_for_pickup -> picked_up -> delivered; terminal states may include merchant_rejected, customer_cancelled, merchant_cancelled, system_cancelled, delivery_failed.
- PAYMENT: unpaid -> pending -> authorized/paid -> partially_refunded/refunded; failed and expired separately. COD: collection_due -> collected -> remitted or discrepancy.
- DELIVERY: unassigned -> offering -> assigned -> rider_to_pickup -> at_pickup -> picked_up -> rider_to_dropoff -> at_dropoff -> delivered or failed/reassigned.
- SETTLEMENT: unposted -> posted -> eligible -> batched -> payout_pending -> paid or payout_failed/reversed.
Only allow explicit transitions with guards, actor authorization, timestamps, idempotency, transactional outbox event and audit log.

### Checkout transaction
1. Resolve branch and delivery polygon eligibility.
2. Reprice snapshot with all discounts, delivery fees, surge, taxes and merchant closing constraints.
3. Reserve capacity/inventory with expiration as required; do not hold stock indefinitely.
4. Create order, line snapshots and pending payment under a DB transaction.
5. Generate provider payment intent outside long-held DB locks; webhook confirms paid with deduplication.
6. Enter merchant acceptance queue only when method conditions satisfied; COD orders can immediately be placed.
7. If merchant times out/rejects, release reservations and trigger appropriate void/refund workflow.

## 6. Dispatch / location service
**Serviceability:** customer point contained by active zone and merchant branch eligibility; actual route must be valid; per-merchant radius alone is not enough. Always have manual dispatch override.

**Automatic candidate selection:** active shift + online consent + approved documents + available state + vehicle policy + zone coverage + recent GPS heartbeat + max pickup travel time + no conflicting active assignment.
Rank candidates with a configurable weighted score of road-based ETA to restaurant, customer route length, order readiness, rider fairness and workload, acceptance history, and constraints. Never treat straight-line distance as accurate ETA.

**Dispatch algorithm MVP:**
1. On merchant acceptance (or near ready time), enqueue `DispatchOrder` with mutex keyed by order.
2. Fetch eligible riders from PostGIS near branch, verify positions are fresh.
3. Request/cache Valhalla route times for shortlist; compute deterministic score and offer top rider.
4. Offer expires after an admin-configured interval. Atomic compare-and-set assignment to avoid double booking.
5. On rejection, timeout, disconnect, or stale position, move to next candidate with retry/backoff.
6. Escalate to dispatcher with reason codes after thresholds; dispatcher can force/reassign and notify all parties.
7. On accepted job, save pickup and dropoff route; refresh ETA periodically, with smoothing and GPS jitter filtering.
8. Rider verifies pickup by order code and delivery by OTP or allowed proof-of-delivery method. Privacy-safe alternatives for contactless handoffs.
9. For COD, record expected cash, confirmed collected cash, rider outstanding liability and remittance reconciliation.

**Location engineering:** use 5–15-second active-delivery updates while foregrounded where practical, adaptive intervals/accuracy in background per OS permissions and battery budget, and reduce frequency when idle/off shift; no hard guarantees in restricted mobile backgrounds. Receive server timestamps and GPS accuracy; reject implausible jumps; persist rider last-known position to fast cache + periodic sampled history; expire customer location subscriptions after delivery. Do not provide public write access to GPS endpoints.

**ETA:** `merchant_prep_remaining + expected_rider_arrival + expected_road_delivery + calibrated_buffer`; update on events and route deviations; label ETA as estimate, not promise. Track per-zone calibration error.

## 7. Pricing and economics
At checkout show every line:
`items_subtotal - product_discounts - platform_promos + delivery_fee + small_order_fee + service_fee + applicable_taxes = customer_total`
Separately track allocation of promo subsidy (platform vs merchant), merchant commission, processing fee, rider base earning, distance/time/surge bonus, refunds, COD cash liability, and platform net revenue. Define whether tax is included or added according to proper PH tax treatment and legal advice.

**Illustrative calculation, NOT a recommended market price:**
- Items: PHP 500.00; delivery fee: PHP 60.00; service fee: PHP 10.00; discount: PHP 30.00 -> customer pays PHP 540.00 (before any separate tax adjustment).
- Commission example: 15% of PHP 500.00 = PHP 75.00; merchant payout basis PHP 425.00, adjusted for agreed tax, discounts, refunds and fee responsibilities.
- Rider earning example: PHP 45.00; actual processor fees and operating costs configurable. Never imply PHP 540 less PHP 425 less PHP 45 is pure profit; subsidies, payment fees, tax and other expenses apply.

## 8. Endpoints (illustrative contract; OpenAPI must be generated)
Customer: `POST /api/v1/auth/*`, `GET /api/v1/locations/serviceability`, `GET /api/v1/restaurants`, `GET /api/v1/restaurants/{id}/menu`, `POST /api/v1/carts`, `POST /api/v1/checkout/quote`, `POST /api/v1/orders`, `GET /api/v1/orders/{id}`, `POST /api/v1/orders/{id}/cancel`, `POST /api/v1/support/tickets`.
Merchant: `GET /api/v1/merchant/orders`, `POST /api/v1/merchant/orders/{id}/accept`, `POST /api/v1/merchant/orders/{id}/reject`, `POST /api/v1/merchant/orders/{id}/ready`, `PATCH /api/v1/merchant/menu/*`, `GET /api/v1/merchant/settlements`.
Rider: `POST /api/v1/rider/shifts/start`, `POST /api/v1/rider/shifts/end`, `POST /api/v1/rider/location`, `GET /api/v1/rider/offers`, `POST /api/v1/rider/offers/{id}/accept`, `POST /api/v1/rider/offers/{id}/decline`, `POST /api/v1/rider/jobs/{id}/arrived-pickup`, `POST /api/v1/rider/jobs/{id}/picked-up`, `POST /api/v1/rider/jobs/{id}/delivered`, `GET /api/v1/rider/wallet`.
Admin: `GET /api/v1/admin/overview`, `GET /api/v1/admin/dispatch/map`, `POST /api/v1/admin/assignments/{id}/reassign`, `GET /api/v1/admin/payments/reconciliation`, `POST /api/v1/admin/merchant-payouts/{id}/approve`, `GET /api/v1/admin/audit-logs`.
Provider: `POST /api/v1/webhooks/payments/{provider}` verifying signature, timestamp/replay protection and duplicate event IDs.

## 9. Security / privacy / compliance
- Explicit platform permission model with server-enforced RBAC and tenant/branch scopes. Customers may only view their own orders; merchant sees only own branches; riders only assigned jobs; support roles limited, financial admin approvals separately scoped.
- Authentication via secure cookie/sessions for web and short-lived tokens/refresh rotation for mobile, MFA for privileged staff, login throttling, account/device revocation and admin session logs.
- Input validation, file MIME verification and malware scanning, secure presigned uploads, encryption at rest, TLS, secrets vault, dependency and container scanning, SSRF protection for integrations, webhook signature validation, CSRF protection for cookie endpoints.
- Respect PH Data Privacy Act (RA 10173) and NPC requirements: privacy notice, lawful basis, processing agreements, data minimization, retention schedule, data subject requests, incident response; local legal review before go-live.
- Mask customer telephone number and exact home location unless necessary for a valid active delivery; remove rider access after completion; redact proof-of-delivery images and limit retention.
- Avoid storing raw payment card credentials; use approved payment provider's hosted/tokenized method; determine merchant of record and payout obligations with gateway/legal professionals.
- PH operational readiness: verify business registration, invoices/tax compliance, food merchant permits, rider road safety/insurance, worker/contractor treatment, local transport constraints, and refund/consumer policies with licensed professionals.
- Data controls: audit every privileged change and financial adjustment; 2-person approval for large payout batches and fee rule changes; backups with restore drill; retention and deletion schedules.

## 10. Nonfunctional acceptance criteria
Targets to validate in load tests rather than promise at inception:
- Standard read API p95 < 400 ms under defined staging workload excluding third-party dependencies; checkout request p95 < 2 s where external provider initiation is asynchronous.
- Order status events observed by connected clients typically within 3 seconds in controlled tests; reconnect must pull authoritative state.
- 99.9% app availability target after production hardening (not guaranteed by MVP).
- No double charge, no duplicate rider assignment, no unbalanced ledger transaction; test via concurrency and retry scenarios.
- Daily database backups with PITR when production-ready; RPO <= 15 min and RTO <= 4 h targets subject to infrastructure verification.
- All production releases with audit trail, rollback strategy, dashboard health checks and security scans.

## 11. QA test matrix
**Unit:** money math, fee rounding, zone containment, permissions, status transitions, dispatch scoring.
**Integration:** payment webhooks duplicate/out of order; checkout idempotency; invalid COD confirmation; unserviceable address; geocoder unavailable; Valhalla timeout; offer expiration; merchant closure; queue retry.
**End-to-end:** cash order success, online payment success/failure, merchant rejection/refund, rider refusal/reassignment, incorrect address, lost GPS/network, rider shift end, delivery OTP failure, partial refund, COD shortfall, payout reversal.
**Security:** IDOR across merchants/customers, forged merchant IDs, cross-tenant socket subscriptions, replayed webhooks, file upload attack, privilege escalation, rate limits, MFA flows, payment secrets never exposed.
**Performance:** simulated simultaneous checkouts, 1k+ GPS events/min in later scalability test, database spatial queries, rebroadcast load, queue lag and map tile capacity. Tune workload to actual growth assumptions.
**Operations:** backup restore, disaster rehearsal, support incident, ledger reconciliation, merchant payout reconciliation, data export/delete, app offline behavior.

## 12. Delivery milestones and gates (sequence, no fixed time promises)
### Phase 0 — Product discovery
Decide service city/zone, merchant contracts and commission, rider employment/payment model, accepted payment methods, customer support and refund policy, merchant-of-record flow, city-specific motorcycle/legal rules. Create journey maps, high-fidelity UI, architecture decision records, threat model and commercial budget. **Gate:** sign-off on rules and MVP scope.
### Phase 1 — Foundation
Initialize monorepo, Docker, Laravel 13, PostgreSQL/PostGIS, Redis, CI/CD, API conventions, authentication, role scopes, migrations, observability, test fixtures. **Gate:** green CI, seeded roles and isolation tests.
### Phase 2 — Merchants and catalog
Onboarding, approvals, branch locations, service zones, menus/modifiers, pricing, hours, availability, image processing and search. **Gate:** sample merchant can publish a live menu visible only in eligible zones.
### Phase 3 — Customer ordering and payments
Discovery, address selector, cart, quote, order creation, COD, payment gateway sandbox, webhook reconciliation, cancellation policies, notifications. **Gate:** each payment outcome reliably maps to exactly one order and balanced ledger entries.
### Phase 4 — Kitchen + dispatch
Merchant order board, rider shift/offers, dispatch console, GPS intake, MapLibre map, Valhalla route calculation, assignment timeouts, fallback/manual dispatch, pickup/dropoff proof. **Gate:** full order lifecycle in staging with concurrent rider contention tests.
### Phase 5 — Accounting + support
Commission engine, rider/COD wallet, settlements, manual bank payout export (until regulated automated payouts approved), refunds, ticketing, coupons with limits, RBAC and immutable audits. **Gate:** finance can explain/reconcile every peso on a test day.
### Phase 6 — Hardening + pilot
Privacy review, security test, real motorcycle road route verification, lost-network drills, QA sign-off, load testing, staging to production, pilot with few merchants and riders, daily incident review. **Gate:** measured pilot acceptance and cancellation rates, map/route quality, financial reconciliation.
### Phase 7 — Expansion
Multiple cities, zone operations, scheduled orders, merchant chain accounts, rider batching/multi-stop only with explicit economic model, smart surge, loyalty, referral, optimized ETA, iOS rider app, marketplace ads; each behind feature flags.

## 13. Development rules for the coding agent (Claude Code)
1. Read this document, create `/docs/architecture/` ADRs and `/docs/backlog.md` with testable user stories and dependencies before coding.
2. Implement incrementally, never produce a giant unreviewable feature dump. Every slice includes migration, API schema, tests, UI state, role authorization and error paths.
3. Do not fabricate live payment provider callbacks, routing results, SMS messages or GPS events in production paths. Mock only in explicit development/test environments.
4. Test first critical money, permissions, transitions and concurrency. Use transactional outbox, queues, retryable idempotent handlers, unique database constraints and pessimistic locking where appropriate.
5. Never rely only on frontend role checks; never trust client-supplied prices, branch IDs, delivery fees, proof timestamps or payout amounts.
6. Use responsive reusable design tokens (spacing, type, color, states) and accessibility checks. Avoid using any competitor's trademark assets or copying their UI.
7. All routes require documentation; validate request schemas and publish an OpenAPI specification. Auto-generate typed API clients where supported.
8. Run formatting, lint, static analysis, unit/integration tests, migrations, browser E2E, Flutter tests, dependency audit and security checks at every relevant gate.
9. After each milestone, record changed files, tests run, unresolved risks, screenshots, actual demo steps and rollback notes. Stop progression when critical tests fail.
10. Maintain ADRs for contentious choices, update the backlog, and never silently add major scope beyond MVP.

## 14. Production operational checklist
- [ ] Legal/business and payment-processing structure reviewed.
- [ ] Real service area polygon and exclusions approved.
- [ ] Merchant KYC and documentation approved.
- [ ] Rider qualifications, safety and insurance checks approved.
- [ ] Provider production keys and webhooks verified.
- [ ] Correct map attribution and production tile usage licensed.
- [ ] Own Valhalla routing service provisioned and Philippines road data refreshed.
- [ ] Secrets, HTTPS, CSP, backups, restores, dashboards and alerts verified.
- [ ] Full path checkout → kitchen → rider → customer → finance approved.
- [ ] Refund, failed payment, chargeback, COD shortage and cancellation playbooks documented.
- [ ] Data retention, privacy notice, support response and incident escalation reviewed.
- [ ] Pilot launch budget, metrics, and rollback criteria approved.

## 15. Open decisions to answer before implementation
1. Launch city and specific delivery boundaries?
2. Brand and preferred design identity?
3. Who owns merchant payouts and funds flow: platform collection, merchant-direct, or supported split settlement?
4. Commission, delivery fee and rider pay calculation?
5. Rider fleet: company-operated, independent, restaurant-specific, or hybrid?
6. Payments: COD first, GCash/Maya/cards, bank transfers?
7. Restaurant acceptance timeout, refunds, cancellation windows and dispute policy?
8. Merchant/rider KYC document requirements and retention?
9. Hosting budget and expected concurrent users/orders in pilot?
10. Whether customer mobile apps are needed immediately or responsive web/PWA suffices?

## 16. Source and licensing references (verified November 2026)
- Laravel 13 release and support: https://laravel.com/framework/docs/releases
- MapLibre GL JS / BSD-3-Clause: https://github.com/maplibre/maplibre-gl-js
- MapLibre docs: https://maplibre.org/maplibre-gl-js/docs/
- Valhalla / MIT: https://github.com/valhalla/valhalla
- OSM tile usage restrictions: https://operations.osmfoundation.org/policies/tiles/
- PayMongo payment methods and API: https://www.paymongo.com/en-ph/products/accept-payments/payments-api

**Reminder:** open-source software does not mean free production operations. Servers, map tiles or tile generation, live SMS, payment transaction fees, backups, domain names, and app-store distribution can cost money.

## 17. Implementation documents and agent tooling (added 2026-11-12)
- Phase 1 specification: `FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md` (formerly `FOOD_DELIVERY_PHASE1_CODEX_IMPLEMENTATION.md`).
- Agent rules: `AGENTS.md` (canonical), loaded by Claude Code through `CLAUDE.md`. The rules in section 13 above remain in force; `AGENTS.md` is the stricter Phase 1 version.
- Agent skills: `.claude/skills/`, pinned in `skills-lock.json`; the skill-to-sprint map is section 18 of the Phase 1 specification.
