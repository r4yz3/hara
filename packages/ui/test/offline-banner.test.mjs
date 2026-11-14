import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

// Node cannot import .tsx, so these read the source. The behaviour (events, live region, focus) is proved in a
// browser by tests/browser/offline-banner.spec.ts.
const source = readFileSync(new URL("../src/offline-banner.tsx", import.meta.url), "utf8");

test("the banner says what is affected, in plain words", () => {
  assert.ok(source.includes("You are offline. Changes can't be saved until you reconnect."));
});

test("the banner is a polite status region that never takes focus", () => {
  assert.match(source, /role="status"/);
  assert.match(source, /aria-live="polite"/);
  assert.doesNotMatch(source, /\.focus\(|autoFocus|tabIndex/);
});

test("the banner assumes online until mounted and follows both events", () => {
  assert.match(source, /useState\(false\)/);
  assert.match(source, /addEventListener\("online"/);
  assert.match(source, /addEventListener\("offline"/);
  assert.match(source, /removeEventListener\("online"/);
  assert.match(source, /removeEventListener\("offline"/);
});

test("the banner uses an approved colour pair and no toast timer", () => {
  assert.match(source, /bg-surface-2/);
  assert.match(source, /text-text-primary/);
  assert.doesNotMatch(source, /setTimeout|setInterval/);
});
