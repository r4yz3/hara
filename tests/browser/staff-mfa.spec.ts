import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

const admin = "http://localhost:3002";
const enrolmentOnlyMessage = "Set up two-step sign-in before using the admin console.";
const secret = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP";
const otpauth = `otpauth://totp/Console:staff@example.test?secret=${secret}&issuer=Console`;
const recoveryCodes = Array.from({ length: 10 }, (_, index) => `abcd-ef${index}0-1234-5678`);

const user = (mfaEnabled: boolean) => ({
  data: {
    id: "01JTESTSTAFF000000000000000",
    name: "Test Staff",
    email: "staff@example.test",
    email_verified: true,
    mfa_enabled: mfaEnabled,
    surface: "admin",
    grants: [],
  },
  meta: { request_id: "test" },
});

const meta = { request_id: "test" };
const failure = (status: number, message: string, fields?: Record<string, string[]>) => ({
  status,
  json: { error: { code: "X", message, fields, request_id: "test" } },
});

// The pages call the API on another origin, so a fulfilled response must carry
// the CORS headers a real answer would. Retry-After is exposed here only so the
// wait can be shown; the real API does not expose it yet (reported gap).
const cors = {
  "Access-Control-Allow-Origin": admin,
  "Access-Control-Allow-Credentials": "true",
  "Access-Control-Expose-Headers": "Retry-After",
};

function reply(route: Route, init: { status?: number; json?: unknown; headers?: Record<string, string> }) {
  return route.fulfill({ status: init.status ?? 200, json: init.json ?? {}, headers: { ...cors, ...init.headers } });
}

type Handler = (route: Route) => Promise<void> | void;

async function mockApi(page: Page, handlers: Record<string, Handler>) {
  // Anything not named answers "signed out", so no screen waits on a real API.
  await page.route("**/api/v1/**", (route) => reply(route, failure(401, "Unauthenticated.")));
  await page.route("**/api/v1/auth/csrf", (route) => reply(route, { json: { data: { csrf_token: "browser-test" } } }));
  for (const [path, handler] of Object.entries(handlers)) {
    await page.route(`**/api/v1/${path}`, handler);
  }
}

async function assertAccessible(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(0);
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
    .analyze();
  expect(violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }))).toEqual([]);
}

/** Password step answered with mfa_required, then the code field. */
async function reachCodeStep(page: Page, verify: Handler, extra: Record<string, Handler> = {}) {
  await mockApi(page, {
    "auth/login": (route) => reply(route, { json: { data: { mfa_required: true }, meta } }),
    "auth/mfa/verify": verify,
    me: (route) => reply(route, { json: user(true) }),
    ...extra,
  });
  await page.goto(`${admin}/login`);
  await page.getByLabel("Email").fill("staff@example.test");
  await page.getByLabel("Password").fill("not-a-real-password");
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page.getByLabel("6-digit code")).toBeFocused();
}

