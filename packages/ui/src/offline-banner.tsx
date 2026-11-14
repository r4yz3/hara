"use client";

import { useEffect, useState } from "react";

// The one "You are offline" banner shared by the three web apps (ADR-019, backlog U-02).
//
// Rules: it follows the browser's online and offline events, takes no focus, needs no dismissing and
// disappears by itself when the connection returns. It assumes "online" until mounted, so the server markup and
// the first client render match (no hydration mismatch). The live region is always in the document and only its
// content changes, because screen readers announce changes to an existing region more reliably than a region
// that appears together with its text. Nothing is animated, so there is no motion to reduce.
//
// Colours are an approved pair from docs/ux/contrast-report.md: text-primary on surface-2, with a
// border-strong outline.

export const OFFLINE_MESSAGE = "You are offline. Changes can't be saved until you reconnect.";

export function OfflineBanner() {
  const [offline, setOffline] = useState(false);

  useEffect(() => {
    const sync = () => setOffline(!navigator.onLine);
    sync();
    window.addEventListener("online", sync);
    window.addEventListener("offline", sync);
    return () => {
      window.removeEventListener("online", sync);
      window.removeEventListener("offline", sync);
    };
  }, []);

  return (
    <div role="status" aria-live="polite" data-testid="offline-banner" className={offline ? "sticky top-0 z-50" : undefined}>
      {offline ? (
        <p className="border-b border-border-strong bg-surface-2 px-4 py-2 text-center text-sm font-medium text-text-primary">
          {OFFLINE_MESSAGE}
        </p>
      ) : null}
    </div>
  );
}
