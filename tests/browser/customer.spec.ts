// Customer web scenarios from spec 10.2 (docs/qa/phase1-acceptance.md): 1, 2, 5, 7, 8, plus /account.
// The API is replaced by route interception; positions and accounts are synthetic.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

const customer = "http://localhost:3000";

const user = {
  id: "01JTESTTESTTESTTESTTESTTES", name: "Synthetic Customer", email: "customer@example.test",
  email_verified: true, mfa_enabled: false, surface: "customer", grants: [],
};

const stub = (route: Route, status: number, json?: unknown) =>
  json === undefined ? route.fulfill({ status }) : route.fulfill({ status, json });

async function signedOut(page: Page) {
  await page.route("**/api/v1/me", (route) => stub(route, 401));
  await page.route("**/api/v1/auth/csrf", (route) => stub(route, 200, { data: { csrf_token: "browser-test" } }));
}

async function noOverflow(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
}

async function assertAccessible(page: Page) {
  await noOverflow(page);
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

// ------------------------------------------------ scenario 1: landing, no invented restaurants

test("scenario 1: the landing page has no configured city and no invented restaurants", async ({ page }) => {
  test.skip(process.env.BROWSER_LIVE === "1", "Uses the browser suite's closed-coverage fixture.");
  await signedOut(page);
  await page.goto(customer);
  await expect(page.getByRole("heading", { name: "We are not delivering anywhere yet" })).toBeVisible();
  await expect(page.getByText("No restaurants yet")).toBeVisible();
  // The layout preview is labelled as not real and hidden from assistive technology.
  await expect(page.getByText("Layout preview, with no real restaurants:")).toBeVisible();
  await expect(page.getByText(/\b(Manila|Cebu|Davao|Quezon|Makati)\b/)).toHaveCount(0);
  await assertAccessible(page);
});

// ------------------------------------------------ scenarios 2 and 5: serviceability

async function check(page: Page, answer: unknown) {
  await signedOut(page);
  const seen: { body: unknown; url: string } = { body: null, url: "" };
  await page.route("**/api/v1/locations/serviceability", async (route) => {
    seen.body = route.request().postDataJSON();
    seen.url = route.request().url();
    await stub(route, 200, answer);
  });
  await page.goto(`${customer}/location`);
  await page.getByLabel("Latitude").fill("1.5");
  await page.getByLabel("Longitude").fill("2.5");
  await page.getByRole("button", { name: "Check", exact: true }).click();
  return seen;
}

test("scenario 2: a position outside every zone shows the unavailable state", async ({ page }) => {
  const seen = await check(page, { data: { serviceable: false, reason: "OUTSIDE_COVERAGE", city: null, zone: null } });
  await expect(page.getByText(/outside our delivery areas/i)).toBeVisible();
  await expect(page.getByText(/Yes, this spot is inside/)).toHaveCount(0);
  expect(seen.body).toEqual({ latitude: "1.5", longitude: "2.5" });
  await assertAccessible(page);
});

test("scenario 5 (customer half): a serviceable answer is shown as eligible and no position goes in a URL", async ({ page }) => {
  const seen = await check(page, {
    data: { serviceable: true, reason: null, city: { id: "c1", name: "Synthetic city" }, zone: { id: "z1", name: "Synthetic zone" } },
  });
  await expect(page.getByText("Yes, this spot is inside a delivery area")).toBeVisible();
  await expect(page.getByText(/Synthetic zone/)).toBeVisible();
  expect(seen.body).toEqual({ latitude: "1.5", longitude: "2.5" });
  expect(seen.url).not.toMatch(/\?|1\.5|2\.5/);
  expect(page.url()).toBe(`${customer}/location`);
});

test.fixme("scenario 5 (admin half): activating a city and zone makes the entered point eligible end to end", async () => {
  // Needs the live API and an admin session; covered by live-coverage.spec.ts (BROWSER_LIVE=1, desktop project).
});

// ------------------------------------------------ scenario 7: bad session, safe return path

test("scenario 7: /account without a session goes to sign-in carrying only a local return path", async ({ page }) => {
  await signedOut(page);
  await page.goto(`${customer}/account`);
  await expect(page).toHaveURL(`${customer}/login?next=%2Faccount`);
  await expect(page.getByRole("heading", { name: "Sign in" })).toBeVisible();
});

test("scenario 7: signing in returns to the local path that was asked for", async ({ page }) => {
  await signedOut(page);
  await page.route("**/api/v1/auth/login", (route) => stub(route, 200, { data: user }));
  await page.goto(`${customer}/login?next=%2Flocation`);
  await page.getByLabel("Email").fill(user.email);
  await page.getByLabel("Password").fill("synthetic-browser-test");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page).toHaveURL(`${customer}/location`);
});

for (const hostile of ["https://external.example/", "//external.example/", "/\\external.example/"]) {
  test(`scenario 7: the return path ${JSON.stringify(hostile)} never leaves the site`, async ({ page }) => {
    await signedOut(page);
    await page.route("**/api/v1/auth/login", (route) => stub(route, 200, { data: user }));
    await page.route("https://external.example/**", (route) => route.abort());
    await page.goto(`${customer}/login?next=${encodeURIComponent(hostile)}`);
    await page.getByLabel("Email").fill(user.email);
    await page.getByLabel("Password").fill("synthetic-browser-test");
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await expect(page).toHaveURL(`${customer}/`);
  });
}

