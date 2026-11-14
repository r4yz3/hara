"use client";

import { useEffect, useState } from "react";

import type { City, Zone } from "@food-delivery/api-client";
import { apiGet, isEnrolmentOnly, type ApiFailure } from "@food-delivery/session";

import { StatusRow } from "@/components/api-status";

type Envelope<T> = { data: T; meta: { next_cursor?: string | null } };

type Row = {
  tone: "ok" | "problem" | "waiting";
  title: string;
  detail: string;
  word?: string;
  action?: { href: string; label: string };
};

type Summary =
  | { phase: "loading" }
  | { phase: "failed"; failure: ApiFailure }
  | {
      phase: "ready";
      cities: City[];
      moreCities: boolean;
      zones: "failed" | { activeIn: Set<string>; unsure: Set<string> };
    };

const citiesLink = { href: "/cities", label: "Open Cities" };

/**
 * Reads the signed-in admin API from the browser, like the other console screens: the session cookie belongs to the
 * API's origin, so a server component could not forward it. Cities come from one list call; zone status needs one
 * list call per active city (there is no cross-city zone list), which stays small while few cities are active.
 */
async function load(): Promise<Summary> {
  const cities = await apiGet<Envelope<City[]>>("/api/v1/admin/cities?status=active&limit=100");
  if (!cities.ok) return { phase: "failed", failure: cities.failure };

  const active = cities.value.data.filter((city) => city.status === "active");
  const moreCities = cities.value.meta.next_cursor != null;
  const lists = await Promise.all(
    active.map((city) => apiGet<Envelope<Zone[]>>(`/api/v1/admin/cities/${city.id}/zones?limit=100`)),
  );

  const activeIn = new Set<string>();
  const unsure = new Set<string>();
  let failed = false;

  lists.forEach((list, index) => {
    if (!list.ok) {
      failed = true;
    } else if (list.value.data.some((zone) => zone.status === "active")) {
      activeIn.add(active[index].id);
    } else if (list.value.meta.next_cursor != null) {
      unsure.add(active[index].id);
    }
  });

  return { phase: "ready", cities: active, moreCities, zones: failed ? "failed" : { activeIn, unsure } };
}

function failedRows(failure: ApiFailure): [Row, Row] {
  let row: Omit<Row, "title">;

  if (failure.kind === "signed_out") {
    row = {
      tone: "waiting",
      word: "Sign in to check",
      detail: "You are signed out, so this is not checked.",
      action: { href: "/login?next=%2F", label: "Sign in" },
    };
  } else if (isEnrolmentOnly(failure)) {
    row = {
      tone: "waiting",
      word: "Set up first",
      detail: "Set up two-step sign-in before this can be checked.",
      action: { href: "/security", label: "Set up two-step sign-in" },
    };
  } else if (failure.kind === "forbidden") {
    row = { tone: "waiting", word: "Not checked", detail: "Your account cannot read this, so it is not checked." };
  } else {
    row = { tone: "problem", word: "Could not check", detail: "The API did not answer this check. Reload the page to try again." };
  }

  return [
    { ...row, title: "City configured" },
    { ...row, title: "Delivery zone active" },
  ];
}

function rowsFor(summary: Summary): [Row, Row] {
  if (summary.phase === "loading") {
    return [
      { tone: "waiting", word: "Checking", title: "Checking cities", detail: "Asking the admin API." },
      { tone: "waiting", word: "Checking", title: "Checking delivery zones", detail: "Asking the admin API." },
    ];
  }
  if (summary.phase === "failed") return failedRows(summary.failure);

  const { cities, moreCities, zones } = summary;

  if (cities.length === 0) {
    return [
      { tone: "waiting", word: "Waiting", title: "City configured", detail: "No active city yet. Create or activate one on Cities.", action: citiesLink },
      { tone: "waiting", word: "Waiting", title: "Delivery zone active", detail: "Waiting for an active city first.", action: citiesLink },
    ];
  }

  const names = cities.slice(0, 3).map((city) => city.name).join(", ") + (cities.length > 3 ? `, and ${cities.length - 3} more` : "");
  const count = `${cities.length}${moreCities ? "+" : ""}`;
  const cityRow: Row = {
    tone: "ok",
    title: "City configured",
    detail: `${count} active ${cities.length === 1 && !moreCities ? "city" : "cities"}: ${names}.`,
    action: citiesLink,
  };

  if (zones === "failed") {
    return [
      cityRow,
      { tone: "problem", word: "Could not check", title: "Delivery zone active", detail: "The zone lists did not all answer. Reload the page to try again.", action: citiesLink },
    ];
  }

  const without = cities.filter((city) => !zones.activeIn.has(city.id));
  const certain = without.filter((city) => !zones.unsure.has(city.id));

  if (certain.length > 0) {
    const list = certain.slice(0, 3).map((city) => city.name).join(", ") + (certain.length > 3 ? " and others" : "");
    return [cityRow, { tone: "problem", title: "Delivery zone active", detail: `An active city has no active zone: ${list}.`, action: citiesLink }];
  }
  if (without.length > 0) {
    return [
      cityRow,
      { tone: "waiting", word: "Not fully checked", title: "Delivery zone active", detail: "Some cities have too many zones to summarise here. Open the city to see them.", action: citiesLink },
    ];
  }

  return [
    cityRow,
    { tone: "ok", title: "Delivery zone active", detail: `Every active city has an active zone (${zones.activeIn.size} of ${cities.length}).`, action: citiesLink },
  ];
}

export function CoverageStatus() {
  const [summary, setSummary] = useState<Summary>({ phase: "loading" });

  useEffect(() => {
    let cancelled = false;
    void load().then((next) => {
      if (!cancelled) setSummary(next);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <>
      {rowsFor(summary).map((row) => (
        <StatusRow key={row.title} {...row} />
      ))}
    </>
  );
}
