import Link from "next/link";
import { connection } from "next/server";
import type { ReactNode } from "react";

import { ClockIcon, PinIcon } from "@/components/icons";

const apiUrl = process.env.API_URL ?? "http://localhost:8000";

type ServiceState = "open" | "closed" | "unknown";

/**
 * How long a successful answer is reused, in ms. Every page render on this
 * server asks the API, and all of those calls share one client IP, which the
 * API rate-limits (public-config, 120/min per IP). Reusing a recent answer
 * keeps normal traffic under that limit. SERVICE_STATE_CACHE_SECONDS sets it
 * (default 30; 0 turns the cache off). Anything that is not a non-negative
 * integer falls back to the default.
 */
const DEFAULT_SERVICE_STATE_CACHE_SECONDS = 30;

function cacheTtlMs(raw: string | undefined): number {
  const value = raw?.trim() ?? "";
  const seconds = /^\d+$/.test(value) ? Number(value) : DEFAULT_SERVICE_STATE_CACHE_SECONDS;
  return seconds * 1000;
}

const SERVICE_STATE_TTL_MS = cacheTtlMs(process.env.SERVICE_STATE_CACHE_SECONDS);

let cached: { state: "open" | "closed"; expiresAt: number } | undefined;
let inFlight: Promise<ServiceState> | undefined;

async function askApi(): Promise<ServiceState> {
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

/**
 * Asks the API, from the server, whether any delivery area is open. The
 * answer may be up to 30 s old by default (see SERVICE_STATE_CACHE_SECONDS):
 * the last successful answer ("open" or "closed") is kept in memory for
 * SERVICE_STATE_TTL_MS, and concurrent
 * renders share one in-flight request. A failed or unreadable answer is
 * never cached, so the next render asks again. The banner never claims the
 * service is open unless the API said so; if the API cannot be reached it
 * says that instead.
 */
async function serviceState(): Promise<ServiceState> {
  if (cached && cached.expiresAt > Date.now()) return cached.state;

  inFlight ??= askApi()
    .then((state) => {
      if (state !== "unknown" && SERVICE_STATE_TTL_MS > 0) {
        cached = { state, expiresAt: Date.now() + SERVICE_STATE_TTL_MS };
      }
      return state;
    })
    .finally(() => {
      inFlight = undefined;
    });

  return inFlight;
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
