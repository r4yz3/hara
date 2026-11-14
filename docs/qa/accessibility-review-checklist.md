# Manual accessibility review: screen reader and keyboard

Written 2026-11-15 for the operator. Backlog X-01 and spec section 15 ("Keyboard, screen reader semantics and color contrast checked") need this review. Automated checks already run (axe in the Playwright suite, see `docs/qa/ui-state-matrix.md`). This review covers what axe cannot see: whether the flow makes sense when heard, and whether it can be finished with the keyboard alone.

Time needed: about one hour. You do not need to be an accessibility specialist. Tick a box only when you saw or heard the result yourself. A pass with no notes is fine. Passing this review is not a WCAG conformance statement.

Staging hosts (release `1ef4016` or later):
- Customer: `https://staging.haradelivery.com`
- Merchant: `https://staging-merchant.haradelivery.com`
- Admin: `https://staging-admin.haradelivery.com`
- Rider: the Android release build pointed at staging (`--dart-define=API_BASE_URL=...`, see `docs/qa/evidence/rider-device-2026-11-15.txt`)

## 1. Setup (about 10 minutes)

Record these in your evidence file before you start:
- Date, and the Windows version (Settings > System > About).
- Browser and version (Chrome or Edge: menu > Help > About).
- Screen reader and version: NVDA (Help > About NVDA).
- Android device model, Android version, and TalkBack version (Settings > Accessibility > TalkBack > About), if you test the rider app.

Install the screen reader:
1. NVDA is free and open source. Download it only from the official site, `https://www.nvaccess.org`. Choose "Download NVDA", run the installer, and accept the defaults.
2. Start NVDA with Ctrl+Alt+N if it is not already running. Turn speech on or off with the NVDA menu (NVDA key+N).
3. Learn these keys before you start. The NVDA key is Insert (or Caps Lock if you changed it):
   - Ctrl: stop speaking.
   - NVDA key+Down: read from the cursor onward.
   - NVDA key+F7: Elements list, with tabs for Headings, Links, Form fields and Landmarks. Use the arrow keys and Enter to jump.
   - NVDA key+Space: switch between browse mode and focus mode. In a form field NVDA switches to focus mode by itself.

Keyboard only:
- Put the mouse or touchpad out of reach for the keyboard rows. Use Tab, Shift+Tab, Enter, Space, Escape and the arrow keys.
- Use Ctrl+L to reach the address bar if needed.
- Browser zoom stays at 100% unless a row says otherwise. Use Ctrl+0 to reset.

Android (rider app only):
1. Settings > Accessibility > TalkBack > On. Accept the prompt. Use the volume-key shortcut to turn it on and off quickly.
2. Practise: swipe right for the next item, swipe left for the previous one, double-tap to activate, and two-finger swipe to scroll.
3. Turn TalkBack off when you finish, unless you are keeping it on for the next session.

Data hygiene:
- Use the test accounts and synthetic records the operator gives you. Do not create accounts.
- Do not type a real password into anything you save. Use a made-up address such as `a.tester@example.test` with a wrong password where a row asks for a refused sign-in.
- Do not record your location, addresses, email addresses or phone numbers in notes or screenshots. Write `[redacted]`.
- Do not save a change on staging unless the operator agreed to it in advance. Cancel is always fine.

## 2. How to record results

Create `docs/qa/evidence/a11y-manual-<YYYY-MM-DD>.md` with the date of the run. Use this layout:

```
# Manual accessibility review, <YYYY-MM-DD>

- Tester: <name or initials>
- Windows: <version>; browser: <name and version>; NVDA: <version>
- Android: <device, version>; TalkBack: <version> (rider rows only)
- Hosts and release tested: <host list, release commit if shown>

| Row | Result | What was heard or seen | Notes |
|---|---|---|---|
| G1 | pass | Tab order matched the layout | |
| C-login-3 | fail | Error text not read after Enter | Expected: read once; happened: silent. Screenshot: docs/qa/evidence/<file> |
```

Rules for the table:
- Result is `pass`, `fail` or `n/a`. Use `n/a` only with a reason, such as "no rider account on staging".
- A row you did not reach is `not run`, with a reason. Do not leave it blank.
- For a fail, write what you expected, what happened, the page URL, and the step that caused it. Add a screenshot only if it shows no personal data.
- Give the file name to the agent. The agent links it from `docs/PHASE1_STATUS.md` and updates the X-01 row. The agent does not commit it.

## 3. Checks on every page

These run once on each page below. Note the row ID with the page check, for example `C-home-G4`.

