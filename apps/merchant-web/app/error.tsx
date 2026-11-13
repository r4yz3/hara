"use client";

import { ErrorScreen } from "@food-delivery/ui/problem-page";

// Catches an unexpected error in any page of this app. Shows a plain message,
// a retry and a link home; it never shows the error itself (backlog U-03).
export default function Error({ error, retry }: { error: unknown; retry: () => void }) {
  return <ErrorScreen error={error} retry={retry} />;
}
