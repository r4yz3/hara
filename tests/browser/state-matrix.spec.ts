// Proves the UI state matrix of spec section 8.7 for every view (docs/qa/ui-state-matrix.md).
// The API is replaced by route interception; no real data or account is involved.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type BrowserContext, type Page, type Route } from "@playwright/test";

const customer = "http://localhost:3000";
const merchant = "http://localhost:3001";
const admin = "http://localhost:3002";

const city = {
  id: "test-city", name: "Synthetic city", slug: "test-city", country_code: "ZZ", currency_code: "PHP",
  timezone: "UTC", status: "draft", created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};
const zone = {
  id: "test-zone", city_id: "test-city", name: "Synthetic zone", slug: "test-zone", status: "draft", version: 3, priority: 0,
  created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};

async function assertAccessible(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

// Fulfilled routes answer even when the context is offline, so "offline" also
// makes every mocked API request fail the way a dead connection does.
const offline = new WeakSet<BrowserContext>();

async function setOffline(context: BrowserContext, value: boolean) {
  if (value) offline.add(context); else offline.delete(context);
  await context.setOffline(value);
}

/** Routes one API path (exact, any method, any query) to a handler. */
function api(page: Page, pathname: string, handler: (route: Route) => unknown) {
  return page.route(
    (url) => url.pathname === pathname,
    (route) => offline.has(page.context()) ? route.abort("internetdisconnected") : void handler(route),
  );
}

/** A response held back until `open()`, for the loading and slow states. */
function gate() {
  let open!: () => void;
  const opened = new Promise<void>((resolve) => { open = resolve; });
  return { opened, open };
}

async function basics(page: Page) {
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await api(page, "/api/v1/auth/csrf", (route) => route.fulfill({ json: { data: { csrf_token: "browser-test" } } }));
}

const method = (route: Route) => route.request().method();

// ---------------------------------------------------------------- shared session pill

for (const [app, base] of [["customer", customer], ["merchant", merchant], ["admin", admin]] as const) {
  test(`${app}: the session pill says so when the server cannot be reached`, async ({ page }) => {
    await page.route("**/api/v1/me", (route) => route.fulfill({ status: 500, json: {} }));
    await page.goto(base);
    await expect(page.getByText("Server unreachable")).toBeVisible();
  });
}

test("merchant: the session pill shows progress, then the sign-in link", async ({ page }) => {
  const held = gate();
  await page.route("**/api/v1/me", async (route) => { await held.opened; await route.fulfill({ status: 401 }); });
  await page.goto(merchant);
  await expect(page.getByText("Checking sign-in…")).toHaveAttribute("aria-busy", "true");
  held.open();
  await expect(page.getByRole("link", { name: "Sign in" })).toBeVisible();
});

// ---------------------------------------------------------------- admin overview

test("admin overview reports the live API row and invents no figures", async ({ page }) => {
  await basics(page);
  await page.goto(admin);
  await expect(page.getByRole("heading", { name: "Setup readiness" })).toBeVisible();
  await expect(page.getByText(/API is (not )?reachable/)).toBeVisible();
  await expect(page.getByText("There are no orders, riders or figures, so none are shown.")).toBeVisible();
  await assertAccessible(page);
});

// ---------------------------------------------------------------- admin /cities

test("cities: progress while slow, then the empty instruction", async ({ page }) => {
  await basics(page);
  const held = gate();
  await api(page, "/api/v1/public/config", (route) => route.fulfill({ json: { data: { currency: "PHP" } } }));
  await api(page, "/api/v1/admin/cities", async (route) => { await held.opened; await route.fulfill({ json: { data: [], meta: { next_cursor: null } } }); });
  await page.goto(`${admin}/cities`);
  await expect(page.getByText("Loading cities…")).toHaveAttribute("aria-busy", "true");
  await expect(page.getByRole("status").filter({ hasText: "Loading cities…" })).toBeVisible();
  held.open();
  await expect(page.getByText("No cities yet")).toBeVisible();
  await expect(page.getByText("Loading cities…")).toHaveCount(0);
});

test("cities: a server error offers a retry", async ({ page }) => {
  await basics(page);
  let healthy = false;
  await api(page, "/api/v1/public/config", (route) => route.fulfill({ json: { data: { currency: "PHP" } } }));
  await api(page, "/api/v1/admin/cities", (route) => healthy
    ? route.fulfill({ json: { data: [city], meta: { next_cursor: null } } })
    : route.fulfill({ status: 500, json: {} }));
  await page.goto(`${admin}/cities`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not complete");
  await assertAccessible(page);
  healthy = true;
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByRole("link", { name: /Synthetic city/ })).toBeVisible();
});

test("cities: forbidden explains and leads back to the overview", async ({ page }) => {
  await basics(page);
  await api(page, "/api/v1/public/config", (route) => route.fulfill({ json: { data: { currency: "PHP" } } }));
  await api(page, "/api/v1/admin/cities", (route) => route.fulfill({ status: 403, json: {} }));
  await page.goto(`${admin}/cities`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("You are not allowed");
  await expect(page.getByRole("button", { name: "Try again" })).toHaveCount(0);
  await assertAccessible(page);
  await page.getByRole("link", { name: "Back to overview" }).click();
  await expect(page).toHaveURL(`${admin}/`);
  await expect(page.getByRole("heading", { name: "Setup readiness" })).toBeVisible();
});

test("cities: a rejected create focuses the first invalid field, then succeeds", async ({ page }) => {
  await basics(page);
  let created = false;
  let posts = 0;
  await api(page, "/api/v1/public/config", (route) => route.fulfill({ json: { data: { currency: "PHP" } } }));
  await api(page, "/api/v1/admin/cities", async (route) => {
    if (method(route) === "POST") {
      posts++;
      if (posts === 1) {
        await route.fulfill({ status: 422, json: { error: { message: "Invalid", fields: { slug: ["That short name is taken."], timezone: ["Choose a timezone."] } } } });
        return;
      }
      created = true;
      await route.fulfill({ status: 201, json: { data: city } });
      return;
    }
    await route.fulfill({ json: { data: created ? [city] : [], meta: { next_cursor: null } } });
  });
  await page.goto(`${admin}/cities`);
  const form = page.getByRole("region", { name: "Create a city" });
  await form.getByLabel("Name", { exact: true }).fill("Synthetic city");
  await form.getByLabel("Country code").fill("ZZ");
  await form.getByLabel("Timezone").selectOption("UTC");
  await form.getByRole("button", { name: "Create draft city" }).click();
  await expect(form.getByLabel("Short name for links")).toBeFocused();
  await expect(form.getByLabel("Short name for links")).toHaveAttribute("aria-invalid", "true");
  await expect(form.getByText("That short name is taken.")).toBeVisible();
  await assertAccessible(page);
  await form.getByRole("button", { name: "Create draft city" }).click();
  await expect(form.getByRole("status")).toContainText("Created Synthetic city as a draft.");
  await expect(page.getByRole("link", { name: /Synthetic city/ })).toBeVisible();
  expect(posts).toBe(2);
});

test("cities: a slow create blocks a second submit", async ({ page }) => {
  await basics(page);
  const held = gate();
  let posts = 0;
  await api(page, "/api/v1/public/config", (route) => route.fulfill({ json: { data: { currency: "PHP" } } }));
  await api(page, "/api/v1/admin/cities", async (route) => {
    if (method(route) === "POST") {
      posts++;
      await held.opened;
      await route.fulfill({ status: 201, json: { data: city } });
      return;
    }
    await route.fulfill({ json: { data: [], meta: { next_cursor: null } } });
  });
  await page.goto(`${admin}/cities`);
  const form = page.getByRole("region", { name: "Create a city" });
  await form.getByLabel("Name", { exact: true }).fill("Synthetic city");
  await form.getByLabel("Country code").fill("ZZ");
  await form.getByLabel("Timezone").selectOption("UTC");
  const submit = form.getByRole("button", { name: /Create draft city|Creating/ });
  await submit.click();
  await expect(submit).toBeDisabled();
  await expect(submit).toHaveText("Creating…");
  await expect(submit).toHaveAttribute("aria-busy", "true");
  await form.getByLabel("Name", { exact: true }).press("Enter");
  held.open();
  await expect(form.getByRole("status")).toContainText("Created Synthetic city as a draft.");
  expect(posts).toBe(1);
});

test("cities: working offline keeps the form and reports the lost connection", async ({ page, context }) => {
  await basics(page);
  await api(page, "/api/v1/public/config", (route) => route.fulfill({ json: { data: { currency: "PHP" } } }));
  await api(page, "/api/v1/admin/cities", (route) => method(route) === "POST"
    ? route.fulfill({ status: 201, json: { data: city } })
    : route.fulfill({ json: { data: [], meta: { next_cursor: null } } }));
  await page.goto(`${admin}/cities`);
  const form = page.getByRole("region", { name: "Create a city" });
  await form.getByLabel("Name", { exact: true }).fill("Synthetic city");
  await form.getByLabel("Country code").fill("ZZ");
  await form.getByLabel("Timezone").selectOption("UTC");
  await setOffline(context, true);
  await form.getByRole("button", { name: "Create draft city" }).click();
  await expect(form.getByRole("alert")).toContainText("could not reach the server");
  await expect(form.getByLabel("Name", { exact: true })).toHaveValue("Synthetic city");
  await expect(form.getByRole("button", { name: "Create draft city" })).toBeEnabled();
  await setOffline(context, false);
  await form.getByRole("button", { name: "Create draft city" }).click();
  await expect(form.getByRole("status")).toContainText("Created Synthetic city as a draft.");
});

// ---------------------------------------------------------------- admin /cities/[id]

async function mockCity(page: Page, options: { zones?: object[]; cityHandler?: (route: Route) => unknown } = {}) {
  await basics(page);
  await api(page, "/api/v1/admin/cities/test-city", options.cityHandler ?? ((route) => route.fulfill({ json: { data: city } })));
  await api(page, "/api/v1/admin/cities/test-city/zones", (route) => method(route) === "GET"
    ? route.fulfill({ json: { data: options.zones ?? [] } })
    : route.fulfill({ status: 422, json: { error: { message: "Invalid", fields: { slug: ["That short name is taken."] } } } }));
  await api(page, "/api/v1/admin/zones/test-zone/exclusions", (route) => route.fulfill({ json: { data: [], meta: { next_cursor: null } } }));
}

test("city: progress while slow, then the empty zone list", async ({ page }) => {
  const held = gate();
  await mockCity(page, { cityHandler: async (route) => { await held.opened; await route.fulfill({ json: { data: city } }); } });
  await page.goto(`${admin}/cities/test-city`);
  await expect(page.getByText("Loading city…")).toHaveAttribute("aria-busy", "true");
  held.open();
  await expect(page.getByText("No delivery zones yet")).toBeVisible();
});

test("city: not found and forbidden lead back; a server error retries", async ({ page }) => {
  let status = 404;
  await mockCity(page, {
    cityHandler: (route) => status === 200 ? route.fulfill({ json: { data: city } }) : route.fulfill({ status, json: {} }),
  });
  await page.goto(`${admin}/cities/test-city`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("does not exist");
  await expect(page.getByRole("link", { name: "Back to overview" })).toBeVisible();

  status = 403;
  await page.reload();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("You are not allowed");
  await expect(page.getByRole("link", { name: "Back to overview" })).toBeVisible();
  await assertAccessible(page);

  status = 500;
  await page.reload();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not complete");
  await assertAccessible(page);
  status = 200;
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByRole("heading", { name: "Synthetic city" })).toBeVisible();
});

test("city: a stale status change prompts a reload of the latest version", async ({ page }) => {
  let current = city;
  await mockCity(page, {
    cityHandler: (route) => {
      if (method(route) === "PATCH") {
        current = { ...city, status: "active", updated_at: "2026-01-02T00:00:00Z" };
        return route.fulfill({ status: 409, json: { error: { message: "This city changed." } } });
      }
      return route.fulfill({ json: { data: current } });
    },
  });
  await page.goto(`${admin}/cities/test-city`);
  await page.getByRole("button", { name: "Activate", exact: true }).click();
  const dialog = page.getByRole("dialog", { name: "Activate Synthetic city?" });
  await dialog.getByRole("button", { name: "Yes, activate" }).click();
  await expect(dialog.getByRole("alert")).toContainText("This city changed.");
  await assertAccessible(page);
  await dialog.getByRole("button", { name: "Reload latest" }).click();
  await expect(dialog).toBeHidden();
  await expect(page.getByRole("button", { name: "Pause", exact: true })).toBeVisible();
});

test("city: a rejected rename focuses the field that was refused", async ({ page }) => {
  await mockCity(page, {
    cityHandler: (route) => method(route) === "PATCH"
      ? route.fulfill({ status: 422, json: { error: { message: "Invalid", fields: { timezone: ["Use an IANA timezone."] } } } })
      : route.fulfill({ json: { data: city } }),
  });
  await page.goto(`${admin}/cities/test-city`);
  await page.locator("#edit-timezone").fill("Nowhere");
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(page.locator("#edit-timezone")).toBeFocused();
  await expect(page.locator("#edit-timezone")).toHaveAttribute("aria-invalid", "true");
  await expect(page.getByText("Use an IANA timezone.")).toBeVisible();
});

test("city: a stale zone change prompts a reload and the new version appears", async ({ page }) => {
  let current = zone;
  await mockCity(page, { zones: [zone] });
  await api(page, "/api/v1/admin/cities/test-city/zones", (route) => route.fulfill({ json: { data: [current] } }));
  await api(page, "/api/v1/admin/zones/test-zone/activate", (route) => {
    current = { ...zone, version: 4, status: "active" };
    return route.fulfill({ status: 409, json: { error: { message: "This zone changed." } } });
  });
  await page.goto(`${admin}/cities/test-city`);
  const row = page.getByRole("listitem").filter({ hasText: "Synthetic zone" });
  await row.getByRole("button", { name: "Activate", exact: true }).click();
  await row.getByRole("button", { name: "Yes, activate" }).click();
  await expect(row.getByRole("alert")).toContainText("This zone changed.");
  await expect(row).toContainText("version 3");
  await row.getByRole("button", { name: "Reload latest" }).click();
  await expect(row).toContainText("version 4");
  await expect(row.getByRole("alert")).toHaveCount(0);
});

test("city: zone creation focuses the first invalid field, and a slow save cannot be repeated", async ({ page }) => {
  await mockCity(page);
  await page.goto(`${admin}/cities/test-city`);
  const form = page.getByRole("region", { name: "Add a delivery zone" });
  await form.getByLabel("Name", { exact: true }).fill("Synthetic zone");
  await form.getByLabel("Short name for links").fill("test-zone");

  await form.getByLabel("Shape (GeoJSON Polygon or MultiPolygon)").fill("{");
  await form.getByRole("button", { name: "Add draft zone" }).click();
  await expect(form.getByLabel("Shape (GeoJSON Polygon or MultiPolygon)")).toBeFocused();
  await expect(form.getByText("This is not valid JSON.")).toBeVisible();

  await form.getByLabel("Shape (GeoJSON Polygon or MultiPolygon)").fill('{"type":"Polygon","coordinates":[]}');
  await form.getByRole("button", { name: "Add draft zone" }).click();
  await expect(form.getByLabel("Short name for links")).toBeFocused();
  await expect(form.getByLabel("Short name for links")).toHaveAttribute("aria-invalid", "true");
  await expect(form.getByText("That short name is taken.")).toBeVisible();
  await assertAccessible(page);
});

test("city: a slow zone save shows progress and blocks a second submit", async ({ page }) => {
  await mockCity(page);
  const held = gate();
  let posts = 0;
  await api(page, "/api/v1/admin/cities/test-city/zones", async (route) => {
    if (method(route) === "GET") {
      await route.fulfill({ json: { data: [] } });
      return;
    }
    posts++;
    await held.opened;
    await route.fulfill({ status: 201, json: { data: zone } });
  });
  await page.goto(`${admin}/cities/test-city`);
  const form = page.getByRole("region", { name: "Add a delivery zone" });
  await form.getByLabel("Name", { exact: true }).fill("Synthetic zone");
  await form.getByLabel("Short name for links").fill("test-zone");
  await form.getByLabel("Shape (GeoJSON Polygon or MultiPolygon)").fill('{"type":"Polygon","coordinates":[]}');
  const submit = form.getByRole("button", { name: /Add draft zone|Checking shape/ });
  await submit.click();
  await expect(submit).toBeDisabled();
  await expect(submit).toHaveText("Checking shape…");
  await form.getByLabel("Name", { exact: true }).press("Enter");
  held.open();
  await expect(form.getByRole("status")).toContainText("Added Synthetic zone as a draft.");
  expect(posts).toBe(1);
});

test("city: an action while offline reports it and can be repeated", async ({ page, context }) => {
  await mockCity(page, { zones: [zone] });
  await api(page, "/api/v1/admin/zones/test-zone/activate", (route) =>
    route.fulfill({ json: { data: { ...zone, version: 4, status: "active" } } }));
  await page.goto(`${admin}/cities/test-city`);
  const row = page.getByRole("listitem").filter({ hasText: "Synthetic zone" });
  await row.getByRole("button", { name: "Activate", exact: true }).click();
  // Keyboard activation: the dev-only Next.js overlay badge can sit over buttons on small screens.
  const confirm = row.getByRole("button", { name: "Yes, activate" });
  await setOffline(context, true);
  await confirm.press("Enter");
  await expect(row.getByRole("alert")).toContainText("could not reach the server");
  await expect(confirm).toBeEnabled();
  await setOffline(context, false);
  const sent = page.waitForRequest((request) => request.url().endsWith("/zones/test-zone/activate") && request.method() === "POST");
  await confirm.press("Enter");
  await sent;
  await expect(row.getByRole("alert")).toHaveCount(0);
});

// ---------------------------------------------------------------- zone exclusions panel

test("exclusions: a failed load offers Retry, and offline is reported", async ({ page, context }) => {
  await mockCity(page, { zones: [zone] });
  let healthy = false;
  await api(page, "/api/v1/admin/zones/test-zone/exclusions", (route) => healthy
    ? route.fulfill({ json: { data: [], meta: { next_cursor: null } } })
    : route.fulfill({ status: 500, json: {} }));
  await page.goto(`${admin}/cities/test-city`);
  const toggle = page.getByRole("button", { name: "Coverage exclusions" });
  await toggle.click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not complete");
  await assertAccessible(page);
  await setOffline(context, true);
  await page.getByRole("button", { name: "Retry" }).click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not reach the server");
  await setOffline(context, false);
  healthy = true;
  await page.getByRole("button", { name: "Retry" }).click();
  await expect(page.getByText("No coverage exclusions.")).toBeVisible();
});

test("exclusions: a refused or malformed shape focuses the shape field; a slow add is not repeated", async ({ page }) => {
  await mockCity(page, { zones: [zone] });
  const held = gate();
  let posts = 0;
  await api(page, "/api/v1/admin/zones/test-zone/exclusions", async (route) => {
    if (method(route) === "GET") {
      await route.fulfill({ json: { data: [], meta: { next_cursor: null } } });
      return;
    }
    posts++;
    await held.opened;
    await route.fulfill({ status: 422, json: { error: { message: "Invalid", fields: { geometry: ["The shape is not a polygon."] } } } });
  });
  await page.goto(`${admin}/cities/test-city`);
  await page.getByRole("button", { name: "Coverage exclusions" }).click();
  const form = page.getByRole("form", { name: "Add coverage exclusion" });
  await form.getByLabel("Exclusion reason").fill("Synthetic reason");

  await form.getByLabel("Exclusion shape (GeoJSON)").fill("{");
  await form.getByRole("button", { name: "Add exclusion" }).click();
  await expect(form.getByLabel("Exclusion shape (GeoJSON)")).toBeFocused();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("valid GeoJSON");

  await form.getByLabel("Exclusion shape (GeoJSON)").fill('{"type":"Polygon","coordinates":[]}');
  await form.getByLabel("Exclusion reason").focus();
  await form.getByRole("button", { name: "Add exclusion" }).click();
  await expect(page.getByRole("status")).toContainText("Working…");
  await expect(form.getByRole("button", { name: "Add exclusion" })).toBeDisabled();
  held.open();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("The shape is not a polygon.");
  await expect(form.getByLabel("Exclusion shape (GeoJSON)")).toBeFocused();
  expect(posts).toBe(1);
});

// ---------------------------------------------------------------- admin /audit

const entry = {
  id: "entry-1", actor_user_id: null, action: "city.created", subject_type: "city", subject_id: null, city_id: null,
  before: null, after: null, correlation_id: null, created_at: "2026-01-01T00:00:00Z",
};

test("audit: progress while slow, then the empty instruction", async ({ page }) => {
  await basics(page);
  const held = gate();
  await api(page, "/api/v1/admin/audit-logs", async (route) => { await held.opened; await route.fulfill({ json: { data: [], meta: { next_cursor: null } } }); });
  await page.goto(`${admin}/audit`);
  await expect(page.getByText("Loading entries…")).toHaveAttribute("aria-busy", "true");
  held.open();
  await expect(page.getByText("No entries")).toBeVisible();
});

test("audit: a server error retries and forbidden leads back", async ({ page }) => {
  await basics(page);
  let status = 500;
  await api(page, "/api/v1/admin/audit-logs", (route) => status === 200
    ? route.fulfill({ json: { data: [entry], meta: { next_cursor: null } } })
    : route.fulfill({ status, json: {} }));
  await page.goto(`${admin}/audit`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not complete");
  await assertAccessible(page);
  status = 200;
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByText("city.created", { exact: true })).toBeVisible();

  status = 403;
  await page.reload();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("You are not allowed");
  await assertAccessible(page);
  await page.getByRole("link", { name: "Back to overview" }).click();
  await expect(page).toHaveURL(`${admin}/`);
});

test("audit: loading older entries offline keeps the list and can be repeated", async ({ page, context }) => {
  await basics(page);
  await api(page, "/api/v1/admin/audit-logs", (route) => {
    const more = new URL(route.request().url()).searchParams.has("cursor");
    return route.fulfill({ json: { data: [more ? { ...entry, id: "entry-2", action: "zone.created" } : entry], meta: { next_cursor: more ? null : "next" } } });
  });
  await page.goto(`${admin}/audit`);
  await expect(page.getByText("city.created", { exact: true })).toBeVisible();
  // Keyboard activation: on a phone the dev-only Next.js overlay badge can sit over this button.
  const older = page.getByRole("button", { name: "Load older entries" });
  await setOffline(context, true);
  await older.press("Enter");
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not reach the server");
  await expect(page.getByText("city.created", { exact: true })).toBeVisible();
  await setOffline(context, false);
  await older.press("Enter");
  await expect(page.getByText("zone.created", { exact: true })).toBeVisible();
  await expect(page.getByText("End of the log.")).toBeVisible();
});

test("audit: a slow filter shows progress and ignores a second Apply", async ({ page }) => {
  await basics(page);
  const held = gate();
  let filtered = 0;
  await api(page, "/api/v1/admin/audit-logs", async (route) => {
    if (new URL(route.request().url()).searchParams.has("action")) {
      filtered++;
      await held.opened;
    }
    await route.fulfill({ json: { data: [entry], meta: { next_cursor: null } } });
  });
  await page.goto(`${admin}/audit`);
  await expect(page.getByText("city.created", { exact: true })).toBeVisible();
  await page.getByLabel("Action").fill("city.created");
  const apply = page.getByRole("button", { name: "Apply" });
  await apply.click();
  await expect(page.getByText("Loading entries…")).toBeVisible();
  await expect(apply).toHaveAttribute("aria-disabled", "true");
  await page.getByLabel("Action").press("Enter");
  held.open();
  await expect(page.getByText("city.created", { exact: true })).toBeVisible();
  expect(filtered).toBe(1);
});

// ---------------------------------------------------------------- admin /settings

const counts = { draft: 0, active: 0, paused: 0, archived: 0 };
const systemStatus = {
  environment: "testing", debug: false, checks: { database: true, redis: true }, postgis: true,
  cities: counts, zones: counts, features: {}, map_style_configured: false, geometry_caps_approved: false,
};

test("settings: progress while the status is slow", async ({ page }) => {
  await basics(page);
  const held = gate();
  await api(page, "/api/v1/admin/system/status", async (route) => { await held.opened; await route.fulfill({ json: { data: systemStatus } }); });
  await page.goto(`${admin}/settings`);
  await expect(page.getByRole("status").filter({ hasText: "Loading system status…" })).toBeVisible();
  held.open();
  await expect(page.getByRole("heading", { name: "Service checks" })).toBeVisible();
});

test("settings: forbidden is accessible and leads back; offline refresh reports and recovers", async ({ page, context }) => {
  await basics(page);
  let forbidden = true;
  await api(page, "/api/v1/admin/system/status", (route) => forbidden
    ? route.fulfill({ status: 403, json: {} })
    : route.fulfill({ json: { data: systemStatus } }));
  await page.goto(`${admin}/settings`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("You are not allowed");
  await assertAccessible(page);
  await expect(page.getByRole("link", { name: "Back to overview" })).toBeVisible();

  forbidden = false;
  await page.reload();
  await expect(page.getByRole("heading", { name: "Service checks" })).toBeVisible();
  await setOffline(context, true);
  await page.getByRole("button", { name: "Refresh status" }).click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not reach the server");
  await assertAccessible(page);
  await setOffline(context, false);
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByRole("heading", { name: "Service checks" })).toBeVisible();
});

// ---------------------------------------------------------------- customer /location

const outside = { data: { serviceable: false, reason: "OUTSIDE_COVERAGE", city: null, zone: null } };

async function mockLocation(page: Page, handler: (route: Route) => unknown) {
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await api(page, "/api/v1/auth/csrf", (route) => route.fulfill({ json: { data: { csrf_token: "browser-test" } } }));
  await api(page, "/api/v1/locations/serviceability", handler);
  await page.goto(`${customer}/location`);
}

test("location: an empty or malformed position focuses the first invalid field", async ({ page }) => {
  await mockLocation(page, (route) => route.fulfill({ json: outside }));
  const check = page.getByRole("button", { name: "Check", exact: true });
  await check.click();
  await expect(page.getByLabel("Latitude")).toBeFocused();
  await expect(page.getByLabel("Latitude")).toHaveAttribute("aria-invalid", "true");
  await expect(page.getByText("Enter the latitude.")).toBeVisible();
  await page.getByLabel("Latitude").fill("1");
  await page.getByLabel("Longitude").fill("abc");
  await check.click();
  await expect(page.getByLabel("Longitude")).toBeFocused();
  await expect(page.getByText(/longitude must be a plain number/)).toBeVisible();
  await assertAccessible(page);
});

test("location: a refused position from the server focuses the field it names", async ({ page }) => {
  await mockLocation(page, (route) =>
    route.fulfill({ status: 422, json: { error: { message: "Invalid", fields: { longitude: ["Out of range."] } } } }));
  await page.getByLabel("Latitude").fill("1");
  await page.getByLabel("Longitude").fill("2");
  await page.getByRole("button", { name: "Check", exact: true }).click();
  await expect(page.getByLabel("Longitude")).toBeFocused();
  await expect(page.getByText("Out of range.")).toBeVisible();
  await expect(page.getByLabel("Longitude")).toHaveAttribute("aria-invalid", "true");
});

test("location: a slow check shows progress and cannot be submitted twice", async ({ page }) => {
  const held = gate();
  let posts = 0;
  await mockLocation(page, async (route) => { posts++; await held.opened; await route.fulfill({ json: outside }); });
  await page.getByLabel("Latitude").fill("1");
  await page.getByLabel("Longitude").fill("2");
  const submit = page.getByRole("button", { name: /^(Check|Checking…)$/ });
  await submit.click();
  await expect(submit).toBeDisabled();
  await expect(submit).toHaveText("Checking…");
  await expect(submit).toHaveAttribute("aria-busy", "true");
  await page.getByLabel("Longitude").press("Enter");
  held.open();
  await expect(page.getByText(/outside our delivery areas/i)).toBeVisible();
  expect(posts).toBe(1);
});

test("location: offline reports the lost connection, keeps the numbers and recovers", async ({ page, context }) => {
  await mockLocation(page, (route) => route.fulfill({ json: outside }));
  await page.getByLabel("Latitude").fill("1");
  await page.getByLabel("Longitude").fill("2");
  await setOffline(context, true);
  await page.getByRole("button", { name: "Check", exact: true }).click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not reach the server");
  await expect(page.getByLabel("Latitude")).toHaveValue("1");
  await assertAccessible(page);
  await setOffline(context, false);
  await page.getByRole("button", { name: "Check", exact: true }).click();
  await expect(page.getByText(/outside our delivery areas/i)).toBeVisible();
});

test("exclusions: forbidden is stated in place and the page navigation stays", async ({ page }) => {
  await mockCity(page, { zones: [zone] });
  await api(page, "/api/v1/admin/zones/test-zone/exclusions", (route) => route.fulfill({ status: 403, json: {} }));
  await page.goto(`${admin}/cities/test-city`);
  await page.getByRole("button", { name: "Coverage exclusions" }).click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("You are not allowed");
  await expect(page.getByRole("link", { name: "← All cities" })).toBeVisible();
  await assertAccessible(page);
});

test("exclusions: an added exclusion is listed, and a removal needs confirmation", async ({ page }) => {
  await mockCity(page, { zones: [zone] });
  const stored: object[] = [];
  await api(page, "/api/v1/admin/zones/test-zone/exclusions", async (route) => {
    if (method(route) === "POST") {
      stored.push({ id: "exclusion-1", reason: "Synthetic reason", active_from: null, active_until: null });
      await route.fulfill({ status: 201, json: { data: stored[0] } });
      return;
    }
    await route.fulfill({ json: { data: stored, meta: { next_cursor: null } } });
  });
  await api(page, "/api/v1/admin/exclusions/exclusion-1", (route) => {
    stored.length = 0;
    return route.fulfill({ status: 204 });
  });
  await page.goto(`${admin}/cities/test-city`);
  await page.getByRole("button", { name: "Coverage exclusions" }).click();
  const form = page.getByRole("form", { name: "Add coverage exclusion" });
  await form.getByLabel("Exclusion reason").fill("Synthetic reason");
  await form.getByLabel("Exclusion shape (GeoJSON)").fill('{"type":"Polygon","coordinates":[]}');
  await form.getByRole("button", { name: "Add exclusion" }).click();
  await expect(page.getByText("Synthetic reason", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Remove exclusion" }).click();
  await page.getByRole("button", { name: "Confirm removal" }).click();
  await expect(page.getByText("No coverage exclusions.")).toBeVisible();
});
