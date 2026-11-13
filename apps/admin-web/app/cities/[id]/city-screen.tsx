"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState, type FormEvent } from "react";

import type { City, Zone } from "@food-delivery/api-client";
import { apiGet, apiSend, describeFailure, type ApiFailure, type ApiResult } from "@food-delivery/session";

import { FieldError, LoadProblem, Notice, Panel, StatusPill, dangerButton, fieldClass, primaryButton, quietButton } from "@/components/ui";

type Envelope<T> = { data: T };
type Message = { tone: "ok" | "problem"; text: string } | null;

// The same edges the API enforces (CityStatus / zone status in the backend).
const NEXT: Record<string, { to: string; label: string; danger?: boolean }[]> = {
  draft: [
    { to: "active", label: "Activate" },
    { to: "archived", label: "Archive", danger: true },
  ],
  active: [{ to: "paused", label: "Pause" }],
  paused: [
    { to: "active", label: "Reactivate" },
    { to: "archived", label: "Archive", danger: true },
  ],
  archived: [],
};

type State =
  | { phase: "loading" }
  | { phase: "failed"; failure: ApiFailure }
  | { phase: "ready"; city: City; zones: Zone[] };

async function loadCity(id: string): Promise<State> {
  const [city, zones] = await Promise.all([
    apiGet<Envelope<City>>(`/api/v1/admin/cities/${encodeURIComponent(id)}`),
    apiGet<Envelope<Zone[]>>(`/api/v1/admin/cities/${encodeURIComponent(id)}/zones?limit=100`),
  ]);

  if (!city.ok) return { phase: "failed", failure: city.failure };
  if (!zones.ok) return { phase: "failed", failure: zones.failure };

  return { phase: "ready", city: city.value.data, zones: zones.value.data };
}

export function CityScreen() {
  const { id } = useParams<{ id: string }>();
  const [state, setState] = useState<State>({ phase: "loading" });

  // After a change: fetch again and swap the content in place.
  const fetchAll = useCallback(async () => {
    setState(await loadCity(id));
  }, [id]);

  useEffect(() => {
    let cancelled = false;

    void loadCity(id).then((next) => {
      if (!cancelled) setState(next);
    });

    return () => {
      cancelled = true;
    };
  }, [id]);

  if (state.phase === "loading") {
    return (
      <Panel>
        <p aria-busy="true" className="text-text-secondary">
          Loading city…
        </p>
      </Panel>
    );
  }

  if (state.phase === "failed") {
    return (
      <LoadProblem
        failure={state.failure}
        returnTo={`/cities/${id}`}
        onRetry={() => {
          setState({ phase: "loading" });
          void fetchAll();
        }}
      />
    );
  }

  const { city, zones } = state;

  return (
    <>
      <Link href="/cities" className="w-fit text-sm font-medium text-brand-600 underline underline-offset-4">
        ← All cities
      </Link>

      <CityDetails key={`${city.id}:${city.updated_at}`} city={city} onChanged={fetchAll} />

      <section aria-labelledby="zones-heading" className="flex flex-col gap-3">
        <div className="flex flex-col gap-1">
          <h2 id="zones-heading" className="text-lg font-semibold">
            Delivery zones
          </h2>
          <p className="max-w-prose text-sm text-text-secondary">
            Customers can be served only inside an active zone of an active city. A point on a zone&apos;s edge counts as
            inside.
          </p>
        </div>

        {zones.length === 0 ? (
          <Panel>
            <p className="font-medium">No delivery zones yet</p>
            <p className="text-sm text-text-secondary">Add one below. It starts as a draft.</p>
          </Panel>
        ) : (
          <ul className="flex flex-col gap-3">
            {zones.map((zone) => (
              <li key={`${zone.id}:${zone.version}`}>
                <ZoneRow zone={zone} cityActive={city.status === "active"} onChanged={fetchAll} />
              </li>
            ))}
          </ul>
        )}
      </section>

      {city.status === "archived" ? null : <CreateZone cityId={city.id} onCreated={fetchAll} />}
    </>
  );
}

