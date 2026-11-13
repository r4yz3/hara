// Response headers for the three web apps (backlog E-02).
//
// The CSP here is a baseline: it stops framing, plugins and base-tag or form
// hijacking, none of which needs a nonce. It does not yet restrict scripts,
// styles or connections. Next.js needs a per-request nonce for that, which
// means dynamic rendering, and that change is tracked separately.

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
