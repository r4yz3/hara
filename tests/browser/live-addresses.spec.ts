// Live saved delivery addresses, end to end: real API (localhost:8000), real customer app (3000), merchant app (3001).
// Opt-in: BROWSER_LIVE=1 with DEMO_PASSWORD set, desktop project only. Needs the seeded demo accounts
// (dev.ps1 seed-demo). Positions are synthetic: the seeder's demo zone is the square lon 100.00..100.02,
// lat 10.00..10.02 (DevelopmentSeeder); the "inside" point is in it, the "outside" point is far from it.
// Every address this test creates is removed through the API at the end, even when a step fails.
import { expect, test, type BrowserContext, type Page } from "@playwright/test";

const customer = "http://localhost:3000";
const merchant = "http://localhost:3001";
const api = "http://localhost:8000/api/v1";
const demoCity = "Demo City (synthetic, not a real place)";
test.use({ trace: "off", screenshot: "off" });

const inside = { line: "Synthetic test address A", landmark: "Synthetic landmark alpha", lat: "10.0123", lon: "100.0145" };
const outside = { line: "Synthetic test address B", landmark: "Synthetic landmark bravo", lat: "10.4321", lon: "100.4567" };
const edited = "Synthetic landmark alpha edited";

type Listed = { id: string };

async function csrf(context: BrowserContext, origin: string): Promise<string> {
  const response = await context.request.get(`${api}/auth/csrf`, { headers: { Origin: origin } });
  expect(response.status()).toBe(200);
  return (await response.json()).data.csrf_token;
}

/** Delete every saved address of the signed-in demo customer through the API. Returns how many there were. */
async function deleteAll(context: BrowserContext): Promise<number> {
  const list = await context.request.get(`${api}/me/addresses`, { headers: { Origin: customer } });
  if (list.status() !== 200) return -1;
  const items = ((await list.json()).data ?? []) as Listed[];
  if (items.length === 0) return 0;
  const token = await csrf(context, customer);
  for (const item of items) {
    const gone = await context.request.delete(`${api}/me/addresses/${item.id}`, {
      headers: { Origin: customer, "X-CSRF-TOKEN": token },
    });
    expect([204, 404]).toContain(gone.status());
  }
  return items.length;
}

async function signIn(page: Page, base: string, email: string, password: string) {
  await page.goto(`${base}/login`);
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
}

const section = (page: Page) => page.getByRole("region", { name: "Delivery addresses" });
const rows = (page: Page) => section(page).getByRole("list", { name: "Saved addresses" }).getByRole("listitem");
const rowFor = (page: Page, line: string) => rows(page).filter({ hasText: line });

async function fill(page: Page, a: typeof inside) {
  await page.getByLabel("Address", { exact: true }).fill(a.line);
  await page.getByLabel("Landmark", { exact: true }).fill(a.landmark);
  await page.getByLabel("Latitude").fill(a.lat);
  await page.getByLabel("Longitude").fill(a.lon);
}