function CityDetails({ city, onChanged }: { city: City; onChanged: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<Message>(null);
  const [confirming, setConfirming] = useState<string | null>(null);
  const archived = city.status === "archived";

  async function send(body: Record<string, unknown>, done: string) {
    setBusy(true);
    setFields({});
    setMessage(null);
    const result = await apiSend<Envelope<City>>("PATCH", `/api/v1/admin/cities/${city.id}`, body);
    setBusy(false);
    setConfirming(null);

    if (result.ok) {
      setMessage({ tone: "ok", text: done });
      await onChanged();
      return;
    }

    if (result.failure.kind === "validation") setFields(result.failure.fields);
    setMessage({ tone: "problem", text: describeFailure(result.failure) });
  }

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    const data = new FormData(event.currentTarget);
    void send({ name: String(data.get("name") ?? "").trim(), timezone: String(data.get("timezone") ?? "").trim() }, "Saved.");
  }

  return (
    <section aria-labelledby="city-heading" className="flex max-w-3xl flex-col gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <h2 id="city-heading" className="text-lg font-semibold">
          {city.name}
        </h2>
        <StatusPill status={city.status} />
      </div>

      <Panel>
        <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
          <div>
            <dt className="text-text-secondary">Short name</dt>
            <dd className="font-mono">{city.slug}</dd>
          </div>
          <div>
            <dt className="text-text-secondary">Country</dt>
            <dd className="font-mono">{city.country_code}</dd>
          </div>
          <div>
            <dt className="text-text-secondary">Currency</dt>
            <dd className="font-mono">{city.currency_code}</dd>
          </div>
        </dl>

        <form onSubmit={onSubmit} noValidate className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="edit-name" className="text-sm font-medium">
              Name
            </label>
            <input
              id="edit-name"
              name="name"
              defaultValue={city.name}
              maxLength={160}
              disabled={archived}
              aria-describedby="edit-name-error"
              className={fieldClass}
            />
            <FieldError id="edit-name-error" messages={fields.name} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="edit-timezone" className="text-sm font-medium">
              Timezone
            </label>
            <input
              id="edit-timezone"
              name="timezone"
              defaultValue={city.timezone}
              disabled={archived}
              aria-describedby="edit-timezone-error"
              className={`${fieldClass} font-mono`}
            />
            <FieldError id="edit-timezone-error" messages={fields.timezone} />
          </div>
          <div className="sm:col-span-2">
            <button type="submit" disabled={busy || archived} className={quietButton}>
              Save changes
            </button>
          </div>
        </form>

        <div className="flex flex-col gap-2 border-t border-border pt-3">
          <p className="text-sm font-medium">Status</p>
          {archived ? (
            <p className="text-sm text-text-secondary">This city is archived and cannot be changed.</p>
          ) : (
            <div className="flex flex-wrap items-center gap-2">
              {NEXT[city.status].map((step) =>
                confirming === step.to ? (
                  <span key={step.to} className="flex flex-wrap items-center gap-2">
                    <span className="text-sm">
                      {step.to === "archived"
                        ? "Archiving cannot be undone. Continue?"
                        : step.to === "active"
                          ? "Customers can be served here once a zone is active. Continue?"
                          : "This stops service in every zone of this city. Continue?"}
                    </span>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => void send({ status: step.to }, `City is now ${step.to}.`)}
                      className={step.danger ? dangerButton : primaryButton}
                    >
                      Yes, {step.label.toLowerCase()}
                    </button>
                    <button type="button" disabled={busy} onClick={() => setConfirming(null)} className={quietButton}>
                      Cancel
                    </button>
                  </span>
                ) : (
                  <button key={step.to} type="button" disabled={busy} onClick={() => setConfirming(step.to)} className={quietButton}>
                    {step.label}
                  </button>
                ),
              )}
            </div>
          )}
        </div>

        {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
      </Panel>
    </section>
  );
}

