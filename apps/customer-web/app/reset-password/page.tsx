import type { Metadata } from "next";
import Link from "next/link";

import { ResetPasswordForm } from "@food-delivery/session";

export const metadata: Metadata = { title: "Choose a new password" };

export default function ResetPasswordPage() {
  return (
    <main className="flex flex-1 items-center justify-center bg-warm-50 px-4 py-10">
      <div className="flex w-full max-w-sm flex-col gap-6 rounded-3xl border border-border bg-surface p-6 sm:p-8">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Choose a new password</h1>
          <p className="text-text-secondary">This link works once.</p>
        </div>

        <ResetPasswordForm actionClassName="bg-cta text-on-cta" />

        <Link href="/login" className="min-h-11 content-center text-sm font-medium text-brand-600 underline underline-offset-4">
          Back to sign in
        </Link>
      </div>
    </main>
  );
}
