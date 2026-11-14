// Content-Security-Policy of the three web apps (backlog E-02, ADR-020).
// Runs against the production builds on ports 4100 to 4102 (playwright.config.ts), because the development
// server needs a looser policy ('unsafe-eval') and so proves nothing about what ships.
// The API is replaced by route interception; the map style is a synthetic, background-only style.
import { expect, test, type Page } from "@playwright/test";

const apps = {
  customer: "http://localhost:4100",
  merchant: "http://localhost:4101",
  admin: "http://localhost:4102",
} as const;
const api = "http://localhost:8000";
const tiles = "https://styles.example.test";

const city = {
  id: "test-city", name: "Synthetic city", slug: "test-city", country_code: "ZZ", currency_code: "PHP",
  timezone: "UTC", status: "draft", created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};
const zone = {
  id: "test-zone", city_id: "test-city", name: "Synthetic zone", slug: "test-zone", status: "draft", version: 3, priority: 0,
  geometry: { type: "Polygon", coordinates: [[[10, 20], [12, 20], [12, 23], [10, 23], [10, 20]]] },
  created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};
// 1x1 transparent PNG, served as the style's sprite sheet to prove sprite loading needs no img-src entry.
const png = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAHnOcRQAAAABJRU5ErkJggg==", "base64");

async function mock(page: Page) {
  const answer = (pathname: string, body: unknown) =>
    page.route((url) => url.pathname === pathname, (route) => route.fulfill({ json: body }));
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await answer("/api/v1/auth/csrf", { data: { csrf_token: "browser-test" } });
  await answer("/api/v1/public/config", { data: { currency: "PHP", map_style_url: `${tiles}/style.json` } });
  await answer("/api/v1/admin/cities/test-city", { data: city });
  await answer("/api/v1/admin/cities/test-city/zones", { data: [zone] });
  await answer("/api/v1/admin/zones/test-zone", { data: zone });
  await answer("/api/v1/admin/zones/test-zone/exclusions", { data: [], meta: { next_cursor: null } });
  await page.route("**/api/v1/locations/serviceability", (route) =>
    route.fulfill({ json: { data: { serviceable: false, reason: "OUTSIDE_COVERAGE", city: null, zone: null } } }),
  );
  await page.route(`${tiles}/**`, (route) => {
    const { pathname } = new URL(route.request().url());
    const cors = { "access-control-allow-origin": "*" };
    if (pathname === "/style.json") {
      return route.fulfill({
        contentType: "application/json",
        headers: cors,
        body: JSON.stringify({
          version: 8, sprite: `${tiles}/sprite`, sources: {},
          layers: [{ id: "background", type: "background", paint: { "background-color": "#dddddd" } }],
        }),
      });
    }
    if (pathname === "/sprite.json") return route.fulfill({ json: {}, headers: cors });
    if (pathname === "/sprite.png") return route.fulfill({ body: png, contentType: "image/png", headers: cors });
    return route.abort();
  });
}

/** Records every CSP violation the browser reports, from before the page's own scripts run. */
async function watch(page: Page) {
  const consoleErrors: string[] = [];
  page.on("console", (message) => {
    if (/content security policy|refused to (load|execute|apply|connect|create)/i.test(message.text())) consoleErrors.push(message.text());
  });
  await page.addInitScript(() => {
    const seen: string[] = [];
    (window as unknown as { __csp: string[] }).__csp = seen;
    document.addEventListener("securitypolicyviolation", (event) => {
      seen.push(`${event.effectiveDirective} ${event.blockedURI || "inline"} ${event.sample}`);
    });
  });
  return {
    async violations() {
      const fromPage = await page.evaluate(() => (window as unknown as { __csp?: string[] }).__csp ?? []);
      return [...fromPage, ...consoleErrors];
    },
  };
}

const heading = (p: Page) => expect(p.getByRole("heading", { level: 1 }).first()).toBeVisible();
const signIn = (p: Page) => expect(p.getByRole("button", { name: "Sign in" })).toBeVisible();
const errorScreen = (p: Page) => expect(p.getByRole("heading", { level: 1, name: "Something went wrong" })).toBeVisible();

const pages: { app: keyof typeof apps; path: string; ready: (page: Page) => Promise<void> }[] = [
  { app: "customer", path: "/", ready: heading },
  { app: "customer", path: "/login", ready: signIn },
  { app: "customer", path: "/forgot-password", ready: heading },
  {
    app: "customer", path: "/location",
    ready: async (p) => {
      await p.getByLabel("Latitude").fill("1.5");
      await p.getByLabel("Longitude").fill("2.5");
      await p.getByRole("button", { name: "Check", exact: true }).click();
      await expect(p.getByText(/outside our delivery areas/i)).toBeVisible();
    },
  },
  { app: "customer", path: "/this-page-does-not-exist", ready: heading },
  { app: "customer", path: "/test-faults/layout", ready: errorScreen },
  { app: "merchant", path: "/", ready: heading },
  { app: "merchant", path: "/login", ready: signIn },
  { app: "merchant", path: "/test-faults/layout", ready: errorScreen },
  { app: "admin", path: "/", ready: heading },
  { app: "admin", path: "/login", ready: signIn },
  {
    app: "admin", path: "/cities/test-city",
    ready: async (p) => {
      const map = p.getByRole("region", { name: "Add a delivery zone" }).getByTestId("map-view");
      await expect(map).toHaveAttribute("data-map-state", "ready");
      await expect(map.locator("canvas.maplibregl-canvas")).toHaveCount(1);
    },
  },
  { app: "admin", path: "/test-faults/layout", ready: errorScreen },
];

