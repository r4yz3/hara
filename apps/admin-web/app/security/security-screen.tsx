"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";

import {
  confirmTotpEnrolment,
  currentUser,
  isEnrolmentOnly,
  signOut,
  startTotpEnrolment,
  type Failure,
  type MfaFailure,
} from "@food-delivery/session";

import { Notice, fieldClass, primaryButton, quietButton } from "@/components/ui";

// Secrets and recovery codes live only in this component's state. They are
// never written to the URL, web storage or the console, and they are dropped
// when the person leaves the page (the state goes with the component).

type Phase =
  | { name: "loading" }
  | { name: "signed-out" }
  | { name: "failed"; failure: Failure }
  | { name: "on" }
  | { name: "off"; enrolmentOnly: boolean }
  | { name: "enrolling"; secret: string; uri: string }
  | { name: "codes"; codes: string[] };

function waitText(failure: Failure): string {
  if (failure.kind === "rate_limited") {
    return failure.retryAfterSeconds === null
      ? "Too many attempts. Wait a minute and try again."
      : `Too many attempts. Try again in ${failure.retryAfterSeconds} seconds.`;
  }
  return failure.kind === "network"
    ? "We could not reach the server. Check your connection and try again."
    : "The server could not complete that. Try again shortly.";
}

function groupsOfFour(secret: string): string {
  return secret.replace(/\s+/g, "").replace(/(.{4})(?=.)/g, "$1 ");
}

export function SecurityScreen() {
  const [phase, setPhase] = useState<Phase>({ name: "loading" });
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async (): Promise<Phase> => {
    const result = await currentUser();

    if (result.ok) {
      if (result.value === null) return { name: "signed-out" };
      return result.value.mfa_enabled ? { name: "on" } : { name: "off", enrolmentOnly: false };
    }
    if (result.failure.kind === "forbidden" && isEnrolmentOnly(result.failure)) return { name: "off", enrolmentOnly: true };

    return { name: "failed", failure: result.failure };
  }, []);

  useEffect(() => {
    let cancelled = false;

    void load().then((next) => {
      if (!cancelled) setPhase(next);
    });

    return () => {
      cancelled = true;
    };
  }, [load]);

  function reload(message: string | null = null) {
    setPhase({ name: "loading" });
    setNotice(message);
    void load().then(setPhase);
  }

  if (phase.name === "loading") {
    return (
      <p aria-busy="true" role="status" className="text-text-secondary">
        Checking your sign-in settings…
      </p>
    );
  }

  if (phase.name === "signed-out") {
    return (
      <div className="flex flex-col gap-3">
        <p role="alert" className="font-medium">
          You are signed out. Sign in to set up two-step sign-in.
        </p>
        <Link href="/login?next=%2Fsecurity" className={`${primaryButton} content-center self-start`}>
          Sign in
        </Link>
      </div>
    );
  }

  if (phase.name === "failed") {
    return (
      <div className="flex flex-col gap-3">
        <p role="alert" className="font-medium">
          {phase.failure.kind === "forbidden" ? phase.failure.message : waitText(phase.failure)}
        </p>
        {phase.failure.kind !== "forbidden" && (
          <button type="button" onClick={() => reload()} className={`${quietButton} self-start`}>
            Try again
          </button>
        )}
      </div>
    );
  }

  if (phase.name === "on") {
    return (
      <div className="flex flex-col gap-3">
        {notice && <Notice tone="ok">{notice}</Notice>}
        <p className="font-medium">
          <span aria-hidden="true">● </span>Two-step sign-in is on for your account.
        </p>
        <p className="text-sm text-text-secondary">
          You are asked for a code from your authenticator app, or a recovery code, each time you sign in. Turning it off is not available.
        </p>
      </div>
    );
  }

  if (phase.name === "off") {
    return (
      <Off
        enrolmentOnly={phase.enrolmentOnly}
        notice={notice}
        onStarted={setPhase}
        onAlreadySet={() => reload("Two-step sign-in was already set up.")}
      />
    );
  }

  if (phase.name === "enrolling") {
    return (
      <Enrol
        secret={phase.secret}
        uri={phase.uri}
        onCodes={(codes) => setPhase({ name: "codes", codes })}
        onAlreadySet={() => reload("Two-step sign-in was already set up.")}
      />
    );
  }

  return <Codes codes={phase.codes} onDone={() => void finish(setPhase)} />;
}

