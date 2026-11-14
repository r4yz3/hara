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
