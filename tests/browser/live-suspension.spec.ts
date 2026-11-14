// Live staff suspension, end to end: real API (localhost:8000), real admin app (3002), mail read from Mailpit.
// Opt-in: BROWSER_LIVE=1 with DEMO_PASSWORD set, desktop project only. Addresses are unique per run (example.test).
import { randomBytes, randomUUID } from "node:crypto";
import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

const admin = "http://localhost:3002";
const api = "http://localhost:8000/api/v1";
const mailpit = "http://127.0.0.1:8025/api/v1";
test.use({ trace: "off", screenshot: "off" });

async function signIn(page: Page, email: string, password: string, next = "/access") {
  await page.goto(`${admin}/login?next=${encodeURIComponent(next)}`);
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
}

async function postJson(request: APIRequestContext, path: string, body: unknown) {
  const csrf = (await (await request.get(`${api}/auth/csrf`, { headers: { Origin: admin } })).json()).data.csrf_token;
  return request.post(`${api}${path}`, { headers: { Origin: admin, "X-CSRF-TOKEN": csrf, "Content-Type": "application/json" }, data: body });
}

async function inviteMail(email: string): Promise<{ id: string; link: string }> {
  let id: string | undefined;
  await expect
    .poll(
      async () => {
        const r = await fetch(`${mailpit}/search?query=${encodeURIComponent(`to:${email}`)}`);
        id = (await r.json()).messages?.[0]?.ID;
        return id;
      },
      { timeout: 30_000, message: `no mail for ${email}` },
    )
    .toBeTruthy();
  const message = await (await fetch(`${mailpit}/message/${id}`)).json();
  const link = (message.Text as string).match(/https?:\/\/\S+\/invite#token=[0-9a-f]+/)?.[0];
  expect(link, "invite link in mail text").toBeTruthy();
  return { id: id!, link: link! };
}

async function findMember(page: Page, email: string) {
  await page.goto(`${admin}/access`);
  const member = page.getByRole("listitem").filter({ has: page.getByText(email, { exact: true }) });
  for (let i = 0; i < 20 && (await member.count()) === 0; i++) {
    const more = page.getByRole("button", { name: "Load more people" });
    if ((await more.count()) === 0) break;
    await more.click();
    await expect(more).toBeEnabled({ timeout: 10_000 }).catch(() => undefined);
  }
  await expect(member.first()).toBeVisible();
  return member.first();
}

test("staff suspension works end to end against the real API", async ({ page: ownerPage, browser }, testInfo) => {
  test.skip(process.env.BROWSER_LIVE !== "1" || testInfo.project.name !== "desktop", "Opt-in desktop test for the local stack.");
  const password = process.env.DEMO_PASSWORD;
  if (!password) throw new Error("Set DEMO_PASSWORD to the local seeded demo password.");
  testInfo.setTimeout(180_000);
  const run = Date.now().toString(36) + randomBytes(2).toString("hex");
  const person = { name: `Synthetic Suspendee ${run}`, email: `suspendee-${run}@example.test` };
  const personPassword = `Pw-${randomUUID()}-${randomUUID()}`;
  const reason = `Synthetic suspension check ${run}`;
  const note = `Synthetic reactivation ${run}`;
  const mailIds: string[] = [];
  const ownerContext = ownerPage.context();
  const ownerEmail = "demo-owner@example.test";
  const origin = { headers: { Origin: admin } };

  try {
    await signIn(ownerPage, ownerEmail, password);
    await expect(ownerPage).toHaveURL(`${admin}/access`);

    // 1. Invite, accept through the mail link, sign in as the invitee in a second context.
    const form = ownerPage.getByRole("form", { name: "Invite a staff member" });
    await form.getByLabel("Name").fill(person.name);
    await form.getByLabel("Email address").fill(person.email);
    await form.getByLabel("Role").selectOption("dispatcher");
    const scope = form.getByLabel("Applies to");
    const scopes = await scope.locator("option").evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value).filter(Boolean));
    await scope.selectOption(scopes.find((v) => v.startsWith("city:")) ?? scopes[0]);
    const sent = ownerPage.waitForResponse((r) => r.url().endsWith("/api/v1/admin/invitations") && r.request().method() === "POST");
    await form.getByRole("button", { name: "Send invitation" }).click();
    expect((await sent).status()).toBe(202);
    const mail = await inviteMail(person.email);
    mailIds.push(mail.id);

    const inviteeContext = await browser.newContext();
    const invitee = await inviteeContext.newPage();
    try {
      await invitee.goto(mail.link);
      await invitee.getByLabel("Password", { exact: true }).fill(personPassword);
      await invitee.getByLabel("Password again").fill(personPassword);
      await invitee.getByRole("button", { name: "Accept invitation" }).click();
      await expect(invitee.getByRole("status").filter({ hasText: "Invitation accepted." })).toBeVisible();
      await signIn(invitee, person.email, personPassword, "/");
      await expect(invitee).toHaveURL(`${admin}/`);
      const meBefore = await inviteeContext.request.get(`${api}/me`, origin);
      expect(meBefore.status(), "invitee session works before suspension").toBe(200);
      const memberId: string = (await meBefore.json()).data.id;

      // 2. Owner suspends from /access: reason is required, then confirmed.
      const row = await findMember(ownerPage, person.email);
      await expect(row.getByRole("button", { name: `Reactivate ${person.name}` })).toHaveCount(0);
      await row.getByRole("button", { name: `Suspend ${person.name}` }).click();
      const dialog = ownerPage.getByRole("dialog");
      await dialog.getByRole("button", { name: "Yes, suspend" }).click();
      await expect(dialog.getByText("Say why this account is being suspended.")).toBeVisible();
      await dialog.getByLabel("Reason").fill(reason);
      const suspended = ownerPage.waitForResponse((r) => /\/admin\/users\/[^/]+\/suspend$/.test(r.url()) && r.request().method() === "POST");
      await dialog.getByRole("button", { name: "Yes, suspend" }).click();
      expect((await suspended).status()).toBe(204);
      await expect(ownerPage.getByRole("status").filter({ hasText: `Suspended ${person.name}.` })).toBeVisible();

      // 3. The open session stops on the very next request; signing in again gets the generic failure.
      const meAfter = await inviteeContext.request.get(`${api}/me`, origin);
      expect([401, 403], `invitee /me after suspension was ${meAfter.status()}`).toContain(meAfter.status());
      await invitee.goto(`${admin}/access`);
      // The console shows its signed-out state in place (no staff data) with a way back to sign-in.
      await expect(invitee.getByRole("main").getByRole("alert").filter({ hasText: "You are signed out. Sign in and try again." })).toBeVisible();
      await expect(invitee.getByRole("main").getByRole("link", { name: "Sign in" })).toBeVisible();
      await expect(invitee.getByText(person.email)).toHaveCount(0);
      await signIn(invitee, person.email, personPassword, "/");
      await expect(invitee.getByText("That email and password do not match an account that can use this app.")).toBeVisible();
      await expect(invitee).toHaveURL(/\/login/);
      expect([401, 403]).toContain((await inviteeContext.request.get(`${api}/me`, origin)).status());

      // 5. Guard rails: self-suspension refused (no button either); a second suspend is a 409.
      const me = (await (await ownerContext.request.get(`${api}/me`, origin)).json()).data;
      expect(me.email).toBe(ownerEmail);
      const self = await postJson(ownerContext.request, `/admin/users/${me.id}/suspend`, { reason });
      expect(self.status()).toBe(403);
      expect((await self.json()).error.message).toBe("You cannot suspend or reactivate your own account.");
      const ownRow = await findMember(ownerPage, ownerEmail);
      await expect(ownRow.getByRole("button", { name: /^(Suspend|Reactivate) / })).toHaveCount(0);
      expect((await postJson(ownerContext.request, `/admin/users/${memberId}/suspend`, { reason })).status()).toBe(409);

      // 4. Owner reactivates; the person can sign in again.
      const row2 = await findMember(ownerPage, person.email);
      await expect(row2.getByRole("button", { name: `Suspend ${person.name}` })).toHaveCount(0);
      await row2.getByRole("button", { name: `Reactivate ${person.name}` }).click();
      const dialog2 = ownerPage.getByRole("dialog");
      await dialog2.getByLabel("Note (optional)").fill(note);
      const reactivated = ownerPage.waitForResponse((r) => /\/admin\/users\/[^/]+\/reactivate$/.test(r.url()) && r.request().method() === "POST");
      await dialog2.getByRole("button", { name: "Yes, reactivate" }).click();
      expect((await reactivated).status()).toBe(204);
      await expect(ownerPage.getByRole("status").filter({ hasText: `Reactivated ${person.name}.` })).toBeVisible();
      await signIn(invitee, person.email, personPassword, "/");
      await expect(invitee).toHaveURL(`${admin}/`);
      const meBack = await inviteeContext.request.get(`${api}/me`, origin);
      expect(meBack.status()).toBe(200);
      expect((await meBack.json()).data.email).toBe(person.email);

      // 6. Audit entries for both actions.
      for (const [action, text] of [
        ["user.suspended", reason],
        ["user.reactivated", note],
      ] as const) {
        const res = await ownerContext.request.get(`${api}/admin/audit-logs?action=${action}&subject_id=${memberId}`, origin);
        expect(res.status()).toBe(200);
        const entries = (await res.json()).data as Array<{ actor_user_id: string; after: unknown }>;
        expect(entries.length, `${action} audit entries`).toBe(1);
        expect(entries[0].actor_user_id).toBe(me.id);
        expect(JSON.stringify(entries[0].after)).toContain(text);
      }
    } finally {
      await inviteeContext.close();
    }
  } finally {
    if (mailIds.length) {
      await fetch(`${mailpit}/messages`, { method: "DELETE", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ IDs: mailIds }) });
    }
  }
});