for (const { app, path, ready } of pages) {
  test(`csp: ${app} ${path} loads with no violations`, async ({ page }) => {
    await mock(page);
    const watcher = await watch(page);
    const response = await page.goto(`${apps[app]}${path}`);
    await ready(page);
    // Settle: lazy chunks, hydration and the map's worker have all had their chance to be blocked.
    await page.waitForLoadState("networkidle");
    expect(await watcher.violations()).toEqual([]);

    const csp = response?.headers()["content-security-policy"] ?? "";
    expect(csp).not.toBe("");
    const nonce = /'nonce-([^']+)'/.exec(csp)?.[1];
    expect(nonce).toBeTruthy();
    // Every script and style tag the server rendered carries this request's nonce.
    const html = await response!.text();
    const bare = [...html.matchAll(/<(script|style)\b([^>]*)>/g)].filter((m) => !m[2].includes(`nonce="${nonce}"`));
    expect(bare.map((m) => m[0])).toEqual([]);
  });
}

test("csp: the header carries exactly the expected directives, and every request gets a new nonce", async ({ request }) => {
  const nonces = new Set<string>();
  for (const base of Object.values(apps)) {
    const csp = (await request.get(`${base}/login`)).headers()["content-security-policy"];
    nonces.add(/'nonce-([^']+)'/.exec(csp)![1]);
    const directive = (name: string) => csp.split("; ").find((d) => d.startsWith(`${name} `))?.split(" ").slice(1);
    expect(directive("default-src")).toEqual(["'self'"]);
    expect(directive("script-src")).toEqual(["'self'", expect.stringMatching(/^'nonce-.+'$/), "'strict-dynamic'"]);
    expect(directive("style-src")).toEqual(["'self'", expect.stringMatching(/^'nonce-.+'$/)]);
    expect(directive("connect-src")).toEqual(["'self'", api, tiles]);
    expect(directive("worker-src")).toEqual(["'self'"]);
    expect(directive("object-src")).toEqual(["'none'"]);
    expect(directive("base-uri")).toEqual(["'none'"]);
    expect(directive("form-action")).toEqual(["'self'"]);
    expect(directive("frame-ancestors")).toEqual(["'none'"]);
    expect(csp).not.toMatch(/unsafe-|\*/);
  }
  expect(nonces.size).toBe(3);
  const again = (await request.get(`${apps.admin}/login`)).headers()["content-security-policy"];
  expect(nonces.has(/'nonce-([^']+)'/.exec(again)![1])).toBe(false);
});

test("csp: the baseline headers are still sent with it", async ({ request }) => {
  for (const base of Object.values(apps)) {
    const headers = (await request.get(`${base}/login`)).headers();
    expect(headers["x-frame-options"]).toBe("DENY");
    expect(headers["x-content-type-options"]).toBe("nosniff");
    expect(headers["referrer-policy"]).toBe("strict-origin-when-cross-origin");
    expect(headers["permissions-policy"]).toContain("camera=()");
    expect(headers["x-powered-by"]).toBeUndefined();
    // Pages are rendered per request, so a shared cache must never keep one.
    expect(headers["cache-control"]).toMatch(/no-store|private/);
  }
});

test("csp: the policy is enforced, not just present (an inline handler and a foreign connection are blocked)", async ({ page }) => {
  await mock(page);
  const watcher = await watch(page);
  await page.goto(`${apps.customer}/login`);
  await page.waitForLoadState("networkidle");
  const outcome = await page.evaluate(async () => {
    (window as unknown as { __ran?: boolean }).__ran = false;
    // An inline event handler is what an injected-markup attack uses; no script-src source allows it.
    const holder = document.createElement("div");
    holder.innerHTML = '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" onload="window.__ran = true" onerror="window.__ran = true">';
    document.body.append(holder);
    await new Promise((resolve) => setTimeout(resolve, 300));
    const blockedFetch = await fetch("https://not-allowed.example.test/").then(() => "allowed", () => "blocked");
    return { ran: (window as unknown as { __ran: boolean }).__ran, blockedFetch };
  });
  expect(outcome).toEqual({ ran: false, blockedFetch: "blocked" });
  const seen = (await watcher.violations()).join("\n");
  expect(seen).toMatch(/script-src/);
  expect(seen).toMatch(/connect-src/);
});
