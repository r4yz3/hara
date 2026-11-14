// Two small front-end changes, proven with the API replaced by route interception:
//  1. customer /account lets a person change their display name (PATCH /api/v1/me).
//  2. admin screens send enrolment-only staff to /security instead of showing the generic forbidden text.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

const customer = "http://localhost:3000";
const admin = "http://localhost:3002";
const enrolmentOnlyMessage = "Set up two-step sign-in before using the admin console.";

const user = {
  id: "01JTESTTESTTESTTESTTESTTES", name: "Synthetic Customer", email: "customer@example.test",
  email_verified: true, mfa_enabled: false, surface: "customer", grants: [],
};
const me = (name: string) => ({ data: { ...user, name }, meta: { request_id: "test" } });
const failure = (message: string, fields?: Record<string, string[]>) => ({
  error: { code: "X", message, fields, request_id: "test" },
});

// The pages call the API on another origin, so a fulfilled answer carries the CORS headers a real one would.
function reply(origin: string, route: Route, status: number, json: unknown) {
  return route.fulfill({
    status,
    json,
    headers: { "Access-Control-Allow-Origin": origin, "Access-Control-Allow-Credentials": "true" },
  });
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

// ------------------------------------------------ customer /account: change the display name

/** GET /me answers with `current()`; PATCH /me goes to `onPatch`. */
async function accountApi(page: Page, onPatch: (route: Route) => unknown, current: () => string = () => user.name) {
  await page.route("**/api/v1/auth/csrf", (route) => reply(customer, route, 200, { data: { csrf_token: "browser-test" } }));
  await page.route("**/api/v1/me", (route) =>
    route.request().method() === "PATCH" ? void onPatch(route) : reply(customer, route, 200, me(current())));
}

test.describe("customer account: display name", () => {
  test("the form is prefilled from /me and email is read-only with a note", async ({ page }) => {
    await accountApi(page, (route) => reply(customer, route, 200, me("x")));
    await page.goto(`${customer}/account`);
    await expect(page.getByRole("textbox", { name: "Name" })).toHaveValue(user.name);
    await expect(page.getByRole("button", { name: "Save name" })).toBeDisabled();
    await expect(page.getByText(user.email, { exact: true })).toBeVisible();
    await expect(page.getByLabel("Email")).toHaveCount(0);
    await expect(page.getByText("email and phone number cannot be changed here yet")).toBeVisible();
    await assertAccessible(page);
  });

  test("saving sends only the name, shows a success message and disables Save again", async ({ page }) => {
    let name = user.name;
    let sent: unknown = null;
    let csrf: string | null = null;
    await accountApi(page, (route) => {
      sent = route.request().postDataJSON();
      csrf = route.request().headers()["x-csrf-token"] ?? null;
      name = "Renamed Customer";
      return reply(customer, route, 200, me(name));
    }, () => name);
    await page.goto(`${customer}/account`);
    await page.getByRole("textbox", { name: "Name" }).fill("Renamed Customer");
    await expect(page.getByRole("button", { name: "Save name" })).toBeEnabled();
    await page.getByRole("button", { name: "Save name" }).click();
    await expect(page.getByRole("status").filter({ hasText: "Your name was saved." })).toBeVisible();
    expect(sent).toEqual({ name: "Renamed Customer" });
    expect(csrf).toBe("browser-test");
    await expect(page.getByRole("button", { name: "Save name" })).toBeDisabled();
    await expect(page.getByText("Renamed Customer", { exact: true })).toBeVisible();
    await assertAccessible(page);
  });

  test("Save stays disabled until the name differs, and again when it is put back", async ({ page }) => {
    await accountApi(page, (route) => reply(customer, route, 200, me("x")));
    await page.goto(`${customer}/account`);
    const save = page.getByRole("button", { name: "Save name" });
    await page.getByRole("textbox", { name: "Name" }).fill("Someone Else");
    await expect(save).toBeEnabled();
    await page.getByRole("textbox", { name: "Name" }).fill(user.name);
    await expect(save).toBeDisabled();
    await page.getByRole("textbox", { name: "Name" }).fill(`${user.name}   `);
    await expect(save).toBeDisabled();
  });

  test("a 422 shows the message next to the field and focuses it", async ({ page }) => {
    await accountApi(page, (route) =>
      reply(customer, route, 422, failure("The given data was invalid.", { name: ["The name must not be empty."] })));
    await page.goto(`${customer}/account`);
    await page.getByRole("textbox", { name: "Name" }).fill("   ");
    await page.getByRole("button", { name: "Save name" }).click();
    await expect(page.getByText("The name must not be empty.")).toBeVisible();
    await expect(page.getByRole("textbox", { name: "Name" })).toBeFocused();
    await expect(page.getByRole("textbox", { name: "Name" })).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByRole("textbox", { name: "Name" })).toHaveAccessibleDescription("The name must not be empty.");
    await assertAccessible(page);
  });

  test("a slow save disables the button and a second press sends nothing", async ({ page }) => {
    let patches = 0;
    const held = gate();
    await accountApi(page, async (route) => {
      patches += 1;
      await held.opened;
      await reply(customer, route, 200, me("Slow Name"));
    });
    await page.goto(`${customer}/account`);
    await page.getByRole("textbox", { name: "Name" }).fill("Slow Name");
    await page.getByRole("button", { name: "Save name" }).click();
    const busy = page.getByRole("button", { name: "Saving…" });
    await expect(busy).toBeDisabled();
    await expect(busy).toHaveAttribute("aria-busy", "true");
    await page.getByRole("textbox", { name: "Name" }).press("Enter");
    held.open();
    await expect(page.getByText("Your name was saved.")).toBeVisible();
    expect(patches).toBe(1);
  });

  test("a server error says so and the retry saves", async ({ page }) => {
    let healthy = false;
    await accountApi(page, (route) => healthy ? reply(customer, route, 200, me("Retry Name")) : reply(customer, route, 500, {}));
    await page.goto(`${customer}/account`);
    await page.getByRole("textbox", { name: "Name" }).fill("Retry Name");
    await page.getByRole("button", { name: "Save name" }).click();
    await expect(page.getByRole("main").getByRole("alert").filter({ hasText: "could not complete" })).toBeVisible();
    await assertAccessible(page);
    healthy = true;
    await page.getByRole("button", { name: "Try again" }).click();
    await expect(page.getByText("Your name was saved.")).toBeVisible();
    await expect(page.getByRole("textbox", { name: "Name" })).toHaveValue("Retry Name");
  });

  test("a refused save (403) shows the server's message, without a retry", async ({ page }) => {
    await accountApi(page, (route) => reply(customer, route, 403, failure("You cannot edit this account.")));
    await page.goto(`${customer}/account`);
    await page.getByRole("textbox", { name: "Name" }).fill("Nope");
    await page.getByRole("button", { name: "Save name" }).click();
    await expect(page.getByRole("main").getByRole("alert").filter({ hasText: "You cannot edit this account." })).toBeVisible();
    await expect(page.getByRole("button", { name: "Try again" })).toHaveCount(0);
  });

  test("signed out: the page goes to sign-in", async ({ page }) => {
    await page.route("**/api/v1/me", (route) => reply(customer, route, 401, failure("Unauthenticated.")));
    await page.route("**/api/v1/auth/csrf", (route) => reply(customer, route, 200, { data: { csrf_token: "browser-test" } }));
    await page.goto(`${customer}/account`);
    await expect(page).toHaveURL(`${customer}/login?next=%2Faccount`);
  });

  test("signed out between loading and pressing Save: goes to sign-in", async ({ page }) => {
    await accountApi(page, (route) => reply(customer, route, 401, failure("Unauthenticated.")));
    await page.goto(`${customer}/account`);
    await page.getByRole("textbox", { name: "Name" }).fill("Too Late");
    await page.getByRole("button", { name: "Save name" }).click();
    await expect(page).toHaveURL(`${customer}/login?next=%2Faccount`);
  });
});