test.describe("staff sign-in code step", () => {
  test("a password then a valid code signs in, with no code in the URL", async ({ page }) => {
    let body: unknown = null;
    await reachCodeStep(page, (route) => {
      body = route.request().postDataJSON();
      expect(new URL(route.request().url()).search).toBe("");
      return reply(route, { json: user(true) });
    });

    await expect(page.getByLabel("6-digit code")).toHaveAttribute("autocomplete", "one-time-code");
    await expect(page.getByLabel("6-digit code")).toHaveAttribute("inputmode", "numeric");
    await assertAccessible(page);

    await page.getByLabel("6-digit code").fill("123456");
    await page.getByRole("button", { name: "Verify" }).click();

    await expect(page).toHaveURL(`${admin}/`);
    expect(body).toEqual({ code: "123456" });
    expect(page.url()).not.toContain("123456");
  });

  test("a wrong code shows an inline error and keeps the step", async ({ page }) => {
    await reachCodeStep(page, (route) => reply(route, failure(401, "Invalid.")));

    await page.getByLabel("6-digit code").fill("000000");
    await page.getByRole("button", { name: "Verify" }).click();

    await expect(page.getByRole("main").getByRole("alert")).toContainText("was not accepted");
    await expect(page.getByLabel("6-digit code")).toBeFocused();
    await expect(page.getByLabel("6-digit code")).toHaveValue("");
    expect(page.url()).not.toContain("000000");
  });

  test("an expired pending step tells the person to start again, and Start again returns to the password", async ({ page }) => {
    await reachCodeStep(page, (route) => reply(route, failure(401, "Invalid.")));

    await page.getByLabel("6-digit code").fill("123456");
    await page.getByRole("button", { name: "Verify" }).click();
    await expect(page.getByRole("main").getByRole("alert")).toContainText("start again");

    await page.getByRole("button", { name: "Start again" }).click();
    await expect(page.getByLabel("Password")).toBeVisible();
    await expect(page.getByLabel("6-digit code")).toHaveCount(0);
  });

  test("a rate limit shows how long to wait", async ({ page }) => {
    await reachCodeStep(page, (route) => reply(route, { ...failure(429, "Too many."), headers: { "Retry-After": "42" } }));

    await page.getByLabel("6-digit code").fill("123456");
    await page.getByRole("button", { name: "Verify" }).click();

    await expect(page.getByRole("main").getByRole("alert")).toContainText("42 seconds");
  });

  test("an empty or rejected code shows a validation message", async ({ page }) => {
    await reachCodeStep(page, (route) => reply(route, failure(422, "Invalid.", { code: ["bad"] })));

    await page.getByRole("button", { name: "Verify" }).click();
    await expect(page.getByRole("main").getByRole("alert")).toContainText("6-digit code");

    await page.getByLabel("6-digit code").fill("12");
    await page.getByRole("button", { name: "Verify" }).click();
    await expect(page.getByRole("main").getByRole("alert")).toContainText("6-digit code");
  });

  test("the button is disabled while a code is being checked, so it cannot be sent twice", async ({ page }) => {
    let calls = 0;
    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => (release = resolve));
    await reachCodeStep(page, async (route) => {
      calls++;
      await gate;
      await reply(route, failure(401, "Invalid."));
    });

    await page.getByLabel("6-digit code").fill("123456");
    const verify = page.getByRole("button", { name: /Checking|Verify/ });
    await verify.click();
    await expect(page.getByRole("button", { name: "Checking…" })).toBeDisabled();
    await page.getByLabel("6-digit code").press("Enter");
    release();

    await expect(page.getByRole("main").getByRole("alert")).toContainText("was not accepted");
    expect(calls).toBe(1);
  });

  test("a recovery code can be used instead", async ({ page }, testInfo) => {
    let body: unknown = null;
    await reachCodeStep(page, (route) => {
      body = route.request().postDataJSON();
      return reply(route, { json: user(true) });
    });

    await page.getByRole("button", { name: "Use a recovery code instead" }).click();
    await expect(page.getByLabel("Recovery code")).toBeFocused();
    await assertAccessible(page);
    if (testInfo.project.name === "phone") {
      await page.getByRole("main").screenshot({ path: testInfo.outputPath("admin-recovery-code.png") });
    }

    await page.getByLabel("Recovery code").fill("abcd-efgh-ijkl-mnop");
    await page.getByRole("button", { name: "Verify" }).click();

    await expect(page).toHaveURL(`${admin}/`);
    expect(body).toEqual({ recovery_code: "abcd-efgh-ijkl-mnop" });
    expect(page.url()).not.toContain("abcd");
  });

  test("a staff session that must enrol is led to the security page", async ({ page }) => {
    await mockApi(page, {
      "auth/login": (route) => reply(route, { json: user(false) }),
      me: (route) => reply(route, failure(403, enrolmentOnlyMessage)),
    });

    await page.goto(`${admin}/login`);
    await page.getByLabel("Email").fill("staff@example.test");
    await page.getByLabel("Password").fill("not-a-real-password");
    await page.getByRole("button", { name: "Sign in" }).click();

    await expect(page).toHaveURL(`${admin}/security`);
    await expect(page.getByText("You must set it up before you can use the admin console.")).toBeVisible();
  });
});

