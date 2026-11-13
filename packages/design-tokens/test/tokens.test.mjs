import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";

import { contrastRatio } from "../src/contrast.mjs";
import { measure, renderCss, renderDart, renderReport } from "../src/generate.mjs";
import { restricted, tokens } from "../src/tokens.mjs";

const read = (relative) => readFileSync(new URL(relative, import.meta.url), "utf8").replace(/\r\n/g, "\n");

test("contrast ratio matches the WCAG reference values", () => {
  assert.equal(contrastRatio("#000000", "#FFFFFF"), 21);
  assert.equal(contrastRatio("#FFFFFF", "#FFFFFF"), 1);
  assert.equal(contrastRatio("#FFFFFF", "#000000"), contrastRatio("#000000", "#FFFFFF"));
  // #767676 on white is the well-known 4.5:1 boundary grey.
  assert.ok(Math.abs(contrastRatio("#767676", "#FFFFFF") - 4.54) < 0.01);
});

test("rejects colours that are not #rrggbb", () => {
  assert.throws(() => contrastRatio("#fff", "#000000"));
  assert.throws(() => contrastRatio("rgb(0,0,0)", "#000000"));
});

test("light and dark define the same tokens", () => {
  assert.deepEqual(Object.keys(tokens.light), Object.keys(tokens.dark));
});

test("every approved pair meets its threshold in both themes", () => {
  const failures = measure()
    .filter((r) => !r.pass)
    .map((r) => `${r.theme}: ${r.foreground} on ${r.background} = ${r.ratio.toFixed(2)}, needs ${r.min}`);

  assert.deepEqual(failures, []);
});

test("restricted tokens really do fail, so the restriction is not stale", () => {
  for (const { token } of restricted) {
    assert.ok(contrastRatio(tokens.light[token], tokens.light.surface) < 3, `${token} no longer needs restricting`);
  }
});

test("tokens.css is up to date with tokens.mjs", () => {
  assert.equal(read("../tokens.css"), renderCss());
});

test("contrast report is up to date with tokens.mjs", () => {
  assert.equal(read("../../../docs/ux/contrast-report.md"), renderReport());
});

test("the rider app's Dart tokens are up to date with tokens.mjs", () => {
  assert.equal(read("../../../apps/rider-flutter/lib/core/tokens.g.dart"), renderDart());
});