// ------------------------------------------------ admin: enrolment-only staff are sent to /security

async function adminApi(page: Page, status: number, body: unknown) {
  await page.route("**/api/v1/me", (route) => reply(admin, route, 401, failure("Unauthenticated.")));
  await page.route("**/api/v1/auth/csrf", (route) => reply(admin, route, 200, { data: { csrf_token: "browser-test" } }));
  await page.route("**/api/v1/public/config", (route) => reply(admin, route, 200, { data: { currency: "PHP" }, meta: {} }));
  await page.route("**/api/v1/admin/**", (route) => reply(admin, route, status, body));
}

test.describe("admin: enrolment-only 403", () => {
  for (const [name, path] of [["cities", "/cities"], ["audit", "/audit"], ["settings", "/settings"], ["access", "/access"]] as const) {
    test(`${name} shows the set-up message and a link to /security`, async ({ page }) => {
      await adminApi(page, 403, failure(enrolmentOnlyMessage));
      await page.goto(`${admin}${path}`);
      await expect(page.getByRole("main").getByRole("alert")).toContainText(enrolmentOnlyMessage);
      await expect(page.getByRole("main").getByRole("heading", { name: "Set up two-step sign-in" })).toBeVisible();
      await expect(page.getByText("You are not allowed")).toHaveCount(0);
      await expect(page.getByRole("button", { name: "Try again" })).toHaveCount(0);
      await expect(page.getByRole("main").getByRole("link", { name: "Set up two-step sign-in" })).toHaveAttribute("href", "/security");
    });
  }

  test("nothing redirects by itself; the link leads to the security page", async ({ page }) => {
    await adminApi(page, 403, failure(enrolmentOnlyMessage));
    await page.goto(`${admin}/audit`);
    await expect(page.getByRole("main").getByRole("alert")).toContainText(enrolmentOnlyMessage);
    await expect(page).toHaveURL(`${admin}/audit`);
    await page.getByRole("main").getByRole("link", { name: "Set up two-step sign-in" }).click();
    await expect(page).toHaveURL(`${admin}/security`);
  });

  test("the set-up message is accessible", async ({ page }) => {
    await adminApi(page, 403, failure(enrolmentOnlyMessage));
    await page.goto(`${admin}/settings`);
    await expect(page.getByRole("main").getByRole("alert")).toContainText(enrolmentOnlyMessage);
    await assertAccessible(page);
  });

  for (const [name, path] of [["cities", "/cities"], ["audit", "/audit"], ["settings", "/settings"]] as const) {
    test(`${name}: an ordinary 403 still shows the generic forbidden text`, async ({ page }) => {
      await adminApi(page, 403, {});
      await page.goto(`${admin}${path}`);
      await expect(page.getByRole("main").getByRole("alert")).toContainText("You are not allowed");
      await expect(page.getByRole("link", { name: "Set up two-step sign-in" })).toHaveCount(0);
      await expect(page.getByRole("link", { name: "Back to overview" })).toBeVisible();
    });
  }

  test("an ordinary 403 with its own message keeps that message", async ({ page }) => {
    await adminApi(page, 403, failure("Your role cannot view the audit log."));
    await page.goto(`${admin}/audit`);
    await expect(page.getByRole("main").getByRole("alert")).toContainText("Your role cannot view the audit log.");
    await expect(page.getByRole("link", { name: "Set up two-step sign-in" })).toHaveCount(0);
  });
});
