import { NextResponse, type NextRequest } from "next/server";
import { NONCE_HEADER, nonceCsp } from "@food-delivery/config/security-headers";

// Per-request nonce CSP (backlog E-02, ADR-020). Next.js reads the nonce from the request's
// Content-Security-Policy header and puts it on its own inline scripts and styles.
// The NEXT_PUBLIC_* reads are literal on purpose, so they are inlined at build time.
export function proxy(request: NextRequest) {
  const { nonce, policy } = nonceCsp({
    env: {
      NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL,
      NEXT_PUBLIC_MAP_STYLE_URL: process.env.NEXT_PUBLIC_MAP_STYLE_URL,
    },
    dev: process.env.NODE_ENV === "development",
  });
  const requestHeaders = new Headers(request.headers);
  requestHeaders.set(NONCE_HEADER, nonce);
  requestHeaders.set("Content-Security-Policy", policy);
  const response = NextResponse.next({ request: { headers: requestHeaders } });
  response.headers.set("Content-Security-Policy", policy);
  return response;
}

export const config = {
  matcher: [
    // Every request except the three static asset classes, anchored so that look-alike paths (`/favicon.icoX`,
    // `/_next/staticX`) still pass through here. Prefetches are included on purpose: they must carry the policy too (ADR-020).
    // Keep in step with PROXY_SKIPPED_SOURCES in packages/config/security-headers.mjs (a unit test compares them).
    "/((?!_next/static/|_next/image$|favicon\\.ico$).*)",
  ],
};
