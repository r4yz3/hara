// Staff invitations in the admin console: the invite form and pending list on /access, and the public /invite page.
// The API is replaced by route interception; no real data, account or token is involved.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

const admin = "http://localhost:3002";
const token = "ab".repeat(32);

const city = {
  id: "test-city", name: "Synthetic city", slug: "test-city", country_code: "ZZ", currency_code: "PHP",
  timezone: "UTC", status: "draft", created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};
const self = {
  id: "person-self", name: "Synthetic owner", email: "owner@example.test", email_masked: false, status: "active",
  mfa_enrolled: true, is_self: true,
  grants: [{ id: "grant-self", role: "platform_owner", role_label: "Platform owner", scope_type: "global", scope_id: null, city_name: null, granted_at: "2026-01-01T00:00:00Z", can_revoke: false }],
};
const assignable = { slug: "dispatcher", label: "Dispatcher", scope_types: ["city"], global: false, all_cities: false, city_ids: ["test-city"] };
const invitation = {
  id: "invite-1", name: "Synthetic invitee", email: "invitee@example.test", email_masked: false, role: "dispatcher", role_label: "Dispatcher",
  scope_type: "city", scope_id: "test-city", city_name: "Synthetic city", invited_by_me: true,
  created_at: "2026-02-03T00:00:00Z", expires_at: "2026-02-06T00:00:00Z", can_revoke: true,
};
const preview = { name: "Synthetic invitee", role_label: "Dispatcher", scope_label: "Synthetic city", expires_at: "2026-02-06T00:00:00Z" };
const page_ = (data: unknown[]) => ({ json: { data, meta: { next_cursor: null } } });

