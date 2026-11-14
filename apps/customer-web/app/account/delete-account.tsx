"use client";

import { useRouter } from "next/navigation";
import { useRef, useState, type FormEvent } from "react";

import { apiSend, describeFailure } from "@food-delivery/session";

const button = "min-h-12 rounded-xl border border-border-strong bg-surface px-4 text-base font-semibold disabled:opacity-70";
const danger = "min-h-12 rounded-xl bg-danger px-4 text-base font-semibold text-on-danger disabled:opacity-70";
const field = "min-h-12 w-full rounded-xl border border-border-strong bg-surface px-4 text-base text-text-primary";

type Outcome =
  | { phase: "idle" }
  | { phase: "sending" }
  | { phase: "field"; text: string }
  | { phase: "work-account"; text: string }
  | { phase: "wait"; text: string }
  | { phase: "failed"; text: string };

/**
 * Closes the signed-in customer's own account: DELETE /api/v1/me with the current password (ADR-017).
 * The password lives only in this component's state and is cleared after every request and when the dialog closes.
 */
export function DeleteAccountSection() {
  const router = useRouter();
  const [password, setPassword] = useState("");
  const [outcome, setOutcome] = useState<Outcome>({ phase: "idle" });
  const dialog = useRef<HTMLDialogElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const cancel = useRef<HTMLButtonElement>(null);
  const sending = useRef(false);

  const busy = outcome.phase === "sending";

  function open() {
    setPassword("");
    setOutcome({ phase: "idle" });
    dialog.current?.showModal();
    // React mounts this dialog closed, so its autoFocus never fires: start on the safe choice explicitly.
    cancel.current?.focus();
  }

  async function submit(event?: FormEvent) {
    event?.preventDefault();
    if (sending.current) return;
    sending.current = true;
    setOutcome({ phase: "sending" });

    try {
      const result = await apiSend("DELETE", "/api/v1/me", { password });
      setPassword("");

      if (result.ok) {
        router.replace("/account/deleted");
        return;
      }

      const failure = result.failure;
      if (failure.kind === "signed_out") {
        router.replace(`/login?next=${encodeURIComponent("/account")}`);
        return;
      }
      if (failure.kind === "validation") {
        const messages = failure.fields.password ?? Object.values(failure.fields).flat();
        setOutcome({ phase: "field", text: messages.length > 0 ? messages.join(" ") : "Enter your current password." });
        // The input is still mounted; focus it once the state above has rendered.
        requestAnimationFrame(() => input.current?.focus());
        return;
      }
      if (failure.kind === "conflict") {
        setOutcome({ phase: "work-account", text: failure.message });
        return;
      }
      if (failure.kind === "rate_limited") {
        const seconds = failure.retryAfterSeconds;
        setOutcome({
          phase: "wait",
          text: seconds ? `Too many attempts. Try again in ${seconds} ${seconds === 1 ? "second" : "seconds"}.` : "Too many attempts. Wait a little and try again.",
        });
        return;
      }
      setOutcome({ phase: "failed", text: describeFailure(failure) });
    } finally {
      sending.current = false;
    }
  }

  const fieldError = outcome.phase === "field" ? outcome.text : null;
  const blocked = outcome.phase === "work-account";

  return (
    <section aria-labelledby="delete-account-heading" className="flex flex-col gap-3 rounded-3xl border border-border bg-surface p-5">
      <h2 id="delete-account-heading" className="font-semibold">
        Delete my account
      </h2>
      <p className="text-sm text-text-secondary">
        This removes your saved addresses, your profile and your sign-in. It happens immediately and cannot be undone. Copies may
        remain in backups for a limited time.
      </p>
      <button type="button" onClick={open} className={`${button} self-start`}>
        Delete my account
      </button>

      <dialog
        ref={dialog}
        aria-labelledby="delete-account-title"
        aria-describedby="delete-account-description"
        onClose={() => {
          setPassword("");
          setOutcome({ phase: "idle" });
        }}
        onCancel={(event) => {
          if (sending.current) event.preventDefault();
        }}
        className="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-border-strong bg-surface p-5 text-text-primary backdrop:bg-black/50"
      >
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          <h3 id="delete-account-title" className="text-lg font-semibold">
            Delete your account permanently?
          </h3>
          <p id="delete-account-description" className="text-sm text-text-secondary">
            Your saved addresses, profile and sign-in are removed straight away. This cannot be undone. Enter your password to
            confirm.
          </p>

          {blocked ? (
            <p role="alert" className="text-sm font-medium text-danger">
              {outcome.text}
            </p>
          ) : (
            <div className="flex flex-col gap-1.5">
              <label htmlFor="delete-account-password" className="text-sm font-medium">
                Current password
              </label>
              <input
                ref={input}
                id="delete-account-password"
                name="password"
                type="password"
                autoComplete="current-password"
                value={password}
                readOnly={busy}
                aria-invalid={fieldError !== null}
                aria-describedby={fieldError !== null ? "delete-account-password-error" : undefined}
                onChange={(event) => setPassword(event.target.value)}
                className={field}
              />
              {fieldError !== null ? (
                <p id="delete-account-password-error" className="text-sm font-medium text-danger">
                  {fieldError}
                </p>
              ) : null}
            </div>
          )}

          {outcome.phase === "wait" || outcome.phase === "failed" ? (
            <p role="alert" className="text-sm font-medium text-danger">
              {outcome.text}
            </p>
          ) : null}

          <div className="flex flex-wrap gap-2">
            <button ref={cancel} type="button" disabled={busy} onClick={() => dialog.current?.close()} className={button}>
              {blocked ? "Close" : "Cancel"}
            </button>
            {blocked ? null : (
              <button type="submit" disabled={busy} aria-busy={busy} className={danger}>
                {busy ? "Deleting…" : outcome.phase === "failed" ? "Try again" : "Delete my account permanently"}
              </button>
            )}
          </div>
        </form>
      </dialog>
    </section>
  );
}
