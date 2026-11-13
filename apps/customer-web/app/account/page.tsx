import type { Metadata } from "next";
import Link from "next/link";

import { AccountScreen } from "./account-screen";

export const metadata: Metadata = { title: "Your account" };

export default function AccountPage() {
  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 px-4 py-8">
      <Link href="/" className="min-h-11 w-fit content-center text-sm font-medium text-accent-text underline underline-offset-4">
        ← Back
      </Link>

      <h1 className="text-3xl font-bold tracking-tight">Your account</h1>

      <AccountScreen />

      <section aria-labelledby="account-limits-heading" className="rounded-3xl border border-dashed border-border-strong bg-surface p-5">
        <h2 id="account-limits-heading" className="font-semibold">
          Not available yet
        </h2>
        <ul className="mt-2 list-disc pl-5 text-sm text-text-secondary">
          <li>Saved addresses.</li>
          <li>Orders and order history.</li>
          <li>Payment methods.</li>
        </ul>
      </section>
    </main>
  );
}