async function assertAccessible(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

function api(page: Page, pathname: string, handler: (route: Route) => unknown) {
  return page.route((url) => url.pathname === pathname, (route) => {
    if (route.request().method() === "OPTIONS") return route.fulfill({ status: 204 });
    return handler(route);
  });
}

async function basics(page: Page) {
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await api(page, "/api/v1/auth/csrf", (route) => route.fulfill({ json: { data: { csrf_token: "browser-test" } } }));
}

async function mockAccess(page: Page, options: { roles?: object[]; invitations?: (route: Route) => unknown } = {}) {
  await basics(page);
  await api(page, "/api/v1/admin/users", (route) => route.fulfill(page_([self])));
  await api(page, "/api/v1/admin/roles", (route) => route.fulfill(page_(options.roles ?? [assignable])));
  await api(page, "/api/v1/admin/cities", (route) => route.fulfill(page_([city])));
  await api(page, "/api/v1/admin/invitations", options.invitations ?? ((route) => route.fulfill(page_([]))));
}

// ---------------------------------------------------------------- /access: invite

test("invite: the form checks its fields, sends the chosen scope and says nothing about the account", async ({ page }) => {
  const posts: unknown[] = [];
  let listed = false;
  await mockAccess(page, {
    invitations: (route) => {
      if (route.request().method() === "POST") {
        posts.push(route.request().postDataJSON());
        return route.fulfill({ status: 202, json: { data: { id: "invite-9", status: "invitation_sent" }, meta: {} } });
      }
      listed = true;
      return route.fulfill(page_(posts.length > 0 ? [invitation] : []));
    },
  });
  await page.goto(`${admin}/access`);
  const form = page.getByRole("form", { name: "Invite a staff member" });

  await form.getByRole("button", { name: "Send invitation" }).press("Enter");
  await expect(form.getByLabel("Name")).toBeFocused();
  await expect(form).toContainText("Enter their name.");
  await expect(form).toContainText("Choose a role.");
  expect(posts).toEqual([]);

  await form.getByLabel("Name").fill("Synthetic invitee");
  await form.getByLabel("Email address").fill("not-an-address");
  await form.getByRole("button", { name: "Send invitation" }).press("Enter");
  await expect(form.getByLabel("Email address")).toBeFocused();
  await expect(form).toContainText("Enter a valid email address.");

  await form.getByLabel("Email address").fill("invitee@example.test");
  await form.getByLabel("Role").selectOption("dispatcher");
  await form.getByLabel("Applies to").selectOption("city:test-city");
  await form.getByRole("button", { name: "Send invitation" }).press("Enter");

  const success = page.getByRole("status").filter({ hasText: "If the address can receive mail, an invitation is on its way. It expires in 72 hours." });
  await expect(success).toBeVisible();
  expect(posts).toEqual([{ name: "Synthetic invitee", email: "invitee@example.test", role: "dispatcher", scope_type: "city", scope_id: "test-city" }]);
  // The form is cleared and the pending list refreshed.
  await expect(form.getByLabel("Name")).toHaveValue("");
  await expect(page.getByRole("heading", { name: "Pending invitations" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Revoke the invitation for Synthetic invitee" })).toBeVisible();
  expect(listed).toBe(true);
  await assertAccessible(page);
});

test("invite: server field errors, a duplicate and a rate limit are reported in the form", async ({ page }) => {
  let answer: { status: number; json: unknown } = { status: 422, json: { error: { code: "VALIDATION_FAILED", message: "Invalid.", fields: { email: ["You cannot invite your own address."] } } } };
  await mockAccess(page, {
    invitations: (route) => route.request().method() === "POST" ? route.fulfill(answer) : route.fulfill(page_([])),
  });
  await page.goto(`${admin}/access`);
  const form = page.getByRole("form", { name: "Invite a staff member" });
  await form.getByLabel("Name").fill("Synthetic invitee");
  await form.getByLabel("Email address").fill("owner@example.test");
  await form.getByLabel("Role").selectOption("dispatcher");
  await form.getByLabel("Applies to").selectOption("city:test-city");
  await form.getByRole("button", { name: "Send invitation" }).press("Enter");
  await expect(form.getByLabel("Email address")).toBeFocused();
  await expect(form).toContainText("You cannot invite your own address.");
  await expect(form.getByLabel("Email address")).toHaveAttribute("aria-invalid", "true");

  answer = { status: 409, json: { error: { code: "CONFLICT", message: "A pending invitation for that address, role and place already exists." } } };
  await form.getByRole("button", { name: "Send invitation" }).press("Enter");
  await expect(form.getByRole("alert")).toContainText("A pending invitation for that address, role and place already exists.");
  // The form keeps what was typed so the person can adjust it.
  await expect(form.getByLabel("Name")).toHaveValue("Synthetic invitee");

  answer = { status: 429, json: { error: { code: "TOO_MANY_REQUESTS", message: "Slow down." } } };
  await form.getByRole("button", { name: "Send invitation" }).press("Enter");
  await expect(form.getByRole("alert")).toContainText("Too many invitations to that address recently");
  await expect(page.getByText("an invitation is on its way")).toHaveCount(0);
  await assertAccessible(page);
});

test("invite: a role that cannot invite anyone sees no form", async ({ page }) => {
  await mockAccess(page, { roles: [] });
  await page.goto(`${admin}/access`);
  await expect(page.getByText("You cannot invite staff")).toBeVisible();
  await expect(page.getByRole("form", { name: "Invite a staff member" })).toHaveCount(0);
  await assertAccessible(page);
});

// ---------------------------------------------------------------- /access: pending invitations

test("pending invitations: listed with the inviter badge, revoke needs confirmation and sends one request", async ({ page }) => {
  let revoked = false;
  const deletes: string[] = [];
  const masked = { ...invitation, id: "invite-2", name: "Another invitee", email: "a***@example.test", email_masked: true, invited_by_me: false, can_revoke: false };
  await mockAccess(page, {
    invitations: (route) => route.fulfill(page_(revoked ? [masked] : [invitation, masked])),
  });
  await api(page, "/api/v1/admin/invitations/invite-1", (route) => {
    deletes.push(route.request().method());
    revoked = true;
    return route.fulfill({ status: 204 });
  });
  await page.goto(`${admin}/access`);

  await expect(page.getByText("Invited by you")).toBeVisible();
  await expect(page.getByText("invitee@example.test")).toBeVisible();
  await expect(page.getByText("a***@example.test")).toBeVisible();
  await expect(page.getByText("Address hidden for your role.")).toBeVisible();
  await expect(page.getByText("You cannot revoke this")).toBeVisible();
  await expect(page.getByRole("button", { name: /^Revoke/ })).toHaveCount(1);
  await expect(page.getByText("expires 2026-02-06 UTC").first()).toBeVisible();
  await assertAccessible(page);

  const revoke = page.getByRole("button", { name: "Revoke the invitation for Synthetic invitee" });
  await revoke.press("Enter");
  const dialog = page.getByRole("dialog", { name: "Revoke the invitation for Synthetic invitee?" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Cancel" }).press("Enter");
  await expect(dialog).toHaveCount(0);
  expect(deletes).toEqual([]);

  await revoke.press("Enter");
  await page.getByRole("dialog").getByRole("button", { name: "Yes, revoke" }).press("Enter");
  await expect(page.getByRole("status").filter({ hasText: "Revoked the invitation for Synthetic invitee" })).toBeVisible();
  await expect(page.getByText("Invited by you")).toHaveCount(0);
  expect(deletes).toEqual(["DELETE"]);
});

test("pending invitations: a refused revoke is reported in the dialog", async ({ page }) => {
  await mockAccess(page, { invitations: (route) => route.fulfill(page_([invitation])) });
  await api(page, "/api/v1/admin/invitations/invite-1", (route) => route.fulfill({ status: 404, json: { error: { code: "NOT_FOUND", message: "Not found." } } }));
  await page.goto(`${admin}/access`);
  await page.getByRole("button", { name: /^Revoke the invitation/ }).press("Enter");
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "Yes, revoke" }).press("Enter");
  await expect(dialog.getByRole("alert")).toContainText("does not exist");
  await expect(dialog.getByRole("button", { name: "Yes, revoke" })).toBeEnabled();
});

test("pending invitations: empty, loading, failed and forbidden states", async ({ page }) => {
  let status = 500;
  let release!: () => void;
  const held = new Promise<void>((resolve) => { release = resolve; });
  await mockAccess(page, {
    invitations: async (route) => {
      await held;
      return status === 200 ? route.fulfill(page_([])) : route.fulfill({ status, json: {} });
    },
  });
  await page.goto(`${admin}/access`);
  await expect(page.getByText("Loading invitations…")).toHaveAttribute("aria-busy", "true");
  release();
  await expect(page.getByRole("alert").filter({ hasText: "could not complete" })).toBeVisible();
  await assertAccessible(page);

  status = 200;
  await page.getByRole("button", { name: "Try again" }).press("Enter");
  await expect(page.getByText("No pending invitations")).toBeVisible();
  await assertAccessible(page);

  status = 403;
  await page.goto(`${admin}/access`);
  await expect(page.getByText("Your role cannot see invitations.")).toBeVisible();
  await expect(page.getByRole("button", { name: "Try again" })).toHaveCount(0);
  await assertAccessible(page);
});

test("pending invitations: load more appends the next page", async ({ page }) => {
  const second = { ...invitation, id: "invite-3", name: "Third invitee", email: "third@example.test" };
  await mockAccess(page, {
    invitations: (route) => {
      const cursor = new URL(route.request().url()).searchParams.get("cursor");
      return route.fulfill({ json: { data: [cursor === "next-1" ? second : invitation], meta: { next_cursor: cursor === "next-1" ? null : "next-1" } } });
    },
  });
  await page.goto(`${admin}/access`);
  await page.getByRole("button", { name: "Load more invitations" }).press("Enter");
  await expect(page.getByRole("button", { name: "Revoke the invitation for Third invitee" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Revoke the invitation for Synthetic invitee" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Load more invitations" })).toHaveCount(0);
});

// ---------------------------------------------------------------- /invite (public)

test("invite page: reads the token from the fragment, removes it, and sends it only in the request body", async ({ page }) => {
  const inspects: { url: string; body: unknown }[] = [];
  await basics(page);
  await api(page, "/api/v1/auth/invitations/inspect", (route) => {
    inspects.push({ url: route.request().url(), body: route.request().postDataJSON() });
    return route.fulfill({ json: { data: preview, meta: {} } });
  });
  const external: string[] = [];
  page.on("request", (request) => {
    const url = new URL(request.url());
    if (!["localhost", "127.0.0.1"].includes(url.hostname)) external.push(request.url());
  });

  await page.goto(`${admin}/invite#token=${token}`);
  await expect(page.getByRole("heading", { name: "Accept your invitation" })).toBeVisible();
  await expect(page.getByText("Synthetic invitee", { exact: true })).toBeVisible();
  await expect(page.getByText("Dispatcher", { exact: true })).toBeVisible();
  await expect(page.getByText("Synthetic city", { exact: true })).toBeVisible();
  await expect(page.getByText("2026-02-06 00:00 UTC")).toBeVisible();
  await expect(page.getByText("If you already have an account with this address, enter its current password; it will not be changed.")).toBeVisible();

  expect(page.url()).toBe(`${admin}/invite`);
  expect(await page.evaluate(() => window.location.hash)).toBe("");
  expect(inspects).toHaveLength(1);
  expect(inspects[0].body).toEqual({ token });
  expect(inspects[0].url).not.toContain(token);
  expect(inspects[0].url).not.toContain("?");
  const stored = await page.evaluate(() => JSON.stringify([{ ...localStorage }, { ...sessionStorage }]));
  expect(stored).not.toContain(token);
  expect(external).toEqual([]);
  await assertAccessible(page);
});

test("invite page: accepting sends the token and password in the body and points to sign in", async ({ page }) => {
  const accepts: { url: string; body: unknown }[] = [];
  await basics(page);
  await api(page, "/api/v1/auth/invitations/inspect", (route) => route.fulfill({ json: { data: preview, meta: {} } }));
  await api(page, "/api/v1/auth/invitations/accept", (route) => {
    accepts.push({ url: route.request().url(), body: route.request().postDataJSON() });
    return route.fulfill({ json: { data: { status: "invitation_accepted" }, meta: {} } });
  });
  await page.goto(`${admin}/invite#token=${token}`);

  const password = page.getByLabel("Password", { exact: true });
  await expect(password).toHaveAttribute("type", "password");
  await page.getByLabel("Show passwords").check();
  await expect(password).toHaveAttribute("type", "text");
  await page.getByLabel("Show passwords").uncheck();

  // Too short, then mismatched: nothing is sent.
  await password.fill("short");
  await page.getByLabel("Password again").fill("short");
  await page.getByRole("button", { name: "Accept invitation" }).press("Enter");
  await expect(page.getByRole("main").getByRole("alert")).toContainText("at least 10 characters");
  await expect(password).toBeFocused();

  await password.fill("a-long-enough-synthetic-password");
  await page.getByLabel("Password again").fill("a-different-synthetic-password");
  await page.getByRole("button", { name: "Accept invitation" }).press("Enter");
  await expect(page.getByRole("main").getByRole("alert")).toContainText("The two passwords do not match.");
  expect(accepts).toEqual([]);

  await page.getByLabel("Password again").fill("a-long-enough-synthetic-password");
  await page.getByRole("button", { name: "Accept invitation" }).press("Enter");
  await expect(page.getByRole("status").filter({ hasText: "Invitation accepted. Sign in, then set up two-step sign-in." })).toBeVisible();
  expect(accepts).toEqual([{ url: accepts[0].url, body: { token, password: "a-long-enough-synthetic-password" } }]);
  expect(accepts[0].url).not.toContain(token);
  expect(accepts[0].url).not.toContain("?");
  await expect(page.getByRole("link", { name: "Go to sign in" })).toHaveAttribute("href", "/login");
  await assertAccessible(page);
});

test("invite page: an unknown, used or expired link gets one generic message", async ({ page }) => {
  await basics(page);
  await api(page, "/api/v1/auth/invitations/inspect", (route) => route.fulfill({ status: 404, json: { error: { code: "NOT_FOUND", message: "Not found." } } }));
  await page.goto(`${admin}/invite#token=${token}`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("This invitation link is not valid.");
  await expect(page.getByRole("main").getByRole("alert")).toContainText("expired, been used already, or been withdrawn");
  expect(page.url()).toBe(`${admin}/invite`);
  await assertAccessible(page);
});

test("invite page: a missing or malformed fragment asks for the emailed link and sends nothing", async ({ page }) => {
  const calls: string[] = [];
  await basics(page);
  await api(page, "/api/v1/auth/invitations/inspect", (route) => { calls.push(route.request().url()); return route.fulfill({ status: 404, json: {} }); });
  await page.goto(`${admin}/invite#token=nonsense`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("needs the link from an invitation email");
  await page.goto(`${admin}/invite?token=${token}`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("needs the link from an invitation email");
  expect(calls).toEqual([]);
  await assertAccessible(page);
});

test("invite page: an acceptance that fails as invalid, is rate limited, or loses the connection says so and can be repeated", async ({ page }) => {
  let dead = false;
  let answer: { status: number; json: unknown } = { status: 404, json: { error: { code: "NOT_FOUND", message: "Not found." } } };
  await basics(page);
  await api(page, "/api/v1/auth/invitations/inspect", (route) => route.fulfill({ json: { data: preview, meta: {} } }));
  await api(page, "/api/v1/auth/invitations/accept", (route) => dead ? route.abort("connectionfailed") : route.fulfill(answer));
  await page.goto(`${admin}/invite#token=${token}`);
  await page.getByLabel("Password", { exact: true }).fill("a-long-enough-synthetic-password");
  await page.getByLabel("Password again").fill("a-long-enough-synthetic-password");
  const submit = page.getByRole("button", { name: "Accept invitation" });

  await submit.press("Enter");
  await expect(page.getByRole("main").getByRole("alert")).toContainText("This invitation link is not valid.");

  answer = { status: 429, json: { error: { code: "TOO_MANY_REQUESTS", message: "Slow down." } } };
  await submit.press("Enter");
  await expect(page.getByRole("main").getByRole("alert")).toContainText("Too many attempts with this link.");
  await assertAccessible(page);

  dead = true;
  await submit.press("Enter");
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not reach the server");
});

test("invite page: a rate-limited or unreachable lookup offers Try again", async ({ page }) => {
  let status = 429;
  await basics(page);
  await api(page, "/api/v1/auth/invitations/inspect", (route) => status === 200
    ? route.fulfill({ json: { data: preview, meta: {} } })
    : route.fulfill({ status, json: { error: { code: "X", message: "x" } } }));
  await page.goto(`${admin}/invite#token=${token}`);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("Too many attempts with this link.");
  await assertAccessible(page);

  status = 500;
  await page.getByRole("button", { name: "Try again" }).press("Enter");
  await expect(page.getByRole("main").getByRole("alert")).toContainText("could not complete");

  status = 200;
  await page.getByRole("button", { name: "Try again" }).press("Enter");
  await expect(page.getByRole("button", { name: "Accept invitation" })).toBeVisible();
});

test("invite page: progress while the lookup is slow", async ({ page }) => {
  let release!: () => void;
  const held = new Promise<void>((resolve) => { release = resolve; });
  await basics(page);
  await api(page, "/api/v1/auth/invitations/inspect", async (route) => { await held; await route.fulfill({ json: { data: preview, meta: {} } }); });
  await page.goto(`${admin}/invite#token=${token}`);
  await expect(page.getByText("Checking your invitation…")).toHaveAttribute("aria-busy", "true");
  release();
  await expect(page.getByRole("button", { name: "Accept invitation" })).toBeVisible();
});

// ---------------------------------------------------------------- offline and double submit

test("invite: a lost connection is reported, keeps what was typed, and a slow send blocks a second submit", async ({ page }) => {
  let dead = true;
  let release!: () => void;
  const held = new Promise<void>((resolve) => { release = resolve; });
  const posts: unknown[] = [];
  await mockAccess(page, {
    invitations: async (route) => {
      if (route.request().method() !== "POST") return route.fulfill(page_([]));
      if (dead) return route.abort("connectionfailed");
      posts.push(route.request().postDataJSON());
      await held;
      return route.fulfill({ status: 202, json: { data: { id: "invite-9", status: "invitation_sent" }, meta: {} } });
    },
  });
  await page.goto(`${admin}/access`);
  const form = page.getByRole("form", { name: "Invite a staff member" });
  await form.getByLabel("Name").fill("Synthetic invitee");
  await form.getByLabel("Email address").fill("invitee@example.test");
  await form.getByLabel("Role").selectOption("dispatcher");
  await form.getByLabel("Applies to").selectOption("city:test-city");

  await form.getByRole("button", { name: "Send invitation" }).press("Enter");
  await expect(form.getByRole("alert")).toContainText("could not reach the server");
  await expect(form.getByLabel("Name")).toHaveValue("Synthetic invitee");

  dead = false;
  await form.getByRole("button", { name: "Send invitation" }).press("Enter");
  await expect(form.getByRole("button", { name: "Sending…" })).toBeDisabled();
  await form.getByLabel("Name").press("Enter");
  release();
  await expect(page.getByRole("status").filter({ hasText: "an invitation is on its way" })).toBeVisible();
  expect(posts).toHaveLength(1);
});

test("invite page: a slow acceptance blocks a second submit", async ({ page }) => {
  let release!: () => void;
  const held = new Promise<void>((resolve) => { release = resolve; });
  let accepts = 0;
  await basics(page);
  await api(page, "/api/v1/auth/invitations/inspect", (route) => route.fulfill({ json: { data: preview, meta: {} } }));
  await api(page, "/api/v1/auth/invitations/accept", async (route) => {
    accepts += 1;
    await held;
    await route.fulfill({ json: { data: { status: "invitation_accepted" }, meta: {} } });
  });
  await page.goto(`${admin}/invite#token=${token}`);
  await page.getByLabel("Password", { exact: true }).fill("a-long-enough-synthetic-password");
  await page.getByLabel("Password again").fill("a-long-enough-synthetic-password");
  await page.getByRole("button", { name: "Accept invitation" }).press("Enter");
  await expect(page.getByRole("button", { name: "Accepting…" })).toBeDisabled();
  await page.getByLabel("Password again").press("Enter");
  release();
  await expect(page.getByRole("link", { name: "Go to sign in" })).toBeVisible();
  expect(accepts).toBe(1);
});
