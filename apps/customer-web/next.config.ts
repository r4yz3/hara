import { securityHeaders } from "@food-delivery/config/security-headers";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  async headers() {
    return securityHeaders({ geolocation: true });
  },
  transpilePackages: ["@food-delivery/session"],
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
