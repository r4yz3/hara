// Evidence screenshots (backlog U-09). Opt-in: writes files only when SCREENSHOTS=1, so normal runs stay clean.
//   $env:SCREENSHOTS=1; pnpm exec playwright test tests/browser/screenshots.spec.ts --workers=1
// Output: docs/qa/evidence/screens/<app>/<screen>-<width>.png (phone project also writes 360).
// The API is replaced by route interception, as in state-matrix.spec.ts. Anything with a name on screen is
// labelled "Synthetic"; no password, token or real account is involved.
import { test, type Page, type Route } from "@playwright/test";
import { mkdirSync } from "node:fs";
import path from "node:path";

const bases = { customer: "http://localhost:3000", merchant: "http://localhost:3001", admin: "http://localhost:3002" } as const;
type App = keyof typeof bases;

const city = {
  id: "test-city", name: "Synthetic city", slug: "test-city", country_code: "ZZ", currency_code: "PHP",
  timezone: "UTC", status: "draft", created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};
const zone = {
  id: "test-zone", city_id: "test-city", name: "Synthetic zone", slug: "test-zone", status: "draft", version: 3, priority: 0,
  created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};
const entry = {
  id: "entry-1", actor_user_id: null, action: "city.created", subject_type: "city", subject_id: null, city_id: null,
  before: null, after: null, correlation_id: null, created_at: "2026-01-01T00:00:00Z",
};
const counts = { draft: 0, active: 0, paused: 0, archived: 0 };
const systemStatus = {
  environment: "testing", debug: false, checks: { database: true, redis: true }, postgis: true,
  cities: counts, zones: counts, features: {}, map_style_configured: false, geometry_caps_approved: false,
};
const staff = {
  data: { id: "01JTESTSTAFF000000000000000", name: "Test Staff", email: "staff@example.test", email_verified: true, mfa_enabled: true, surface: "admin", grants: [] },
  meta: { request_id: "test" },
};

/** Routes one API path (any query string) to a handler. */
const api = (page: Page, pathname: string, handler: (route: Route) => unknown) =>
  page.route((url) => url.pathname === pathname, (route) => void handler(route));

const list = (data: unknown[]) => ({ json: { data, meta: { next_cursor: null } } });

async function mock(page: Page, signedIn: boolean) {
  await page.route("**/api/v1/me", (route) => (signedIn ? route.fulfill({ json: staff }) : route.fulfill({ status: 401 })));
  await page.route("**/api/v1/auth/csrf", (route) => route.fulfill({ json: { data: { csrf_token: "browser-test" } } }));
  await page.route("**/api/v1/public/config", (route) => route.fulfill({ json: { data: { currency: "PHP" } } }));
}

type Shot = { app: App; name: string; url: string; setup?: (page: Page) => Promise<void>; signedIn?: boolean; wait?: string };

const shots: Shot[] = [
  { app: "customer", name: "home", url: "/" },
  { app: "customer", name: "login", url: "/login" },
  { app: "customer", name: "forgot-password", url: "/forgot-password" },
  { app: "customer", name: "reset-password", url: "/reset-password" },
  { app: "customer", name: "location", url: "/location" },
  { app: "merchant", name: "home", url: "/" },
  { app: "merchant", name: "login", url: "/login" },
  { app: "merchant", name: "forgot-password", url: "/forgot-password" },
  { app: "merchant", name: "reset-password", url: "/reset-password" },
  { app: "admin", name: "overview", url: "/" },
  { app: "admin", name: "login", url: "/login" },
  { app: "admin", name: "forgot-password", url: "/forgot-password" },
  { app: "admin", name: "reset-password", url: "/reset-password" },
  { app: "admin", name: "security", url: "/security", signedIn: true, wait: "Two-step sign-in is on" },
  {
    app: "admin", name: "cities-empty", url: "/cities", wait: "No cities yet",
    setup: (page) => api(page, "/api/v1/admin/cities", (route) => route.fulfill(list([]))),
  },
  {
    app: "admin", name: "cities-list", url: "/cities", wait: "Synthetic city",
    setup: (page) => api(page, "/api/v1/admin/cities", (route) => route.fulfill(list([city]))),
  },
  {
    app: "admin", name: "city-detail", url: "/cities/test-city", wait: "Synthetic zone",
    setup: async (page) => {
      await api(page, "/api/v1/admin/cities/test-city", (route) => route.fulfill({ json: { data: city } }));
      await api(page, "/api/v1/admin/cities/test-city/zones", (route) => route.fulfill({ json: { data: [zone] } }));
      await api(page, "/api/v1/admin/zones/test-zone/exclusions", (route) => route.fulfill(list([])));
    },
  },
  {
    app: "admin", name: "audit", url: "/audit", wait: "city.created",
    setup: (page) => api(page, "/api/v1/admin/audit-logs", (route) => route.fulfill(list([entry]))),
  },
  {
    app: "admin", name: "access", url: "/access", wait: "Synthetic dispatcher",
    setup: async (page) => {
      const grant = { id: "grant-1", role: "dispatcher", role_label: "Dispatcher", scope_type: "city", scope_id: "test-city", city_name: "Synthetic city", granted_at: "2026-02-03T00:00:00Z", can_revoke: true };
      const person = { id: "person-1", name: "Synthetic dispatcher", email: "dispatcher@example.test", email_masked: false, status: "active", mfa_enrolled: false, is_self: false, grants: [grant] };
      await api(page, "/api/v1/admin/users", (route) => route.fulfill(list([person])));
      await api(page, "/api/v1/admin/roles", (route) => route.fulfill(list([{ slug: "dispatcher", label: "Dispatcher", scope_types: ["city"], global: false, all_cities: false, city_ids: ["test-city"] }])));
      await api(page, "/api/v1/admin/cities", (route) => route.fulfill(list([city])));
    },
  },
  {
    app: "admin", name: "settings", url: "/settings", wait: "Service checks",
    setup: (page) => api(page, "/api/v1/admin/system/status", (route) => route.fulfill({ json: { data: systemStatus } })),
  },
];

test.skip(process.env.SCREENSHOTS !== "1", "opt-in: set SCREENSHOTS=1 to write evidence images");

for (const shot of shots) {
  test(`screenshot ${shot.app}/${shot.name}`, async ({ page }) => {
    const viewport = page.viewportSize()!;
    const widths = viewport.width === 375 ? [375, 360] : [viewport.width];
    await mock(page, shot.signedIn ?? false);
    await shot.setup?.(page);
    const dir = path.join("docs", "qa", "evidence", "screens", shot.app);
    mkdirSync(dir, { recursive: true });
    for (const width of widths) {
      await page.setViewportSize({ width, height: viewport.height });
      await page.goto(bases[shot.app] + shot.url);
      if (shot.wait) await page.getByText(shot.wait).first().waitFor();
      await page.waitForLoadState("networkidle");
      // The dev-server badge ("Cache disabled") is not part of the app; keep it out of the evidence.
      await page.addStyleTag({ content: "nextjs-portal { display: none !important; }" });
      await page.screenshot({ path: path.join(dir, `${shot.name}-${width}.png`) });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      if (overflow > 0) console.log(`OVERFLOW ${shot.app}/${shot.name} @${width}: ${overflow}px`);
    }
  });
}