/** After the codes are saved: a deployed session has ended, a local one carries on. */
async function finish(setPhase: (next: Phase) => void) {
  const result = await currentUser();

  if (result.ok && result.value !== null) {
    setPhase({ name: "on" });
    return;
  }

  window.location.replace("/login?setup=done");
}

function Off({
  enrolmentOnly,
  notice,
  onStarted,
  onAlreadySet,
}: {
  enrolmentOnly: boolean;
  notice: string | null;
  onStarted: (next: Phase) => void;
  onAlreadySet: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function start() {
    if (busy) return;
    setBusy(true);
    setError(null);
    const result = await startTotpEnrolment();

    if (result.ok) {
      onStarted({ name: "enrolling", secret: result.value.secret, uri: result.value.otpauthUri });
      return;
    }

    setBusy(false);
    const failure: MfaFailure = result.failure;
    if (failure.kind === "conflict") onAlreadySet();
    else if (failure.kind === "forbidden") setError(failure.message);
    else if (failure.kind === "credentials") setError("You are signed out. Sign in again to continue.");
    else setError(waitText(failure));
  }

  return (
    <div className="flex flex-col gap-3">
      {notice && <Notice tone="ok">{notice}</Notice>}
      <p className="font-medium">
        <span aria-hidden="true">○ </span>Two-step sign-in is off for your account.
      </p>
      {enrolmentOnly && <p className="text-sm text-text-secondary">You must set it up before you can use the admin console.</p>}
      <p className="text-sm text-text-secondary">You need an authenticator app on your phone.</p>
      <p id="start-error" role="alert" aria-live="assertive" className="min-h-6 text-sm font-medium text-danger">
        {error}
      </p>
      <div className="flex flex-wrap gap-2">
        <button type="button" onClick={start} disabled={busy} aria-busy={busy} aria-describedby="start-error" className={primaryButton}>
          {busy ? "Starting…" : "Set up"}
        </button>
        {enrolmentOnly && <SignOut />}
      </div>
    </div>
  );
}

function SignOut() {
  const [busy, setBusy] = useState(false);

  return (
    <button
      type="button"
      disabled={busy}
      className={quietButton}
      onClick={async () => {
        setBusy(true);
        const result = await signOut();
        if (result.ok) window.location.replace("/login");
        else setBusy(false);
      }}
    >
      {busy ? "Signing out…" : "Sign out"}
    </button>
  );
}

function CopyButton({ label, value, doneLabel }: { label: string; value: string; doneLabel: string }) {
  const [state, setState] = useState<"idle" | "done" | "failed">("idle");

  return (
    <div className="flex flex-wrap items-center gap-3">
      <button
        type="button"
        className={quietButton}
        onClick={async () => {
          try {
            await navigator.clipboard.writeText(value);
            setState("done");
          } catch {
            setState("failed");
          }
        }}
      >
        {label}
      </button>
      <span role="status" className="text-sm text-text-secondary">
        {state === "done" ? doneLabel : state === "failed" ? "Copying did not work. Select the text and copy it by hand." : ""}
      </span>
    </div>
  );
}

function Enrol({
  secret,
  uri,
  onCodes,
  onAlreadySet,
}: {
  secret: string;
  uri: string;
  onCodes: (codes: string[]) => void;
  onAlreadySet: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const codeRef = useRef<HTMLInputElement>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;

    const code = (codeRef.current?.value ?? "").replace(/\s+/g, "");
    if (code === "") {
      setError("Enter the 6-digit code from your authenticator app.");
      codeRef.current?.focus();
      return;
    }

    setBusy(true);
    setError(null);
    const result = await confirmTotpEnrolment(code);

    if (result.ok) {
      onCodes(result.value);
      return;
    }

    setBusy(false);
    const failure: MfaFailure = result.failure;
    if (failure.kind === "conflict") {
      onAlreadySet();
      return;
    }
    if (failure.kind === "validation") {
      setError(failure.fields.code?.[0] ?? "That code is not right. Check the app and try the new code.");
      if (codeRef.current) codeRef.current.value = "";
      codeRef.current?.focus();
    } else if (failure.kind === "forbidden") {
      setError(failure.message);
    } else if (failure.kind === "credentials") {
      setError("You are signed out. Sign in again to continue.");
    } else {
      setError(waitText(failure));
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <ol className="flex list-decimal flex-col gap-2 pl-5 text-sm">
        <li>Open an authenticator app on your phone and choose to add an account by entering a key.</li>
        <li>Enter the key below. If the app asks, choose time-based codes.</li>
        <li>Type the 6-digit code the app shows into the box below and confirm.</li>
      </ol>
      <p className="text-sm text-text-secondary">A QR code is not available yet, so enter the key by hand or copy the setup link.</p>

      <div className="flex flex-col gap-2">
        <p className="text-sm font-medium" id="secret-label">
          Setup key
        </p>
        <p
          aria-labelledby="secret-label"
          className="break-words rounded-xl border border-border bg-surface-2 p-3 font-mono text-lg tracking-wider"
        >
          {groupsOfFour(secret)}
        </p>
        <CopyButton label="Copy key" value={secret} doneLabel="Key copied." />
      </div>

      <div className="flex flex-col gap-2">
        <label htmlFor="otpauth-link" className="text-sm font-medium">
          Setup link
        </label>
        <input
          id="otpauth-link"
          readOnly
          value={uri}
          className={`${fieldClass} font-mono text-sm`}
          onFocus={(event) => event.currentTarget.select()}
        />
        <CopyButton label="Copy link" value={uri} doneLabel="Link copied." />
      </div>

      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
        <div className="flex flex-col gap-1.5">
          <label htmlFor="confirm-code" className="text-sm font-medium">
            6-digit code from the app
          </label>
          <input
            ref={codeRef}
            id="confirm-code"
            name="code"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            pattern="[0-9]*"
            maxLength={7}
            aria-describedby="confirm-error"
            className={fieldClass}
          />
        </div>
        <p id="confirm-error" role="alert" aria-live="assertive" className="min-h-6 text-sm font-medium text-danger">
          {error}
        </p>
        <button type="submit" disabled={busy} aria-busy={busy} className={`${primaryButton} self-start`}>
          {busy ? "Checking…" : "Confirm"}
        </button>
      </form>
    </div>
  );
}

function Codes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  const [saved, setSaved] = useState(false);

  return (
    <div className="flex flex-col gap-4">
      <h2 className="text-lg font-semibold">Save your recovery codes</h2>
      <p className="text-sm text-text-secondary">
        Two-step sign-in is now on. Each recovery code works once if you lose your phone. They are shown only now, so store them somewhere safe.
      </p>
      <ul aria-label="Recovery codes" className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        {codes.map((code) => (
          <li key={code} className="rounded-xl border border-border bg-surface-2 p-2 font-mono">
            {code}
          </li>
        ))}
      </ul>
      <CopyButton label="Copy all" value={codes.join("\n")} doneLabel="All codes copied." />

      <label className="flex min-h-11 items-center gap-3 text-sm font-medium">
        <input type="checkbox" checked={saved} onChange={(event) => setSaved(event.target.checked)} className="size-5" />I have saved these
        codes
      </label>
      <button type="button" disabled={!saved} onClick={onDone} className={`${primaryButton} self-start`}>
        Continue
      </button>
    </div>
  );
}
