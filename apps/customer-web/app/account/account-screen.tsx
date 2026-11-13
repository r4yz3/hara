"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { currentUser, signOut, type CurrentUser, type Failure } from "@food-delivery/session";

type State =
  | { phase: "loading" }
  | { phase: "signed-in"; user: CurrentUser }
  | { phase: "error"; failure: Failure };

function describe(failure: Failure): string {
  if (failure.kind === "network") return "We could not reach the server. Check your connection and try again.";
  if (failure.kind === "forbidden") return failure.message;

  return "Your account could not be loaded right now. Try again shortly.";
}

const button = "min-h-12 rounded-xl border border-border-strong bg-surface px-4 text-base font-semibold";

/**
 * The signed-in customer's own name and email, from GET /api/v1/me.
 * Someone who is not signed in is sent to /login, which returns here after.
 */
export function AccountScreen() {
  const [state, setState] = useState<State>({ phase: "loading" });
  const [leaving, setLeaving] = useState(false);
  const [leaveError, setLeaveError] = useState<string | null>(null);

  const router = useRouter();
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;

    currentUser().then((result) => {
      if (cancelled) return;
      if (!result.ok) setState({ phase: "error", failure: result.failure });
      else if (result.value === null) router.replace(`/login?next=${encodeURIComponent("/account")}`);
      else setState({ phase: "signed-in", user: result.value });
    });

    return () => {
      cancelled = true;
    };
  }, [attempt, router]);

  if (state.phase === "loading") {
    return (
      <p role="status" aria-busy="true" className="text-text-secondary">
        Loading your account…
      </p>
    );
  }

  if (state.phase === "error") {
    return (
      <div role="alert" className="flex flex-col items-start gap-3 rounded-3xl border border-border bg-surface p-5">
        <p className="font-medium text-danger">{describe(state.failure)}</p>
        {state.failure.kind === "forbidden" ? null : (
          <button type="button" onClick={() => {
              setState({ phase: "loading" });
              setAttempt((n) => n + 1);
            }} className={button}>
            Try again
          </button>
        )}
      </div>
    );
  }

  const { user } = state;

  return (
    <div className="flex flex-col gap-4">
      <section aria-labelledby="profile-heading" className="flex flex-col gap-3 rounded-3xl border border-border bg-surface p-5">
        <h2 id="profile-heading" className="font-semibold">
          Your details
        </h2>
        <dl className="grid gap-3 sm:grid-cols-[8rem_1fr]">
          <dt className="text-sm text-text-secondary">Name</dt>
          <dd className="break-words font-medium">{user.name.trim() === "" ? "No name on file" : user.name}</dd>
          <dt className="text-sm text-text-secondary">Email</dt>
          <dd className="break-all font-medium">{user.email.trim() === "" ? "No email on file" : user.email}</dd>
        </dl>
      </section>

      <p role="alert" className="min-h-6 text-sm font-medium text-danger">
        {leaveError}
      </p>

      <button
        type="button"
        disabled={leaving}
        aria-busy={leaving}
        onClick={async () => {
          if (leaving) return;
          setLeaving(true);
          setLeaveError(null);
          const result = await signOut();
          if (result.ok) {
            router.push("/");
            return;
          }
          setLeaving(false);
          setLeaveError("We could not sign you out. Check your connection and try again.");
        }}
        className={`${button} self-start disabled:opacity-70`}
      >
        {leaving ? "Signing out…" : "Sign out"}
      </button>
    </div>
  );
}
