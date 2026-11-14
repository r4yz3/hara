import { createRequire } from "node:module";
import path from "node:path";

import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

// qrcode-generator is an admin-web dependency, so resolve it from that app.
const requireFromAdmin = createRequire(path.resolve(process.cwd(), "apps/admin-web/package.json"));
const qrcode = requireFromAdmin("qrcode-generator") as typeof import("qrcode-generator");

const admin = "http://localhost:3002";
const enrolmentOnlyMessage = "Set up two-step sign-in before using the admin console.";
const secret = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP";
const otpauth = `otpauth://totp/Console:staff@example.test?secret=${secret}&issuer=Console`;
const recoveryCodes = Array.from({ length: 10 }, (_, index) => `abcd-ef${index}0-1234-5678`);
const meta = { request_id: "test" };
const cors = {
  "Access-Control-Allow-Origin": admin,
  "Access-Control-Allow-Credentials": "true",
  "Access-Control-Expose-Headers": "Retry-After",
};

function reply(route: Route, init: { status?: number; json?: unknown }) {
  return route.fulfill({ status: init.status ?? 200, json: init.json ?? {}, headers: cors });
}

const error = (status: number, message: string) => ({ status, json: { error: { code: "X", message, request_id: "test" } } });

async function mockEnrolment(page: Page) {
  await page.route("**/api/v1/**", (route) => reply(route, error(401, "Unauthenticated.")));
  await page.route("**/api/v1/auth/csrf", (route) => reply(route, { json: { data: { csrf_token: "browser-test" } } }));
  await page.route("**/api/v1/me", (route) => reply(route, error(403, enrolmentOnlyMessage)));
  await page.route("**/api/v1/me/mfa/totp", (route) =>
    reply(route, { status: 201, json: { data: { secret, otpauth_uri: otpauth }, meta } }),
  );
  await page.route("**/api/v1/me/mfa/totp/confirm", (route) =>
    reply(route, { json: { data: { recovery_codes: recoveryCodes }, meta } }),
  );
}

async function startSetup(page: Page) {
  await mockEnrolment(page);
  await page.goto(`${admin}/security`);
  await page.getByRole("button", { name: "Set up" }).click();
}

const qrImage = (page: Page) => page.getByRole("img", { name: "QR code for your authenticator app" });

test.describe("staff setup QR code", () => {
  test("shows the QR above the typed key, with the key still available", async ({ page }) => {
    await startSetup(page);

    await expect(qrImage(page)).toBeVisible();
    await expect(page.getByText("Scan this with your authenticator app, or type the key below.")).toBeVisible();
    await expect(page.getByText("JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP")).toBeVisible();
    await expect(page.getByRole("button", { name: "Copy key" })).toBeVisible();

    const qrBox = await qrImage(page).boundingBox();
    const keyBox = await page.getByText("JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP").boundingBox();
    expect(qrBox && keyBox && qrBox.y < keyBox.y).toBe(true);
    // It fits the viewport width and is square.
    expect(qrBox!.width).toBeLessThanOrEqual(page.viewportSize()!.width);
    expect(Math.abs(qrBox!.width - qrBox!.height)).toBeLessThan(1);
  });

  test("the rendered modules match a QR built from the same otpauth URI", async ({ page }) => {
    await startSetup(page);
    await expect(qrImage(page)).toBeVisible();

    const expected = qrcode(0, "M");
    expected.addData(otpauth);
    expected.make();
    const size = expected.getModuleCount();
    let dark = 0;
    for (let row = 0; row < size; row++) for (let col = 0; col < size; col++) if (expected.isDark(row, col)) dark++;

    await expect(qrImage(page)).toHaveAttribute("data-modules", String(size));
    const rendered = await qrImage(page).evaluate((svg) => {
      const d = svg.querySelector("path")?.getAttribute("d") ?? "";
      return { dark: (d.match(/M/g) ?? []).length, d };
    });
    expect(rendered.dark).toBe(dark);

    // Every dark module sits where the reference matrix says (offset by the 4-module quiet zone).
    const cells = [...rendered.d.matchAll(/M(\d+) (\d+)h1v1h-1z/g)].map((m) => `${Number(m[2]) - 4},${Number(m[1]) - 4}`);
    const want: string[] = [];
    for (let row = 0; row < size; row++) for (let col = 0; col < size; col++) if (expected.isDark(row, col)) want.push(`${row},${col}`);
    expect(cells).toEqual(want);

    // Dark on white, and no attribute carries the secret.
    await expect(qrImage(page).locator("rect")).toHaveAttribute("fill", "#ffffff");
    await expect(qrImage(page).locator("path")).toHaveAttribute("fill", "#000000");
    expect(await qrImage(page).evaluate((svg) => svg.outerHTML)).not.toContain(secret);
  });

  test("nothing but the enrolment response carries the secret, and the page keeps it out of URL and storage", async ({ page }) => {
    const leaks: string[] = [];
    const watch = (page: Page) =>
      page.on("request", (request) => {
        const where = `${request.url()}\n${request.postData() ?? ""}\n${JSON.stringify(request.headers())}`;
        if (where.includes(secret) || where.includes(encodeURIComponent(secret))) leaks.push(request.url());
      });
    watch(page);
    const consoleText: string[] = [];
    page.on("console", (message) => consoleText.push(message.text()));

    await startSetup(page);
    await expect(qrImage(page)).toBeVisible();

    expect(leaks).toEqual([]);
    expect(page.url()).not.toContain(secret);
    expect(consoleText.join("\n")).not.toContain(secret);
    const stored = await page.evaluate(() => JSON.stringify([{ ...localStorage }, { ...sessionStorage }]));
    expect(stored).not.toContain(secret);
    expect(await page.evaluate(() => performance.getEntriesByType("resource").map((e) => e.name).join("\n"))).not.toContain(secret);
  });

  test("leaving the setup step clears the QR", async ({ page }) => {
    await startSetup(page);
    await expect(qrImage(page)).toBeVisible();

    await page.getByLabel("6-digit code from the app").fill("222222");
    await page.getByRole("button", { name: "Confirm" }).click();

    await expect(page.getByRole("heading", { name: "Save your recovery codes" })).toBeVisible();
    await expect(qrImage(page)).toHaveCount(0);
    expect(await page.evaluate(() => document.body.innerHTML.includes("otpauth"))).toBe(false);
  });

  test("the setup step has no accessibility violations", async ({ page }) => {
    await startSetup(page);
    await expect(qrImage(page)).toBeVisible();

    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
    const { violations } = await new AxeBuilder({ page })
      .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
      .analyze();
    expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
  });
});
