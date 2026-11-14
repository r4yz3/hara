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

import { apiOriginFrom, contentSecurityPolicy, createNonce } from "../security-headers.mjs";

test("nonces are fresh, base64 and at least 128 bits", () => {
  const nonces = new Set(Array.from({ length: 50 }, createNonce));
  assert.equal(nonces.size, 50);
  for (const n of nonces) assert.match(n, /^[A-Za-z0-9+/]{22}==$/);
});

test("production policy: nonce plus strict-dynamic, no unsafe sources, API-only connections", () => {
  const csp = contentSecurityPolicy({ nonce: "abc", apiOrigin: "https://api.example.test" });
  assert.match(csp, /script-src 'self' 'nonce-abc' 'strict-dynamic'(;|$)/);
  assert.match(csp, /style-src 'self' 'nonce-abc'(;|$)/);
  assert.match(csp, /connect-src 'self' https:\/\/api\.example\.test(;|$)/);
  assert.match(csp, /frame-ancestors 'none'/);
  assert.match(csp, /object-src 'none'/);
  assert.match(csp, /base-uri 'none'/);
  assert.doesNotMatch(csp, /unsafe-/);
});

test("development alone may use unsafe-eval and inline styles", () => {
  const csp = contentSecurityPolicy({ nonce: "abc", apiOrigin: "http://localhost:8000", dev: true });
  assert.match(csp, /script-src[^;]*'unsafe-eval'/);
  assert.match(csp, /style-src 'self' 'unsafe-inline'/);
  assert.doesNotMatch(csp, /script-src[^;]*unsafe-inline/);
});

test("a nonce is required and the API origin comes from the environment", () => {
  assert.throws(() => contentSecurityPolicy({ nonce: "", apiOrigin: "http://x.test" }));
  assert.equal(apiOriginFrom({ NEXT_PUBLIC_API_URL: "https://api.example.test/v1/" }), "https://api.example.test");
});

import { tileOriginFrom } from "../security-headers.mjs";

test("map tiles: only the configured https origin is added, and only to connect-src (ADR-015)", () => {
  const without = contentSecurityPolicy({ nonce: "abc", apiOrigin: "https://api.example.test" });
  assert.match(without, /connect-src 'self' https:\/\/api\.example\.test;/);
  const withTiles = contentSecurityPolicy({ nonce: "abc", apiOrigin: "https://api.example.test", tileOrigin: "https://tiles.example.test" });
  assert.match(withTiles, /connect-src 'self' https:\/\/api\.example\.test https:\/\/tiles\.example\.test;/);
  assert.match(withTiles, /worker-src 'self';/);
  assert.doesNotMatch(withTiles, /worker-src[^;]*blob:/);
  assert.equal(tileOriginFrom({ MAP_STYLE_URL: "https://tiles.example.test/style.json" }), "https://tiles.example.test");
  assert.equal(tileOriginFrom({ MAP_STYLE_URL: "http://tiles.example.test/style.json" }), null);
  assert.equal(tileOriginFrom({}), null);
});
