// Customer /account: saved delivery addresses (no map), proven with the API replaced by route interception.
// All coordinates are synthetic (near longitude 100, latitude 10) and name no real place.
import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

const customer = "http://localhost:3000";

const user = {
  id: "01JTESTTESTTESTTESTTESTTES", name: "Synthetic Customer", email: "customer@example.test",
  email_verified: true, mfa_enabled: false, surface: "customer", grants: [],
};
const failure = (message: string, fields?: Record<string, string[]>) => ({
  error: { code: "X", message, fields, request_id: "test" },
});

type Address = {
  id: string; tag: string | null; address_line: string; landmark: string; latitude: number; longitude: number;
  is_default: boolean; city: { id: string; name: string } | null; serviceable: boolean; created_at: string; updated_at: string;
};

const city = { id: "01JCITYCITYCITYCITYCITYCIT", name: "Testville" };
const make = (n: number, over: Partial<Address> = {}): Address => ({
  id: `01JADDR${String(n).padStart(19, "0")}`,
  tag: null, address_line: `${n} Sample Street, Block ${n}`, landmark: `Blue gate ${n}`,
  latitude: 10.0123 + n / 1000, longitude: 100.0456 + n / 1000,
  is_default: false, city: null, serviceable: false,
  created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z", ...over,
});
const home = make(1, { tag: "Home", is_default: true, city, serviceable: true });
const work = make(2, { tag: "Work" });

function reply(route: Route, status: number, json?: unknown) {
  const headers = { "Access-Control-Allow-Origin": customer, "Access-Control-Allow-Credentials": "true" };
  return status === 204 ? route.fulfill({ status, headers }) : route.fulfill({ status, json, headers });
}

function gate() {
  let open!: () => void;
  const opened = new Promise<void>((resolve) => { open = resolve; });
  return { opened, open };
}

async function assertAccessible(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

type Call = { method: string; path: string; body: unknown; csrf: string | null };

/**
 * A small stateful stand-in for the addresses endpoints. `before` may answer a request itself
 * (return true once it has); otherwise the request is applied to `state.items`.
 */
async function addressesApi(
  page: Page,
  initial: Address[],
  before?: (route: Route, call: Call) => Promise<boolean> | boolean,
) {
  const state = { items: initial.map((item) => ({ ...item })), calls: [] as Call[] };
  await page.route("**/api/v1/auth/csrf", (route) => reply(route, 200, { data: { csrf_token: "browser-test" } }));
  await page.route("**/api/v1/me", (route) => reply(route, 200, { data: user, meta: { request_id: "test" } }));
  await page.route("**/api/v1/me/addresses**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const method = request.method();
    if (method === "OPTIONS") return route.fallback();
    const call: Call = {
      method, path, body: request.postData() ? request.postDataJSON() : null, csrf: request.headers()["x-csrf-token"] ?? null,
    };
    state.calls.push(call);
    if (before && (await before(route, call))) return;

    const id = path.split("/")[5];
    const found = state.items.find((item) => item.id === id);
    if (method === "GET") return reply(route, 200, { data: state.items, meta: { request_id: "test" } });
    if (method === "POST" && !id) {
      const body = call.body as Record<string, unknown>;
      const created = make(state.items.length + 10, {
        address_line: String(body.address_line), landmark: String(body.landmark), tag: (body.tag as string | undefined) ?? null,
        latitude: Number(body.latitude), longitude: Number(body.longitude),
        is_default: state.items.length === 0 || body.is_default === true,
      });
      if (created.is_default) state.items = state.items.map((item) => ({ ...item, is_default: false }));
      state.items = [created, ...state.items];
      return reply(route, 201, { data: created, meta: { request_id: "test" } });
    }
    if (!found) return reply(route, 404, failure("Not found."));
    if (method === "DELETE") {
      state.items = state.items.filter((item) => item.id !== id);
      return reply(route, 204);
    }
    if (method === "PATCH") {
      const body = call.body as Record<string, unknown>;
      Object.assign(found, { ...body, latitude: Number(body.latitude), longitude: Number(body.longitude) });
      return reply(route, 200, { data: found, meta: { request_id: "test" } });
    }
    if (method === "POST" && path.endsWith("/default")) {
      state.items = state.items.map((item) => ({ ...item, is_default: item.id === id })).sort((a, b) => Number(b.is_default) - Number(a.is_default));
      return reply(route, 200, { data: { ...found, is_default: true }, meta: { request_id: "test" } });
    }
    return reply(route, 404, failure("Not found."));
  });

  return state;
}

