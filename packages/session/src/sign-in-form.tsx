"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";

import {
  currentUser,
  isEnrolmentOnly,
  safeReturnPath,
  signIn,
  signInFirstStep,
  verifySecondStep,
  type Failure,
} from "./client";

function describe(failure: Failure): string {
  switch (failure.kind) {
    case "credentials":
      return "That email and password do not match an account that can use this app.";
    case "validation":
      return "Enter your email and your password.";
    case "rate_limited":
      return failure.retryAfterSeconds === null
        ? "Too many attempts. Wait a minute and try again."
        : `Too many attempts. Try again in ${failure.retryAfterSeconds} seconds.`;
    case "forbidden":
      return failure.message;
    case "network":
      return "We could not reach the server. Check your connection and try again.";
    case "unavailable":
      return "Sign-in is not available right now. Try again shortly.";
  }
}

/**
 * Sign-in form shared by the three web apps. Styling relies on the design
 * tokens each app maps in its globals.css (surface, border-strong, danger,
 * and the `action` / `on-action` pair passed in by the app).
 */
export function SignInForm({ actionClassName, twoStep = false }: { actionClassName: string; twoStep?: boolean }) {
  const [step, setStep] = useState<"password" | "code">("password");
  const [message, setMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const emailRef = useRef<HTMLInputElement>(null);
  const passwordRef = useRef<HTMLInputElement>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting) return; // one request at a time; no duplicate submits

    const data = new FormData(event.currentTarget);
    const email = String(data.get("email") ?? "").trim();
    const password = String(data.get("password") ?? "");

    if (email === "" || password === "") {
      setMessage("Enter your email and your password.");
      (email === "" ? emailRef : passwordRef).current?.focus();
      return;
    }

    setSubmitting(true);
    setMessage(null);
    if (twoStep) {
      const first = await signInFirstStep(email, password);

      if (first.ok && first.value.step === "code_required") {
        setSubmitting(false);
        setStep("code");
        return;
      }

      if (first.ok) {
        await leave(true);
        return;
      }

      if (first.failure.kind === "forbidden" && isEnrolmentOnly(first.failure)) {
        window.location.assign("/security");
        return;
      }

      fail(first.failure);
      return;
    }

    const result = await signIn(email, password);

    if (result.ok) {
      await leave(false);
      return;
    }

    fail(result.failure);
  }

  /** After a full sign-in. Staff who must still enrol are sent to /security. */
  async function leave(checkEnrolment: boolean) {
    if (checkEnrolment) {
      const me = await currentUser();
      if (!me.ok && me.failure.kind === "forbidden" && isEnrolmentOnly(me.failure)) {
        window.location.assign("/security");
        return;
      }
    }

    const target = safeReturnPath(new URLSearchParams(window.location.search).get("next"));
    window.location.assign(target);
  }

  function fail(failure: Failure) {
    setSubmitting(false);
    setMessage(describe(failure));
    if (failure.kind === "credentials") {
      if (passwordRef.current) passwordRef.current.value = "";
      passwordRef.current?.focus();
    }
  }

  if (step === "code") {
    return (
      <CodeStep
        actionClassName={actionClassName}
        onDone={() => leave(true)}
        onRestart={() => {
          setStep("password");
          setMessage(null);
        }}
      />
    );
  }

  const field =
    "min-h-12 w-full rounded-xl border border-border-strong bg-surface px-4 text-base text-text-primary";

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4" aria-describedby="sign-in-message">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="email" className="text-sm font-medium">
          Email
        </label>
        <input
          ref={emailRef}
          id="email"
          name="email"
          type="email"
          autoComplete="username"
          inputMode="email"
          required
          className={field}
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="password" className="text-sm font-medium">
          Password
        </label>
        <input
          ref={passwordRef}
          id="password"
          name="password"
          type="password"
          autoComplete="current-password"
          required
          className={field}
        />
      </div>

      <p id="sign-in-message" role="alert" aria-live="assertive" className="min-h-6 text-sm font-medium text-danger">
        {message}
      </p>

      <button
        type="submit"
        disabled={submitting}
        aria-busy={submitting}
        className={`min-h-12 rounded-xl px-4 text-base font-semibold disabled:opacity-70 ${actionClassName}`}
      >
        {submitting ? "Signing in…" : "Sign in"}
      </button>
    </form>
  );
}

