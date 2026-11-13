import { defineConfig } from "@playwright/test";

const live = process.env.BROWSER_LIVE === "1";
const publicConfigUrl = "http://127.0.0.1:3089";
const serverFetchSpec = /server-fetch-states\.spec\.ts$/;

export default defineConfig({
  testDir: "./tests/browser",
  timeout: 45_000,
  use: { trace: "retain-on-failure", screenshot: "only-on-failure" },
  projects: [
    { name: "phone", testIgnore: serverFetchSpec, use: { viewport: { width: 375, height: 812 } } },
    { name: "tablet", testIgnore: serverFetchSpec, use: { viewport: { width: 768, height: 1024 } } },
    { name: "desktop", testIgnore: serverFetchSpec, use: { viewport: { width: 1440, height: 900 } } },
    // Server-rendered fetch states (customer `/` banner, admin `/` API row). They change what the shared
    // stand-in API answers, so they cannot overlap each other or the projects above: each project below waits
    // for the one before it. `--project=phone-server-fetch` alone also runs everything it depends on; add
    // `--no-deps` to run only these.
    ...(!live
      ? [
          { name: "phone-server-fetch", testMatch: serverFetchSpec, dependencies: ["phone", "tablet", "desktop"], use: { viewport: { width: 375, height: 812 } } },
          { name: "tablet-server-fetch", testMatch: serverFetchSpec, dependencies: ["phone-server-fetch"], use: { viewport: { width: 768, height: 1024 } } },
          { name: "desktop-server-fetch", testMatch: serverFetchSpec, dependencies: ["tablet-server-fetch"], use: { viewport: { width: 1440, height: 900 } } },
        ]
      : []),
  ],
  webServer: [
    ...(!live ? [{ command: "node tests/browser/public-config-server.mjs", url: `${publicConfigUrl}/api/v1/public/config` }] : []),
    {
      command: "pnpm dev:customer",
      port: 3000,
      timeout: 120_000,
      env: { API_URL: live ? "http://localhost:8000" : publicConfigUrl },
    },
    { command: "pnpm dev:merchant", port: 3001, timeout: 120_000 },
    {
      command: "pnpm dev:admin",
      port: 3002,
      timeout: 120_000,
      env: { API_URL: live ? "http://localhost:8000" : publicConfigUrl },
    },
  ],
});
