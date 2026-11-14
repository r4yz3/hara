// Admin actions (create a city, change a city's status, invite, give a role) that fail for enrolment-only staff
// show the set-up words with a link to /security, in the place the action's message goes. Any other 403 keeps
// its own sentence and shows no link. The API is replaced by route interception; no real data or account is used.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

const admin = "http://localhost:3002";
const enrolmentOnlyMessage = "Set up two-step sign-in before using the admin console.";
const enrolmentLink = "Set up two-step sign-in";

const city = {
  id: "test-city", name: "Synthetic city", slug: "test-city", country_code: "ZZ", currency_code: "PHP",
  timezone: "UTC", status: "draft", created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};
const self = {
  id: "person-self", name: "Synthetic owner", email: "owner@example.test", email_masked: false, status: "active",
  mfa_enrolled: true, is_self: true,
  grants: [{ id: "grant-self", role: "platform_owner", role_label: "Platform owner", scope_type: "global", scope_id: null, city_name: null, granted_at: "2026-01-01T00:00:00Z", can_revoke: false }],
};
const other = {
  id: "person-other", name: "Synthetic colleague", email: "colleague@example.test", email_masked: false, status: "active",
  mfa_enrolled: true, is_self: false, grants: [],
};
const assignable = { slug: "dispatcher", label: "Dispatcher", scope_types: ["city"], global: false, all_cities: false, city_ids: ["test-city"] };

