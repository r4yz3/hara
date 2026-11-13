import type { Metadata } from "next";
import Link from "next/link";

import { LocationCheck } from "./location-check";

export const metadata: Metadata = { title: "Check my address" };

export default function LocationPage() {
  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 px-4 py-8">
      <Link href="/" className="w-fit text-sm font-medium text-accent-text underline underline-offset-4">
        ← Back
      </Link>

      <div className="flex flex-col gap-2">
        <h1 className="text-3xl font-bold tracking-tight">Do we deliver to you?</h1>
        <p className="text-text-secondary">
          Give the position of your delivery address and we will tell you whether it is inside an open delivery area.
        </p>
      </div>

      <LocationCheck />

      <section aria-labelledby="limits-heading" className="rounded-3xl border border-dashed border-border-strong bg-surface p-5">
        <h2 id="limits-heading" className="font-semibold">
          Not built yet
        </h2>
        <ul className="mt-2 list-disc pl-5 text-sm text-text-secondary">
          <li>Typing an address or dropping a pin on a map. No map or address-lookup provider has been chosen.</li>
          <li>Saving an address with a landmark to your account.</li>
          <li>Checking that a rider can actually reach the spot by road. This check uses the area&apos;s outline only.</li>
        </ul>
      </section>
    </main>
  );
}