function ZoneRow({ zone, cityActive, onChanged }: { zone: Zone; cityActive: boolean; onChanged: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [mode, setMode] = useState<"idle" | "activate" | "pause" | "archive">("idle");
  const [reason, setReason] = useState("");
  const [message, setMessage] = useState<Message>(null);

  async function act(action: "activate" | "pause" | "archive", body: Record<string, unknown>) {
    setBusy(true);
    setMessage(null);
    // The version the screen loaded goes with every change; a stale one is refused.
    const result: ApiResult<Envelope<Zone>> = await apiSend("POST", `/api/v1/admin/zones/${zone.id}/${action}`, {
      version: zone.version,
      ...body,
    });
    setBusy(false);

    if (result.ok) {
      await onChanged();
      return;
    }

    setMessage({
      tone: "problem",
      text:
        result.failure.kind === "validation"
          ? Object.values(result.failure.fields).flat().join(" ") || describeFailure(result.failure)
          : describeFailure(result.failure),
    });
    if (result.failure.kind === "conflict") await onChanged();
  }

  const steps = NEXT[zone.status] ?? [];

  return (
    <Panel>
      <div className="flex flex-wrap items-center gap-3">
        <p className="font-medium">{zone.name}</p>
        <StatusPill status={zone.status} />
        <p className="text-sm text-text-secondary">
          <span className="font-mono">{zone.slug}</span> · priority {zone.priority} · version {zone.version}
        </p>
      </div>

      {zone.status === "active" && !cityActive ? (
        <p className="text-sm text-text-secondary">
          This zone is active, but its city is not, so nobody is served here.
        </p>
      ) : null}

      {mode === "idle" ? (
        <div className="flex flex-wrap gap-2">
          {steps.length === 0 ? <p className="text-sm text-text-secondary">Archived. No further changes.</p> : null}
          {steps.map((step) => (
            <button
              key={step.to}
              type="button"
              disabled={busy}
              onClick={() => setMode(step.to === "active" ? "activate" : step.to === "paused" ? "pause" : "archive")}
              className={quietButton}
            >
              {step.label}
            </button>
          ))}
        </div>
      ) : (
        <div className="flex flex-col gap-2">
          {mode === "activate" ? (
            <p className="text-sm">
              {cityActive
                ? "Customers inside this zone can be served as soon as it is active. Continue?"
                : "The city is not active, so activation will be refused until it is."}
            </p>
          ) : (
            <div className="flex max-w-md flex-col gap-1.5">
              <label htmlFor={`reason-${zone.id}`} className="text-sm font-medium">
                {mode === "pause" ? "Reason for pausing (kept in the audit log)" : "Reason for archiving (optional, kept in the audit log)"}
              </label>
              <input
                id={`reason-${zone.id}`}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                maxLength={255}
                className={fieldClass}
              />
              {mode === "archive" ? <p className="text-sm">Archiving cannot be undone.</p> : null}
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              disabled={busy || (mode === "pause" && reason.trim() === "")}
              onClick={() =>
                void act(
                  mode,
                  mode === "activate" ? { confirm: true } : reason.trim() === "" ? {} : { reason: reason.trim() },
                )
              }
              className={mode === "archive" ? dangerButton : primaryButton}
            >
              {mode === "activate" ? "Yes, activate" : mode === "pause" ? "Pause zone" : "Archive zone"}
            </button>
            <button type="button" disabled={busy} onClick={() => setMode("idle")} className={quietButton}>
              Cancel
            </button>
          </div>
        </div>
      )}

      {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
    </Panel>
  );
}

function CreateZone({ cityId, onCreated }: { cityId: string; onCreated: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<Message>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;

    const form = event.currentTarget;
    const data = new FormData(form);
    setFields({});
    setMessage(null);

    let geometry: unknown;
    try {
      geometry = JSON.parse(String(data.get("geometry") ?? ""));
    } catch {
      setFields({ geometry: ["This is not valid JSON. Paste a GeoJSON Polygon or MultiPolygon."] });
      return;
    }

    setBusy(true);
    const result = await apiSend<Envelope<Zone>>("POST", `/api/v1/admin/cities/${cityId}/zones`, {
      name: String(data.get("name") ?? "").trim(),
      slug: String(data.get("slug") ?? "").trim(),
      priority: Number(data.get("priority") ?? 0),
      geometry,
    });
    setBusy(false);

    if (result.ok) {
      form.reset();
      setMessage({ tone: "ok", text: `Added ${result.value.data.name} as a draft.` });
      await onCreated();
      return;
    }

    if (result.failure.kind === "validation") setFields(result.failure.fields);
    setMessage({ tone: "problem", text: describeFailure(result.failure) });
  }

  const geometryErrors = Object.entries(fields)
    .filter(([key]) => key === "geometry" || key.startsWith("geometry."))
    .flatMap(([, messages]) => messages);

  return (
    <section aria-labelledby="zone-create-heading" className="flex max-w-3xl flex-col gap-3">
      <h2 id="zone-create-heading" className="text-lg font-semibold">
        Add a delivery zone
      </h2>
      <Panel dashed>
        <p className="text-sm text-text-secondary">
          Map drawing is not built yet and no map provider is chosen, so the shape is entered as GeoJSON. The server checks
          it and stores its own normalised copy.
        </p>
        <form onSubmit={onSubmit} noValidate className="grid gap-4 sm:grid-cols-3">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="zone-name" className="text-sm font-medium">
              Name
            </label>
            <input id="zone-name" name="name" maxLength={160} required aria-describedby="zone-name-error" className={fieldClass} />
            <FieldError id="zone-name-error" messages={fields.name} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="zone-slug" className="text-sm font-medium">
              Short name for links
            </label>
            <input
              id="zone-slug"
              name="slug"
              maxLength={160}
              required
              aria-describedby="zone-slug-error"
              className={`${fieldClass} font-mono`}
            />
            <FieldError id="zone-slug-error" messages={fields.slug} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="zone-priority" className="text-sm font-medium">
              Priority
            </label>
            <input
              id="zone-priority"
              name="priority"
              type="number"
              min={-1000}
              max={1000}
              defaultValue={0}
              aria-describedby="zone-priority-hint zone-priority-error"
              className={fieldClass}
            />
            <p id="zone-priority-hint" className="text-sm text-text-secondary">
              Where zones overlap, the higher number wins.
            </p>
            <FieldError id="zone-priority-error" messages={fields.priority} />
          </div>
          <div className="flex flex-col gap-1.5 sm:col-span-3">
            <label htmlFor="zone-geometry" className="text-sm font-medium">
              Shape (GeoJSON Polygon or MultiPolygon)
            </label>
            <textarea
              id="zone-geometry"
              name="geometry"
              rows={7}
              required
              spellCheck={false}
              aria-describedby="zone-geometry-hint zone-geometry-error"
              className={`${fieldClass} py-2 font-mono text-sm`}
            />
            <p id="zone-geometry-hint" className="text-sm text-text-secondary">
              Positions are longitude first, then latitude. Each ring must end where it starts.
            </p>
            <FieldError id="zone-geometry-error" messages={geometryErrors} />
          </div>
          <div className="flex flex-col gap-2 sm:col-span-3">
            <button type="submit" disabled={busy} aria-busy={busy} className={`${primaryButton} w-fit`}>
              {busy ? "Checking shape…" : "Add draft zone"}
            </button>
            {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
          </div>
        </form>
      </Panel>
    </section>
  );
}