async function assertAccessible(page: Page) {
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

const failure = (message: string) => ({ error: { code: "FORBIDDEN", message, request_id: "test" } });

// The pages call the API on another origin, so a fulfilled answer carries the CORS headers a real one would.
function reply(route: Route, status: number, json: unknown) {
  return route.fulfill({
    status,
    json,
    headers: { "Access-Control-Allow-Origin": admin, "Access-Control-Allow-Credentials": "true" },
  });
}

/** Signed in as staff, with harmless answers for every admin path; specific answers are added with `onAdmin`. */
async function adminApi(page: Page, lists: Record<string, unknown[]> = {}) {
  await page.route("**/api/v1/me", (route) => reply(route, 401, failure("Unauthenticated.")));
  await page.route("**/api/v1/auth/csrf", (route) => reply(route, 200, { data: { csrf_token: "browser-test" } }));
  await page.route("**/api/v1/public/config", (route) => reply(route, 200, { data: { currency: "PHP" }, meta: {} }));
  // Registered first, so every later handler is tried before it.
  await page.route("**/api/v1/admin/**", (route) => {
    const path = new URL(route.request().url()).pathname;
    const rows = Object.entries(lists).find(([name]) => path === name)?.[1] ?? [];
    return reply(route, 200, { data: rows, meta: { next_cursor: null } });
  });
}

/** Answers one admin path for one method; other methods fall through to the defaults. */
function onAdmin(page: Page, pathname: string, method: string, answer: (route: Route) => unknown) {
  return page.route(
    (url) => url.pathname === pathname,
    (route) => (route.request().method() === method ? answer(route) : route.fallback()),
  );
}

// ------------------------------------------------ /cities: create a city

test.describe("create a city", () => {
  async function createCityApi(page: Page, message: string) {
    await adminApi(page, { "/api/v1/admin/cities": [city] });
    await onAdmin(page, "/api/v1/admin/cities", "POST", (route) => reply(route, 403, failure(message)));
  }

  test("an enrolment-only 403 shows the set-up words and a link to /security", async ({ page }) => {
    await createCityApi(page, enrolmentOnlyMessage);
    await page.goto(`${admin}/cities`);
    const form = page.getByRole("region", { name: "Create a city" });
    await form.getByLabel("Name", { exact: true }).fill("Synthetic city");
    await form.getByLabel("Country code").fill("ZZ");
    await form.getByLabel("Timezone").selectOption("UTC");
    await form.getByRole("button", { name: "Create draft city" }).click();

    await expect(form.getByRole("alert")).toContainText(enrolmentOnlyMessage);
    await expect(form.getByRole("link", { name: enrolmentLink })).toHaveAttribute("href", "/security");
    await assertAccessible(page);
    await expect(form.getByRole("status")).toHaveCount(0);
  });

  test("an ordinary 403 keeps its own sentence and shows no link", async ({ page }) => {
    await createCityApi(page, "Your role cannot create cities.");
    await page.goto(`${admin}/cities`);
    const form = page.getByRole("region", { name: "Create a city" });
    await form.getByLabel("Name", { exact: true }).fill("Synthetic city");
    await form.getByLabel("Country code").fill("ZZ");
    await form.getByLabel("Timezone").selectOption("UTC");
    await form.getByRole("button", { name: "Create draft city" }).click();

    await expect(form.getByRole("alert")).toContainText("Your role cannot create cities.");
    await expect(page.getByRole("link", { name: enrolmentLink })).toHaveCount(0);
  });
});

// ------------------------------------------------ /cities/[id]: change a city's status

test.describe("change a city's status", () => {
  async function statusApi(page: Page, message: string) {
    await adminApi(page);
    await onAdmin(page, "/api/v1/admin/cities/test-city", "GET", (route) => reply(route, 200, { data: city }));
    await onAdmin(page, "/api/v1/admin/cities/test-city", "PATCH", (route) => reply(route, 403, failure(message)));
  }

  async function confirmActivate(page: Page) {
    await page.goto(`${admin}/cities/test-city`);
    await page.getByRole("button", { name: "Activate", exact: true }).click();
    const dialog = page.getByRole("dialog", { name: "Activate Synthetic city?" });
    await dialog.getByRole("button", { name: "Yes, activate" }).click();
    return dialog;
  }

  test("an enrolment-only 403 shows the set-up words and a link to /security", async ({ page }) => {
    await statusApi(page, enrolmentOnlyMessage);
    const dialog = await confirmActivate(page);

    await expect(dialog.getByRole("alert")).toContainText(enrolmentOnlyMessage);
    await expect(dialog.getByRole("link", { name: enrolmentLink })).toHaveAttribute("href", "/security");
    await assertAccessible(page);
  });

  test("an ordinary 403 keeps its own sentence and shows no link", async ({ page }) => {
    await statusApi(page, "Your role cannot change this city.");
    const dialog = await confirmActivate(page);

    await expect(dialog.getByRole("alert")).toContainText("Your role cannot change this city.");
    await expect(page.getByRole("link", { name: enrolmentLink })).toHaveCount(0);
  });
});

// ------------------------------------------------ /access: invite and give a role

test.describe("staff invitations and roles", () => {
  const accessLists = { "/api/v1/admin/users": [self, other], "/api/v1/admin/roles": [assignable], "/api/v1/admin/cities": [city] };

  test("invite: an enrolment-only 403 shows the set-up words and a link to /security", async ({ page }) => {
    await adminApi(page, accessLists);
    await onAdmin(page, "/api/v1/admin/invitations", "POST", (route) => reply(route, 403, failure(enrolmentOnlyMessage)));
    await page.goto(`${admin}/access`);
    const form = page.getByRole("form", { name: "Invite a staff member" });
    await form.getByLabel("Name").fill("Synthetic invitee");
    await form.getByLabel("Email address").fill("invitee@example.test");
    await form.getByLabel("Role").selectOption("dispatcher");
    await form.getByLabel("Applies to").selectOption("city:test-city");
    await form.getByRole("button", { name: "Send invitation" }).click();

    await expect(form.getByRole("alert")).toContainText(enrolmentOnlyMessage);
    await expect(form.getByRole("link", { name: enrolmentLink })).toHaveAttribute("href", "/security");
    await assertAccessible(page);
  });

  test("invite: an ordinary 403 keeps its own sentence and shows no link", async ({ page }) => {
    await adminApi(page, accessLists);
    await onAdmin(page, "/api/v1/admin/invitations", "POST", (route) => reply(route, 403, failure("Your role cannot invite staff to this city.")));
    await page.goto(`${admin}/access`);
    const form = page.getByRole("form", { name: "Invite a staff member" });
    await form.getByLabel("Name").fill("Synthetic invitee");
    await form.getByLabel("Email address").fill("invitee@example.test");
    await form.getByLabel("Role").selectOption("dispatcher");
    await form.getByLabel("Applies to").selectOption("city:test-city");
    await form.getByRole("button", { name: "Send invitation" }).click();

    await expect(form.getByRole("alert")).toContainText("Your role cannot invite staff to this city.");
    await expect(page.getByRole("link", { name: enrolmentLink })).toHaveCount(0);
  });

  test("give a role: an enrolment-only 403 shows the set-up words and a link to /security", async ({ page }) => {
    await adminApi(page, accessLists);
    await onAdmin(page, "/api/v1/admin/users/person-other/roles", "POST", (route) => reply(route, 403, failure(enrolmentOnlyMessage)));
    await page.goto(`${admin}/access`);
    const form = page.getByRole("form", { name: "Give a role" });
    await form.getByLabel("Person").selectOption("person-other");
    await form.getByLabel("Role").selectOption("dispatcher");
    await form.getByLabel("Applies to").selectOption("city:test-city");
    await form.getByRole("button", { name: "Give role" }).click();

    await expect(form.getByRole("alert")).toContainText(enrolmentOnlyMessage);
    await expect(form.getByRole("link", { name: enrolmentLink })).toHaveAttribute("href", "/security");
    await assertAccessible(page);
  });

  test("give a role: an ordinary 403 keeps its own sentence and shows no link", async ({ page }) => {
    await adminApi(page, accessLists);
    await onAdmin(page, "/api/v1/admin/users/person-other/roles", "POST", (route) => reply(route, 403, failure("Your role cannot give this role.")));
    await page.goto(`${admin}/access`);
    const form = page.getByRole("form", { name: "Give a role" });
    await form.getByLabel("Person").selectOption("person-other");
    await form.getByLabel("Role").selectOption("dispatcher");
    await form.getByLabel("Applies to").selectOption("city:test-city");
    await form.getByRole("button", { name: "Give role" }).click();

    await expect(form.getByRole("alert")).toContainText("Your role cannot give this role.");
    await expect(page.getByRole("link", { name: enrolmentLink })).toHaveCount(0);
  });

  test("remove a role: an enrolment-only 403 shows the set-up words and a link to /security", async ({ page }) => {
    const granted = {
      ...other,
      grants: [{ id: "grant-other", role: "dispatcher", role_label: "Dispatcher", scope_type: "city", scope_id: "test-city", city_name: "Synthetic city", granted_at: "2026-01-01T00:00:00Z", can_revoke: true }],
    };
    await adminApi(page, { ...accessLists, "/api/v1/admin/users": [self, granted] });
    await onAdmin(page, "/api/v1/admin/users/person-other/roles/grant-other", "DELETE", (route) => reply(route, 403, failure(enrolmentOnlyMessage)));
    await page.goto(`${admin}/access`);
    await page.getByRole("button", { name: /^Remove Dispatcher/ }).click();
    const dialog = page.getByRole("dialog", { name: /^Remove Dispatcher from/ });
    await dialog.getByRole("button", { name: "Yes, remove" }).click();

    await expect(dialog.getByRole("alert")).toContainText(enrolmentOnlyMessage);
    await expect(dialog.getByRole("link", { name: enrolmentLink })).toHaveAttribute("href", "/security");
    await assertAccessible(page);
  });

  test("revoke an invitation: an enrolment-only 403 shows the set-up words and a link to /security", async ({ page }) => {
    const pending = {
      id: "invite-1", name: "Synthetic invitee", email: "invitee@example.test", email_masked: false, role: "dispatcher", role_label: "Dispatcher",
      scope_type: "city", scope_id: "test-city", city_name: "Synthetic city", invited_by_me: true,
      created_at: "2026-02-03T00:00:00Z", expires_at: "2026-02-06T00:00:00Z", can_revoke: true,
    };
    await adminApi(page, { ...accessLists, "/api/v1/admin/invitations": [pending] });
    await onAdmin(page, "/api/v1/admin/invitations/invite-1", "DELETE", (route) => reply(route, 403, failure(enrolmentOnlyMessage)));
    await page.goto(`${admin}/access`);
    await page.getByRole("button", { name: "Revoke the invitation for Synthetic invitee" }).click();
    const dialog = page.getByRole("dialog", { name: "Revoke the invitation for Synthetic invitee?" });
    await dialog.getByRole("button", { name: "Yes, revoke" }).click();

    await expect(dialog.getByRole("alert")).toContainText(enrolmentOnlyMessage);
    await expect(dialog.getByRole("link", { name: enrolmentLink })).toHaveAttribute("href", "/security");
    await assertAccessible(page);
  });
});

// ------------------------------------------------ /cities/[id]: add a zone

test.describe("add a zone", () => {
  const square = JSON.stringify({ type: "Polygon", coordinates: [[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]] });

  for (const [label, message, enrolment] of [
    ["an enrolment-only 403 shows the set-up words and a link to /security", enrolmentOnlyMessage, true],
    ["an ordinary 403 keeps its own sentence and shows no link", "Your role cannot add zones.", false],
  ] as const) {
    test(label, async ({ page }) => {
      await adminApi(page);
      await onAdmin(page, "/api/v1/admin/cities/test-city", "GET", (route) => reply(route, 200, { data: city }));
      await onAdmin(page, "/api/v1/admin/cities/test-city/zones", "POST", (route) => reply(route, 403, failure(message)));
      await page.goto(`${admin}/cities/test-city`);
      const section = page.getByRole("region", { name: "Add a delivery zone" });
      await section.getByLabel("Name", { exact: true }).fill("Synthetic zone");
      await section.getByLabel("Short name for links").fill("synthetic-zone");
      await section.getByLabel(/^Shape/).fill(square);
      await section.getByRole("button", { name: "Add draft zone" }).click();

      await expect(section.getByRole("alert")).toContainText(message);
      if (enrolment) {
        await expect(section.getByRole("link", { name: enrolmentLink })).toHaveAttribute("href", "/security");
      } else {
        await expect(page.getByRole("link", { name: enrolmentLink })).toHaveCount(0);
      }
      await assertAccessible(page);
    });
  }
});
