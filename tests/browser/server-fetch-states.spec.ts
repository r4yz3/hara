// Server-rendered fetch states (backlog U-03, docs/qa/ui-state-matrix.md, formerly gap G2):
//   customer `/`  banner  <- GET /api/v1/public/config
//   admin `/`     API row <- GET /health/live
// These calls are made by the Next.js server, so page.route() cannot see them. The dev servers are started
// (playwright.config.ts) with API_URL pointing at tests/browser/public-config-server.mjs, a stand-in API whose
// answer a test sets with POST /__mode. "drop" closes the socket, which is what a dead port does to the caller;
// "hang" never answers, so the page's own 2.5 s timeout ends it. The projects that run this file are chained one
// after the other because they share that stand-in.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

const customer = "http://localhost:3000";
const admin = "http://localhost:3002";
const standIn = "http://127.0.0.1:3089/__mode";

type Mode = "ok" | "open" | "error" | "garbage" | "slow" | "hang" | "drop";

async function setMode(request: APIRequestContext, modes: { config?: Mode; health?: Mode }) {
  const response = await request.post(standIn, { data: modes });
  expect(response.ok()).toBe(true);
}

test.afterEach(async ({ request }) => {
  await setMode(request, { config: "ok", health: "ok" });
});

async function assertAccessible(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

async function quietSession(page: Page) {
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await page.route("**/api/v1/auth/csrf", (route) => route.fulfill({ json: { data: { csrf_token: "browser-test" } } }));
}

const cannotCheck = "We could not check where we deliver";

/** The page around the banner must still be there when the API is not. */
async function expectRestOfCustomerPage(page: Page) {
  await expect(page.getByRole("heading", { name: "Restaurants near you" })).toBeVisible();
  await expect(page.getByText("No restaurants yet")).toBeVisible();
}

// ---------------------------------------------------------------- customer `/` banner

for (const mode of ["error", "garbage", "drop"] as const) {
  test(`banner: ${mode} from the API shows "could not check", never a claim, and the page stands`, async ({ page, request }) => {
    await quietSession(page);
    await setMode(request, { config: mode });
    await page.goto(customer);

    await expect(page.getByRole("heading", { level: 1, name: cannotCheck })).toBeVisible();
    await expect(page.getByText("The service did not answer. Reload the page to try again.")).toBeVisible();
    await expect(page.getByText("Delivery areas are open")).toHaveCount(0);
    await expect(page.getByText("We are not delivering anywhere yet")).toHaveCount(0);
    await expectRestOfCustomerPage(page);
    await assertAccessible(page);
  });
}

test("banner: reloading after the API comes back replaces the fallback with the answer", async ({ page, request }) => {
  await quietSession(page);
  await setMode(request, { config: "error" });
  await page.goto(customer);
  await expect(page.getByRole("heading", { level: 1, name: cannotCheck })).toBeVisible();

  await setMode(request, { config: "ok" });
  await page.reload();
  await expect(page.getByRole("heading", { level: 1, name: "We are not delivering anywhere yet" })).toBeVisible();
  await expect(page.getByText(cannotCheck)).toHaveCount(0);
});

test("banner: while the API is slow the page shows progress, then the answer", async ({ page, request }) => {
  await quietSession(page);
  await setMode(request, { config: "slow" });
  await page.goto(customer, { waitUntil: "commit" });

  const progress = page.getByRole("heading", { level: 1, name: "Checking where we deliver" });
  await expect(progress).toBeVisible();
  await expect(page.getByText("One moment.")).toHaveAttribute("aria-busy", "true");
  await assertAccessible(page);

  await expect(page.getByRole("heading", { level: 1, name: "We are not delivering anywhere yet" })).toBeVisible();
  await expect(progress).toHaveCount(0);
});

test("banner: an API that never answers ends in 'could not check' instead of hanging", async ({ page, request }) => {
  await quietSession(page);
  await setMode(request, { config: "hang" });
  await page.goto(customer, { waitUntil: "commit" });

  await expect(page.getByRole("heading", { level: 1, name: "Checking where we deliver" })).toBeVisible();
  await expect(page.getByRole("heading", { level: 1, name: cannotCheck })).toBeVisible({ timeout: 15_000 });
  await expectRestOfCustomerPage(page);
  await assertAccessible(page);
});

test("banner: when an area is open it says so and links to the delivery check", async ({ page, request }) => {
  await quietSession(page);
  await setMode(request, { config: "open" });
  await page.goto(customer);

  await expect(page.getByRole("heading", { level: 1, name: "See if we deliver to you" })).toBeVisible();
  await expect(page.getByText("Delivery areas are open")).toBeVisible();
  await expect(page.getByRole("link", { name: "Check my address" }).first()).toHaveAttribute("href", "/location");
  await assertAccessible(page);
});

// ---------------------------------------------------------------- admin `/` API row

const notReachable = "API is not reachable";

for (const mode of ["error", "drop"] as const) {
  test(`api row: ${mode} from the liveness route says "not reachable" with what to do, and the page stands`, async ({ page, request }) => {
    await quietSession(page);
    await setMode(request, { health: mode });
    await page.goto(admin);

    await expect(page.getByText(notReachable)).toBeVisible();
    await expect(page.getByText("Start the stack, then reload this page.")).toBeVisible();
    await expect(page.getByText("API is reachable")).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "Setup readiness" })).toBeVisible();
    await expect(page.getByText("There are no orders, riders or figures, so none are shown.")).toBeVisible();
    await assertAccessible(page);
  });
}

test("api row: a healthy API is reported as reachable", async ({ page, request }) => {
  await quietSession(page);
  await setMode(request, { health: "ok" });
  await page.goto(admin);

  await expect(page.getByText("API is reachable")).toBeVisible();
  await expect(page.getByText(notReachable)).toHaveCount(0);
  await assertAccessible(page);
});

test("api row: while the liveness route is slow it shows progress, then the answer", async ({ page, request }) => {
  await quietSession(page);
  await setMode(request, { health: "slow" });
  await page.goto(admin, { waitUntil: "commit" });

  await expect(page.getByText("Checking the API")).toBeVisible();
  await assertAccessible(page);
  await expect(page.getByText("API is reachable")).toBeVisible();
  await expect(page.getByText("Checking the API")).toHaveCount(0);
});

test("api row: a liveness route that never answers ends in 'not reachable'", async ({ page, request }) => {
  await quietSession(page);
  await setMode(request, { health: "hang" });
  await page.goto(admin, { waitUntil: "commit" });

  await expect(page.getByText("Checking the API")).toBeVisible();
  await expect(page.getByText(notReachable)).toBeVisible({ timeout: 15_000 });
  await assertAccessible(page);
});
