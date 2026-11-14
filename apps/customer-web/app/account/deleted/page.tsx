import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = { title: "Account deleted" };

/** Where a customer lands after deleting their account. Static: nothing personal is passed or shown. */
export default function AccountDeletedPage() {
  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 px-4 py-8">
      <h1 className="text-3xl font-bold tracking-tight">Your account was deleted.</h1>
      <p role="status" className="text-text-secondary">
        Your saved addresses, profile and sign-in are gone. You are signed out. Copies may remain in backups for a limited time.
      </p>
      <Link
        href="/"
        className="inline-flex min-h-12 w-fit items-center rounded-xl border border-border-strong bg-surface px-4 text-base font-semibold"
      >
        Go to the home page
      </Link>
    </main>
  );
}
