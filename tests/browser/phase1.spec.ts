import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

const customer = "http://localhost:3000";
const merchant = "http://localhost:3001";
const admin = "http://localhost:3002";

test("admin settings retry a failed status request and show deployment checks", async ({ page }, testInfo) => {
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  const counts = { draft: 0, active: 0, paused: 0, archived: 0 };
  let available = false;
  await page.route("**/api/v1/admin/system/status", (route) => available
    ? route.fulfill({ json: { data: {
      environment: "testing", debug: false, checks: { database: true, redis: false }, postgis: true,
      cities: counts, zones: counts, features: {}, map_style_configured: false, geometry_caps_approved: false,
    } } })
    : route.fulfill({ status: 503, json: {} }),
  );
  await page.goto(`${admin}/settings`);
  const retry = page.getByRole("button", { name: "Try again" });
  await expect(retry).toBeVisible();
  available = true;
  await retry.click();
  await expect(page.getByRole("heading", { name: "Service checks" })).toBeVisible();
  await expect(page.getByText("Unavailable", { exact: true })).toBeVisible();
  await expect(page.getByText("Pending approval", { exact: true })).toBeVisible();
  await assertAccessibleLayout(page);
  if (testInfo.project.name === "phone") {
    await page.getByRole("main").screenshot({ path: testInfo.outputPath("admin-settings.png") });
  }
});