- [ ] **G1 Tab order.** Tab from the top of the page. Focus moves in the visual reading order, and no hidden control receives focus.
- [ ] **G2 Visible focus.** On every control the focus ring is visible at 100% zoom, against the background.
- [ ] **G3 Skip link.** The first Tab shows a skip link (for example "Skip to main content"). Enter moves focus past the navigation. If the page has no skip link, record "absent" and do not fail the row for that alone.
- [ ] **G4 Headings.** NVDA key+F7, Headings. There is one level-1 heading, the rest follow in order, and no level is skipped.
- [ ] **G5 Landmarks.** NVDA key+F7, Landmarks. The page has main content, and navigation is labelled when there is more than one navigation area.
- [ ] **G6 Names.** Read the Links and Buttons lists in NVDA key+F7. Each name makes sense out of context. No "click here", no icon with no name.
- [ ] **G7 Form labels and errors.** Each field is announced with its label and whether it is required. Submit with an empty or invalid field: focus moves to the first invalid field, and the error text is read aloud.
- [ ] **G8 Progress and results.** On submit, a progress message such as "Working..." is read once, not repeatedly. The result is read without moving focus away from the form.
- [ ] **G9 Offline.** Turn Wi-Fi off, or use Chrome DevTools > Network > Offline. The "You are offline" banner appears and is read aloud. Turn the network back on and the banner clears. Skip this row on pages that do not need the network.
- [ ] **G10 Zoom to 200%.** Press Ctrl+= until the zoom reads 200%. Text is not cut off, and the page does not need a horizontal scroll for text. Every button still works.
- [ ] **G11 Reflow at 320 px.** Press F12, then Ctrl+Shift+M for the device toolbar. Set the width to 320. There is no horizontal scroll except in a data table or on the map.
- [ ] **G12 Colour is not the only cue.** Turn on Settings > Accessibility > Color filters > Grayscale. Status, errors and disabled actions still make sense from the text alone.
- [ ] **G13 Reduced motion.** In Chrome DevTools, press Ctrl+Shift+P, type "prefers-reduced-motion", and choose reduce. Spinners and transitions do not stop the page from being used or read.
- [ ] **G14 No trap, dialogs close.** Tab into and out of every control. In any dialog, Escape closes it and focus returns to the button that opened it.

## 4. Customer web (`staging.haradelivery.com`)

- [ ] **C-home-G1 to G6.** Home: the service banner is read once when the page loads. The empty restaurant state reads as a sentence, not a list of blank cards.
- [ ] **C-login-G7, G8.** `/login` with a made-up address and a wrong password: the refused-sign-in message is read after you submit.
- [ ] **C-login-G14.** Tab reaches the password show/hide control (if there is one) and its state is announced.
- [ ] **C-location-G7.** `/location`: submit empty. Focus moves to the field, and the message is read. Then submit a malformed value and check the same.
- [ ] **C-location-G8.** A slow check shows progress that is read once. The result, "outside our delivery areas" or the area name, is read aloud.
- [ ] **C-location-device.** Use the device-location button. If the browser asks for permission, choose allow or deny and record which. Whatever you chose, the answer must be readable in words. Do not record the position.
- [ ] **C-location-G9.** Offline behaviour matches G9.
- [ ] **C-account-G1 to G6.** `/account` (signed in). Headings are "Your account" and the form sections, in order.
- [ ] **C-account-G7.** "Change your name": the label is announced. Save with an empty name, and the error is read.
- [ ] **C-account-sign-out.** Sign-out is announced as a button. While it works the button reads as busy or disabled, and it does not send twice.
- [ ] **C-account-signed-out.** In a private window, `/account` sends you to sign-in and the sign-in heading is read.

## 5. Merchant web (`staging-merchant.haradelivery.com`)

- [ ] **M-home-G1 to G6.** Home: side navigation is labelled, and on a narrow window the bottom bar has the same six names. The "Pause store" action is disabled, and NVDA reads it as dimmed or unavailable together with its reason. The reason is text, not a tooltip only.
- [ ] **M-home-board.** The New, Preparing and Ready for pickup lanes are headed. An empty lane says it is empty.
- [ ] **M-home-branch.** The branch line reads "None assigned" as text.
- [ ] **M-login-G7, G8.** `/login` with a made-up address and a wrong password: error read once after submit.
- [ ] **M-login-G14.** Forgot-password link is reached with the keyboard and the page it opens has a heading.

## 6. Admin web (`staging-admin.haradelivery.com`)

