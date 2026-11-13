import Link from "next/link";
import type { ReactNode } from "react";

import { SessionStatus } from "@food-delivery/session";

// Placeholder until a brand is chosen (docs/ux/design-direction.md, rule 4).
const appName = process.env.NEXT_PUBLIC_APP_NAME ?? "Food Delivery";

type NavItem = { label: string; href?: string; note?: "Not built yet" | "Later phase" | "Inside Cities" };

// Order follows the Phase 1 specification, section 8.5.
const navigation: { group: string; items: NavItem[] }[] = [
  {
    group: "Setup",
    items: [
      { label: "Overview", href: "/" },
      { label: "Cities", href: "/cities" },
      { label: "Delivery zones", note: "Inside Cities" },
      { label: "Roles and access", note: "Not built yet" },
      { label: "Audit log", href: "/audit" },
      { label: "Settings", note: "Not built yet" },
    ],
  },
  {
    group: "Operations",
    items: ["Live dispatch", "Orders", "Merchants", "Riders", "Customers"].map((label) => ({ label, note: "Later phase" })),
  },
  {
    group: "Business",
    items: ["Pricing", "Finance", "Promotions", "Support", "Reports"].map((label) => ({ label, note: "Later phase" })),
  },
];

function NavList({ current }: { current: string }) {
  return (
    <div className="flex flex-col gap-5">
      {navigation.map(({ group, items }) => (
        <div key={group} className="flex flex-col gap-1">
          <p className="px-3 text-xs font-semibold uppercase tracking-wide text-on-nav-muted">{group}</p>
          <ul className="flex flex-col gap-0.5">
            {items.map((item) => (
              <li key={item.label}>
                {item.href === undefined ? (
                  <button
                    type="button"
                    disabled
                    className="flex min-h-11 w-full items-center justify-between gap-2 rounded-xl px-3 text-left text-on-nav-muted"
                  >
                    {item.label}
                    <span className="shrink-0 rounded-full border border-on-nav-muted px-2 py-0.5 text-xs">{item.note}</span>
                  </button>
                ) : item.label === current ? (
                  <Link
                    href={item.href}
                    aria-current="page"
                    className="flex min-h-11 items-center rounded-xl bg-brand-600 px-3 font-medium text-on-brand"
                  >
                    {item.label}
                  </Link>
                ) : (
                  <Link href={item.href} className="flex min-h-11 items-center rounded-xl px-3 text-on-nav hover:underline">
                    {item.label}
                  </Link>
                )}
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  );
}

/**
 * The console frame: dark navigation rail, top bar with the live session
 * state, and the page body. `current` is the navigation label to highlight.
 */
export function ConsoleShell({ current, title, children }: { current: string; title: string; children: ReactNode }) {
  return (
    <div className="flex min-h-full flex-1 flex-col lg:flex-row">
      {/* Phone and tablet: the same navigation inside a disclosure, no script needed. */}
      <details className="bg-nav text-on-nav lg:hidden">
        <summary className="flex min-h-14 cursor-pointer items-center justify-between px-4 font-semibold">
          {appName} · Operations
          <span className="rounded-full border border-on-nav-muted px-3 py-1 text-sm font-normal">Menu</span>
        </summary>
        <nav aria-label="Primary" className="px-2 pb-4">
          <NavList current={current} />
        </nav>
      </details>

      <aside className="hidden w-64 shrink-0 flex-col gap-6 bg-nav px-3 py-5 text-on-nav lg:flex">
        <p className="px-3 text-lg font-bold tracking-tight">
          {appName}
          <span className="block text-sm font-normal text-on-nav-muted">Operations console</span>
        </p>
        <nav aria-label="Primary">
          <NavList current={current} />
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex flex-wrap items-center gap-3 border-b border-border bg-surface px-4 py-3 lg:px-8">
          <h1 className="mr-auto text-xl font-semibold">{title}</h1>
          <SessionStatus className="flex min-h-11 max-w-full items-center rounded-full border border-border-strong px-4 text-sm" />
        </header>

        <main className="flex flex-1 flex-col gap-8 px-4 py-6 lg:px-8">{children}</main>
      </div>
    </div>
  );
}
