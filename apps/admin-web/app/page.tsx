import { Suspense } from "react";

import { SessionStatus } from "@food-delivery/session";

import { ApiStatus, StatusRow } from "@/components/api-status";

// Placeholder until a brand is chosen (docs/ux/design-direction.md, rule 4).
const appName = process.env.NEXT_PUBLIC_APP_NAME ?? "Food Delivery";

type NavItem = { label: string; state: "current" | "this-phase" | "later" };

// Order follows the Phase 1 specification, section 8.5.
const navigation: { group: string; items: NavItem[] }[] = [
  {
    group: "Setup",
    items: [
      { label: "Overview", state: "current" },
      { label: "Cities", state: "this-phase" },
      { label: "Delivery zones", state: "this-phase" },
      { label: "Roles and access", state: "this-phase" },
      { label: "Audit log", state: "this-phase" },
      { label: "Settings", state: "this-phase" },
    ],
  },
  {
    group: "Operations",
    items: [
      { label: "Live dispatch", state: "later" },
      { label: "Orders", state: "later" },
      { label: "Merchants", state: "later" },
      { label: "Riders", state: "later" },
      { label: "Customers", state: "later" },
    ],
  },
  {
    group: "Business",
    items: [
      { label: "Pricing", state: "later" },
      { label: "Finance", state: "later" },
      { label: "Promotions", state: "later" },
      { label: "Support", state: "later" },
      { label: "Reports", state: "later" },
    ],
  },
];

function NavList() {
  return (
    <div className="flex flex-col gap-5">
      {navigation.map(({ group, items }) => (
        <div key={group} className="flex flex-col gap-1">
          <p className="px-3 text-xs font-semibold uppercase tracking-wide text-on-nav-muted">{group}</p>
          <ul className="flex flex-col gap-0.5">
            {items.map((item) => (
              <li key={item.label}>
                {item.state === "current" ? (
                  <span
                    aria-current="page"
                    className="flex min-h-11 items-center rounded-xl bg-brand-600 px-3 font-medium text-on-brand"
                  >
                    {item.label}
                  </span>
                ) : (
                  <button
                    type="button"
                    disabled
                    className="flex min-h-11 w-full items-center justify-between gap-2 rounded-xl px-3 text-left text-on-nav-muted"
                  >
                    {item.label}
                    <span className="shrink-0 rounded-full border border-on-nav-muted px-2 py-0.5 text-xs">
                      {item.state === "later" ? "Later phase" : "Not built yet"}
                    </span>
                  </button>
                )}
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  );
}

export default function AdminOverview() {
  return (
    <div className="flex min-h-full flex-1 flex-col lg:flex-row">
      {/* Phone and tablet: the same navigation inside a disclosure, no script needed. */}
      <details className="bg-nav text-on-nav lg:hidden">
        <summary className="flex min-h-14 cursor-pointer items-center justify-between px-4 font-semibold">
          {appName} · Operations
          <span className="rounded-full border border-on-nav-muted px-3 py-1 text-sm font-normal">Menu</span>
        </summary>
        <nav aria-label="Primary" className="px-2 pb-4">
          <NavList />
        </nav>
      </details>

      <aside className="hidden w-64 shrink-0 flex-col gap-6 bg-nav px-3 py-5 text-on-nav lg:flex">
        <p className="px-3 text-lg font-bold tracking-tight">
          {appName}
          <span className="block text-sm font-normal text-on-nav-muted">Operations console</span>
        </p>
        <nav aria-label="Primary">
          <NavList />
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex flex-wrap items-center gap-3 border-b border-border bg-surface px-4 py-3 lg:px-8">
          <h1 className="mr-auto text-xl font-semibold">Overview</h1>
          <div className="flex min-h-11 items-center gap-2 rounded-full border border-border-strong px-4 text-sm">
            <span className="text-text-secondary">City</span>
            <span className="font-medium">None configured</span>
          </div>
          <SessionStatus className="flex min-h-11 max-w-full items-center rounded-full border border-border-strong px-4 text-sm" />
        </header>

        <main className="flex flex-1 flex-col gap-8 px-4 py-6 lg:px-8">
          <section aria-labelledby="readiness-heading" className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
              <h2 id="readiness-heading" className="text-lg font-semibold">
                Setup readiness
              </h2>
              <p className="max-w-prose text-sm text-text-secondary">
                What has to be true before the service can open. Only the first item is checked live; the others
                cannot be checked until their screens exist.
              </p>
            </div>
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <Suspense
                fallback={<StatusRow tone="waiting" title="Checking the API" detail="Asking the liveness route." />}
              >
                <ApiStatus />
              </Suspense>
              <StatusRow tone="waiting" title="Two-step verification" detail="Staff can sign in locally. The second step is not built, so production stays closed." />
              <StatusRow tone="waiting" title="City configured" detail="Cannot be checked here until the cities screen exists." />
              <StatusRow tone="waiting" title="Delivery zone active" detail="Needs a city and a drawn zone." />
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
              This is how the dispatch screen will be arranged. There are no orders, riders or figures, so none are
              shown.
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
        </main>
      </div>
    </div>
  );
}
