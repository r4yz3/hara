import type { Metadata } from "next";
import Link from "next/link";

import { SignInForm } from "@food-delivery/session";

export const metadata: Metadata = { title: "Sign in" };

export default function LoginPage() {
  return (
    <main className="flex flex-1 items-center justify-center bg-warm-50 px-4 py-10">
      <div className="flex w-full max-w-sm flex-col gap-6 rounded-3xl border border-border bg-surface p-6 sm:p-8">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Sign in</h1>
          <p className="text-text-secondary">Sign in to your account.</p>
        </div>

        <SignInForm actionClassName="bg-cta text-on-cta" />

        <p className="text-sm text-text-secondary">Creating an account is not open yet.</p>
        <p className="text-sm text-text-secondary">Forgotten passwords cannot be reset yet.</p>
        <Link href="/" className="min-h-11 content-center text-sm font-medium text-brand-600 underline underline-offset-4">
          Back to the start page
        </Link>
      </div>
    </main>
  );
}
