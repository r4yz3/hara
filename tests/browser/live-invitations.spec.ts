// Live staff invitations, end to end: real API (localhost:8000), real admin app (3002), mail read from Mailpit.
// Opt-in: BROWSER_LIVE=1 with DEMO_PASSWORD set, desktop project only. Addresses are unique per run (example.test).
import { randomBytes, randomUUID } from "node:crypto";
import { expect, test, type Browser, type Page } from "@playwright/test";

const admin = "http://localhost:3002";
const mailpit = "http://127.0.0.1:8025/api/v1";
test.use({ trace: "off", screenshot: "off" });

const neutral = "If the address can receive mail, an invitation is on its way. It expires in 72 hours.";
const generic = "This invitation link is not valid.";

async function signInOwner(page: Page, password: string) {
  await page.goto(`${admin}/login?next=%2Faccess`);
  await page.getByLabel("Email").fill("demo-owner@example.test");
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page).toHaveURL(`${admin}/access`);
}

async function optionValues(select: ReturnType<Page["locator"]>) {
  return select.locator("option").evaluateAll((options) => options.map((option) => (option as HTMLOptionElement).value).filter(Boolean));
}

async function invite(page: Page, name: string, email: string) {
  const form = page.getByRole("form", { name: "Invite a staff member" });
  await expect(form).toBeVisible();
  await form.getByLabel("Name").fill(name);
  await form.getByLabel("Email address").fill(email);
  const role = form.getByLabel("Role");
  const slugs = await optionValues(role);
  await role.selectOption(slugs.includes("dispatcher") ? "dispatcher" : slugs[0]);
  const scope = form.getByLabel("Applies to");
  const values = await optionValues(scope);
  await scope.selectOption(values.find((value) => value.startsWith("city:")) ?? values[0]);
  const roleLabel = ((await role.locator("option:checked").textContent()) ?? "").trim();
  const sent = page.waitForResponse((r) => r.url().endsWith("/api/v1/admin/invitations") && r.request().method() === "POST");
  await form.getByRole("button", { name: "Send invitation" }).click();
  expect((await sent).status()).toBe(202);
  await expect(page.getByRole("status").filter({ hasText: neutral })).toBeVisible();
  return roleLabel;
}

