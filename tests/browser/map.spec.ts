// Map component (backlog U-04, ADR-007) on the admin zone screens.
// The API is replaced by route interception; the shape is synthetic, not a real place.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

const admin = "http://localhost:3002";
const city = {
  id: "test-city", name: "Synthetic city", slug: "test-city", country_code: "ZZ", currency_code: "PHP",
  timezone: "UTC", status: "draft", created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};
const geometry = { type: "Polygon", coordinates: [[[10, 20], [12, 20], [12, 23], [10, 23], [10, 20]]] };
const zone = {
  id: "test-zone", city_id: "test-city", name: "Synthetic zone", slug: "test-zone", status: "draft", version: 3, priority: 0,
  geometry, created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z",
};

async function mock(page: Page, mapStyleUrl: string | null) {
  const answer = (pathname: string, body: unknown) =>
    page.route((url) => url.pathname === pathname, (route) => route.fulfill({ json: body }));
  await page.route("**/api/v1/me", (route) => route.fulfill({ status: 401 }));
  await answer("/api/v1/auth/csrf", { data: { csrf_token: "browser-test" } });
  await answer("/api/v1/public/config", { data: { currency: "PHP", map_style_url: mapStyleUrl } });
  await answer("/api/v1/admin/cities/test-city", { data: city });
  await answer("/api/v1/admin/cities/test-city/zones", { data: [zone] });
  await answer("/api/v1/admin/zones/test-zone", { data: zone });
  await answer("/api/v1/admin/zones/test-zone/exclusions", { data: [], meta: { next_cursor: null } });
}

async function openCreate(page: Page) {
  await page.goto(`${admin}/cities/test-city`);
  return page.getByRole("region", { name: "Add a delivery zone" });
}

test("empty MAP_STYLE_URL: a labelled placeholder, no centre, attribution note, GeoJSON field still works", async ({ page }) => {
  await mock(page, null);
  const create = await openCreate(page);
  const map = create.getByTestId("map-view");
  await expect(map).toHaveAttribute("data-map-state", "placeholder");
  await expect(map.getByText("Map not configured")).toBeVisible();
  await expect(map.getByTestId("map-bounds")).toContainText("not centred anywhere");
  await expect(map.getByTestId("map-attribution")).toContainText("Map attribution");
  await expect(map.locator("canvas")).toHaveCount(0);

  const field = create.getByLabel("Shape (GeoJSON Polygon or MultiPolygon)");
  await field.fill("{");
  await expect(map.getByTestId("map-bounds")).toContainText("not centred anywhere");
  await expect(field).toBeEditable();
});

test("a shape fits the bounds from its own coordinates and nothing else", async ({ page }) => {
  await mock(page, null);
  const create = await openCreate(page);
  await create.getByLabel("Shape (GeoJSON Polygon or MultiPolygon)").fill(JSON.stringify(geometry));
  await expect(create.getByTestId("map-bounds")).toContainText("west 10, south 20, east 12, north 23");
});

test("editing a zone shows its bounds beside the editor", async ({ page }) => {
  await mock(page, null);
  await page.goto(`${admin}/cities/test-city`);
  await page.getByRole("button", { name: "Edit zone" }).click();
  const edit = page.getByRole("form", { name: "Edit Synthetic zone" });
  await expect(edit.getByTestId("map-bounds")).toContainText("west 10, south 20, east 12, north 23");
  await expect(edit.getByLabel("Shape (GeoJSON)")).toBeEditable();
});

test("a style that fails to load offers retry, keeps attribution and leaves GeoJSON usable", async ({ page }) => {
  await page.route("https://styles.example.test/**", (route) => route.abort());
  await mock(page, "https://styles.example.test/style.json");
  const create = await openCreate(page);
  const map = create.getByTestId("map-view");
  await expect(map).toHaveAttribute("data-map-state", "failed");
  await expect(map.getByText("The map could not load")).toBeVisible();
  await expect(map.getByRole("button", { name: "Retry map" })).toBeVisible();
  await expect(map.getByTestId("map-attribution")).toBeVisible();
  await expect(create.getByLabel("Shape (GeoJSON Polygon or MultiPolygon)")).toBeEditable();
});