test("the demo customer manages saved addresses against the live API; nothing leaks to URLs or storage; other apps are refused", async ({ page, browser }, testInfo) => {
  test.skip(process.env.BROWSER_LIVE !== "1" || testInfo.project.name !== "desktop", "Opt-in desktop test for the local stack.");
  const password = process.env.DEMO_PASSWORD;
  if (!password) throw new Error("Set DEMO_PASSWORD to the local seeded demo password.");
  testInfo.setTimeout(150_000);
  const context = page.context();
  const urls: string[] = [];
  page.on("request", (request) => urls.push(request.url()));

  try {
    // Sign in as the demo customer; step 1: no saved addresses (leftovers removed through the API).
    await signIn(page, customer, "demo-customer@example.test", password);
    await expect(page).toHaveURL(`${customer}/`);
    const removed = await deleteAll(context);
    expect(removed, "the customer API list answered 200").toBeGreaterThanOrEqual(0);
    console.log(`step 1: leftover addresses removed through the API: ${removed}`);
    await page.goto(`${customer}/account`);
    await expect(section(page)).toBeVisible();
    await expect(page.getByText("No saved addresses yet")).toBeVisible();
    await expect(rows(page)).toHaveCount(0);

    // Step 2: first address inside the demo zone: Default badge and the city line.
    await fill(page, inside);
    await page.getByLabel("Label").fill("Synthetic A");
    await page.getByRole("button", { name: "Save address" }).click();
    await expect(section(page).getByRole("status").filter({ hasText: "Address saved." })).toBeVisible();
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText(inside.line);
    await expect(rows(page).first()).toContainText("Default");
    await expect(rows(page).first()).toContainText(`Inside a delivery area (${demoCity})`);

    // Step 3: second address outside every zone, "Make this my default" left unticked.
    await page.getByRole("button", { name: "Add an address" }).click();
    await fill(page, outside);
    await page.getByLabel("Label").fill("Synthetic B");
    await expect(page.getByLabel("Make this my default")).not.toBeChecked();
    await page.getByRole("button", { name: "Save address" }).click();
    await expect(rows(page)).toHaveCount(2);
    const second = rowFor(page, outside.line);
    await expect(second).toHaveCount(1);
    await expect(second).toContainText("Not inside an open delivery area yet");
    await expect(second).not.toContainText("Default");
    await expect(rowFor(page, inside.line)).toContainText("Default");

    // Step 4: make the second one the default; the badge moves.
    await second.getByRole("button", { name: /Make default/ }).click();
    await expect(section(page).getByRole("status").filter({ hasText: "Synthetic B is now your default address." })).toBeVisible();
    await expect(rowFor(page, outside.line)).toContainText("Default");
    await expect(rowFor(page, inside.line)).not.toContainText("Default");
    await expect(rows(page).first()).toContainText(outside.line);

    // Step 5: edit the first address's landmark; the new text survives a reload.
    await rowFor(page, inside.line).getByRole("button", { name: /^Edit/ }).click();
    await expect(page.getByLabel("Landmark", { exact: true })).toHaveValue(inside.landmark);
    await page.getByLabel("Landmark", { exact: true }).fill(edited);
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(section(page).getByRole("status").filter({ hasText: "Address saved." })).toBeVisible();
    await expect(rowFor(page, inside.line)).toContainText(`Landmark: ${edited}`);
    await page.reload();
    await expect(rows(page)).toHaveCount(2);
    await expect(rowFor(page, inside.line)).toContainText(`Landmark: ${edited}`);
    await expect(rowFor(page, inside.line)).toContainText(`Inside a delivery area (${demoCity})`);
    await expect(rowFor(page, outside.line)).toContainText("Default");

    // Step 6: delete with the confirmation dialog; gone, and still gone after a reload.
    await rowFor(page, inside.line).getByRole("button", { name: /^Delete/ }).click();
    const dialog = page.getByRole("dialog", { name: "Delete Synthetic A?" });
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: "Yes, delete" }).click();
    await expect(section(page).getByRole("status").filter({ hasText: "Synthetic A was deleted." })).toBeVisible();
    await expect(rows(page)).toHaveCount(1);
    await expect(rowFor(page, inside.line)).toHaveCount(0);
    await page.reload();
    await expect(rows(page)).toHaveCount(1);
    await expect(rowFor(page, inside.line)).toHaveCount(0);
    await expect(rowFor(page, outside.line)).toHaveCount(1);

    // Step 7: no address text or position in any request URL, page URL, or browser storage.
    const needles = [inside.line, outside.line, inside.landmark, outside.landmark, inside.lat, inside.lon, outside.lat, outside.lon, "Synthetic", "landmark", "latitude", "longitude"];
    const haystacks = urls.flatMap((url) => {
      let decoded = url;
      try {
        decoded = decodeURIComponent(url);
      } catch {
        // keep the raw form
      }
      return [url.toLowerCase(), decoded.toLowerCase()];
    });
    expect(urls.some((url) => url.includes("/api/v1/me/addresses"))).toBe(true);
    for (const needle of needles) {
      for (const haystack of haystacks) expect(haystack, `request URL leaks "${needle}"`).not.toContain(needle.toLowerCase());
    }
    expect(new URL(page.url()).search).toBe("");
    expect(new URL(page.url()).hash).toBe("");
    const storage = (await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }))).toLowerCase();
    for (const needle of needles) expect(storage, `browser storage leaks "${needle}"`).not.toContain(needle.toLowerCase());
    console.log(`step 7: ${urls.length} request URLs checked against ${needles.length} needles; storage and page URL clean`);

    // Step 8: the merchant app cannot reach the customer addresses (404), signed in or not.
    const other = await browser.newContext();
    try {
      const merchantPage = await other.newPage();
      await signIn(merchantPage, merchant, "demo-merchant@example.test", password);
      await expect(merchantPage).toHaveURL(`${merchant}/`);
      const viaMerchant = await other.request.get(`${api}/me/addresses`, { headers: { Origin: merchant } });
      expect(viaMerchant.status()).toBe(404);
      expect(JSON.stringify(await viaMerchant.json().catch(() => ({})))).not.toContain("Synthetic");
      const viaCustomerOrigin = await other.request.get(`${api}/me/addresses`, { headers: { Origin: customer } });
      expect([401, 403, 404]).toContain(viaCustomerOrigin.status());
      console.log(`step 8: merchant origin answered ${viaMerchant.status()}; merchant session on customer origin answered ${viaCustomerOrigin.status()}`);
    } finally {
      await other.close();
    }
    // The customer session itself on the merchant origin is also refused.
    const crossOrigin = await context.request.get(`${api}/me/addresses`, { headers: { Origin: merchant } });
    expect([401, 404]).toContain(crossOrigin.status());
  } finally {
    // Cleanup: remove every address, even after a failure.
    const left = await deleteAll(context).catch(() => -1);
    const check = await context.request.get(`${api}/me/addresses`, { headers: { Origin: customer } }).catch(() => null);
    const remaining = check && check.status() === 200 ? ((await check.json()).data as Listed[]).length : -1;
    console.log(`cleanup: removed ${left}, remaining after cleanup: ${remaining}`);
    expect(remaining).toBe(0);
  }
});
