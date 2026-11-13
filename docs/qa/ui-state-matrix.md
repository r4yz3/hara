# UI state matrix (backlog U-03)

Spec: `FOOD_DELIVERY_PHASE1_IMPLEMENTATION.md` section 8.7 (states) and 8.8 (accessibility).
Tests: `tests/browser/state-matrix.spec.ts` (this item) and `tests/browser/phase1.spec.ts` (earlier items, cited as `phase1`).
Every test runs in the phone (375), tablet (768) and desktop (1440) projects. The API is replaced by route
interception, so no real data, account or position is involved. Sign-in, forgot-password and reset-password pages are
not in this matrix (they are being changed separately).

## How to read a cell

- `covered (test)`: a browser test forces the state and asserts what the user sees. Tests that start with a view name
  (`cities:`, `city:`, `exclusions:`, `audit:`, `settings:`, `location:`) are in `state-matrix.spec.ts`.
- `n/a (reason)`: the view cannot reach the state.
- `gap (reason)`: reachable, not proven by a test, or not built.

Where a view shows an error, it names what happened (`describeFailure` in `packages/session`) and keeps entered data.
Axe (WCAG 2.2 AA tags, plus a no-horizontal-scroll check) runs in the forbidden and error states of the admin
cities, city, audit and settings views, the location view's validation and offline states, and the admin overview.

## Matrix

| View | Loading | Empty | Error with retry | Forbidden with back | Offline | Success | Validation, focus first invalid | Conflict (409) | Slow, no duplicate submit |
|---|---|---|---|---|---|---|---|---|---|
| Customer `/` | gap (the banner has a Suspense fallback, but its data is fetched on the server from a fixed fixture, so a browser test cannot slow it) | covered (phase1 "customer home shows the empty restaurant state") | gap (the banner has a "could not check" state with a reload instruction, but a browser test cannot make the server-side fetch fail; the session pill part is covered: "customer: the session pill says so...") | n/a (public page, no authorization) | n/a (nothing is fetched by the browser after load except the session pill, covered above) | gap (the "areas are open" banner needs a second server-side fixture) | n/a (no form) | n/a (nothing is changed) | n/a (no submit) |
| Customer `/location` | n/a (no data is loaded; the form is the page) | n/a (no list; the idle form is the starting state) | covered (phase1 "customer can retry a delivery check after an API error") | n/a (public endpoint; the page always has a Back link) | covered ("location: offline reports the lost connection...") | covered (phase1, same test, "outside our delivery areas" answer) | covered ("location: an empty or malformed position focuses...", "...a refused position from the server focuses...") | n/a (nothing versioned) | covered ("location: a slow check shows progress and cannot be submitted twice") |
| Merchant `/` | covered ("merchant: the session pill shows progress...", the only thing the page loads) | covered (phase1 "merchant overview keeps unavailable actions disabled"; every panel says what is missing) | covered ("merchant: the session pill says so when the server cannot be reached") | n/a (no API data; there is no merchant account to be refused yet) | n/a (static page; the pill states are covered) | n/a (no action can succeed; every action is disabled and labelled) | n/a (no form) | n/a | n/a (no submit) |
| Admin `/` | gap (Suspense row for the API check is fetched on the server; cannot be slowed from the browser) | n/a (layout preview panels are labelled and hold no list) | gap (the "API is not reachable" row exists and tells staff to reload; its server-side fetch cannot be forced to fail) | n/a (static; no API data) | n/a (static) | covered ("admin overview reports the live API row...", accepts reachable or not, asserts no invented figures, axe clean) | n/a | n/a | n/a |
| Admin `/cities` | covered ("cities: progress while slow...") | covered (same test, "No cities yet") | covered ("cities: a server error offers a retry") | covered ("cities: forbidden explains and leads back...") | covered ("cities: working offline keeps the form...") | covered ("cities: a rejected create ... then succeeds") | covered (same test; focus moves to the first refused field, `aria-invalid` set) | n/a (create has no version; the list is read-only) | covered ("cities: a slow create blocks a second submit") |
| Admin `/cities/[id]` (city, zones) | covered ("city: progress while slow...") | covered (same test, "No delivery zones yet") | covered ("city: not found and forbidden lead back; a server error retries") | covered (same test) | covered ("city: an action while offline reports it...") | covered ("city: a slow zone save ... blocks a second submit"; "city: a stale status change ..." shows the reloaded status) | covered ("city: a rejected rename focuses...", "city: zone creation focuses the first invalid field...") | covered ("city: a stale status change prompts a reload...", "city: a stale zone change prompts a reload...", phase1 "a stale zone edit keeps the draft available") | covered ("city: a slow zone save shows progress and blocks a second submit"; phase1 confirmation dialog) |
| Coverage exclusions panel (inside `/cities/[id]`) | covered ("exclusions: a refused or malformed shape...", the "Working..." status; the panel loads on open) | covered (phase1 "No coverage exclusions.") | covered ("exclusions: a failed load offers Retry...") | covered ("exclusions: forbidden is stated in place...": the message is in place and the page navigation stays) | covered (same test as Error) | covered ("exclusions: an added exclusion is listed, and a removal needs confirmation") | covered ("exclusions: a refused or malformed shape focuses the shape field...") | n/a (exclusions have no version) | covered (same test as Validation: one POST while slow) |
| Admin `/audit` | covered ("audit: progress while slow...") | covered (same test, "No entries") | covered ("audit: a server error retries...") | covered (same test, with back link) | covered ("audit: loading older entries offline...") | covered ("audit: loading older entries offline...", entries and "End of the log.") | n/a (optional free-text filters; an empty filter is valid) | n/a (read-only) | covered ("audit: a slow filter shows progress and ignores a second Apply") |
| Admin `/settings` | covered ("settings: progress while the status is slow") | n/a (the endpoint always returns a status document) | covered (phase1 "admin settings retry a failed status request...") | covered (phase1 "...show a permission denial...", "settings: forbidden is accessible and leads back...") | covered ("settings: forbidden ... offline refresh reports and recovers") | covered (phase1, "Service checks" shown) | n/a (read-only) | n/a (read-only) | covered ("settings: progress while the status is slow"; the only control, Refresh, is replaced by the progress text while a request runs) |

