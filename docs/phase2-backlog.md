# Phase 2 backlog: merchants and catalogue

Status: draft for product owner review
Date: 2026-11-14
Item: X-03 (`docs/backlog.md`, Sprint 8)

## Goal

Phase 2 is "Merchants and catalog". The master plan (`FOOD_DELIVERY_PLATFORM_MASTER_PLAN.md`, section 12, Phase 2) lists onboarding, approvals, branch locations, service zones, menus and modifiers, pricing, hours, availability, image processing and search. Its gate is: "sample merchant can publish a live menu visible only in eligible zones." Phase 2 adds no ordering, checkout, payments or dispatch; those start in Phase 3 (master section 12).

Conventions for every story below: the merchant is taken from the authenticated context, never from the request (ADR-005); money is integer centavos (ADR-000, spec 5.1); IDs are ULIDs (ADR-003); UI stories include loading, empty, error, forbidden, offline and conflict states (spec 8.7, backlog U-03); each route is added to `backend/openapi/openapi.yaml` and `RouteInventoryTest` or `RouteCoverageTest` as the Phase 1 rules require.

Module note: merchant and catalogue code lives in `app/Domain/Merchants` and `app/Domain/Catalog` (ADR-001). The `Catalog` module is created when its first story starts (ADR-000, item 4).

## Epic 1: Merchant onboarding and approval

| ID | Item | Acceptance | Depends on | Status |
|---|---|---|---|---|
| M2-01 | Admin creates a merchant record | Needs a merchant write permission in an in-scope city; slug is unique; status cannot be set by the client; audited; customer and merchant roles get 403 | B-05, B-07, D-01 (migration 009), decision 1 | todo |
| M2-02 | Invite a merchant owner by email | Invite token is single-use and expires; sent in the email link fragment, never in a query string; acceptance creates a `merchant_owner` grant for that merchant only | M2-01, B-03, B-06, decision 1 | todo |
| M2-03 | Merchant submits its profile for review | Owner only; required fields validated server-side; draft to submitted, and back to draft after a change request; submitted data cannot be edited by the merchant | M2-02, decision 2 | todo |
| M2-04 | Merchant uploads documents | Stored privately (ADR-012); MIME type checked from file content; malware scan result recorded before the file can be read; downloads only through a short-lived authorised link; file names and contents kept out of logs; retention per decision 2 | M2-03, ADR-012, decisions 2, 3, 4, B-07 | todo |
| M2-05 | Admin approves, requests changes or rejects a submission | Needs the approval permission in scope (decision 18); moves only from submitted; a reason is required for changes and rejection; audit row written in the same transaction; an admin cannot approve a merchant they belong to | M2-03, B-05, B-07, decision 18 | todo |
| M2-06 | Suspend and reactivate an approved merchant | Suspension removes all branches and menus from public reads on the next request; merchant write routes refuse while suspended; reason stored; audited; reactivation restores prior branch states | M2-05, M2-12, decisions 18, 22 | todo |

## Epic 2: Branches, service zones and hours

| ID | Item | Acceptance | Depends on | Status |
|---|---|---|---|---|
| M2-07 | Create and edit a branch | Fields: display name, city, address text, location point; city must be active; location is a valid WGS84 point with longitude and latitude order tested; no default coordinates or map centre; branch belongs to the actor's merchant; audited | M2-05, B-05, D-01 (migration 009), ADR-002, decision 5 | todo |
| M2-08 | Set the branch location with a map pin and a manual fallback | Map loads client-only; placeholder shown when the style URL is empty; latitude and longitude can be typed; same validation as M2-07; no tiles from public OSM servers (ADR-007) | M2-07, U-04, U-10, decision 19 | todo |
| M2-09 | Branch states: draft, published, paused | Only published branches of approved merchants can be public; each transition audited; pause is reversible; state values confirmed before build | M2-07, M2-05, decisions 5, 22 | todo |
| M2-10 | Weekly branch hours | Open and close per weekday in the city timezone (ADR-004); no overlapping intervals; overnight rule as decided; invalid rows return field errors (422); tests freeze time at day boundaries | M2-07, decision 7 | todo |
| M2-11 | Admin assigns a published branch to active zones | Zone city must equal branch city; only active zones can be chosen; each change audited; merchant can read but not change; removing a zone hides the branch from that zone at once | M2-09, C-04, C-05, decision 6 | todo |
| M2-12 | Branch eligibility resolver | A branch is eligible for a point only when: merchant approved and not suspended, branch published, branch assigned to an active zone covering the point (`ST_Covers`), and no active exclusion covers the point; no active city returns the not-configured answer; uses the GiST index | M2-06, M2-11, C-07, C-08, ADR-002 | todo |
| M2-13 | Public restaurant list for a delivery point | Point sent in a POST body only (ADR-013); returns eligible branches with public fields only; outside all zones returns the empty state; route added to the public list in `RouteCoverageTest` and to OpenAPI; customer point never logged | M2-12, decision 14, ADR-010, ADR-013 | todo |
| M2-14 | Open-now status on a branch | Computed on the server from hours and server time (ADR-004); tests use a frozen clock; shown as a label only, with no order action (ordering is Phase 3) | M2-10, M2-13, decision 7 | todo |

