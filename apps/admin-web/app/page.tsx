import Link from "next/link";
import { Suspense } from "react";

import { ApiStatus, StatusRow } from "@/components/api-status";
import { ConsoleShell } from "@/components/console-shell";

export default function AdminOverview() {
  return (
    <ConsoleShell current="Overview" title="Overview">
      <section aria-labelledby="readiness-heading" className="flex flex-col gap-3">
        <div className="flex flex-col gap-1">
          <h2 id="readiness-heading" className="text-lg font-semibold">
            Setup readiness
          </h2>
          <p className="max-w-prose text-sm text-text-secondary">
            What has to be true before the service can open. Only the first item is checked live on this page; cities
            and zones are managed on the{" "}
            <Link href="/cities" className="font-medium text-brand-600 underline underline-offset-4">
              Cities
            </Link>{" "}
            screen.
          </p>
        </div>
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Suspense fallback={<StatusRow tone="waiting" title="Checking the API" detail="Asking the liveness route." />}>
            <ApiStatus />
          </Suspense>
          <StatusRow
            tone="ok"
            title="Two-step verification"
            detail="Staff sign in with a password and an authenticator code (ADR-011). This row is fixed text, not checked live."
          />
          <StatusRow tone="waiting" title="City configured" detail="See the Cities screen. It is not summarised here yet." />
          <StatusRow tone="waiting" title="Delivery zone active" detail="See a city's zones. It is not summarised here yet." />
        </ul>
      </section>

      <section aria-labelledby="dispatch-heading" className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="dispatch-heading" className="text-lg font-semibold">
            Live dispatch
          </h2>
          <p className="rounded-full border border-border-strong px-3 py-1 text-sm text-text-secondary">
            Layout preview · later phase
          </p>
        </div>
        <p className="max-w-prose text-sm text-text-secondary">
          This is how the dispatch screen will be arranged. There are no orders, riders or figures, so none are shown.
        </p>

        <div className="grid gap-3 xl:grid-cols-[18rem_minmax(0,1fr)_20rem]">
          <div className="flex flex-col gap-2 rounded-2xl border border-dashed border-border-strong bg-surface p-4">
            <h3 className="font-medium">Unassigned orders</h3>
            <p className="text-sm text-text-secondary">Orders waiting for a rider will queue here.</p>
          </div>

          <div className="flex min-h-72 flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-border-strong bg-surface p-6 text-center">
            <h3 className="font-medium">Map</h3>
            <p className="max-w-sm text-sm text-text-secondary">
              No city is selected, so there is nothing to centre on. The map has no default location.
            </p>
          </div>

          <div className="flex flex-col gap-2 rounded-2xl border border-dashed border-border-strong bg-surface p-4">
            <h3 className="font-medium">Order details</h3>
            <p className="text-sm text-text-secondary">
              Pickup, drop-off and assignment for the selected order will appear here.
            </p>
          </div>
        </div>
      </section>
    </ConsoleShell>
  );
}
