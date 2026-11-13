import Link from "next/link";
import { Suspense } from "react";

import { SessionStatus } from "@food-delivery/session";

import {
  BagIcon,
  HeartIcon,
  HomeIcon,
  PinIcon,
  ReceiptIcon,
  SearchIcon,
  UserIcon,
} from "@/components/icons";
import { ServiceBanner, ServiceBannerFallback } from "@/components/service-banner";

// Placeholder until a brand is chosen (docs/ux/design-direction.md, rule 4).
const appName = process.env.NEXT_PUBLIC_APP_NAME ?? "Food Delivery";

// Cuisine types are generic categories, not data about any restaurant.
const categories = [
  { label: "Burgers", glyph: "🍔" },
  { label: "Pizza", glyph: "🍕" },
  { label: "Rice meals", glyph: "🍛" },
  { label: "Noodles", glyph: "🍜" },
  { label: "Chicken", glyph: "🍗" },
  { label: "Salads", glyph: "🥗" },
  { label: "Desserts", glyph: "🍰" },
  { label: "Drinks", glyph: "🧋" },
];

const tabs = [
  { label: "Home", icon: HomeIcon, current: true },
  { label: "Orders", icon: ReceiptIcon, current: false },
  { label: "Saved", icon: HeartIcon, current: false },
  { label: "Account", icon: UserIcon, current: false },
];

function SoonBadge() {
  return (
    <span className="rounded-full bg-warm-100 px-2 py-0.5 text-xs font-medium text-text-secondary">
      Not built yet
    </span>
  );
}

export default function Home() {
  return (
    <div className="flex min-h-full flex-1 flex-col bg-linear-to-b from-warm-100 to-warm-50 pb-24 md:pb-0">
      <header className="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-3 px-4 pt-4 md:flex-nowrap md:gap-6 md:py-5">
        <p className="order-1 text-lg font-bold tracking-tight">{appName}</p>

        <Link
          href="/location"
          aria-describedby="location-hint"
          className="order-3 flex min-h-11 w-full items-center gap-2 rounded-full border border-border-strong bg-surface px-4 text-left text-sm md:order-2 md:w-auto"
        >
          <PinIcon className="shrink-0 text-accent-text" />
          <span className="flex flex-col leading-tight">
            <span className="text-xs text-text-secondary">Deliver to</span>
            <span className="font-medium">Check my address</span>
          </span>
        </Link>

        <div className="order-4 flex min-h-11 w-full items-center gap-2 rounded-full border border-border-strong bg-surface px-4 md:order-3 md:flex-1">
          <SearchIcon className="shrink-0 text-text-secondary" />
          <label htmlFor="search" className="sr-only">
            Search dishes and restaurants
          </label>
          <input
            id="search"
            type="search"
            disabled
            placeholder="Search opens with the first restaurants"
            className="min-h-11 w-full bg-transparent text-sm placeholder:text-text-secondary focus:outline-none"
          />
        </div>

        <SessionStatus className="order-2 ml-auto flex min-h-11 max-w-full items-center rounded-full border border-border-strong bg-surface px-4 text-sm md:order-4 md:ml-0" />

        <button
          type="button"
          disabled
          aria-label="Cart, not built yet"
          className="order-2 grid size-11 place-items-center rounded-full border border-border-strong bg-surface text-text-secondary md:order-5"
        >
          <BagIcon />
        </button>
      </header>

      <p id="location-hint" className="mx-auto w-full max-w-6xl px-4 pt-2 text-xs text-text-secondary">
        You can check whether we deliver to a spot. Saving an address and searching are not built yet.
      </p>

      <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 px-4 py-6">
        <Suspense fallback={<ServiceBannerFallback />}>
          <ServiceBanner />
        </Suspense>

        <section aria-labelledby="categories-heading" className="flex flex-col gap-3">
          <div className="flex items-center justify-between gap-3">
            <h2 id="categories-heading" className="text-xl font-semibold">
              Categories
            </h2>
            <SoonBadge />
          </div>
          <ul
            tabIndex={0}
            aria-labelledby="categories-heading"
            className="-mx-4 flex gap-3 overflow-x-auto px-4 pb-2 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-border-strong md:mx-0 md:grid md:grid-cols-8 md:overflow-visible md:px-0"
          >
            {categories.map((category) => (
              <li
                key={category.label}
                className="flex w-20 shrink-0 flex-col items-center gap-2 md:w-auto"
              >
                <span
                  aria-hidden="true"
                  className="grid size-16 place-items-center rounded-2xl bg-surface text-3xl shadow-sm"
                >
                  {category.glyph}
                </span>
                <span className="text-center text-sm text-text-secondary">{category.label}</span>
              </li>
            ))}
          </ul>
        </section>

        <section aria-labelledby="restaurants-heading" className="flex flex-col gap-3">
          <h2 id="restaurants-heading" className="text-xl font-semibold">
            Restaurants near you
          </h2>

          <div className="flex flex-col items-start gap-2 rounded-3xl border border-border bg-surface p-6">
            <p className="text-lg font-medium">No restaurants yet</p>
            <p className="max-w-prose text-text-secondary">
              Restaurants appear here once merchants have joined and a delivery area is open. Nothing below is a real
              listing.
            </p>
          </div>

          <p className="pt-2 text-sm text-text-secondary">Layout preview, with no real restaurants:</p>
          <ul aria-label="Layout preview, not real restaurants" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {[0, 1, 2].map((item) => (
              <li
                key={item}
                aria-hidden="true"
                className="flex flex-col gap-3 rounded-3xl border border-dashed border-border-strong bg-surface p-3"
              >
                <div className="aspect-[16/10] rounded-2xl bg-warm-100" />
                <div className="flex flex-col gap-2 px-1 pb-2">
                  <div className="h-4 w-2/3 rounded-full bg-warm-100" />
                  <div className="h-3 w-1/2 rounded-full bg-warm-100" />
                </div>
              </li>
            ))}
          </ul>
        </section>
      </main>

      <footer className="mx-auto hidden w-full max-w-6xl px-4 py-6 text-sm text-text-secondary md:block">
        {appName} is in development. This is not a live service.
      </footer>

      <nav
        aria-label="Primary"
        className="fixed inset-x-0 bottom-0 border-t border-border bg-surface px-2 pb-[env(safe-area-inset-bottom)] md:hidden"
      >
        <ul className="mx-auto flex max-w-md justify-between">
          {tabs.map(({ label, icon: TabIcon, current }) => (
            <li key={label} className="flex-1">
              {current ? (
                <span
                  aria-current="page"
                  className="flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-semibold text-accent-text"
                >
                  <TabIcon />
                  {label}
                </span>
              ) : (
                <button
                  type="button"
                  disabled
                  aria-label={`${label}, not built yet`}
                  className="flex min-h-14 w-full flex-col items-center justify-center gap-0.5 text-xs text-text-secondary"
                >
                  <TabIcon />
                  {label}
                </button>
              )}
            </li>
          ))}
        </ul>
      </nav>
    </div>
  );
}