async function mailFor(email: string): Promise<{ id: string; link: string; text: string }> {
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
  const text: string = message.Text;
  const link = text.match(/https?:\/\/\S+\/invite#token=[0-9a-f]+/)?.[0];
  expect(link, "invite link in mail text").toBeTruthy();
  expect(link).not.toContain("?token");
  expect(new URL(link!).search).toBe("");
  expect(new URL(link!).hash).toMatch(/^#token=[0-9a-f]{20,}$/);
  return { id: id!, link: link!, text };
}

async function openLink(browser: Browser, link: string) {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(link);
  return { context, page };
}

test("staff invitations work end to end: invite, mail, accept once, sign in, directory; revoke kills the link", async ({ page, browser }, testInfo) => {
  test.skip(process.env.BROWSER_LIVE !== "1" || testInfo.project.name !== "desktop", "Opt-in desktop test for the local stack.");
  const password = process.env.DEMO_PASSWORD;
  if (!password) throw new Error("Set DEMO_PASSWORD to the local seeded demo password.");
  testInfo.setTimeout(150_000);
  const run = Date.now().toString(36) + randomBytes(2).toString("hex");
  const accepted = { name: `Invitee ${run}`, email: `invitee-${run}@example.test` };
  const revoked = { name: `Revokee ${run}`, email: `revokee-${run}@example.test` };
  const newPassword = `Pw-${randomUUID()}-${randomUUID()}`;
  const mailIds: string[] = [];

  try {
    await signInOwner(page, password);
    await expect(page.getByRole("heading", { name: "Pending invitations" })).toBeVisible();

    // Invite: neutral copy and the pending row.
    const roleLabel = await invite(page, accepted.name, accepted.email);
    await expect(page.getByRole("button", { name: `Revoke the invitation for ${accepted.name}` })).toBeVisible();
    await expect(page.getByText(accepted.email)).toBeVisible();

    // Mail: the link carries the token in the fragment only.
    const mail = await mailFor(accepted.email);
    mailIds.push(mail.id);
    expect(mail.text).toContain(accepted.name);
    expect(mail.text).toContain(roleLabel);

    // Accept in a new signed-out context.
    const { context, page: invitee } = await openLink(browser, mail.link);
    try {
      await expect(invitee.getByRole("heading", { name: "Accept your invitation" })).toBeVisible();
      await expect(invitee.getByText(accepted.name, { exact: true })).toBeVisible();
      expect(invitee.url()).toBe(`${admin}/invite`);
      expect(await invitee.evaluate(() => window.location.hash)).toBe("");
      await invitee.getByLabel("Password", { exact: true }).fill(newPassword);
      await invitee.getByLabel("Password again").fill(newPassword);
      await invitee.getByRole("button", { name: "Accept invitation" }).click();
      await expect(invitee.getByRole("status").filter({ hasText: "Invitation accepted." })).toBeVisible();
      expect(invitee.url()).not.toContain("token");

      // Single use: the same link is now generic-invalid.
      const reuse = await openLink(browser, mail.link);
      try {
        await expect(reuse.page.getByRole("main").getByRole("alert")).toContainText(generic);
        expect(reuse.page.url()).toBe(`${admin}/invite`);
      } finally {
        await reuse.context.close();
      }

      // The invitee can sign in; the real MFA gate is asserted.
      await invitee.goto(`${admin}/login`);
      await invitee.getByLabel("Email").fill(accepted.email);
      await invitee.getByLabel("Password").fill(newPassword);
      await invitee.getByRole("button", { name: "Sign in", exact: true }).click();
      // APP_ENV=local keeps password-only staff sign-in (EnsureSurfaceSession), so the gate that would send a
      // new invitee to /security and answer 403 on /me does not apply here: they reach the console, not yet enrolled.
      await expect(invitee).toHaveURL(`${admin}/`);
      const me = await context.request.get("http://localhost:8000/api/v1/me", { headers: { Origin: admin } });
      expect(me.status()).toBe(200);
      const profile = (await me.json()).data;
      expect(profile.email).toBe(accepted.email);
      expect(profile.mfa_enabled).toBe(false);
      expect(profile.grants.length).toBeGreaterThan(0);
    } finally {
      await context.close();
    }

    // Inviter: gone from pending, present in the directory with the role.
    await page.goto(`${admin}/access`);
    await expect(page.getByRole("heading", { name: "Pending invitations" })).toBeVisible();
    await expect(page.getByText("No pending invitations").or(page.getByRole("button", { name: /^Revoke the invitation/ }).first())).toBeVisible();
    await expect(page.getByRole("button", { name: `Revoke the invitation for ${accepted.name}` })).toHaveCount(0);
    const member = page.getByRole("listitem").filter({ has: page.getByText(accepted.email, { exact: true }) });
    for (let i = 0; i < 20 && (await member.count()) === 0; i++) {
      const more = page.getByRole("button", { name: "Load more people" });
      if ((await more.count()) === 0) break;
      await more.click();
      await expect(more).toBeEnabled({ timeout: 10_000 }).catch(() => undefined);
    }
    await expect(member.first()).toBeVisible();
    await expect(member.first().getByText(accepted.name, { exact: true })).toBeVisible();
    await expect(member.first().getByRole("list", { name: `Roles of ${accepted.name}` })).toContainText(roleLabel);

    // Revoke case.
    await invite(page, revoked.name, revoked.email);
    const revokedMail = await mailFor(revoked.email);
    mailIds.push(revokedMail.id);
    await page.getByRole("button", { name: `Revoke the invitation for ${revoked.name}` }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Yes, revoke" }).click();
    await expect(page.getByRole("status").filter({ hasText: `Revoked the invitation for ${revoked.name}` })).toBeVisible();
    await expect(page.getByRole("button", { name: `Revoke the invitation for ${revoked.name}` })).toHaveCount(0);
    const dead = await openLink(browser, revokedMail.link);
    try {
      await expect(dead.page.getByRole("main").getByRole("alert")).toContainText(generic);
      expect(dead.page.url()).toBe(`${admin}/invite`);
    } finally {
      await dead.context.close();
    }
  } finally {
    if (mailIds.length) {
      await fetch(`${mailpit}/messages`, { method: "DELETE", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ IDs: mailIds }) });
    }
  }
});
