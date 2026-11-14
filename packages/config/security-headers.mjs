// Response headers for the three web apps (backlog E-02).
//
// CSP: the per-request policy from nonceCsp() (each app's proxy.ts) is the policy
// the apps send. A header set in next.config replaces the proxy's header, so the apps
// call securityHeaders({ baselineCsp: false }). The static baseline (frame, base,
// form, object) stays available for any caller that has no proxy.

const BASE_CSP = "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";

// Fail-closed floor for the paths each app's proxy.ts skips (static assets only). Blocks inline script,
// framing, plugins and base/form hijacking; the nonce policy from the proxy is the real policy for everything else.
const FLOOR_CSP = "script-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'; form-action 'self'";

/**
 * The only request paths the apps' proxy.ts does not run on (static assets, which carry no HTML).
 * proxy.ts's matcher must be exactly the complement of these; a test in packages/config compares them.
 * `:path*` matches zero or more segments, so `/_next/static/:path*` does not match `/_next/staticX`.
 */
export const PROXY_SKIPPED_SOURCES = ["/_next/static/:path*", "/_next/image", "/favicon.ico"];

/** The matcher string every proxy.ts must use: everything except PROXY_SKIPPED_SOURCES, anchored. */
export const PROXY_MATCHER = "/((?!_next/static/|_next/image$|favicon\\.ico$).*)";

/**
 * @param {{ geolocation?: boolean, baselineCsp?: boolean, staticFloorCsp?: boolean }} [options] geolocation: allow this app's
 *   own pages to ask for the device position (the customer app only). baselineCsp: send the static
 *   frame/base/form/object CSP. The apps turn it off because a header set in next.config replaces the
 *   proxy's per-request one (it would also hide the nonce from Next), so the proxy sends the whole policy.
 *   staticFloorCsp: with baselineCsp off, send a fail-closed floor CSP on the paths the proxy skips (static assets).
 */
export function securityHeaders({ geolocation = false, baselineCsp = true, staticFloorCsp = false } = {}) {
  return [
    {
      source: "/:path*",
      headers: [
        { key: "X-Content-Type-Options", value: "nosniff" },
        { key: "X-Frame-Options", value: "DENY" },
        { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
        {
          key: "Permissions-Policy",
          value: `camera=(), microphone=(), payment=(), usb=(), geolocation=${geolocation ? "(self)" : "()"}`,
        },
        ...(baselineCsp ? [{ key: "Content-Security-Policy", value: BASE_CSP }] : []),
      ],
    },
    // A header set here replaces the proxy's, so the floor is attached only to the paths the proxy never
    // runs on (PROXY_SKIPPED_SOURCES). Everything else gets the proxy's nonce policy.
    ...(staticFloorCsp
      ? PROXY_SKIPPED_SOURCES.map((source) => ({ source, headers: [{ key: "Content-Security-Policy", value: FLOOR_CSP }] }))
      : []),
  ];
}

/** Where the browser may call the API; the same variable the session client reads. */
export function apiOriginFrom(env = process.env) {
  const raw = env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
  return new URL(raw).origin;
}

/** Origin of MAP_STYLE_URL, or null when it is unset or not https. */
export function tileOriginFrom(env = process.env) {
  const raw = env.NEXT_PUBLIC_MAP_STYLE_URL ?? env.MAP_STYLE_URL ?? "";
  try {
    const url = new URL(raw);
    return url.protocol === "https:" ? url.origin : null;
  } catch {
    return null;
  }
}

/** 128 random bits, base64. Must be fresh for every request. */
export function createNonce() {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return btoa(String.fromCharCode(...bytes));
}

/**
 * The per-request, nonce-based policy (backlog E-02).
 *
 * @param {{ nonce: string, apiOrigin: string, tileOrigin?: string | null, dev?: boolean }} options
 *   tileOrigin: the origin that serves the map style and PMTiles file (ADR-015),
 *   or null when no map is configured.
 *   dev: `next dev` only. React evals in development to rebuild server stacks,
 *   and the dev overlay injects styles without a nonce. Never set it in a build.
 */
export function contentSecurityPolicy({ nonce, apiOrigin, tileOrigin = null, dev = false }) {
  const tiles = tileOrigin ? ` ${tileOrigin}` : "";
  if (!nonce) throw new Error("A CSP nonce is required.");
  const directives = [
    "default-src 'self'",
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'${dev ? " 'unsafe-eval'" : ""}`,
    // Next.js puts its inline styles through the nonce, so no 'unsafe-inline' outside dev.
    `style-src 'self' ${dev ? "'unsafe-inline'" : `'nonce-${nonce}'`}`,
    "img-src 'self'",
    "font-src 'self'",
    `connect-src 'self' ${apiOrigin}${tiles}`,
    // MapLibre's worker is a same-origin bundled asset (setWorkerUrl), so no blob:.
    "worker-src 'self'",
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ];
  return directives.join("; ");
}

/** Request header that carries the nonce to server components (`(await headers()).get("x-nonce")`). */
export const NONCE_HEADER = "x-nonce";

/**
 * What each app's proxy.ts needs for one request: a fresh nonce and the policy that uses it.
 *
 * `env` must be passed with literal `process.env.NEXT_PUBLIC_*` reads at the call site, so the bundler
 * inlines them at build time, the same way it does for the browser bundle:
 *
 *   nonceCsp({ env: { NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL,
 *                     NEXT_PUBLIC_MAP_STYLE_URL: process.env.NEXT_PUBLIC_MAP_STYLE_URL },
 *              dev: process.env.NODE_ENV === "development" })
 *
 * @param {{ env: Record<string, string | undefined>, dev?: boolean }} options
 */
export function nonceCsp({ env, dev = false }) {
  const nonce = createNonce();
  const policy = contentSecurityPolicy({ nonce, apiOrigin: apiOriginFrom(env), tileOrigin: tileOriginFrom(env), dev });
  return { nonce, policy };
}
