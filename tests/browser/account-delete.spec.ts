// Customer /account: "Delete my account" (DELETE /api/v1/me, ADR-017), proven with the API replaced by route interception.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

const customer = "http://localhost:3000";
const secret = "Sup3r-Secret-Pass-1";

const user = {
  id: "01JTESTTESTTESTTESTTESTTES", name: "Synthetic Customer", email: "customer@example.test",
  email_verified: true, mfa_enabled: false, surface: "customer", grants: [],
};
const failure = (message: string, fields?: Record<string, string[]>) => ({
  error: { code: "X", message, fields, request_id: "test" },
});

function reply(route: Route, status: number, json?: unknown, extra: Record<string, string> = {}) {
  const headers = {
    "Access-Control-Allow-Origin": customer,
    "Access-Control-Allow-Credentials": "true",
    "Access-Control-Expose-Headers": "Retry-After",
    ...extra,
  };
  return status === 204 ? route.fulfill({ status, headers }) : route.fulfill({ status, json, headers });
}

function gate() {
  let open!: () => void;
  const opened = new Promise<void>((resolve) => { open = resolve; });
  return { opened, open };
}

async function assertAccessible(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

type Call = { body: unknown; csrf: string | null };

/** Stand-ins for /me, the CSRF token, the address list and DELETE /me. `onDelete` decides each DELETE answer. */
async function api(page: Page, onDelete: (route: Route, call: Call) => Promise<void> | void) {
  const deletes: Call[] = [];
  await page.route("**/api/v1/auth/csrf", (route) => reply(route, 200, { data: { csrf_token: "browser-test" } }));
  await page.route("**/api/v1/me/addresses**", (route) =>
    route.request().method() === "OPTIONS" ? route.fallback() : reply(route, 200, { data: [], meta: { request_id: "test" } }));
  await page.route("**/api/v1/me", async (route) => {
    const request = route.request();
    if (request.method() === "OPTIONS") return route.fallback();
    if (request.method() === "DELETE") {
      const call: Call = { body: request.postDataJSON(), csrf: request.headers()["x-csrf-token"] ?? null };
      deletes.push(call);
      return onDelete(route, call);
    }
    return reply(route, 200, { data: user, meta: { request_id: "test" } });
  });
  return deletes;
}

const panel = (page: Page) => page.getByRole("region", { name: "Delete my account" });
const dialog = (page: Page) => page.getByRole("dialog", { name: "Delete your account permanently?" });
const opener = (page: Page) => panel(page).getByRole("button", { name: "Delete my account" });

async function openDialog(page: Page) {
  await page.goto(`${customer}/account`);
  await opener(page).click();
  await expect(dialog(page)).toBeVisible();
}

async function confirmWith(page: Page, password = secret) {
  await dialog(page).getByLabel("Current password").fill(password);
  await dialog(page).getByRole("button", { name: "Delete my account permanently" }).click();
}

test.describe("customer account: delete my account", () => {
  test("the panel says what is removed, and opening then cancelling returns focus", async ({ page }) => {
    await api(page, (route) => reply(route, 204));
    await page.goto(`${customer}/account`);
    await expect(panel(page)).toContainText("saved addresses");
    await expect(panel(page)).toContainText("cannot be undone");
    await expect(panel(page)).toContainText("backups");
    await expect(page.getByRole("button", { name: "Sign out" })).toBeInViewport();
    await assertAccessible(page);

    await opener(page).click();
    await expect(dialog(page)).toBeVisible();
    await expect(dialog(page).getByRole("button", { name: "Cancel" })).toBeFocused();
    await expect(dialog(page).getByLabel("Current password")).toHaveAttribute("autocomplete", "current-password");
    await assertAccessible(page);

    await dialog(page).getByRole("button", { name: "Cancel" }).click();
    await expect(dialog(page)).toBeHidden();
    await expect(opener(page)).toBeFocused();

    await opener(page).click();
    await page.keyboard.press("Escape");
    await expect(dialog(page)).toBeHidden();
    await expect(opener(page)).toBeFocused();
  });

  test("a wrong password shows the field message, focuses the field and keeps the dialog open", async ({ page }) => {
    await api(page, (route) => reply(route, 422, failure("Invalid.", { password: ["The password is incorrect."] })));
    await openDialog(page);
    await confirmWith(page, "wrong");
    const password = dialog(page).getByLabel("Current password");
    await expect(dialog(page).getByText("The password is incorrect.")).toBeVisible();
    await expect(password).toBeFocused();
    await expect(password).toHaveAttribute("aria-invalid", "true");
    await expect(password).toHaveValue("");
    await expect(dialog(page)).toBeVisible();
    await assertAccessible(page);
  });

  test("a work account gets the server message and no retry", async ({ page }) => {
    const message = "This account is also used for work. Ask an administrator to close it.";
    await api(page, (route) => reply(route, 409, failure(message)));
    await openDialog(page);
    await confirmWith(page);
    await expect(dialog(page).getByRole("alert")).toHaveText(message);
    await expect(dialog(page).getByRole("button", { name: "Delete my account permanently" })).toHaveCount(0);
    await expect(dialog(page).getByRole("button", { name: "Try again" })).toHaveCount(0);
    await assertAccessible(page);
    await dialog(page).getByRole("button", { name: "Close" }).click();
    await expect(dialog(page)).toBeHidden();
  });

  test("too many attempts says how long to wait", async ({ page }) => {
    await api(page, (route) => reply(route, 429, failure("Too many."), { "Retry-After": "42" }));
    await openDialog(page);
    await confirmWith(page);
    await expect(dialog(page).getByRole("alert")).toHaveText("Too many attempts. Try again in 42 seconds.");
    await expect(dialog(page)).toBeVisible();
  });

  test("a server error offers Try again, which sends the request again", async ({ page }) => {
    let n = 0;
    const deletes = await api(page, (route) => (++n === 1 ? reply(route, 500, failure("Boom.")) : reply(route, 204)));
    await openDialog(page);
    await confirmWith(page);
    await expect(dialog(page).getByRole("alert")).toContainText("could not complete");
    await dialog(page).getByLabel("Current password").fill(secret);
    await dialog(page).getByRole("button", { name: "Try again" }).click();
    await expect(page).toHaveURL(/\/account\/deleted$/);
    expect(deletes).toHaveLength(2);
  });

  test("a slow request is sent once, even when submitted twice", async ({ page }) => {
    const hold = gate();
    const deletes = await api(page, async (route) => {
      await hold.opened;
      await reply(route, 204);
    });
    await openDialog(page);
    await dialog(page).getByLabel("Current password").fill(secret);
    await dialog(page).getByRole("button", { name: "Delete my account permanently" }).click();
    await expect(dialog(page).getByRole("button", { name: "Deleting…" })).toBeDisabled();
    await dialog(page).getByLabel("Current password").press("Enter");
    await page.keyboard.press("Escape");
    await expect(dialog(page)).toBeVisible();
    hold.open();
    await expect(page).toHaveURL(/\/account\/deleted$/);
    expect(deletes).toHaveLength(1);
  });

  test("success leads to the deleted message; the body is only the password and the secret never leaks", async ({ page }) => {
    const consoleLines: string[] = [];
    const urls: string[] = [];
    page.on("console", (message) => consoleLines.push(message.text()));
    page.on("framenavigated", (frame) => urls.push(frame.url()));
    const deletes = await api(page, (route) => reply(route, 204));
    await openDialog(page);
    await confirmWith(page);

    await expect(page).toHaveURL(`${customer}/account/deleted`);
    await expect(page.getByRole("heading", { name: "Your account was deleted." })).toBeVisible();
    await expect(page.getByRole("link", { name: "Go to the home page" })).toHaveAttribute("href", "/");
    await assertAccessible(page);

    expect(deletes).toHaveLength(1);
    expect(deletes[0].body).toEqual({ password: secret });
    expect(Object.keys(deletes[0].body as object)).toEqual(["password"]);
    expect(deletes[0].csrf).toBe("browser-test");

    expect(urls.join("\n")).not.toContain(secret);
    expect(consoleLines.join("\n")).not.toContain(secret);
    const stored = await page.evaluate(() => JSON.stringify([{ ...localStorage }, { ...sessionStorage }, document.cookie]));
    expect(stored).not.toContain(secret);
  });

  test("a signed-out answer sends the person to sign in", async ({ page }) => {
    await api(page, (route) => reply(route, 401, failure("Unauthenticated.")));
    await openDialog(page);
    await confirmWith(page);
    await expect(page).toHaveURL(/\/login\?next=%2Faccount$/);
  });
});
