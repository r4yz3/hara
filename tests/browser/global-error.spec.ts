// The root-layout error screen (`app/global-error.tsx`) of all three web apps (backlog U-03, G4a).
// `next dev` shows its own overlay instead of this screen, so these tests run against production builds
// (playwright.config.ts: `next build` into `.next-fault` with NEXT_PUBLIC_TEST_FAULTS=1, then `next start` on
// ports 4100 to 4102). In those builds the `LayoutFault` component in the root layout (packages/ui/src/fault.tsx)
// throws in the browser at /test-faults/layout; the thrown message carries a marker that must never be shown.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

const apps = {
  customer: "http://localhost:4100",
  merchant: "http://localhost:4101",
  admin: "http://localhost:4102",
} as const;

for (const [app, base] of Object.entries(apps)) {
  test(`global error: ${app} shows its own safe screen when the root layout fails, and leaks nothing`, async ({ page }) => {
    await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
    await page.goto(`${base}/test-faults/layout`);

    // The screen brings its own document, so it is the page's <html>/<body>, not nested in the layout's.
    await expect(page.getByRole("heading", { level: 1, name: "Something went wrong" })).toBeVisible();
    await expect(page).toHaveTitle("Something went wrong");
    expect(await page.locator("html").count()).toBe(1);
    expect(await page.locator("html").getAttribute("lang")).toBe("en");
    await expect(page.getByRole("alert").filter({ hasText: "This page" })).toContainText("This page could not be shown.");
    await expect(page.getByRole("button", { name: "Try again" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Go to the home page" })).toHaveAttribute("href", "/");

    // Nothing from the thrown error, in the text or in the markup.
    const text = await page.locator("body").innerText();
    expect(text).not.toMatch(/SECRET|LAYOUT|stack|at .*\(.*:\d+/i);
    expect(await page.content()).not.toContain("LAYOUT-SECRET-DETAIL");
    const reference = text.match(/Reference: (\S+)/);
    if (reference) expect(reference[1]).toMatch(/^[A-Za-z0-9]{1,8}$/);

    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
    const { violations } = await new AxeBuilder({ page })
      .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
      .analyze();
    expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
  });

  test(`global error: ${app} home link leaves the error, and the layout works on other addresses`, async ({ page }) => {
    await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
    await page.goto(`${base}/test-faults/layout`);
    await expect(page.getByRole("heading", { name: "Something went wrong" })).toBeVisible();

    await page.getByRole("link", { name: "Go to the home page" }).click();
    await expect(page).toHaveURL(`${base}/`);
    await expect(page.getByText("Something went wrong")).toHaveCount(0);
    await expect(page.locator("main").first()).toBeVisible();
  });

  test(`global error: ${app} test-fault routes stay not found in a production build`, async ({ page }) => {
    for (const kind of ["server", "client"]) {
      const response = await page.goto(`${base}/test-faults/${kind}`);
      expect(response?.status()).toBe(404);
      await expect(page.getByRole("heading", { level: 1, name: "Page not found" })).toBeVisible();
    }
  });
}
