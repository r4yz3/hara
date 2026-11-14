import { securityHeaders } from "@food-delivery/config/security-headers";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
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
