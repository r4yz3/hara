import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  transpilePackages: ["@food-delivery/session", "@food-delivery/api-client"],
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