## Epic 3: Menu and catalogue

| ID | Item | Acceptance | Depends on | Status |
|---|---|---|---|---|
| M2-15 | Menus and sections | Create, rename, reorder and delete empty sections; scoped to the actor's merchant; order is stable; deleting a section with products is refused | M2-05, decision 8 | todo |
| M2-16 | Categories and cuisine or dietary tags | Tags come from a managed list; merchant selects only listed tags; tags shown on products and branches | M2-15, decision 21 | todo |
| M2-17 | Product create and edit | Fields: name, description, section, sort order, status (draft or published), base price as integer centavos; decimals, negatives and floats rejected; money through the `Money` value object (D-06); audited | M2-15, D-06, decisions 8, 9 | todo |
| M2-18 | Product variants | Each variant has its own integer centavos price; a product with variants must have at least one; variants of another merchant's product return 404 | M2-17, decisions 9, 11 | todo |
| M2-19 | Option groups and options (modifiers) | Minimum and maximum selections validated (min not above max; required group has min of at least 1); option price changes are integer centavos under decision 10; groups reused only inside the same merchant | M2-17, decisions 9, 10 | todo |
| M2-20 | Price history | Every price or price-change event stored with effective time and actor; history rows never updated or deleted; a query returns the price at a given time | M2-17, M2-19, B-07, decision 20 | todo |
| M2-21 | Publish a menu | Product is public only when published, in a published section, and valid under M2-19; publish validates the whole menu and returns errors with field paths; runs in one transaction; stale edits return 409 (pattern from C-04) | M2-15, M2-17, M2-19, C-04 | todo |
| M2-22 | Public menu read | `GET /api/v1/restaurants/{id}/menu` returns published items only, for eligible branches; draft and hidden items never appear; ineligible or unknown branch returns 404; no cost or internal fields; OpenAPI and route list updated | M2-21, M2-12, decision 14 | todo |
| M2-23 | Product and menu images | Stored per ADR-012; MIME type checked from content; EXIF metadata including location removed; derivatives generated; malware scan before serving; limits per decision 4 | M2-17, ADR-012, decisions 3, 4 | todo |
| M2-24 | Catalogue search | Searches product and restaurant names in eligible, published data only; results never include a customer point or query in logs; empty and error states | M2-22, M2-13, decisions 14, 15 | todo |
| M2-25 | Merchant menu editor screens | Replaces the `/merchant/menu` skeleton; sections, products, options and publish; all states from U-03; keyboard reachable; 44px touch targets; screenshots at 375, 768 and 1440 saved under `docs/qa/evidence/` | M2-15 to M2-21, U-03, U-07, U-09, E-02 | todo |

## Epic 4: Item availability and stock

| ID | Item | Acceptance | Depends on | Status |
|---|---|---|---|---|
| M2-26 | Sold-out or available per branch | Per-branch flag in `branch_product_availability`; change visible on the next public menu read; needs menu permission for that branch; audited with branch and actor | M2-22, M2-07, decision 8 | todo |
| M2-27 | Pause a branch temporarily | Paused branch hidden from listings and menus; other branches unaffected; published menu unchanged; optional reason stored; reversible | M2-09, M2-12, decision 13 | todo |
| M2-28 | Bulk availability update in one branch | All-or-nothing transaction; batch size limit held in configuration; per-item result returned; audited | M2-26, decision 12 | todo |
| M2-29 | Stock quantities with append-only movements | Manual adjustments only (no order decrement in Phase 2); quantity never below zero; each change is a movement row with reason and actor; concurrent adjustments tested with row locking so no update is lost; sold-out derived as decided | M2-26, D-02, B-07, decision 12 | todo |

## Epic 5: Merchant staff and roles

