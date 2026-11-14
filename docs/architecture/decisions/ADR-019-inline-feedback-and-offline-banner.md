# ADR-019: Inline feedback and offline banner

- Status: accepted (human operator decision, 2026-11-15)
- Date: 2026-11-15
- Relates to: backlog U-02 and U-03, `docs/qa/ui-state-matrix.md` (gaps G1 and G3)

## Context
The state matrix left two design questions open: whether actions report their result in pop-up toasts, and how a view shows a lost connection. Toasts disappear on a timer, which is a problem for screen-reader and keyboard users.

## Decision
1. **No pop-up toasts in Phase 1.** The result of an action (success or failure) is shown inline, next to the action, in an element announced to assistive technology (`role="status"` or `role="alert"`) that stays visible.
2. **Offline:** a single "You are offline" banner. There is no offline cache in Phase 1: views keep what is on screen and offer retry (matrix gap G1 stays as decided on 2026-11-14).

## Consequences
- `packages/ui` needs no toast component. Revisit for the customer and rider apps in a later phase.
- Matrix gap G3 is closed. The offline banner is a design decision; whether every view already shows it is not claimed here.