Totals (9 views by 9 states = 81 cells): 48 covered, 28 n/a, 5 gap.

## Changes made to close gaps

- Admin: loading indicators are now `role="status"` (announced), on `/cities`, `/cities/[id]` and `/audit`.
- Admin: a server-refused value (422) now moves focus to the first refused field and sets `aria-invalid` on city
  create, city edit, zone create; focus moves for zone edit and exclusion add. An unparsable GeoJSON entry focuses the
  shape field.
- Admin: after a 409 on a city or zone change, the message is followed by a "Reload latest" button. Before, a zone
  conflict reloaded silently and the explanation was lost when the row was replaced.
- Admin audit: Apply ignores a second press while the list loads (`aria-disabled`, so focus stays on the button).
- Customer: a server-refused position on `/location` focuses the field it names.

## Gaps left, with reasons

- G1. Offline cached read with a stale timestamp (spec 8.7 offline). Not built for any view: it needs a service worker or
  client cache, which is a design decision (data retention on shared staff devices, cache invalidation after 403 or
  sign-out). Today an offline view keeps what is on screen, says the connection was lost, keeps entered data and
  offers a retry; that behaviour is what the offline cells above prove.
- G2. Server-rendered states (customer `/` banner loading, error and "open"; admin `/` API row loading and error). The
  fetch happens in the Next.js server against a fixed fixture started by `playwright.config.ts`
  (`tests/browser/public-config-server.mjs`, always "closed"). Proving these needs a configurable fixture or a
  server-side fault switch, both outside this item's files.
- G3. Success feedback is an inline `role="status"` message plus a refreshed list, not a toast. There is no toast
  component yet (backlog U-02 lists one for `packages/ui`).
- G4. No `error.tsx` or `not-found.tsx` in any app, so an unexpected render error or unknown URL shows the framework
  default. Not changed here; it is a design choice for all three apps.
- G5. The Next.js development overlay badge can sit over bottom-left controls on a phone and intercept pointer
  clicks in the dev server; the audit offline test uses keyboard activation for that reason. It does not exist in a
  production build.

## Accessibility notes (spec 8.8)

Errors use `role="alert"`, progress uses `role="status"` or `aria-busy`, refused fields carry `aria-invalid`, and
every action stays reachable by keyboard. Colour pairs are the ones listed in `docs/ux/contrast-report.md`; no new
colour was added.