| ID | Item | Acceptance | Depends on | Status |
|---|---|---|---|---|
| M2-30 | Invite staff and grant a role | Role set per decision 16; granted through `RoleGrantService`; no self-grant; no grant above the inviter's role or scope; single-use invitation; audited | B-06 (HTTP endpoint open), B-05, M2-02, decision 16 | todo |
| M2-31 | Restrict staff to named branches | Staff sees and edits only assigned branches (`merchant_branch_access`); another merchant's branch ID returns 404; branch-scope grant tested | M2-30, M2-07, C-12 | todo |
| M2-32 | Suspend or remove staff | Revoked staff get 401 or 403 on the next request; audited; a merchant always keeps at least one active owner (proposed, see decision 16) | M2-30, B-06 | todo |
| M2-33 | Merchant-scope cross-tenant suite | Every parameterised merchant route is tried with another merchant's and another branch's IDs; suite fails when a new merchant route is not listed | C-12, M2-07 to M2-32 | todo |
| M2-34 | MFA gate for merchant owners and managers | Privileged merchant sign-in refused in production without MFA, as for admin (B-09); TOTP enrolment, challenge and recovery built and reviewed | B-09, ADR-011, decision 17 | todo |
| M2-35 | Merchant portal scoped shell with real branch data | Branch selector lists only assigned branches; empty message when none assigned (spec 8.3); no invented sales or order data; replaces overview placeholders | U-07, M2-31, C-11 | todo |

## Epic 6: Admin review tools

| ID | Item | Acceptance | Depends on | Status |
|---|---|---|---|---|
| M2-36 | Merchant review queue | Filters: status, city, submitted date; cursor pagination with a stable sort, limit 100 (ADR-010); city-scoped staff see only their city; scope tests | M2-05, B-05, C-10 | todo |
| M2-37 | Merchant detail for admins | Profile, branches, staff count, document metadata; document contents only with the document permission; audit history filtered to the merchant | M2-04, M2-07, C-10, decision 2 | todo |
| M2-38 | Admin branch check | Shows which active zones and exclusions cover a branch, using the M2-12 resolver (single source of truth); read-only; text result shown even when the map placeholder is displayed | M2-12, U-04, U-10, decision 19 | todo |
| M2-39 | Merchants section in the admin console | Queue, detail, approve, reject and suspend actions; irreversible actions need an explicit confirm step (pattern from C-05); all states from U-03; keyboard; screenshots | M2-36, M2-37, M2-05, U-05, U-09 | todo |

## Open decisions for the product owner

Each decision is needed before the stories listed. None is assumed in the stories above.

1. **How do merchants get an account?** Admin-created invitations only, or a public application that an admin approves? Spec 1.2 says merchant access needs an invitation or approved onboarding "in later phases"; master Phase 2 names "onboarding". Blocks: M2-01, M2-02, M2-03.
2. **Which merchant documents are required, per city and merchant type, and how long are they kept?** Master section 9 requires a retention schedule and section 15 item 8 asks the question, but no document list is given. Blocks: M2-03, M2-04, M2-37.
3. **Object storage.** ADR-012 is open and no provider is chosen. Blocks: M2-04, M2-23.
4. **Upload rules and malware scanning.** Allowed file types, maximum sizes, image derivative sizes, and the scanning service are not in either source. Blocks: M2-04, M2-23.
5. **Branch model.** What does "published" mean; must a branch have a map location before publishing; are branch states exactly draft, published and paused? Blocks: M2-07, M2-09, M2-12.
6. **Who assigns branches to zones?** Admin only, or the merchant picks zones and an admin approves? Blocks: M2-11.
7. **Hours detail.** Holiday or exception dates, overnight closing times, and confirmation that Phase 2 shows open or closed as a label only. Blocks: M2-10, M2-14.
8. **Menu ownership.** One menu per merchant shared across branches (with per-branch availability), or a separate menu per branch? Blocks: M2-15, M2-17, M2-26.
9. **Tax in prices.** Are menu prices tax-inclusive or is tax added at checkout? Master section 7 says this needs PH tax advice. Blocks: M2-17, M2-18, M2-19.
10. **Option price changes.** May an option change the price downwards, and is there a maximum? Blocks: M2-19.
11. **Variant or option.** Is a size a product variant or an option group? The master lists both tables without a rule. Blocks: M2-18.
12. **Stock model.** Sold-out flags only, or counted quantities? Master section 2 lists "bulk inventory controls" under the merchant portal without a phase. Blocks: M2-28, M2-29.
13. **Pause store.** Phase 2 (this backlog) or Phase 4 with the order board? Blocks: M2-27.
14. **Customer-facing discovery and menus.** Master Phase 2 gate needs a menu "visible" in eligible zones, but master Phase 3 lists "discovery" and spec 7.6 lists `/restaurants` as a future endpoint. Is the public read in Phase 2? Blocks: M2-13, M2-22, M2-24.
15. **Search.** Technology and scope (PostgreSQL full text, an external engine, or none for Phase 2). Master names Pelias or Photon only for geocoding. Blocks: M2-24.
16. **Staff roles.** Master section 1 lists owner, manager, cashier and kitchen; spec 6.1 lists `merchant_owner`, `merchant_manager` and `merchant_staff`. Which set applies, and must there be a minimum of one active owner? Blocks: M2-30, M2-31, M2-32.
17. **MFA for merchant staff.** Are merchant owners and managers "privileged" under ADR-011 and spec 6.1? Blocks: M2-34.
18. **Approval authority.** Which admin roles may approve, suspend and reactivate merchants? Master section 9 requires two-person approval only for large payout batches and fee rule changes; is merchant approval exempt? Blocks: M2-05, M2-06, M2-39.
19. **Map tile and style provider.** U-10 is a human decision and ADR-007 leaves it open. Until then only placeholders can be shown. Blocks: M2-08, M2-38.
20. **Price change timing.** Do price changes apply immediately to the public menu, or at a set effective time? Blocks: M2-20.
21. **Tag list owner.** Who maintains the cuisine and dietary tag list? Blocks: M2-16.
22. **Deletion or deactivation.** Are merchants and branches ever deleted, or only deactivated? Master section 4 says immutable history is never soft-deleted. Blocks: M2-06, M2-09.

