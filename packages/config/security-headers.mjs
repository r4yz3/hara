// Response headers for the three web apps (backlog E-02).
//
// Two layers of CSP are sent. The static baseline below (next.config headers)
// stops framing, plugins and base-tag or form hijacking. The per-request policy
// from contentSecurityPolicy() (each app's proxy.ts) adds a nonce for scripts
// and styles and limits connections to the API origin. A browser enforces
// every CSP header it receives, so the two intersect and neither loosens the other.

const BASE_CSP = "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";

/**
 * @param {{ geolocation?: boolean }} [options] geolocation: allow this app's
 *   own pages to ask for the device position (the customer app only).
 */
export function securityHeaders({ geolocation = false } = {}) {
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
        { key: "Content-Security-Policy", value: BASE_CSP },
      ],
    },
  ];
}

/** Where the browser may call the API; the same variable the session client reads. */
export function apiOriginFrom(env = process.env) {
  const raw = env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
  return new URL(raw).origin;
}

/** 128 random bits, base64. Must be fresh for every request. */
export function createNonce() {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return btoa(String.fromCharCode(...bytes));
}

/**
 * The per-request, nonce-based policy (backlog E-02).
 *
 * @param {{ nonce: string, apiOrigin: string, dev?: boolean }} options
 *   dev: `next dev` only. React evals in development to rebuild server stacks,
 *   and the dev overlay injects styles without a nonce. Never set it in a build.
 */
export function contentSecurityPolicy({ nonce, apiOrigin, dev = false }) {
  if (!nonce) throw new Error("A CSP nonce is required.");
  const directives = [
    "default-src 'self'",
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'${dev ? " 'unsafe-eval'" : ""}`,
    // Next.js puts its inline styles through the nonce, so no 'unsafe-inline' outside dev.
    `style-src 'self' ${dev ? "'unsafe-inline'" : `'nonce-${nonce}'`}`,
    "img-src 'self' blob: data:",
    "font-src 'self'",
    `connect-src 'self' ${apiOrigin}`,
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ];
  return directives.join("; ");
}