test("a loadable style draws a real map, fitted to the shape, with attribution (ADR-015)", async ({ page }) => {
  const errors: string[] = [];
  page.on("console", (message) => message.type() === "error" && errors.push(message.text()));
  // A style with no tile source: enough to prove MapLibre, its worker and the CSS load in this build.
  await page.route("https://styles.example.test/**", (route) =>
    route.fulfill({
      contentType: "application/json",
      body: JSON.stringify({ version: 8, sources: {}, layers: [{ id: "background", type: "background", paint: { "background-color": "#dddddd" } }] }),
    }),
  );
  await mock(page, "https://styles.example.test/style.json");
  const create = await openCreate(page);
  const map = create.getByTestId("map-view");
  await expect(map).toHaveAttribute("data-map-state", "ready");
  await expect(map.locator("canvas.maplibregl-canvas")).toHaveCount(1);
  await expect(map.locator(".maplibregl-ctrl-attrib")).toHaveCount(1);
  expect(errors.filter((text) => /worker|maplibre/i.test(text))).toEqual([]);
});

test("the map area has no accessibility violations", async ({ page }) => {
  await mock(page, null);
  const create = await openCreate(page);
  await expect(create.getByTestId("map-view")).toBeVisible();
  const { violations } = await new AxeBuilder({ page })
    .include('[data-testid="map-view"]')
    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id }) => id)).toEqual([]);
});

// Drawing a shape on the map (synthetic, background-only style; no real places).
async function readyMap(page: Page) {
  await page.route("https://styles.example.test/**", (route) =>
    route.fulfill({
      contentType: "application/json",
      body: JSON.stringify({ version: 8, sources: {}, layers: [{ id: "background", type: "background", paint: { "background-color": "#dddddd" } }] }),
    }),
  );
  await mock(page, "https://styles.example.test/style.json");
  const create = await openCreate(page);
  const map = create.getByTestId("map-view");
  await expect(map).toHaveAttribute("data-map-state", "ready");
  return { create, map, field: create.getByLabel("Shape (GeoJSON Polygon or MultiPolygon)") };
}

async function clickAt(map: ReturnType<Page["getByTestId"]>, fx: number, fy: number) {
  const box = await map.locator("canvas.maplibregl-canvas").boundingBox();
  if (box === null) throw new Error("no canvas");
  await map.page().mouse.click(box.x + box.width * fx, box.y + box.height * fy);
}

test("a placeholder map has no draw button", async ({ page }) => {
  await mock(page, null);
  const create = await openCreate(page);
  await expect(create.getByTestId("map-view")).toHaveAttribute("data-map-state", "placeholder");
  await expect(create.getByRole("button", { name: "Draw shape on map" })).toHaveCount(0);
});

test("drawing: corners, remove last, use this shape fills a closed Polygon", async ({ page }) => {
  const { create, map, field } = await readyMap(page);
  await create.getByRole("button", { name: "Draw shape on map" }).click();
  const status = map.getByTestId("map-draw-status");
  const use = create.getByRole("button", { name: "Use this shape" });
  await expect(status).toContainText("Click the map to place the first corner");
  await expect(use).toBeDisabled();

  await clickAt(map, 0.2, 0.2);
  await expect(status).toContainText("1 corner placed");
  await clickAt(map, 0.8, 0.2);
  await expect(status).toContainText("2 corners placed");
  await clickAt(map, 0.8, 0.8);
  await expect(status).toContainText("3 corners placed");
  await expect(status).not.toContainText("needs at least 3");
  await expect(use).toBeEnabled();
  await clickAt(map, 0.2, 0.8);
  await expect(status).toContainText("4 corners placed");

  await create.getByRole("button", { name: "Remove last corner" }).click();
  await expect(status).toContainText("3 corners placed");

  const axe = await new AxeBuilder({ page })
    .include('[role="group"][aria-label="Draw a shape"]')
    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(axe.violations.map(({ id }) => id)).toEqual([]);

  await use.click();
  await expect(create.getByRole("button", { name: "Draw shape on map" })).toBeVisible();
  const shape = JSON.parse(await field.inputValue());
  expect(shape.type).toBe("Polygon");
  const ring = shape.coordinates[0];
  expect(ring).toHaveLength(4);
  expect(ring[0]).toEqual(ring[ring.length - 1]);
  expect(new Set(ring.slice(0, 3).map((p: number[]) => p.join(","))).size).toBe(3);
  await expect(map.getByTestId("map-bounds")).toContainText("Zone bounds");
});

test("cancel drawing leaves the field unchanged", async ({ page }) => {
  const { create, map, field } = await readyMap(page);
  await field.fill(JSON.stringify(geometry));
  await create.getByRole("button", { name: "Draw shape on map" }).click();
  await clickAt(map, 0.3, 0.3);
  await clickAt(map, 0.7, 0.3);
  await clickAt(map, 0.5, 0.7);
  await create.getByRole("button", { name: "Cancel drawing" }).click();
  await expect(create.getByRole("button", { name: "Draw shape on map" })).toBeVisible();
  expect(JSON.parse(await field.inputValue())).toEqual(geometry);
});