## Carried over from Phase 1

Statuses are copied from `docs/backlog.md`. Only items that are partial, blocked or open, and that matter for Phase 2, are listed.

| Phase 1 ID | Status | Why it matters for Phase 2 | Blocks |
|---|---|---|---|
| U-03 | todo | UI state matrix is not built; every M2 screen needs it | M2-25, M2-39 |
| U-04 | todo | Map component needed for branch pins and the admin branch check | M2-08, M2-38 |
| U-05 | partial | Admin console has map drawing built (2026-11-14), but it needs hosted tiles before anyone can use it; Roles and access (users) built 2026-11-14; Merchants section extends it | M2-39 |
| U-07 | partial | Merchant shell has no merchant account to sign in with and no branch data | M2-25, M2-35 |
| U-09 | todo | Screenshots from running apps are the Phase 2 gate evidence (AGENTS rule 11) | M2-25, M2-39 |
| U-10 | blocked (human) | Tile provider not chosen (ADR-007) | M2-08, M2-38 |
| B-06 | done as a service; no HTTP endpoint | Staff invites and role changes need an endpoint | M2-30, M2-32 |
| B-09 | partial | TOTP not built; merchant MFA depends on it | M2-34 |
| C-12 | partial (city scope done) | Merchant-scope isolation waits for merchant endpoints | M2-33 |
| D-07 | approved 2026-11-15: draft, active, paused, archived (ADR-000 item 8 closed) | City status values; branches depend on an active city | M2-07, M2-12 |
| E-02 | partial | Nonce-based CSP for web apps still open; merchant portal inherits it | M2-25, M2-35 |
| E-03 | done locally | No schedule, off-machine copy or encryption; merchant documents need backups (M2-04) | M2-04 |
| A-11 | written, never run | CI results unverified; Phase 2 gate needs green CI | All UI and API stories |
| X-01 | partial | Playwright, accessibility and clean-clone checks not run | All UI stories |
| C-13 | blocked (human) | Zone vertex and area caps not set; low impact on Phase 2 unless zone editing grows | M2-11 (indirect) |

Phase 1 items already done and reused by Phase 2: B-05 (`AccessChecker`), B-07 (`AuditLogger`), C-04 and C-05 (versioned zones and activation), C-07 (exclusions), C-08 (serviceability), C-10 (cursor lists), C-11 (generated client), D-01 and D-02 (merchant shell migrations and constraints), D-06 (`Money`).

Not in this backlog, by design: merchant bank accounts and contracts (master section 4, Phase 5 settlements), restaurant reviews and ratings (spec 1.2 out of scope; not in the Phase 2 gate), order and cart tables (Phase 3).
