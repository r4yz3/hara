import { defineConfig } from "@playwright/test";

const live = process.env.BROWSER_LIVE === "1";
const publicConfigUrl = "http://127.0.0.1:3089";

export default defineConfig({
  testDir: "./tests/browser",
  timeout: 45_000,
  use: { trace: "retain-on-failure", screenshot: "only-on-failure" },
  projects: [
    { name: "phone", use: { viewport: { width: 375, height: 812 } } },
    { name: "tablet", use: { viewport: { width: 768, height: 1024 } } },
    { name: "desktop", use: { viewport: { width: 1440, height: 900 } } },
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
    { command: "pnpm dev:admin", port: 3002, timeout: 120_000 },
  ],
});
