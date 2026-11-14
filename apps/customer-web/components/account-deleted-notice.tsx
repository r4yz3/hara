"use client";

import { useEffect, useState } from "react";

// Set by the delete flow just before it navigates to "/". Module state only: it is not in the URL, storage or a cookie,
// it does not survive a reload, and it holds nothing about the account.
let pending = false;

/** Called right before the delete flow navigates to the home page after a successful 204. */
export function announceAccountDeleted() {
  pending = true;
}

/** Reads the flag without changing it. */
function peekAccountDeleted(): boolean {
  return pending;
}

/** Clears the flag, so the notice shows once. */
function clearAccountDeleted() {
  pending = false;
}

/**
 * Shows "Your account was deleted." on the home page once, after a delete. Renders nothing on the server, where the
 * flag is never set. The flag is only read (pure) while rendering and cleared after commit, so Strict Mode's double
 * render or double effect cannot lose it.
 */
export function AccountDeletedNotice() {
  const [announced] = useState(peekAccountDeleted);
  const [dismissed, setDismissed] = useState(false);

  useEffect(() => {
    clearAccountDeleted();
  }, []);

  if (!announced || dismissed) return null;

  return (
    <div
      role="status"
      className="mx-auto flex w-full max-w-6xl items-center justify-between gap-3 rounded-2xl border border-border bg-surface px-4 py-3 text-text-primary shadow-sm"
    >
      <p className="font-medium">Your account was deleted.</p>
      <button
        type="button"
        onClick={() => setDismissed(true)}
        className="min-h-11 shrink-0 rounded-xl border border-border-strong bg-surface px-4 text-sm font-semibold text-text-primary"
      >
        Close
      </button>
    </div>
  );
}