Admin sign-in needs a staff account and the authenticator code. The operator supplies both. If you cannot sign in, record `not run` with the reason and skip to the rows you can reach.

- [ ] **A-login-G7, G8.** Sign-in form labels and error. A wrong password is read once.
- [ ] **A-login-mfa.** If you reach the code step, the code field has a label, its error is read, and the recovery-code link is reachable. Do not record the code.
- [ ] **A-nav.** The navigation rail: each item is a link, and the current page is announced (for example "current page"). Tab order through the rail is logical.
- [ ] **A-overview-G4, G6, G8.** Overview: the API row reads "reachable" or "not reachable" in words. Sections marked "Not built yet" or "Later phase" are read as unavailable, not as empty data. No figures appear that you did not expect.
- [ ] **A-cities-table.** Cities: the table has column headers, and NVDA key+Ctrl+Alt+Right (or the table navigation keys) reads each header with its cell. Status chips say the status in words.
- [ ] **A-cities-create.** Create city form: every field is labelled. An invalid field moves focus there, and the message is read. A slow create cannot be sent twice.
- [ ] **A-city-status-dialog.** On the city page, open the Activate, Pause or Archive dialog and cancel. The dialog is announced by name, focus starts on Cancel, Escape returns focus to the button that opened it. Nothing is changed.
- [ ] **A-city-zone-form.** Zone form: name, priority and GeoJSON fields are labelled. The GeoJSON field has its format described and linked to the field (NVDA reads the description after the label). Submit an invalid shape: focus moves to the field and the error is read.
- [ ] **A-city-conflict.** Only if you can reproduce it safely with a second window: a stale save reads a conflict message that tells you to reload, and the draft text stays in the field. If you cannot reproduce it, `n/a`.
- [ ] **A-city-map-keyboard.** The map is not a keyboard trap: Tab moves past it. A text statement or the GeoJSON field gives the same information as the map for keyboard and screen reader users. Drawing on the map needs a pointer; confirm that the GeoJSON field is an equal route to create or edit the shape, by reading the form instructions.
- [ ] **A-city-map-reflow.** At 320 px the map area does not hide the form controls around it.
- [ ] **A-access-G4, G6, G7.** Roles and access: the staff list has headings and readable row names (use names or IDs as the page shows them, not emails). The grant form: role and scope selects are operable with the keyboard (arrows change the value and it is announced).
- [ ] **A-access-suspend.** Suspend or Reactivate dialog: a reason is required, the message appears in the dialog, and Escape returns focus. Cancel only; do not confirm.
- [ ] **A-audit.** Audit log: filters are labelled. "Load more" (or the next page control) is announced and says what it loads. Redacted values read as "redacted", not as blank.
- [ ] **A-settings.** Settings: each read-only value has a label. The error state, if you can reach it, offers a retry that is announced.

## 7. Rider app (Android, TalkBack)

The rider app has run once on one Android 11 device (2026-11-15). Staging had no rider account then, so the home screen may be unreachable. Record it as `not run` in that case. Do not create a rider account.

- [ ] **R-signin-talkback.** Open the app with TalkBack on. The screen title is read first.
- [ ] **R-signin-fields.** Email and password fields are announced as editable text fields with their labels. The password is announced as hidden, not read as text.
- [ ] **R-signin-refused.** Sign in with a made-up address and a wrong password. The refusal message is read by TalkBack after you activate the button. The button announces its state if it is disabled while signing in.
- [ ] **R-signin-text-size.** Settings > Display > Font size (largest). The sign-in form still reads and the button label is not cut off.
- [ ] **R-signin-motion.** Settings > Accessibility > Remove animations on. The app still moves between screens without losing your place.
- [ ] **R-home-online.** Only if a rider account is available: the "Go online" toggle is announced with its reason when disabled.
- [ ] **R-home-nav.** The bottom bar has four named items. The Home item is announced as selected.
- [ ] **R-home-empty.** "Nothing to show yet" and the empty New jobs panel are read as text, not as blank space.
- [ ] **R-keyboard (optional).** With a Bluetooth keyboard connected, Tab moves between the email, password and sign-in controls in order.

## 8. Pass rule

- The review passes when every row is `pass` or `n/a` with a reason, and `not run` rows are listed with the reason.
- Any `fail` becomes a new row under X-01 in `docs/backlog.md`, with the page and step. Failures do not block the other rows.
- Record the result in `docs/qa/phase1-acceptance.md` only from the evidence file. Do not write a pass that you did not see.
