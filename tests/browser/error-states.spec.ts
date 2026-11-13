// Error and not-found screens of all three web apps (backlog U-03, docs/qa/ui-state-matrix.md).
// `/test-faults/<kind>` throws on purpose in the dev servers these tests use (it is a "not found" in a production
// build): "server" throws while rendering on the server, "client" throws while rendering in the browser.
// The thrown messages carry markers that must never reach the page.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

const apps = {
  customer: "http://localhost:3000",
  merchant: "http://localhost:3001",
  admin: "http://localhost:3002",
} as const;

async function assertAccessible(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

async function quietSession(page: Page) {
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
}

for (const [app, base] of Object.entries(apps)) {
  for (const kind of ["server", "client"] as const) {
    test(`errors: ${app} shows a plain message when a page fails on the ${kind}, and leaks nothing`, async ({ page }) => {
      await quietSession(page);
      await page.goto(`${base}/test-faults/${kind}`);

      await expect(page.getByRole("heading", { level: 1, name: "Something went wrong" })).toBeVisible();
      await expect(page.getByRole("alert").filter({ hasText: "This page" })).toContainText("This page could not be shown.");
      await expect(page.getByRole("button", { name: "Try again" })).toBeVisible();
      await expect(page.getByRole("link", { name: "Go to the home page" })).toHaveAttribute("href", "/");

      // Nothing from the thrown error: no message, no marker, no stack.
      const text = await page.locator("body").innerText();
      expect(text).not.toMatch(/SECRET|stack|at .*\(.*:\d+/i);
      const reference = text.match(/Reference: (\S+)/);
      if (reference) expect(reference[1]).toMatch(/^[A-Za-z0-9]{1,8}$/);

      await assertAccessible(page);
    });
  }

  test(`errors: ${app} "Try again" asks for the page again and the home link leaves the error`, async ({ page }) => {
    await quietSession(page);
    await page.goto(`${base}/test-faults/server`);
    await expect(page.getByRole("heading", { name: "Something went wrong" })).toBeVisible();

    const again = page.waitForRequest((request) => request.url().includes("/test-faults/server"));
    await page.getByRole("button", { name: "Try again" }).click();
    await again;
    // The fault is permanent, so the screen is still there, still usable.
    await expect(page.getByRole("heading", { name: "Something went wrong" })).toBeVisible();

    await page.getByRole("link", { name: "Go to the home page" }).click();
    await expect(page).toHaveURL(`${base}/`);
    await expect(page.getByText("Something went wrong")).toHaveCount(0);
    await expect(page.locator("main").first()).toBeVisible();
  });

  test(`errors: ${app} says so for an unknown address and leads home`, async ({ page }) => {
    await quietSession(page);
    const response = await page.goto(`${base}/there-is-no-such-page`);
    expect(response?.status()).toBe(404);

    await expect(page.getByRole("heading", { level: 1, name: "Page not found" })).toBeVisible();
    await expect(page.getByText("There is no page at this address.")).toBeVisible();
    await assertAccessible(page);

    await page.getByRole("link", { name: "Go to the home page" }).click();
    await expect(page).toHaveURL(`${base}/`);
    await expect(page.getByText("Page not found")).toHaveCount(0);
  });

  test(`errors: ${app} treats an unknown test-fault kind as not found, not as a crash`, async ({ page }) => {
    await quietSession(page);
    await page.goto(`${base}/test-faults/other`);
    await expect(page.getByRole("heading", { level: 1, name: "Page not found" })).toBeVisible();
  });
}
