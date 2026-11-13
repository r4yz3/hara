"use client";

import { useEffect, useState } from "react";

import { currentUser, signOut, type CurrentUser } from "./client";

type State =
  | { phase: "loading" }
  | { phase: "signed-out" }
  | { phase: "signed-in"; user: CurrentUser }
  | { phase: "unreachable" };

/**
 * Shows who is signed in on this app, from the API, with a way in or out.
 * `className` styles the pill; each app passes classes for its own surface.
 */
export function SessionStatus({ className, signInHref = "/login" }: { className: string; signInHref?: string }) {
  const [state, setState] = useState<State>({ phase: "loading" });
  const [leaving, setLeaving] = useState(false);

  useEffect(() => {
    let cancelled = false;

    currentUser().then((result) => {
      if (cancelled) return;
      if (!result.ok) setState({ phase: "unreachable" });
      else if (result.value === null) setState({ phase: "signed-out" });
      else setState({ phase: "signed-in", user: result.value });
    });

    return () => {
      cancelled = true;
    };
  }, []);

  if (state.phase === "loading") {
    return (
      <span className={className} aria-busy="true">
        Checking sign-in…
      </span>
    );
  }

  if (state.phase === "unreachable") {
    return <span className={className}>Server unreachable</span>;
  }

  if (state.phase === "signed-out") {
    return (
      <a href={signInHref} className={`${className} font-medium underline-offset-4 hover:underline`}>
        Sign in
      </a>
    );
  }

  return (
    <span className={`${className} gap-3`}>
      <span className="truncate">
        Signed in as <span className="font-medium">{state.user.name}</span>
      </span>
      <button
        type="button"
        disabled={leaving}
        onClick={async () => {
          setLeaving(true);
          const result = await signOut();
          if (result.ok) window.location.assign("/");
          else setLeaving(false);
        }}
        className="min-h-11 shrink-0 font-medium underline underline-offset-4"
      >
        {leaving ? "Signing out…" : "Sign out"}
      </button>
    </span>
  );
}
