import { securityHeaders } from "@food-delivery/config/security-headers";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  async headers() {
    return securityHeaders();
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
