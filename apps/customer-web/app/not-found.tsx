import type { Metadata } from "next";

import { NotFoundScreen } from "@food-delivery/ui/problem-page";

export const metadata: Metadata = { title: "Page not found" };

export default function NotFound() {
  return <NotFoundScreen />;
}
