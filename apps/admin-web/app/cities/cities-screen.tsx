"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";

import type { City } from "@food-delivery/api-client";
import { apiGet, apiSend, describeFailure, isEnrolmentOnly, type ApiFailure } from "@food-delivery/session";

import { EnrolmentNotice, FieldError, LoadProblem, Notice, Panel, StatusPill, fieldClass, focusFirstInvalid, primaryButton } from "@/components/ui";

type Loaded = { phase: "loading" } | { phase: "failed"; failure: ApiFailure } | { phase: "ready"; cities: City[]; more: boolean };

type Envelope<T> = { data: T; meta: { next_cursor?: string | null } };

function slugify(name: string): string {
  return name
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

async function loadCities(): Promise<Loaded> {
  const result = await apiGet<Envelope<City[]>>("/api/v1/admin/cities?limit=100");

  return result.ok
    ? { phase: "ready", cities: result.value.data, more: result.value.meta.next_cursor != null }
    : { phase: "failed", failure: result.failure };
}

export function CitiesScreen() {
  const [state, setState] = useState<Loaded>({ phase: "loading" });
  const [currency, setCurrency] = useState<string | null>(null);

  // After a change: fetch again and swap the list in place.
  const refresh = useCallback(() => {
    void loadCities().then(setState);
  }, []);

  const load = useCallback(() => {
    setState({ phase: "loading" });
    void loadCities().then(setState);
  }, []);

  useEffect(() => {
    let cancelled = false;

    void loadCities().then((next) => {
      if (!cancelled) setState(next);
    });
    void apiGet<Envelope<{ currency: string }>>("/api/v1/public/config").then((result) => {
      if (!cancelled && result.ok) setCurrency(result.value.data.currency);
    });

    return () => {
      cancelled = true;
    };
  }, []);

  if (state.phase === "loading") {
    return (
      <Panel>
        <p role="status" aria-busy="true" className="text-text-secondary">
          Loading cities…
        </p>
      </Panel>
    );
  }

  if (state.phase === "failed") {
    return <LoadProblem failure={state.failure} returnTo="/cities" onRetry={load} />;
  }

  return (
    <>
      <section aria-labelledby="list-heading" className="flex flex-col gap-3">
        <h2 id="list-heading" className="text-lg font-semibold">
          Your cities
        </h2>

        {state.cities.length === 0 ? (
          <Panel>
            <p className="font-medium">No cities yet</p>
            <p className="max-w-prose text-sm text-text-secondary">
              A city is where the service operates. Create one below; it starts as a draft and serves nobody until you
              activate it and one of its delivery zones.
            </p>
          </Panel>
        ) : (
          <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {state.cities.map((city) => (
              <li key={city.id}>
                <Link
                  href={`/cities/${city.id}`}
                  className="flex min-h-24 flex-col gap-2 rounded-2xl border border-border bg-surface p-4 hover:border-border-strong"
                >
                  <span className="flex items-start justify-between gap-3">
                    <span className="font-medium">{city.name}</span>
                    <StatusPill status={city.status} />
                  </span>
                  <span className="text-sm text-text-secondary">
                    {city.country_code} · {city.currency_code} · {city.timezone}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
        {state.more ? (
          <p className="text-sm text-text-secondary">Showing the first 100 cities. Paging is not built on this screen yet.</p>
        ) : null}
      </section>

      <CreateCity currency={currency} onCreated={refresh} />
    </>
  );
}

function CreateCity({ currency, onCreated }: { currency: string | null; onCreated: () => void }) {
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [slugEdited, setSlugEdited] = useState(false);
  const [busy, setBusy] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<{ tone: "ok" | "problem"; text: string; enrolment?: boolean } | null>(null);

  // The browser's own list; nothing is preselected (a timezone is never assumed).
  const timezones = useMemo(() => {
    try {
      // Browsers leave UTC out of this list; the API accepts it, so offer it.
      const zones = Intl.supportedValuesOf("timeZone");
      return zones.includes("UTC") ? zones : ["UTC", ...zones];
    } catch {
      return ["UTC"];
    }
  }, []);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;

    const form = event.currentTarget;
    const data = new FormData(form);
    setBusy(true);
    setFields({});
    setMessage(null);

    const result = await apiSend<Envelope<City>>("POST", "/api/v1/admin/cities", {
      name: name.trim(),
      slug,
      country_code: String(data.get("country_code") ?? "").toUpperCase(),
      currency_code: currency ?? "",
      timezone: String(data.get("timezone") ?? ""),
    });

    setBusy(false);

    if (result.ok) {
      form.reset();
      setName("");
      setSlug("");
      setSlugEdited(false);
      setMessage({ tone: "ok", text: `Created ${result.value.data.name} as a draft.` });
      onCreated();
      return;
    }

    if (result.failure.kind === "validation") {
      setFields(result.failure.fields);
      focusFirstInvalid(
        [["name", "#city-name"], ["slug", "#city-slug"], ["country_code", "#city-country"], ["timezone", "#city-timezone"]],
        result.failure.fields,
      );
    }
    setMessage({ tone: "problem", text: describeFailure(result.failure), enrolment: isEnrolmentOnly(result.failure) });
  }

  return (
    <section aria-labelledby="create-heading" className="flex max-w-2xl flex-col gap-3">
      <h2 id="create-heading" className="text-lg font-semibold">
        Create a city
      </h2>
      <Panel>
        <form onSubmit={onSubmit} noValidate className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5 sm:col-span-2">
            <label htmlFor="city-name" className="text-sm font-medium">
              Name
            </label>
            <input
              id="city-name"
              value={name}
              onChange={(event) => {
                setName(event.target.value);
                if (!slugEdited) setSlug(slugify(event.target.value));
              }}
              maxLength={160}
              required
              aria-invalid={fields.name ? true : undefined}
              aria-describedby="city-name-error"
              className={fieldClass}
            />
            <FieldError id="city-name-error" messages={fields.name} />
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="city-slug" className="text-sm font-medium">
              Short name for links
            </label>
            <input
              id="city-slug"
              value={slug}
              onChange={(event) => {
                setSlug(event.target.value);
                setSlugEdited(true);
              }}
              maxLength={160}
              required
              aria-invalid={fields.slug ? true : undefined}
              aria-describedby="city-slug-hint city-slug-error"
              className={`${fieldClass} font-mono`}
            />
            <p id="city-slug-hint" className="text-sm text-text-secondary">
              Lower-case letters, numbers and hyphens. Cannot be changed later.
            </p>
            <FieldError id="city-slug-error" messages={fields.slug} />
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="city-country" className="text-sm font-medium">
              Country code
            </label>
            <input
              id="city-country"
              name="country_code"
              maxLength={2}
              required
              autoCapitalize="characters"
              aria-invalid={fields.country_code ? true : undefined}
              aria-describedby="city-country-hint city-country-error"
              className={`${fieldClass} font-mono uppercase`}
            />
            <p id="city-country-hint" className="text-sm text-text-secondary">
              Two letters, for example PH. Cannot be changed later.
            </p>
            <FieldError id="city-country-error" messages={fields.country_code} />
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="city-timezone" className="text-sm font-medium">
              Timezone
            </label>
            <select
              id="city-timezone"
              name="timezone"
              required
              defaultValue=""
              aria-invalid={fields.timezone ? true : undefined}
              aria-describedby="city-timezone-error"
              className={fieldClass}
            >
              <option value="" disabled>
                Choose a timezone
              </option>
              {timezones.map((zone) => (
                <option key={zone} value={zone}>
                  {zone}
                </option>
              ))}
            </select>
            <FieldError id="city-timezone-error" messages={fields.timezone} />
          </div>

          <div className="flex flex-col gap-1.5">
            <span className="text-sm font-medium">Currency</span>
            <p className="flex min-h-11 items-center font-mono">{currency ?? "Loading…"}</p>
            <p className="text-sm text-text-secondary">Set for the whole deployment.</p>
            <FieldError id="city-currency-error" messages={fields.currency_code} />
          </div>

          <div className="flex flex-col gap-2 sm:col-span-2">
            <button type="submit" disabled={busy || currency === null} aria-busy={busy} className={`${primaryButton} w-fit`}>
              {busy ? "Creating…" : "Create draft city"}
            </button>
            {message ? (message.enrolment ? <EnrolmentNotice /> : <Notice tone={message.tone}>{message.text}</Notice>) : null}
          </div>
        </form>
      </Panel>
    </section>
  );
}