const section = (page: Page) => page.getByRole("region", { name: "Delivery addresses" });
const rows = (page: Page) => section(page).getByRole("list", { name: "Saved addresses" }).getByRole("listitem");

async function fillNew(page: Page, over: { line?: string; landmark?: string; tag?: string; lat?: string; lon?: string } = {}) {
  await page.getByLabel("Address", { exact: true }).fill(over.line ?? "9 Synthetic Lane");
  await page.getByLabel("Landmark", { exact: true }).fill(over.landmark ?? "Green fence");
  if (over.tag) await page.getByLabel("Label").fill(over.tag);
  await page.getByLabel("Latitude").fill(over.lat ?? "10.1234");
  await page.getByLabel("Longitude").fill(over.lon ?? "100.5678");
}

test.describe("customer account: saved addresses", () => {
  test("an empty list says so, shows the add form, and a saved address becomes the default", async ({ page }) => {
    const api = await addressesApi(page, []);
    await page.goto(`${customer}/account`);
    await expect(page.getByText("No saved addresses yet")).toBeVisible();
    await expect(page.getByText("A map pin is not available yet").first()).toBeVisible();
    await expect(page.getByRole("button", { name: "Use this device's location" })).toBeVisible();
    await expect(page.getByLabel("Landmark", { exact: true })).toHaveAccessibleDescription(/Riders use this to find the place/);
    await assertAccessible(page);

    await fillNew(page, { tag: "Home" });
    await page.getByRole("button", { name: "Save address" }).click();
    await expect(section(page).getByRole("status").filter({ hasText: "Address saved." })).toBeVisible();
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText("Default");
    await expect(rows(page).first()).toContainText("9 Synthetic Lane");

    const post = api.calls.find((call) => call.method === "POST");
    expect(post?.body).toEqual({ address_line: "9 Synthetic Lane", landmark: "Green fence", tag: "Home", latitude: "10.1234", longitude: "100.5678" });
    expect(post?.csrf).toBe("browser-test");
  });

  test("the list shows the default first, badges and coverage lines, and no raw coordinates", async ({ page }) => {
    await addressesApi(page, [home, work]);
    await page.goto(`${customer}/account`);
    await expect(rows(page)).toHaveCount(2);
    await expect(rows(page).nth(0)).toContainText("Home");
    await expect(rows(page).nth(0)).toContainText("Default");
    await expect(rows(page).nth(0)).toContainText("Inside a delivery area (Testville)");
    await expect(rows(page).nth(1)).toContainText("Not inside an open delivery area yet");
    await expect(rows(page).nth(1)).not.toContainText("Default");
    await expect(rows(page).nth(1)).toContainText("Landmark: Blue gate 2");
    await expect(section(page)).not.toContainText(String(home.latitude));
    await expect(section(page)).not.toContainText(String(home.longitude));
    await assertAccessible(page);
  });

  test("an address without a tag is called Address", async ({ page }) => {
    await addressesApi(page, [make(3)]);
    await page.goto(`${customer}/account`);
    await expect(rows(page).first().getByRole("heading", { name: "Address" })).toBeVisible();
  });

  test("editing sends the changed fields, prefilled including the position, and shows the result", async ({ page }) => {
    const api = await addressesApi(page, [home, work]);
    await page.goto(`${customer}/account`);
    await rows(page).nth(1).getByRole("button", { name: /^Edit/ }).click();
    await expect(page.getByLabel("Address", { exact: true })).toHaveValue(work.address_line);
    await expect(page.getByLabel("Latitude")).toHaveValue(String(Number(work.latitude.toFixed(6))));
    await assertAccessible(page);

    await page.getByLabel("Landmark", { exact: true }).fill("Red roof");
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(section(page).getByRole("status").filter({ hasText: "Address saved." })).toBeVisible();
    await expect(rows(page).nth(1)).toContainText("Landmark: Red roof");
    const patch = api.calls.find((call) => call.method === "PATCH");
    expect(patch?.path).toBe(`/api/v1/me/addresses/${work.id}`);
    expect(patch?.body).toMatchObject({ landmark: "Red roof", address_line: work.address_line, tag: "Work" });
  });

  test("delete asks first, Cancel focus is first, Escape returns focus, confirming deletes", async ({ page }) => {
    const api = await addressesApi(page, [home, work]);
    await page.goto(`${customer}/account`);
    const trigger = rows(page).nth(1).getByRole("button", { name: /^Delete/ });
    await trigger.click();
    const dialog = page.getByRole("dialog", { name: "Delete Work?" });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole("button", { name: "Cancel" })).toBeFocused();
    await assertAccessible(page);

    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    expect(api.calls.some((call) => call.method === "DELETE")).toBe(false);

    await trigger.click();
    await page.getByRole("dialog").getByRole("button", { name: "Yes, delete" }).click();
    await expect(section(page).getByRole("status").filter({ hasText: "Work was deleted." })).toBeVisible();
    await expect(rows(page)).toHaveCount(1);
    expect(api.calls.filter((call) => call.method === "DELETE")).toHaveLength(1);
  });

  test("Make default moves the badge and says so", async ({ page }) => {
    const api = await addressesApi(page, [home, work]);
    await page.goto(`${customer}/account`);
    await expect(rows(page).nth(0).getByRole("button", { name: /Make default/ })).toHaveCount(0);
    await rows(page).nth(1).getByRole("button", { name: /Make default/ }).click();
    await expect(section(page).getByRole("status").filter({ hasText: "Work is now your default address." })).toBeVisible();
    await expect(rows(page).nth(0)).toContainText("Work");
    await expect(rows(page).nth(0)).toContainText("Default");
    expect(api.calls.some((call) => call.method === "POST" && call.path.endsWith("/default"))).toBe(true);
  });

  test("client checks and a server 422 focus the first refused field with its message", async ({ page }) => {
    await addressesApi(page, [], (route, call) => {
      if (call.method !== "POST") return false;
      void reply(route, 422, failure("The given data was invalid.", { landmark: ["The landmark is not allowed."], longitude: ["Out of range."] }));
      return true;
    });
    await page.goto(`${customer}/account`);
    await page.getByRole("button", { name: "Save address" }).click();
    await expect(page.getByLabel("Address", { exact: true })).toBeFocused();
    await expect(page.getByText("Enter the address.")).toBeVisible();
    await expect(page.getByText("Enter a landmark riders can look for.")).toBeVisible();

    await fillNew(page);
    await page.getByRole("button", { name: "Save address" }).click();
    await expect(page.getByText("The landmark is not allowed.")).toBeVisible();
    await expect(page.getByLabel("Landmark", { exact: true })).toBeFocused();
    await expect(page.getByLabel("Landmark", { exact: true })).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByLabel("Longitude")).toHaveAccessibleDescription(/Out of range\./);
    await assertAccessible(page);
  });

  test("the 21st address is refused by the server with a message on the address field", async ({ page }) => {
    await addressesApi(page, [], (route, call) => {
      if (call.method !== "POST") return false;
      void reply(route, 422, failure("Invalid.", { address_line: ["You can save at most 20 addresses."] }));
      return true;
    });
    await page.goto(`${customer}/account`);
    await fillNew(page);
    await page.getByRole("button", { name: "Save address" }).click();
    await expect(page.getByText("You can save at most 20 addresses.")).toBeVisible();
    await expect(page.getByLabel("Address", { exact: true })).toBeFocused();
  });

  test("with 20 addresses the add form is replaced by the limit message", async ({ page }) => {
    await addressesApi(page, Array.from({ length: 20 }, (_, index) => make(index + 1, { is_default: index === 0 })));
    await page.goto(`${customer}/account`);
    await expect(rows(page)).toHaveCount(20);
    await expect(page.getByText("You have 20 saved addresses, the most we keep. Delete one to add another.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Save address" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Add an address" })).toHaveCount(0);
  });

  test("a 404 on delete means the address is gone: the list reloads and says so", async ({ page }) => {
    const api = await addressesApi(page, [home, work], (route, call) => {
      if (call.method !== "DELETE") return false;
      api.items = api.items.filter((item) => item.id !== work.id);
      void reply(route, 404, failure("Not found."));
      return true;
    });
    await page.goto(`${customer}/account`);
    await rows(page).nth(1).getByRole("button", { name: /^Delete/ }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Yes, delete" }).click();
    await expect(section(page).getByRole("alert").filter({ hasText: "That address no longer exists." })).toBeVisible();
    await expect(page.getByRole("dialog")).toBeHidden();
    await expect(rows(page)).toHaveCount(1);
  });

  test("a 404 on edit means the address is gone: the form closes, the list reloads and says so", async ({ page }) => {
    const api = await addressesApi(page, [home, work], (route, call) => {
      if (call.method !== "PATCH") return false;
      api.items = api.items.filter((item) => item.id !== work.id);
      void reply(route, 404, failure("Not found."));
      return true;
    });
    await page.goto(`${customer}/account`);
    await rows(page).nth(1).getByRole("button", { name: /^Edit/ }).click();
    await expect(page.getByLabel("Address", { exact: true })).toHaveValue(work.address_line);
    await page.getByLabel("Landmark", { exact: true }).fill("Red roof");
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(section(page).getByRole("alert").filter({ hasText: "That address no longer exists." })).toBeVisible();
    await expect(page.getByRole("button", { name: "Save changes" })).toHaveCount(0);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText("Home");
    expect(api.calls.some((call) => call.method === "PATCH" && call.path === `/api/v1/me/addresses/${work.id}`)).toBe(true);
  });

  test("a 404 on Make default means the address is gone: the list reloads and says so", async ({ page }) => {
    const api = await addressesApi(page, [home, work], (route, call) => {
      if (call.method !== "POST" || !call.path.endsWith("/default")) return false;
      api.items = api.items.filter((item) => item.id !== work.id);
      void reply(route, 404, failure("Not found."));
      return true;
    });
    await page.goto(`${customer}/account`);
    await rows(page).nth(1).getByRole("button", { name: /Make default/ }).click();
    await expect(section(page).getByRole("alert").filter({ hasText: "That address no longer exists." })).toBeVisible();
    await expect(section(page).getByRole("status").filter({ hasText: "is now your default address." })).toHaveCount(0);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText("Home");
    await expect(rows(page).first()).toContainText("Default");
    expect(api.calls.some((call) => call.method === "POST" && call.path === `/api/v1/me/addresses/${work.id}/default`)).toBe(true);
  });

  test("a slow save disables the button and a second submit sends nothing", async ({ page }) => {
    const held = gate();
    const api = await addressesApi(page, [], async (route, call) => {
      if (call.method !== "POST") return false;
      await held.opened;
      return false;
    });
    await page.goto(`${customer}/account`);
    await fillNew(page);
    await page.getByRole("button", { name: "Save address" }).click();
    const busy = page.getByRole("button", { name: "Saving…" });
    await expect(busy).toBeDisabled();
    await page.getByLabel("Landmark", { exact: true }).press("Enter");
    held.open();
    await expect(rows(page)).toHaveCount(1);
    expect(api.calls.filter((call) => call.method === "POST")).toHaveLength(1);
  });

  test("a failed list shows an error with a retry that loads it", async ({ page }) => {
    let healthy = false;
    await addressesApi(page, [home], (route, call) => {
      if (call.method !== "GET" || healthy) return false;
      void reply(route, 500, {});
      return true;
    });
    await page.goto(`${customer}/account`);
    await expect(section(page).getByRole("alert")).toContainText("could not be loaded");
    await assertAccessible(page);
    healthy = true;
    await section(page).getByRole("button", { name: "Reload addresses" }).click();
    await expect(rows(page)).toHaveCount(1);
  });

  test("a signed-out answer for the list offers a sign-in link", async ({ page }) => {
    await addressesApi(page, [], (route, call) => {
      if (call.method !== "GET") return false;
      void reply(route, 401, failure("Unauthenticated."));
      return true;
    });
    await page.goto(`${customer}/account`);
    await expect(section(page).getByRole("alert")).toContainText("You are signed out");
    await expect(section(page).getByRole("link", { name: "Sign in" })).toHaveAttribute("href", "/login?next=%2Faccount");
  });

  test("this device's location fills the fields rounded to four decimals and stays editable", async ({ page, context }) => {
    await context.grantPermissions(["geolocation"]);
    await context.setGeolocation({ latitude: 10.12341, longitude: 100.54326 });
    await addressesApi(page, []);
    await page.goto(`${customer}/account`);
    await page.getByRole("button", { name: "Use this device's location" }).click();
    await expect(page.getByLabel("Latitude")).toHaveValue("10.1234");
    await expect(page.getByLabel("Longitude")).toHaveValue("100.5433");
    await expect(page.getByText("Filled in from this device.")).toBeVisible();
    await page.getByLabel("Latitude").fill("10.5");
    await expect(page.getByLabel("Latitude")).toHaveValue("10.5");
  });

  test("a refused location permission says so and leaves the fields to type in", async ({ page, context }) => {
    await context.clearPermissions();
    await addressesApi(page, []);
    await page.goto(`${customer}/account`);
    await page.getByRole("button", { name: "Use this device's location" }).click();
    await expect(page.getByText("We could not get this device's location. Enter the numbers instead.")).toBeVisible();
    await expect(page.getByLabel("Latitude")).toHaveValue("");
    await page.getByLabel("Latitude").fill("10.2");
    await expect(page.getByLabel("Latitude")).toHaveValue("10.2");
  });

  test("no position or address text reaches the URL, browser storage, console or any request URL after create and edit", async ({ page }) => {
    const urls: string[] = [];
    const logs: string[] = [];
    page.on("request", (request) => urls.push(request.url()));
    page.on("console", (message) => logs.push(message.text()));
    await addressesApi(page, []);
    await page.goto(`${customer}/account`);
    await fillNew(page, { line: "77 Hidden Road", landmark: "Secret mango tree", lat: "10.7777", lon: "100.7777" });
    await page.getByRole("button", { name: "Save address" }).click();
    await expect(rows(page)).toHaveCount(1);

    await rows(page).first().getByRole("button", { name: /^Edit/ }).click();
    await page.getByLabel("Latitude").fill("10.8888");
    await page.getByLabel("Longitude").fill("100.8888");
    await page.getByLabel("Address", { exact: true }).fill("88 Hidden Road");
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(section(page).getByRole("status").filter({ hasText: "Address saved." })).toBeVisible();
    await expect(rows(page).first()).toContainText("88 Hidden Road");

    const needles = ["10.7777", "100.7777", "10.8888", "100.8888", "Hidden", "mango", "latitude", "longitude"];
    const pageUrl = page.url();
    expect(new URL(pageUrl).search).toBe("");
    expect(new URL(pageUrl).hash).toBe("");
    const storage = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }));
    for (const needle of needles) {
      expect(pageUrl).not.toContain(needle);
      expect(storage).not.toContain(needle);
      expect(logs.join("\n")).not.toContain(needle);
      for (const url of urls) expect(url).not.toContain(needle);
    }
    expect(urls.some((url) => url.includes("/api/v1/me/addresses"))).toBe(true);
  });
});