// ------------------------------------------------ /account states

test("account: shows the signed-in customer's own name and email, and nothing from Phase 2", async ({ page }) => {
  await page.route("**/api/v1/me", (route) => stub(route, 200, { data: user }));
  await page.goto(`${customer}/account`);
  await expect(page.getByRole("heading", { name: "Your account" })).toBeVisible();
  await expect(page.getByText(user.name, { exact: true })).toBeVisible();
  await expect(page.getByText(user.email, { exact: true })).toBeVisible();
  const limits = page.getByRole("region", { name: "Not available yet" });
  await expect(limits).toContainText("Saved addresses");
  await expect(limits).toContainText("Orders");
  await expect(limits).toContainText("Payment methods");
  expect(page.url()).toBe(`${customer}/account`);
  await assertAccessible(page);
});

test("account: an account with no name or email says so", async ({ page }) => {
  await page.route("**/api/v1/me", (route) => stub(route, 200, { data: { ...user, name: "", email: "" } }));
  await page.goto(`${customer}/account`);
  await expect(page.getByText("No name on file")).toBeVisible();
  await expect(page.getByText("No email on file")).toBeVisible();
});

test("account: shows progress while loading", async ({ page }) => {
  let open!: () => void;
  const opened = new Promise<void>((resolve) => { open = resolve; });
  await page.route("**/api/v1/me", async (route) => { await opened; await stub(route, 200, { data: user }); });
  await page.goto(`${customer}/account`);
  await expect(page.getByText("Loading your account…")).toHaveAttribute("aria-busy", "true");
  open();
  await expect(page.getByText(user.email, { exact: true })).toBeVisible();
});

test("account: a server error offers a retry that recovers", async ({ page }) => {
  let healthy = false;
  await page.route("**/api/v1/me", (route) => healthy ? stub(route, 200, { data: user }) : stub(route, 500, {}));
  await page.goto(`${customer}/account`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not be loaded");
  await assertAccessible(page);
  healthy = true;
  await page.getByRole("button", { name: "Try again" }).click();
  await expect(page.getByText(user.email, { exact: true })).toBeVisible();
});

test("account: a lost connection is reported, with a retry", async ({ page }) => {
  await page.route("**/api/v1/me", (route) => route.abort("internetdisconnected"));
  await page.goto(`${customer}/account`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not reach the server");
  await expect(page.getByRole("button", { name: "Try again" })).toBeVisible();
});

test("account: sign out ends the session once and returns to the start page", async ({ page }) => {
  let signedIn = true;
  let logouts = 0;
  await page.route("**/api/v1/me", (route) => signedIn ? stub(route, 200, { data: user }) : stub(route, 401));
  await page.route("**/api/v1/auth/csrf", (route) => stub(route, 200, { data: { csrf_token: "browser-test" } }));
  await page.route("**/api/v1/auth/logout", async (route) => { logouts++; signedIn = false; await stub(route, 204); });
  await page.goto(`${customer}/account`);
  await page.getByRole("main").getByRole("button", { name: "Sign out" }).click();
  await expect(page).toHaveURL(`${customer}/`);
  expect(logouts).toBe(1);
  await page.goto(`${customer}/account`);
  await expect(page).toHaveURL(`${customer}/login?next=%2Faccount`);
});

test("account: a failed sign-out says so and keeps the account on screen", async ({ page }) => {
  await page.route("**/api/v1/me", (route) => stub(route, 200, { data: user }));
  await page.route("**/api/v1/auth/csrf", (route) => stub(route, 200, { data: { csrf_token: "browser-test" } }));
  await page.route("**/api/v1/auth/logout", (route) => stub(route, 500, {}));
  await page.goto(`${customer}/account`);
  await page.getByRole("main").getByRole("button", { name: "Sign out" }).click();
  await expect(page.getByRole("main").getByRole("alert").filter({ hasText: "could not sign you out" })).toBeVisible();
  await expect(page.getByText(user.email, { exact: true })).toBeVisible();
});

// ------------------------------------------------ scenario 8: no horizontal overflow, controls visible

const screens: [string, string, (page: Page) => ReturnType<Page["locator"]>][] = [
  ["home", "/", (page) => page.getByRole("banner").getByRole("link", { name: /check my address/i })],
  ["login", "/login", (page) => page.getByRole("button", { name: "Sign in", exact: true })],
  ["location", "/location", (page) => page.getByRole("button", { name: "Check", exact: true })],
  ["account (signed in)", "/account", (page) => page.getByRole("main").getByRole("button", { name: "Sign out" })],
];

for (const [name, path, control] of screens) {
  test(`scenario 8: ${name} has no horizontal overflow and its main control is in view`, async ({ page }) => {
    await page.route("**/api/v1/me", (route) => path === "/account" ? stub(route, 200, { data: user }) : stub(route, 401));
    await page.goto(`${customer}${path}`);
    const target = control(page);
    await expect(target).toBeVisible();
    await expect(target).toBeInViewport();
    await noOverflow(page);
  });
}
