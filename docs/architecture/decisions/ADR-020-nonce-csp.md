# ADR-020: Per-request nonce CSP for the web apps

- Status: accepted (operator decision 2026-11-15: try Next.js 16's experimental SRI approach first, fall back to nonces)
- Date: 2026-11-15
- Backlog: E-02

## Decision
The three web apps send a strict, per-request, nonce-based Content-Security-Policy from each app's `proxy.ts` (helper `nonceCsp` in `packages/config`). Every page is rendered per request (`export const dynamic = "force-dynamic"` in each root layout) and `cacheComponents` and `partialPrefetching` are turned off in all three apps. Only `/_next/static` assets stay cacheable.

## Why not SRI (tried first, 2026-11-15)
`experimental.sri` (Next 16.4.6, Turbopack build) adds `integrity` hashes to the external chunk scripts only. Every page also carries inline scripts: the Turbopack bootstrap push and the `self.__next_f.push([...])` flight-data scripts, whose content differs per page and, with partial prerendering, is streamed at request time. With the documented policy (`script-src 'self'`) the browser blocked 8 inline scripts on `/login` (console: "Executing inline script violates ... script-src 'self'"). The only ways to allow them are `'unsafe-inline'` (defeats the policy) or listing per-page hashes that the build does not emit. SRI therefore cannot give a strict CSP here.

## Policy
`default-src 'self'`; `script-src 'self' 'nonce-…' 'strict-dynamic'` (no `unsafe-inline`; `unsafe-eval` only under `next dev`); `style-src 'self' 'nonce-…'` (Tailwind is compiled to CSS files and Next's inline styles take the nonce, so no `unsafe-inline`); `img-src 'self'`; `font-src 'self'`; `connect-src 'self'` + API origin + tile origin; `worker-src 'self'`; `object-src 'none'`; `base-uri 'none'`; `form-action 'self'`; `frame-ancestors 'none'`.

MapLibre 6.16 was verified in a production build: no `unsafe-eval`, no `blob:` worker (the bundled worker is a same-origin asset set with `setWorkerUrl`), and no `img-src` entry (sprites, glyphs and tiles are fetched with `fetch`, so `connect-src` covers them).

## Consequences and deploy notes
- A header set in `next.config` replaces the proxy's header and hides the nonce from Next, so the apps call `securityHeaders({ baselineCsp: false })`; the other baseline headers are unchanged.
- The API and tile origins are read at build time (`NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_MAP_STYLE_URL`, literal reads in `proxy.ts`). The web images must be built with both. `MAP_STYLE_URL` from the API must have the same origin as the build arg, or the browser blocks the map. Only an https tile origin is accepted.
- Pages are no longer prerendered; the 404 page and the error screens are rendered per request too.
- Evidence: `tests/browser/csp.spec.ts` (production builds, 16 tests, no violations on key pages of all three apps including the admin map and customer location pages, directive and enforcement checks).
