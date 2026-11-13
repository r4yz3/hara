import { randomUUID } from "node:crypto";
import { expect, test } from "@playwright/test";

const admin = "http://localhost:3002";
const customer = "http://localhost:3000";
const api = "http://localhost:8000/api/v1";
test.use({ trace: "off", screenshot: "off" });

test("a city and its GeoJSON zone control live serviceability", async ({ page, browser }, testInfo) => {
  test.skip(process.env.BROWSER_LIVE !== "1" || testInfo.project.name !== "desktop", "Opt-in desktop test for the local stack.");
  const password = process.env.DEMO_PASSWORD;
  if (!password) throw new Error("Set DEMO_PASSWORD to the local seeded demo password.");
  testInfo.setTimeout(90_000);
  const slug = `browser-${randomUUID()}`;
  const cityName = `Browser test ${slug}`;
  const ring = [[100, 10], [100.02, 10], [100.02, 10.02], [100, 10.02], [100, 10]];
  let cityId: string | undefined;
  let zoneId: string | undefined;
  let exclusionId: string | undefined;

  await page.goto(`${admin}/login?next=%2Fcities`);
  await page.getByLabel("Email").fill("demo-owner@example.test");
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page).toHaveURL(`${admin}/cities`);
  await page.goto(`${admin}/settings`);
  await expect(page.getByRole("heading", { name: "Service checks" })).toBeVisible();
  await expect(page.getByText("Database", { exact: true })).toBeVisible();
  await page.goto(`${admin}/cities`);

  const request = page.context().request;
  async function mutate(method: "POST" | "PATCH" | "DELETE", path: string, data?: object) {
    const csrf = await request.get(`${api}/auth/csrf`, { headers: { Origin: admin } });
    expect(csrf.ok()).toBeTruthy();
    const { data: { csrf_token } } = await csrf.json();
    const response = await request.fetch(`${api}${path}`, {
      method, data, headers: { Origin: admin, "X-CSRF-TOKEN": csrf_token, Accept: "application/json" },
    });
    expect(response.ok(), `${method} ${path}: ${response.status()}`).toBeTruthy();
  }

  try {
    const form = page.getByRole("region", { name: "Create a city" });
    await form.getByLabel("Name", { exact: true }).fill(cityName);
    await form.getByLabel("Country code").fill("ZZ");
    await form.getByLabel("Timezone").selectOption("UTC");
    const cityCreated = page.waitForResponse((response) => response.url() === `${api}/admin/cities` && response.request().method() === "POST");
    await form.getByRole("button", { name: "Create draft city" }).click();
    const cityResponse = await cityCreated;
    expect(cityResponse.status()).toBe(201);
    cityId = (await cityResponse.json()).data.id;
    await page.getByRole("link", { name: new RegExp(cityName) }).click();

    await page.getByRole("button", { name: "Activate", exact: true }).click();
    await page.getByRole("button", { name: "Yes, activate" }).click();
    await expect(page.getByRole("button", { name: "Pause", exact: true })).toBeVisible();

    const zone = page.getByRole("region", { name: "Add a delivery zone" });
    await zone.getByLabel("Name", { exact: true }).fill("Browser test zone");
    await zone.getByLabel("Short name for links").fill("browser-test-zone");
    await zone.getByLabel("Priority").fill("1000");
    const shape = zone.getByLabel(/Shape/);
    await shape.fill(JSON.stringify({ type: "Polygon", coordinates: [[[100, 10], [100.02, 10.02], [100.02, 10], [100, 10.02], [100, 10]]] }));
    const addZone = zone.getByRole("button", { name: "Add draft zone" });
    const rejected = page.waitForResponse((response) => response.url() === `${api}/admin/cities/${cityId}/zones` && response.request().method() === "POST");
    await addZone.click();
    expect((await rejected).status()).toBe(422);
    await expect(zone.getByText(/not valid|self.intersection/i).last()).toBeVisible();

    await shape.fill(JSON.stringify({ type: "Polygon", coordinates: [ring] }));
    const zoneCreated = page.waitForResponse((response) => response.url() === `${api}/admin/cities/${cityId}/zones` && response.request().method() === "POST");
    await addZone.click();
    const zoneResponse = await zoneCreated;
    expect(zoneResponse.status()).toBe(201);
    zoneId = (await zoneResponse.json()).data.id;
    await page.reload();
    await expect(page.getByText("Browser test zone", { exact: true })).toBeVisible();
    const stored = await request.get(`${api}/admin/zones/${zoneId}`, { headers: { Origin: admin } });
    expect(stored.ok()).toBeTruthy();
    expect((await stored.json()).data.geometry).toEqual({ type: "MultiPolygon", coordinates: [[ring]] });
    await page.getByRole("button", { name: "Edit zone" }).click();
    const edit = page.getByRole("form", { name: "Edit Browser test zone" });
    await edit.getByLabel("Name").fill("Edited browser test zone");
    await edit.getByRole("button", { name: "Save zone" }).click();
    await expect(page.getByText("Edited browser test zone", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Activate", exact: true }).click();
    await page.getByRole("button", { name: "Yes, activate" }).click();
    await expect(page.getByText(/version 3/)).toBeVisible();

    const customerContext = await browser.newContext();
    try {
      const customerPage = await customerContext.newPage();
      await customerPage.goto(`${customer}/location`);
      await customerPage.getByLabel("Latitude").fill("10.01");
      await customerPage.getByLabel("Longitude").fill("100.01");
      const checked = customerPage.waitForResponse((response) => response.url() === `${api}/locations/serviceability` && response.request().method() === "POST");
      await customerPage.getByRole("button", { name: "Check", exact: true }).click();
      const coverage = await checked;
      expect(coverage.status()).toBe(200);
      expect((await coverage.json()).data.zone?.id).toBe(zoneId);
      await expect(customerPage.getByText(`Edited browser test zone, ${cityName}.`)).toBeVisible();
      await page.getByRole("button", { name: "Coverage exclusions" }).click();
      await expect(page.getByText("No coverage exclusions.")).toBeVisible();
      const exclusion = page.getByRole("form", { name: "Add coverage exclusion" });
      await exclusion.getByLabel("Exclusion reason").fill("Synthetic browser closure");
      await exclusion.getByLabel("Exclusion shape (GeoJSON)").fill(JSON.stringify({ type: "Polygon", coordinates: [ring] }));
      const created = page.waitForResponse((response) => response.url() === `${api}/admin/zones/${zoneId}/exclusions` && response.request().method() === "POST");
      await exclusion.getByRole("button", { name: "Add exclusion" }).click();
      const exclusionResponse = await created;
      expect(exclusionResponse.status()).toBe(201);
      exclusionId = (await exclusionResponse.json()).data.id;
      await expect(page.getByText("Synthetic browser closure", { exact: true })).toBeVisible();

      const excluded = customerPage.waitForResponse((response) => response.url() === `${api}/locations/serviceability` && response.request().method() === "POST");
      await customerPage.getByRole("button", { name: "Check", exact: true }).click();
      expect((await (await excluded).json()).data.zone?.id).not.toBe(zoneId);

      await page.getByRole("button", { name: "Remove exclusion" }).click();
      await page.getByRole("button", { name: "Confirm removal" }).click();
      await expect(page.getByText("No coverage exclusions.")).toBeVisible();
      exclusionId = undefined;
      await customerPage.getByRole("button", { name: "Check", exact: true }).click();
      await expect(customerPage.getByText(`Edited browser test zone, ${cityName}.`)).toBeVisible();
      await customerPage.goto(`${customer}/login`);
      await customerPage.getByLabel("Email").fill("demo-customer@example.test");
      await customerPage.getByLabel("Password").fill(password);
      await customerPage.getByRole("button", { name: "Sign in", exact: true }).click();
      await expect(customerPage).toHaveURL(`${customer}/`);
      const protectedResponse = await customerContext.request.get(`${api}/admin/cities`, { headers: { Origin: customer } });
      expect(protectedResponse.status()).toBe(404);
    } finally {
      await customerContext.close();
    }
  } finally {
    if (exclusionId) await mutate("DELETE", `/admin/exclusions/${exclusionId}`);
    if (cityId) {
      if (zoneId) {
        const response = await request.get(`${api}/admin/zones/${zoneId}`, { headers: { Origin: admin } });
        const { data: currentZone } = await response.json();
        if (currentZone.status === "active") {
          await mutate("POST", `/admin/zones/${zoneId}/pause`, { version: currentZone.version, reason: "Browser test cleanup" });
          currentZone.version++;
        }
        await mutate("POST", `/admin/zones/${zoneId}/archive`, { version: currentZone.version });
        const archived = await request.get(`${api}/admin/zones/${zoneId}`, { headers: { Origin: admin } });
        expect((await archived.json()).data.status).toBe("archived");
      }
      const current = await request.get(`${api}/admin/cities/${cityId}`, { headers: { Origin: admin } });
      const { data: city } = await current.json();
      if (city.status === "active") await mutate("PATCH", `/admin/cities/${cityId}`, { status: "paused" });
      await mutate("PATCH", `/admin/cities/${cityId}`, { status: "archived" });
    }
  }
});
