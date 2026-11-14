"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";

import { apiSend, currentUser, describeFailure, signOut, type CurrentUser, type Failure } from "@food-delivery/session";

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
        {state.failure.kind === "forbidden" ? (
          <Link href="/" className={`${button} inline-flex items-center`}>
            Back to home
          </Link>
        ) : (
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
  const saved = (next: CurrentUser) => setState({ phase: "signed-in", user: next });

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
        <p className="text-sm text-text-secondary">
          Your email and phone number cannot be changed here yet. Changing them needs a verification step that is not built.
        </p>
      </section>

      <NameForm user={user} onSaved={saved} />

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

const field = "min-h-12 w-full rounded-xl border border-border-strong bg-surface px-4 text-base text-text-primary";
const primary = "min-h-12 rounded-xl bg-brand-600 px-4 text-base font-semibold text-on-brand disabled:opacity-70";

type Saving = { phase: "idle" } | { phase: "saving" } | { phase: "saved" } | { phase: "failed"; text: string; retry: boolean };

/** Edits the display name only: PATCH /api/v1/me. Compares against the saved name, so Save stays off until the text differs. */
function NameForm({ user, onSaved }: { user: CurrentUser; onSaved: (next: CurrentUser) => void }) {
  const router = useRouter();
  const [name, setName] = useState(user.name);
  const [saving, setSaving] = useState<Saving>({ phase: "idle" });
  const [fieldErrors, setFieldErrors] = useState<string[]>([]);
  const input = useRef<HTMLInputElement>(null);

  const unchanged = name.trim() === user.name;
  const busy = saving.phase === "saving";

  async function submit(event?: FormEvent) {
    event?.preventDefault();
    if (busy || unchanged) return;

    setSaving({ phase: "saving" });
    setFieldErrors([]);
    const result = await apiSend<{ data: CurrentUser }>("PATCH", "/api/v1/me", { name });

    if (result.ok) {
      setName(result.value.data.name);
      setSaving({ phase: "saved" });
      onSaved(result.value.data);
      return;
    }

    const failure = result.failure;
    if (failure.kind === "signed_out") {
      router.replace(`/login?next=${encodeURIComponent("/account")}`);
      return;
    }
    if (failure.kind === "validation") {
      const messages = failure.fields.name ?? Object.values(failure.fields).flat();
      setFieldErrors(messages.length > 0 ? messages : ["Enter a name."]);
      setSaving({ phase: "idle" });
      input.current?.focus();
      return;
    }

    setSaving({ phase: "failed", text: describeFailure(failure), retry: failure.kind !== "forbidden" });
  }

  return (
    <form
      onSubmit={submit}
      noValidate
      aria-labelledby="name-heading"
      className="flex flex-col gap-3 rounded-3xl border border-border bg-surface p-5"
    >
      <h2 id="name-heading" className="font-semibold">
        Change your name
      </h2>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="display-name" className="text-sm font-medium">
          Name
        </label>
        <input
          ref={input}
          id="display-name"
          name="name"
          type="text"
          autoComplete="name"
          value={name}
          maxLength={160}
          aria-invalid={fieldErrors.length > 0}
          aria-describedby={fieldErrors.length > 0 ? "display-name-error" : undefined}
          onChange={(event) => {
            setName(event.target.value);
            if (saving.phase === "saved") setSaving({ phase: "idle" });
          }}
          className={field}
        />
        {fieldErrors.length > 0 ? (
          <p id="display-name-error" className="text-sm font-medium text-danger">
            {fieldErrors.join(" ")}
          </p>
        ) : null}
      </div>

      <p role="status" className="min-h-6 text-sm font-medium text-success">
        {saving.phase === "saved" ? "Your name was saved." : null}
      </p>
      {saving.phase === "failed" ? (
        <p role="alert" className="text-sm font-medium text-danger">
          {saving.text}
        </p>
      ) : null}

      <button type="submit" disabled={busy || unchanged} aria-busy={busy} className={`${primary} self-start`}>
        {busy ? "Saving…" : saving.phase === "failed" && saving.retry ? "Try again" : "Save name"}
      </button>
    </form>
  );
}
