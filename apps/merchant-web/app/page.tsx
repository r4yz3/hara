import { SessionStatus } from "@food-delivery/session";

// Placeholder until a brand is chosen (docs/ux/design-direction.md, rule 4).
const appName = process.env.NEXT_PUBLIC_APP_NAME ?? "Food Delivery";

// Routes from the Phase 1 specification, section 8.3.
const navigation = [
  { label: "Overview", current: true },
  { label: "Orders", current: false },
  { label: "Menu", current: false },
  { label: "Branches", current: false },
  { label: "Staff", current: false },
  { label: "Settings", current: false },
];

// Phones get the three most-used destinations (spec 8.3).
const tabs = navigation.filter((item) => ["Orders", "Menu", "Overview"].includes(item.label));

// The order board's lanes (spec 8.3). Empty: there are no orders.
const lanes = [
  { title: "New", hint: "Orders waiting for you to accept or decline.", attention: true },
  { title: "Preparing", hint: "Accepted orders, with their preparation time.", attention: false },
  { title: "Ready for pickup", hint: "Packed orders waiting for a rider.", attention: false },
];

function Tag({ children }: { children: React.ReactNode }) {
  return (
    <span className="shrink-0 rounded-full border border-border-strong px-2 py-0.5 text-xs text-text-secondary">
      {children}
    </span>
  );
}

export default function MerchantOverview() {
  return (
    <div className="flex min-h-full flex-1 pb-16 lg:pb-0">
      <aside className="hidden w-64 shrink-0 flex-col gap-6 border-r border-border bg-surface px-3 py-5 lg:flex">
        <p className="px-3 text-lg font-bold tracking-tight">
          {appName}
          <span className="block text-sm font-normal text-text-secondary">Merchant portal</span>
        </p>
        <nav aria-label="Primary">
          <ul className="flex flex-col gap-1">
            {navigation.map((item) => (
              <li key={item.label}>
                {item.current ? (
                  <span
                    aria-current="page"
                    className="flex min-h-12 items-center rounded-xl bg-brand-600 px-3 font-medium text-on-brand"
                  >
                    {item.label}
                  </span>
                ) : (
                  <button
                    type="button"
                    disabled
                    className="flex min-h-12 w-full items-center justify-between gap-2 rounded-xl px-3 text-left text-text-secondary"
                  >
                    {item.label}
                    <Tag>Not built yet</Tag>
                  </button>
                )}
              </li>
            ))}
          </ul>
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex flex-wrap items-center gap-3 border-b border-border bg-surface px-4 py-3 lg:px-8">
          <div className="mr-auto flex flex-col">
            <p className="text-sm text-text-secondary lg:hidden">{appName} · Merchant portal</p>
            <h1 className="text-xl font-semibold">Overview</h1>
          </div>
          <div className="flex min-h-12 items-center gap-2 rounded-full border border-border-strong px-4 text-sm">
            <span className="text-text-secondary">Branch</span>
            <span className="font-medium">None assigned</span>
          </div>
          <SessionStatus className="flex min-h-12 max-w-full items-center rounded-full border border-border-strong px-4 text-sm" />
          <button
            type="button"
            disabled
            aria-describedby="pause-hint"
            className="min-h-12 rounded-full border border-border-strong px-4 text-sm text-text-secondary"
          >
            Pause store
          </button>
        </header>

        <main className="grid flex-1 content-start gap-6 px-4 py-6 lg:px-8 xl:grid-cols-[minmax(0,1fr)_20rem]">
          <div className="flex min-w-0 flex-col gap-6">
            <section
              aria-labelledby="welcome-heading"
              className="flex flex-col gap-2 rounded-3xl bg-brand-tint p-6"
            >
              <h2 id="welcome-heading" className="text-2xl font-semibold">
                Your store is not set up yet
              </h2>
              <p className="max-w-prose text-text-secondary">
                No branch is linked to this portal yet. When one is, this page will
                show today&apos;s orders for your branch. Until then there are no sales or order figures to show.
              </p>
              <p id="pause-hint" className="text-sm text-text-secondary">
                Pausing the store needs a branch, so the button above is switched off.
              </p>
            </section>

            <section aria-labelledby="board-heading" className="flex flex-col gap-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="board-heading" className="text-lg font-semibold">
                  Order board
                </h2>
                <Tag>Layout preview · later phase</Tag>
              </div>
              <ul className="grid gap-3 md:grid-cols-3">
                {lanes.map((lane) => (
                  <li
                    key={lane.title}
                    className="flex min-h-56 flex-col gap-2 rounded-2xl border border-dashed border-border-strong bg-surface p-4"
                  >
                    <h3
                      className={
                        lane.attention
                          ? "w-fit rounded-full bg-highlight px-3 py-1 font-medium text-on-highlight"
                          : "w-fit rounded-full border border-border-strong px-3 py-1 font-medium"
                      }
                    >
                      {lane.title}
                    </h3>
                    <p className="text-sm text-text-secondary">{lane.hint}</p>
                    <p className="mt-auto text-sm font-medium">No orders</p>
                  </li>
                ))}
              </ul>
            </section>
          </div>

          <aside aria-label="Store panels" className="flex flex-col gap-3">
            <section
              aria-labelledby="stock-heading"
              className="flex flex-col gap-2 rounded-2xl border border-dashed border-border-strong bg-surface p-4"
            >
              <div className="flex items-center justify-between gap-2">
                <h2 id="stock-heading" className="font-semibold">
                  Out of stock
                </h2>
                <Tag>Later phase</Tag>
              </div>
              <p className="text-sm text-text-secondary">
                Items you mark as unavailable will be listed here with the time they return.
              </p>
              <p className="text-sm font-medium">No menu yet</p>
            </section>

            <section
              aria-labelledby="hours-heading"
              className="flex flex-col gap-2 rounded-2xl border border-dashed border-border-strong bg-surface p-4"
            >
              <div className="flex items-center justify-between gap-2">
                <h2 id="hours-heading" className="font-semibold">
                  Opening hours
                </h2>
                <Tag>Later phase</Tag>
              </div>
              <p className="text-sm text-text-secondary">Today&apos;s hours and any pause will show here.</p>
              <p className="text-sm font-medium">No branch assigned</p>
            </section>
          </aside>
        </main>
      </div>

      <nav
        aria-label="Primary"
        className="fixed inset-x-0 bottom-0 border-t border-border bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden"
      >
        <ul className="mx-auto flex max-w-md">
          {tabs.map((item) => (
            <li key={item.label} className="flex-1">
              {item.current ? (
                <span
                  aria-current="page"
                  className="flex min-h-14 items-center justify-center border-t-2 border-brand-600 font-semibold text-brand-600"
                >
                  {item.label}
                </span>
              ) : (
                <button
                  type="button"
                  disabled
                  aria-label={`${item.label}, not built yet`}
                  className="flex min-h-14 w-full items-center justify-center text-text-secondary"
                >
                  {item.label}
                </button>
              )}
            </li>
          ))}
        </ul>
      </nav>
    </div>
  );
}
