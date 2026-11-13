import type { Metadata } from "next";
import Link from "next/link";

import { SignInForm } from "@food-delivery/session";

export const metadata: Metadata = { title: "Staff sign in" };

export default function LoginPage() {
  return (
    <main className="flex flex-1 items-center justify-center bg-surface-2 px-4 py-10">
      <div className="flex w-full max-w-sm flex-col gap-6 rounded-3xl border border-border bg-surface p-6 sm:p-8">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Staff sign in</h1>
          <p className="text-text-secondary">For platform staff only.</p>
        </div>

        <SignInForm actionClassName="bg-brand-600 text-on-brand" />

        <p className="text-sm text-text-secondary">Two-step verification is not built yet, so staff sign-in is switched off in production.</p>
        <p className="text-sm text-text-secondary">Forgotten passwords cannot be reset yet.</p>
        <Link href="/" className="min-h-11 content-center text-sm font-medium text-brand-600 underline underline-offset-4">
          Back to the start page
        </Link>
      </div>
    </main>
  );
}