function describeCode(failure: Failure, recovery: boolean): string {
  switch (failure.kind) {
    case "credentials":
      return `That ${recovery ? "recovery code" : "code"} was not accepted. If you signed in more than 5 minutes ago, start again.`;
    case "validation":
      return recovery ? "Enter one of your recovery codes." : "Enter the 6-digit code from your authenticator app.";
    case "rate_limited":
      return failure.retryAfterSeconds === null
        ? "Too many attempts. Wait a minute and try again."
        : `Too many attempts. Try again in ${failure.retryAfterSeconds} seconds.`;
    case "forbidden":
      return failure.message;
    case "network":
      return "We could not reach the server. Check your connection and try again.";
    case "unavailable":
      return "Sign-in is not available right now. Try again shortly.";
  }
}

/** Second step of staff sign-in: authenticator code, or one recovery code. */
function CodeStep({
  actionClassName,
  onDone,
  onRestart,
}: {
  actionClassName: string;
  onDone: () => Promise<void>;
  onRestart: () => void;
}) {
  const [recovery, setRecovery] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const codeRef = useRef<HTMLInputElement>(null);

  // Focus follows the step and the code type, so a screen reader hears the new label.
  useEffect(() => {
    codeRef.current?.focus();
  }, [recovery]);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting) return;

    const value = (codeRef.current?.value ?? "").trim();
    if (value === "") {
      setMessage(describeCode({ kind: "validation", fields: {} }, recovery));
      codeRef.current?.focus();
      return;
    }

    setSubmitting(true);
    setMessage(null);
    const result = await verifySecondStep(recovery ? { recovery_code: value } : { code: value.replace(/s+/g, "") });

    if (result.ok) {
      await onDone();
      return;
    }

    setSubmitting(false);
    setMessage(describeCode(result.failure, recovery));
    if (codeRef.current) codeRef.current.value = "";
    codeRef.current?.focus();
  }

  const field =
    "min-h-12 w-full rounded-xl border border-border-strong bg-surface px-4 text-base text-text-primary";
  const link = "min-h-11 self-start text-sm font-medium text-brand-600 underline underline-offset-4";

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4" aria-describedby="code-message">
      <div className="flex flex-col gap-1">
        <h2 className="text-lg font-semibold">{recovery ? "Use a recovery code" : "Enter your code"}</h2>
        <p className="text-sm text-text-secondary">
          {recovery
            ? "Enter one of the recovery codes you saved. Each one works once."
            : "Open your authenticator app and enter the 6-digit code for this account."}
        </p>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="mfa-code" className="text-sm font-medium">
          {recovery ? "Recovery code" : "6-digit code"}
        </label>
        {recovery ? (
          <input
            key="recovery"
            ref={codeRef}
            id="mfa-code"
            name="recovery_code"
            type="text"
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            maxLength={64}
            className={field}
          />
        ) : (
          <input
            key="totp"
            ref={codeRef}
            id="mfa-code"
            name="code"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            pattern="[0-9]*"
            maxLength={7}
            className={field}
          />
        )}
      </div>

      <p id="code-message" role="alert" aria-live="assertive" className="min-h-6 text-sm font-medium text-danger">
        {message}
      </p>

      <button
        type="submit"
        disabled={submitting}
        aria-busy={submitting}
        className={`min-h-12 rounded-xl px-4 text-base font-semibold disabled:opacity-70 ${actionClassName}`}
      >
        {submitting ? "Checking…" : "Verify"}
      </button>

      <button
        type="button"
        className={link}
        disabled={submitting}
        onClick={() => {
          setRecovery(!recovery);
          setMessage(null);
        }}
      >
        {recovery ? "Use an authenticator code instead" : "Use a recovery code instead"}
      </button>
      <button type="button" className={link} disabled={submitting} onClick={onRestart}>
        Start again
      </button>
    </form>
  );
}
