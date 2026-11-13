import type { Metadata } from "next";
import Link from "next/link";

import { SecurityScreen } from "./security-screen";

export const metadata: Metadata = { title: "Two-step sign-in" };

export default function SecurityPage() {
  return (
    <main className="flex flex-1 items-start justify-center bg-surface-2 px-4 py-10">
      <div className="flex w-full max-w-lg flex-col gap-6 rounded-3xl border border-border bg-surface p-6 sm:p-8">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Two-step sign-in</h1>
          <p className="text-text-secondary">An authenticator app adds a code to your password, so a stolen password is not enough.</p>
        </div>

        <SecurityScreen />

        <Link href="/" className="min-h-11 content-center text-sm font-medium text-brand-600 underline underline-offset-4">
          Back to the console
        </Link>
      </div>
    </main>
  );
}
