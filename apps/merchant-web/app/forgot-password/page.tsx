import type { Metadata } from "next";
import Link from "next/link";

import { ForgotPasswordForm } from "@food-delivery/session";

export const metadata: Metadata = { title: "Forgotten password" };

export default function ForgotPasswordPage() {
  return (
    <main className="flex flex-1 items-center justify-center bg-surface-2 px-4 py-10">
      <div className="flex w-full max-w-sm flex-col gap-6 rounded-3xl border border-border bg-surface p-6 sm:p-8">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Forgotten password</h1>
          <p className="text-text-secondary">Enter your email and we will send a link to choose a new password.</p>
        </div>

        <ForgotPasswordForm actionClassName="bg-brand-600 text-on-brand" />

        <Link href="/login" className="min-h-11 content-center text-sm font-medium text-brand-600 underline underline-offset-4">
          Back to sign in
        </Link>
      </div>
    </main>
  );
}
