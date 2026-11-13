import Link from "next/link";
import { connection } from "next/server";
import type { ReactNode } from "react";

import { ClockIcon, PinIcon } from "@/components/icons";

const apiUrl = process.env.API_URL ?? "http://localhost:8000";

type ServiceState = "open" | "closed" | "unknown";

/**
 * Asks the API, from the server, on every request, whether any delivery
 * area is open. The banner never claims the service is open unless the API
 * said so; if the API cannot be reached it says that instead.
 */
async function serviceState(): Promise<ServiceState> {
  try {
    const response = await fetch(`${apiUrl}/api/v1/public/config`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
      signal: AbortSignal.timeout(2500),
    });
    if (!response.ok) return "unknown";

    const body = (await response.json()) as { data?: { service_open?: unknown } };
    return body.data?.service_open === true ? "open" : body.data?.service_open === false ? "closed" : "unknown";
  } catch {
    return "unknown";
  }
}

function Frame({ badge, title, children }: { badge: ReactNode; title: string; children: ReactNode }) {
  return (
    <section aria-labelledby="status-heading" className="relative overflow-hidden rounded-3xl bg-cta p-6 text-on-cta md:p-10">
      <div aria-hidden="true" className="absolute -right-10 -top-16 hidden size-56 rounded-full bg-accent-500 opacity-60 lg:block" />
      <div aria-hidden="true" className="absolute -bottom-20 right-24 hidden size-40 rounded-full bg-accent-500 opacity-40 lg:block" />
      <div className="relative flex max-w-xl flex-col gap-3">
        <p className="flex w-fit items-center gap-2 rounded-full bg-surface px-3 py-1 text-sm font-medium text-text-primary">
          {badge}
        </p>
        <h1 id="status-heading" className="text-3xl font-bold tracking-tight md:text-4xl">
          {title}
        </h1>
        {children}
      </div>
    </section>
  );
}

const checkLink =
  "flex min-h-12 w-fit items-center gap-2 rounded-full bg-surface px-5 font-semibold text-text-primary";

export function ServiceBannerFallback() {
  return (
    <Frame
      badge={
        <>
          <ClockIcon width={16} height={16} />
          Checking
        </>
      }
      title="Checking where we deliver"
    >
      <p className="text-base" aria-busy="true">
        One moment.
      </p>
    </Frame>
  );
}

export async function ServiceBanner() {
  await connection();
  const state = await serviceState();

  if (state === "open") {
    return (
      <Frame
        badge={
          <>
            <PinIcon width={16} height={16} />
            Delivery areas are open
          </>
        }
        title="See if we deliver to you"
      >
        <p className="text-base">
          Delivery is open in some areas. Ordering is not built yet, but you can check whether your address is inside
          one.
        </p>
        <Link href="/location" className={checkLink}>
          Check my address
        </Link>
      </Frame>
    );
  }

  if (state === "closed") {
    return (
      <Frame
        badge={
          <>
            <ClockIcon width={16} height={16} />
            Launching soon
          </>
        }
        title="We are not delivering anywhere yet"
      >
        <p className="text-base">
          No delivery area has been opened. When one is, this page will show the restaurants that deliver to your
          address.
        </p>
      </Frame>
    );
  }

  return (
    <Frame
      badge={
        <>
          <ClockIcon width={16} height={16} />
          Not available
        </>
      }
      title="We could not check where we deliver"
    >
      <p className="text-base">The service did not answer. Reload the page to try again.</p>
    </Frame>
  );
}
