import assert from "node:assert/strict";
import { test } from "node:test";
import { securityHeaders } from "../security-headers.mjs";

const byKey = (rules) => Object.fromEntries(rules[0].headers.map((h) => [h.key, h.value]));

test("applies to every path", () => {
  assert.equal(securityHeaders()[0].source, "/:path*");
});

test("forbids framing, sniffing and plugins", () => {
  const h = byKey(securityHeaders());
  assert.equal(h["X-Frame-Options"], "DENY");
  assert.equal(h["X-Content-Type-Options"], "nosniff");
  assert.match(h["Content-Security-Policy"], /frame-ancestors 'none'/);
  assert.match(h["Content-Security-Policy"], /object-src 'none'/);
  assert.match(h["Content-Security-Policy"], /base-uri 'self'/);
});

test("location is off unless asked for, and then only for the app itself", () => {
  assert.match(byKey(securityHeaders())["Permissions-Policy"], /geolocation=\(\)/);
  assert.match(byKey(securityHeaders({ geolocation: true }))["Permissions-Policy"], /geolocation=\(self\)/);
  assert.match(byKey(securityHeaders({ geolocation: true }))["Permissions-Policy"], /camera=\(\)/);
});

test("never allows unsafe script sources", () => {
  assert.doesNotMatch(byKey(securityHeaders())["Content-Security-Policy"], /unsafe-/);
});
