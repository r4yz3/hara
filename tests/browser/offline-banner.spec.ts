// The shared "You are offline" banner (ADR-019, backlog U-02) in the root layout of all three web apps.
// context.setOffline() makes the browser fire its offline and online events, as a real lost connection does.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

const apps = {
  customer: "http://localhost:3000/",
  merchant: "http://localhost:3001/",
  admin: "http://localhost:3002/",
} as const;

const message = "You are offline. Changes can't be saved until you reconnect.";

for (const [app, url] of Object.entries(apps)) {
  test(`offline banner: ${app} announces a lost connection and clears when it returns`, async ({ page, context }) => {
    await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
    await page.goto(url);

    // One polite status region, empty while online and not stealing focus.
    const region = page.getByTestId("offline-banner");
    await expect(region).toHaveAttribute("role", "status");
    await expect(region).toHaveAttribute("aria-live", "polite");
    await expect(region).toHaveText("");
    await expect(page.getByText(message)).toHaveCount(0);

    await page.locator("body").press("Tab");
    const focusedBefore = await page.evaluate(() => document.activeElement?.outerHTML);

    await context.setOffline(true);
    await expect(region).toHaveText(message);
    // The text sits inside the live region, so it is announced as a status.
    await expect(page.getByRole("status").filter({ hasText: message })).toBeVisible();
    expect(await page.evaluate(() => document.activeElement?.outerHTML)).toBe(focusedBefore);

    const { violations } = await new AxeBuilder({ page })
      .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
      .analyze();
    expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);

    await context.setOffline(false);
    await expect(region).toHaveText("");
    await expect(page.getByText(message)).toHaveCount(0);
  });
}
