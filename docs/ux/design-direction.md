# Front-end design direction

Date: 2026-11-13. Source: four reference images supplied by the product owner (a customer app, a dispatch console, a merchant dashboard and a rider app). They set the *direction*. They are other people's designs, so nothing is copied: no logos, names, photographs, illustrations, text or pixel-level layouts (AGENTS.md rule 2, spec section 8.1). Each surface below lists what is taken as direction and what is deliberately different.

## What the references have in common
- Soft, rounded cards (16–24px radius) on a tinted canvas instead of flat white pages.
- One strong action colour per surface, used sparingly for the primary action.
- Large touch targets, pill-shaped inputs and chips.
- Maps as a first-class panel, not a decoration.

## Per surface

| Surface | Direction taken | Colour lead | Deliberately different |
|---|---|---|---|
| Customer web | Warm, food-forward: location and search at the top, a banner, a category rail, image-led cards, a bottom tab bar on phones | Warm wash (`warm-50`, `warm-100`) with a deep orange action (`cta`) | Banner announces real service state instead of a discount. No photographs until merchants supply their own. Desktop layout is a full responsive page, not a phone frame |
| Merchant portal | Calm tablet board: left navigation, a row of counters, order list with status chips, side panels for stock | Teal brand (`brand-600`) with warm highlights | No table or payment columns (this is delivery, not dine-in). Counters appear only when real orders exist |
| Admin and dispatch | Dense operations console: dark left navigation, counter strip, unassigned queue on the left, map in the centre, detail panel on the right | Neutral surfaces with teal for actions | English, city selector with no default, every figure real or absent. Colour is never the only status signal |
| Rider app | High-contrast, one-handed: big online toggle, job cards with distance and pay, map, earnings | Dark header on light body, one bright action | No swipe-only actions (they fail accessibility); a button does the same thing. No interaction that needs attention while riding |

## Rules that override the references
1. **No invented data.** The references are full of sample restaurants, earnings, rider positions and order counts. This product shows a figure only when the system produced it. Until then a screen shows its empty state or a clearly labelled layout preview (spec 8.2, 8.5).
2. **Phase 1 scope.** Ordering, payment, tracking, dispatch and earnings screens are previews without working actions until their phase.
3. **Measured colour.** Every text/background pair comes from `packages/design-tokens` and is listed in `contrast-report.md`. The references use white text on mid-orange and lime, which fails contrast; this product uses the deeper `cta` orange for filled buttons and keeps the light orange for decoration.
4. **No brand yet.** The name shown is a placeholder from configuration.
5. **No city.** Maps have no default centre; with no active city they show an empty state.

## Built so far (2026-11-13)
First screen of each surface: customer home, admin overview, merchant overview, rider home. Each is a shell: actions that need the API are disabled and labelled.

## Build order
1. Customer home (this change).
2. Customer location picker and sign-in, when their API exists (Sprints 4–5).
3. Admin shell with cities and zones (Sprint 6, after the API).
4. Merchant shell.
5. Rider theme and screens.

Screenshots of each built screen go in `docs/qa/evidence/`.
