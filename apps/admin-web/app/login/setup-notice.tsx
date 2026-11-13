"use client";

import { useSyncExternalStore } from "react";

const subscribe = () => () => {};
const isDone = () => new URLSearchParams(window.location.search).get("setup") === "done";

/** Shown after two-step setup ends the session. Read on the client so the page stays static. */
export function SetupNotice() {
  const done = useSyncExternalStore(subscribe, isDone, () => false);
  if (!done) return null;

  return (
    <p role="status" className="text-sm font-medium text-success">
      Two-step sign-in is set up. Sign in again and enter a code from your authenticator app.
    </p>
  );
}
