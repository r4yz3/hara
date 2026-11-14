import type { Metadata } from "next";

import { InviteAccept } from "./invite-accept";

export const metadata: Metadata = { title: "Accept your invitation" };

/** Public: the person opening an invitation has no session yet. */
export default function InvitePage() {
  return (
    <main className="flex flex-1 items-center justify-center bg-surface-2 px-4 py-10">
      <div className="flex w-full max-w-sm flex-col gap-6 rounded-3xl border border-border bg-surface p-6 sm:p-8">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Accept your invitation</h1>
          <p className="text-text-secondary">Join the admin team.</p>
        </div>

        <InviteAccept />
      </div>
    </main>
  );
}
