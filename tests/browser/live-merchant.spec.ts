// Live merchant sign-in, end to end: real API (localhost:8000), real merchant app (3001), admin (3002), customer (3000).
// Opt-in: BROWSER_LIVE=1 with DEMO_PASSWORD set, desktop project only. Needs the seeded demo accounts
// (dev.ps1 seed-demo): demo-merchant@example.test is an active owner of a synthetic merchant. No branch exists.
import { expect, test, type Page } from "@playwright/test";

const merchant = "http://localhost:3001";
const admin = "http://localhost:3002";
const customer = "http://localhost:3000";
const api = "http://localhost:8000/api/v1";
test.use({ trace: "off", screenshot: "off" });

const generic = "That email and password do not match an account that can use this app.";

async function signIn(page: Page, email: string, password: string) {
  await page.goto(`${merchant}/login`);
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
}

test("the demo merchant signs in on the merchant app only; others are refused; sign-out ends the session", async ({ page, browser }, testInfo) => {
  test.skip(process.env.BROWSER_LIVE !== "1" || testInfo.project.name !== "desktop", "Opt-in desktop test for the local stack.");
  const password = process.env.DEMO_PASSWORD;
  if (!password) throw new Error("Set DEMO_PASSWORD to the local seeded demo password.");
  testInfo.setTimeout(120_000);
  const context = page.context();

  // 1. Sign in and see the overview, with no fabricated figures.
  await signIn(page, "demo-merchant@example.test", password);
  await expect(page).toHaveURL(`${merchant}/`);
  await expect(page.getByRole("heading", { name: "Overview", level: 1 })).toBeVisible();
  await expect(page.getByText("Signed in as")).toBeVisible();
  await expect(page.getByText("Demo Merchant Owner (synthetic)")).toBeVisible();
  await expect(page.getByRole("heading", { name: "Your store is not set up yet" })).toBeVisible();
  await expect(page.getByText("None assigned")).toBeVisible();
  await expect(page.getByRole("button", { name: /pause store/i })).toBeDisabled();
  await expect(page.getByText("No orders")).toHaveCount(3);
  const body = (await page.locator("body").innerText()).replace(/\s+/g, " ");
  expect(body, "no currency amounts on the overview").not.toMatch(/[₱$]\s?\d|\bPHP\s?\d/);
  expect(body, "no order counts or sales figures").not.toMatch(/\b\d+\s+(orders?|sales)\b/i);

  // 2. Cookie attributes, read from the browser context.
  const cookies = await context.cookies([merchant, api]);
  const session = cookies.filter((c) => c.name === "fd_merchant_session");
  expect(session).toHaveLength(1);
  expect(session[0].httpOnly).toBe(true);
  expect(session[0].sameSite).toBe("Lax");
  expect((await context.cookies()).filter((c) => /^fd_(admin|customer)_session$/.test(c.name))).toHaveLength(0);

  // 3. The same session opens neither the admin app nor the customer account.
  const meMerchant = await context.request.get(`${api}/me`, { headers: { Origin: merchant } });
  expect(meMerchant.status()).toBe(200);
  expect((await meMerchant.json()).data.email).toBe("demo-merchant@example.test");
  expect((await context.request.get(`${api}/me`, { headers: { Origin: admin } })).status()).toBe(401);
  expect((await context.request.get(`${api}/me`, { headers: { Origin: customer } })).status()).toBe(401);
  await page.goto(`${admin}/access`);
  await expect(page.getByRole("link", { name: /sign in/i }).first()).toBeVisible();
  await expect(page.getByText("Demo Merchant Owner (synthetic)")).toHaveCount(0);
  await page.goto(`${customer}/account`);
  await expect(page).toHaveURL(/\/login\?next=%2Faccount$/);
  await expect(page.getByText("demo-merchant@example.test")).toHaveCount(0);

  // 4. The demo customer and demo owner are refused with the generic failure; they hold no authenticated session.
  for (const email of ["demo-customer@example.test", "demo-owner@example.test"]) {
    const other = await browser.newContext();
    try {
      const refused = await other.newPage();
      await signIn(refused, email, password);
      await expect(refused.getByRole("alert").filter({ hasText: generic })).toBeVisible();
      await expect(refused).toHaveURL(`${merchant}/login`);
      expect((await other.request.get(`${api}/me`, { headers: { Origin: merchant } })).status()).toBe(401);
    } finally {
      await other.close();
    }
  }

  // 5. Sign-out ends the session.
  await page.goto(merchant);
  await page.getByRole("button", { name: "Sign out" }).click();
  await expect(page).toHaveURL(`${merchant}/`);
  await expect(page.getByRole("link", { name: "Sign in" }).first()).toBeVisible();
  expect((await context.request.get(`${api}/me`, { headers: { Origin: merchant } })).status()).toBe(401);
});