test.describe("security page", () => {
  test("shows that two-step sign-in is on, with no way to turn it off", async ({ page }) => {
    await mockApi(page, { me: (route) => reply(route, { json: user(true) }) });
    await page.goto(`${admin}/security`);

    await expect(page.getByText("Two-step sign-in is on for your account.")).toBeVisible();
    await expect(page.getByRole("button", { name: /^(set up|turn off|disable)/i })).toHaveCount(0);
  });

  test("a failed load offers a retry", async ({ page }) => {
    let up = false;
    await mockApi(page, {
      me: (route) => (up ? reply(route, { json: user(true) }) : reply(route, { status: 503, json: {} })),
    });
    await page.goto(`${admin}/security`);

    await expect(page.getByRole("main").getByRole("alert")).toBeVisible();
    up = true;
    await page.getByRole("button", { name: "Try again" }).click();
    await expect(page.getByText("Two-step sign-in is on for your account.")).toBeVisible();
  });

  test("setup shows the key in groups, rejects a wrong code, then shows recovery codes behind a checkbox", async ({ page }) => {
    let confirmCalls = 0;
    await mockApi(page, {
      me: (route) => reply(route, failure(403, enrolmentOnlyMessage)),
      "me/mfa/totp": (route) => reply(route, { status: 201, json: { data: { secret, otpauth_uri: otpauth }, meta } }),
      "me/mfa/totp/confirm": (route) => {
        confirmCalls++;
        expect(new URL(route.request().url()).search).toBe("");
        return confirmCalls === 1
          ? reply(route, failure(422, "Invalid.", { code: ["That code is not right, or setup was not started."] }))
          : reply(route, { json: { data: { recovery_codes: recoveryCodes }, meta } });
      },
    });
    await page.goto(`${admin}/security`);

    await expect(page.getByText("Two-step sign-in is off for your account.")).toBeVisible();
    await page.getByRole("button", { name: "Set up" }).click();

    await expect(page.getByText("JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP")).toBeVisible();
    await expect(page.getByLabel("Setup link")).toHaveValue(otpauth);
    await expect(page.getByText("Scan this with your authenticator app, or type the key below.")).toBeVisible();
    expect(page.url()).not.toContain(secret);

    await page.getByLabel("6-digit code from the app").fill("111111");
    await page.getByRole("button", { name: "Confirm" }).click();
    await expect(page.locator("#confirm-error").filter({ hasText: "not right" })).toBeVisible();

    await page.getByLabel("6-digit code from the app").fill("222222");
    await page.getByRole("button", { name: "Confirm" }).click();

    await expect(page.getByRole("heading", { name: "Save your recovery codes" })).toBeVisible();
    await expect(page.getByRole("list", { name: "Recovery codes" }).getByRole("listitem")).toHaveCount(10);
    await assertAccessible(page);

    const next = page.getByRole("button", { name: "Continue" });
    await expect(next).toBeDisabled();
    await page.getByLabel("I have saved these codes").check();
    await expect(next).toBeEnabled();
    expect(page.url()).not.toContain("abcd");
    expect(await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))).not.toContain(secret);
  });

  test("after confirming in a deployed environment the person is sent to sign in again", async ({ page }) => {
    await mockApi(page, {
      me: (route) => reply(route, failure(403, enrolmentOnlyMessage)),
      "me/mfa/totp": (route) => reply(route, { status: 201, json: { data: { secret, otpauth_uri: otpauth }, meta } }),
      "me/mfa/totp/confirm": (route) => reply(route, { json: { data: { recovery_codes: recoveryCodes }, meta } }),
    });
    await page.goto(`${admin}/security`);
    await page.getByRole("button", { name: "Set up" }).click();
    await page.getByLabel("6-digit code from the app").fill("222222");
    await page.getByRole("button", { name: "Confirm" }).click();
    await page.getByLabel("I have saved these codes").check();
    await page.getByRole("button", { name: "Continue" }).click();

    await expect(page).toHaveURL(`${admin}/login?setup=done`);
    await expect(page.getByRole("status").filter({ hasText: "Two-step sign-in is set up" })).toBeVisible();
  });

  test("a 409 when starting reloads the page state", async ({ page }) => {
    let enrolled = false;
    await mockApi(page, {
      me: (route) => (enrolled ? reply(route, { json: user(true) }) : reply(route, { json: user(false) })),
      "me/mfa/totp": (route) => {
        enrolled = true;
        return reply(route, failure(409, "Two-step sign-in is already set up."));
      },
    });
    await page.goto(`${admin}/security`);
    await page.getByRole("button", { name: "Set up" }).click();

    await expect(page.getByText("Two-step sign-in is on for your account.")).toBeVisible();
    await expect(page.getByText("Two-step sign-in was already set up.")).toBeVisible();
  });

  test("a 409 when confirming reloads the page state", async ({ page }) => {
    let enrolled = false;
    await mockApi(page, {
      me: (route) => (enrolled ? reply(route, { json: user(true) }) : reply(route, { json: user(false) })),
      "me/mfa/totp": (route) => reply(route, { status: 201, json: { data: { secret, otpauth_uri: otpauth }, meta } }),
      "me/mfa/totp/confirm": (route) => {
        enrolled = true;
        return reply(route, failure(409, "Two-step sign-in is already set up."));
      },
    });
    await page.goto(`${admin}/security`);
    await page.getByRole("button", { name: "Set up" }).click();
    await page.getByLabel("6-digit code from the app").fill("222222");
    await page.getByRole("button", { name: "Confirm" }).click();

    await expect(page.getByText("Two-step sign-in is on for your account.")).toBeVisible();
  });

  test("starting setup cannot be sent twice while it is slow", async ({ page }) => {
    let calls = 0;
    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => (release = resolve));
    await mockApi(page, {
      me: (route) => reply(route, { json: user(false) }),
      "me/mfa/totp": async (route) => {
        calls++;
        await gate;
        await reply(route, { status: 201, json: { data: { secret, otpauth_uri: otpauth }, meta } });
      },
    });
    await page.goto(`${admin}/security`);
    await page.getByRole("button", { name: "Set up" }).click();
    await expect(page.getByRole("button", { name: "Starting…" })).toBeDisabled();
    release();

    await expect(page.getByLabel("Setup link")).toBeVisible();
    expect(calls).toBe(1);
  });
});
