import { securityHeaders } from "@food-delivery/config/security-headers";
import type { NextConfig } from "next";
import path from "node:path";

// Set only by infra/docker/prod/web.Dockerfile (E-07). A standalone build copies
// the traced files with symlinks, which needs elevated rights on Windows, so
// local builds and the browser tests leave it unset.
const standalone = process.env.NEXT_OUTPUT === "standalone";

const nextConfig: NextConfig = {
  ...(standalone ? { output: "standalone" as const, outputFileTracingRoot: path.join(process.cwd(), "../..") } : {}),
  // Only the browser tests' production build sets this (playwright.config.ts), so it can sit beside the dev server's .next.
  distDir: process.env.NEXT_DIST_DIR || ".next",
  async headers() {
    return securityHeaders({ geolocation: true });
  },
  transpilePackages: ["@food-delivery/session", "@food-delivery/ui"],
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