test("admin settings show a permission denial without status data", async ({ page }) => {
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await page.route("**/api/v1/admin/system/status", (route) => route.fulfill({ status: 403, json: {} }));
  await page.goto(`${admin}/settings`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("You are not allowed");
  await expect(page.getByRole("heading", { name: "Service checks" })).toHaveCount(0);
});

async function assertAccessibleLayout(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

test("customer home shows the empty restaurant state", async ({ page }, testInfo) => {
  test.skip(process.env.BROWSER_LIVE === "1", "Uses the browser suite's closed-coverage fixture.");
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await page.goto(customer);
  await expect(page.getByRole("heading", { name: "We are not delivering anywhere yet" })).toBeVisible();
  await expect(page.getByText("Delivery areas are open")).toHaveCount(0);
  await expect(page.getByText("No restaurants yet")).toBeVisible();
  await expect(page.getByRole("banner").getByRole("link", { name: /check my address/i })).toBeVisible();
  await expect(page.getByRole("searchbox")).toBeDisabled();
  if (testInfo.project.name === "phone") {
    const categories = page.getByRole("list", { name: "Categories" });
    await categories.focus();
    await expect(categories).toBeFocused();
    await page.keyboard.press("ArrowRight");
    await expect.poll(() => categories.evaluate((list) => list.scrollLeft)).toBeGreaterThan(0);
    await page.screenshot({ path: testInfo.outputPath("customer-category-keyboard.png") });
  }
  await assertAccessibleLayout(page);
});

test("customer can retry a delivery check after an API error", async ({ page }) => {
  await page.route("**/api/v1/auth/csrf", (route) =>
    route.fulfill({ json: { data: { csrf_token: "browser-test" } } }),
  );
  let submissions = 0;
  await page.route("**/api/v1/locations/serviceability", async (route) => {
    expect(route.request().method()).toBe("POST");
    expect(new URL(route.request().url()).search).toBe("");
    expect(route.request().postDataJSON()).toEqual({ latitude: "0", longitude: "0" });
    submissions++;
    if (submissions === 1) {
      await route.fulfill({ status: 503, json: {} });
      return;
    }
    await route.fulfill({ json: { data: { serviceable: false, reason: "OUTSIDE_COVERAGE", city: null, zone: null } } });
  });

  await page.goto(`${customer}/location`);
  await page.getByLabel("Latitude").fill("0");
  await page.getByLabel("Longitude").fill("0");
  const check = page.getByRole("button", { name: "Check", exact: true });
  await check.click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("Try again");
  await expect(check).toBeEnabled();
  expect(submissions).toBe(1);
  await check.click();
  await expect(page.getByText(/outside our delivery areas/i)).toBeVisible();
  expect(submissions).toBe(2);
  await assertAccessibleLayout(page);
});

test("merchant overview keeps unavailable actions disabled", async ({ page }) => {
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await page.goto(merchant);
  await expect(page.getByRole("heading", { name: "Overview" })).toBeVisible();
  await expect(page.getByRole("button", { name: /pause store/i })).toBeDisabled();
  await assertAccessibleLayout(page);
});

test("an expired admin session offers sign-in with a local return path", async ({ page }) => {
  await page.route("**/api/v1/admin/cities**", (route) => route.fulfill({ status: 401 }));
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await page.goto(`${admin}/cities`);
  const signIn = page.getByRole("link", { name: /sign in/i }).and(page.locator('a[href*="next="]'));
  await expect(signIn).toBeVisible();
  await signIn.click();
  await expect(page.getByRole("heading", { name: "Staff sign in" })).toBeVisible();
  const destination = new URL(page.url());
  expect(destination.origin).toBe(admin);
  expect(destination.pathname).toBe("/login");
  expect(destination.searchParams.get("next")).toBe("/cities");
  await assertAccessibleLayout(page);
});

test("sign-in ignores an external return URL", async ({ page }) => {
  await page.route("**/api/v1/auth/csrf", (route) =>
    route.fulfill({ json: { data: { csrf_token: "browser-test" } } }),
  );
  await page.route("**/api/v1/auth/login", (route) =>
    route.fulfill({ json: { data: { id: "browser-test", name: "Test account", email: "browser@example.test", email_verified: false, surface: "customer", grants: [] } } }),
  );
  await page.route("https://external.example/**", (route) => route.abort());
  await page.goto(`${customer}/login?next=${encodeURIComponent("https://external.example/")}`);
  await page.getByLabel("Email").fill("browser@example.test");
  await page.getByLabel("Password").fill("synthetic-browser-test");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page).toHaveURL(`${customer}/`);
});

test("city status confirmation restores keyboard focus on Escape", async ({ page }, testInfo) => {
  const city = { id: "test-city", name: "Synthetic city", slug: "test-city", country_code: "ZZ", currency_code: "PHP", timezone: "UTC", status: "draft" };
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await page.route("**/api/v1/admin/cities/test-city/zones?*", (route) => route.fulfill({ json: { data: [] } }));
  await page.route("**/api/v1/admin/cities/test-city", (route) => route.fulfill({ json: { data: city } }));
  await page.goto(`${admin}/cities/test-city`);
  const activate = page.getByRole("button", { name: "Activate", exact: true });
  await activate.focus();
  await page.keyboard.press("Enter");
  const dialog = page.getByRole("dialog", { name: "Activate Synthetic city?" });
  await expect(dialog.getByRole("button", { name: "Cancel" })).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(dialog.getByRole("button", { name: "Yes, activate" })).toBeFocused();
  await assertAccessibleLayout(page);
  if (testInfo.project.name === "phone") {
    await page.screenshot({ path: testInfo.outputPath("city-confirmation-keyboard.png") });
  }
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(activate).toBeFocused();
});

test("a stale zone edit keeps the draft available", async ({ page }, testInfo) => {
  const city = { id: "test-city", name: "Synthetic city", slug: "test-city", country_code: "ZZ", currency_code: "PHP", timezone: "UTC", status: "draft" };
  const zone = { id: "test-zone", name: "Synthetic zone", slug: "test-zone", status: "draft", version: 3, priority: 0, geometry: { type: "Polygon", coordinates: [[[0, 0], [0.01, 0], [0, 0.01], [0, 0]]] } };
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await page.route("**/api/v1/admin/cities/test-city/zones?*", (route) => route.fulfill({ json: { data: [zone] } }));
  await page.route("**/api/v1/admin/zones/test-zone/exclusions", (route) =>
    route.fulfill({ json: { data: [], meta: { next_cursor: null } } }),
  );
  await page.route("**/api/v1/admin/cities/test-city", (route) => route.fulfill({ json: { data: city } }));
  await page.route("**/api/v1/auth/csrf", (route) => route.fulfill({ json: { data: { csrf_token: "browser-test" } } }));
  await page.route("**/api/v1/admin/zones/test-zone", async (route) => {
    if (route.request().method() === "PATCH") {
      expect(route.request().postDataJSON()).toMatchObject({ version: 3, name: "My unsaved edit" });
      await route.fulfill({ status: 409, json: { error: { message: "Zone changed" } } });
    } else {
      await route.fulfill({ json: { data: zone } });
    }
  });

  await page.goto(`${admin}/cities/test-city`);
  await page.getByRole("button", { name: "Edit zone" }).click();
  const form = page.getByRole("form", { name: "Edit Synthetic zone" });
  await expect(form.getByLabel("Shape (GeoJSON)")).toHaveValue(JSON.stringify(zone.geometry));
  await form.getByLabel("Name", { exact: true }).fill("My unsaved edit");
  await form.getByRole("button", { name: "Save zone" }).click();
  await expect(form.getByRole("alert")).toContainText("This zone changed");
  await expect(form.getByLabel("Name", { exact: true })).toHaveValue("My unsaved edit");
  await expect(form.getByRole("button", { name: "Save zone" })).toBeDisabled();
  await assertAccessibleLayout(page);
  if (testInfo.project.name === "phone") {
    await form.screenshot({ path: testInfo.outputPath("zone-edit-conflict.png") });
  }
  await form.getByRole("button", { name: "Cancel edit" }).click();
  await page.getByRole("button", { name: "Coverage exclusions" }).click();
  await expect(page.getByText("No coverage exclusions.")).toBeVisible();
  const exclusions = page.getByRole("form", { name: "Add coverage exclusion" });
  await expect(exclusions.getByLabel("Starts at (UTC, optional)")).toBeVisible();
  await assertAccessibleLayout(page);
  if (testInfo.project.name === "phone") {
    await exclusions.screenshot({ path: testInfo.outputPath("zone-exclusions.png") });
  }
});
