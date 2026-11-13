"use client";

import { useRef, useState, type FormEvent } from "react";

import { safeReturnPath, signIn, type Failure } from "./client";

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
export function SignInForm({ actionClassName }: { actionClassName: string }) {
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
    const result = await signIn(email, password);

    if (result.ok) {
      const target = safeReturnPath(new URLSearchParams(window.location.search).get("next"));
      window.location.assign(target);
      return;
    }

    setSubmitting(false);
    setMessage(describe(result.failure));
    if (result.failure.kind === "credentials") {
      if (passwordRef.current) passwordRef.current.value = "";
      passwordRef.current?.focus();
    }
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
