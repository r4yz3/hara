"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";

import type { City, Zone } from "@food-delivery/api-client";
import { apiGet, apiSend, describeFailure, type ApiFailure, type ApiResult } from "@food-delivery/session";

import { FieldError, LoadProblem, Notice, Panel, StatusPill, dangerButton, fieldClass, focusFirstInvalid, primaryButton, quietButton } from "@/components/ui";
import { ZoneExclusions } from "./zone-exclusions";
import { ZoneMap } from "./zone-map";

type Envelope<T> = { data: T };
type Message = { tone: "ok" | "problem"; text: string; stale?: boolean } | null;

/** A change notice. After a 409 it also offers to load the latest version (spec 8.7, conflict). */
function Outcome({ message, onReload }: { message: NonNullable<Message>; onReload: () => Promise<void> }) {
  return (
    <>
      <Notice tone={message.tone}>{message.text}</Notice>
      {message.stale ? (
        <button type="button" onClick={() => void onReload()} className={`${quietButton} self-start`}>
          Reload latest
        </button>
      ) : null}
    </>
  );
}

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
        <p role="status" aria-busy="true" className="text-text-secondary">
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
  const [confirming, setConfirming] = useState<(typeof NEXT)[string][number] | null>(null);
  const confirmation = useRef<HTMLDialogElement>(null);
  const archived = city.status === "archived";

  useEffect(() => {
    if (confirming && !confirmation.current?.open) confirmation.current?.showModal();
  }, [confirming]);

  async function send(body: Record<string, unknown>, done: string) {
    setBusy(true);
    setFields({});
    setMessage(null);
    const result = await apiSend<Envelope<City>>("PATCH", `/api/v1/admin/cities/${city.id}`, body);
    setBusy(false);

    if (result.ok) {
      confirmation.current?.close();
      setMessage({ tone: "ok", text: done });
      await onChanged();
      return;
    }

    if (result.failure.kind === "validation") {
      setFields(result.failure.fields);
      focusFirstInvalid([["name", "#edit-name"], ["timezone", "#edit-timezone"]], result.failure.fields);
    }
    setMessage({ tone: "problem", text: describeFailure(result.failure), stale: result.failure.kind === "conflict" });
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
              aria-invalid={fields.name ? true : undefined}
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
              aria-invalid={fields.timezone ? true : undefined}
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
              {NEXT[city.status].map((step) => (
                <button
                  key={step.to}
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    setMessage(null);
                    setConfirming(step);
                  }}
                  className={quietButton}
                >
                  {step.label}
                </button>
              ))}
            </div>
          )}
        </div>

        {message && !confirming ? <Outcome message={message} onReload={onChanged} /> : null}
      </Panel>
      <dialog
        ref={confirmation}
        aria-labelledby="city-confirm-title"
        aria-describedby="city-confirm-description"
        onClose={() => setConfirming(null)}
        onCancel={(event) => { if (busy) event.preventDefault(); }}
        className="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-border-strong bg-surface p-5 text-text-primary backdrop:bg-black/50"
      >
        {confirming ? (
          <div className="flex flex-col gap-4">
            <h3 id="city-confirm-title" className="text-lg font-semibold">{confirming.label} {city.name}?</h3>
            <p id="city-confirm-description" className="text-sm text-text-secondary">
              {confirming.to === "archived"
                ? "Archiving cannot be undone."
                : confirming.to === "active"
                  ? "Customers can be served here once a zone is active."
                  : "This stops service in every zone of this city."}
            </p>
            {message?.tone === "problem" ? <Outcome message={message} onReload={onChanged} /> : null}
            <div className="flex flex-wrap gap-2">
              <button type="button" disabled={busy} onClick={() => confirmation.current?.close()} className={quietButton}>
                Cancel
              </button>
              <button
                type="button"
                disabled={busy}
                aria-busy={busy}
                onClick={() => void send({ status: confirming.to }, `City is now ${confirming.to}.`)}
                className={confirming.danger ? dangerButton : primaryButton}
              >
                {busy ? "Saving…" : `Yes, ${confirming.label.toLowerCase()}`}
              </button>
            </div>
          </div>
        ) : null}
      </dialog>
    </section>
  );
}

function ZoneRow({ zone, cityActive, onChanged }: { zone: Zone; cityActive: boolean; onChanged: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [mode, setMode] = useState<"idle" | "activate" | "pause" | "archive">("idle");
  const [reason, setReason] = useState("");
  const [message, setMessage] = useState<Message>(null);

  const [editing, setEditing] = useState<Zone | null>(null);

  async function openEditor() {
    setBusy(true);
    setMessage(null);
    const result = await apiGet<Envelope<Zone>>(`/api/v1/admin/zones/${zone.id}`);
    setBusy(false);
    if (!result.ok) {
      setMessage({ tone: "problem", text: describeFailure(result.failure) });
    } else if (result.value.data.status === "archived") {
      await onChanged();
    } else {
      setEditing(result.value.data);
    }
  }

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
      stale: result.failure.kind === "conflict",
    });
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

      {editing ? (
        <EditZone
          zone={editing}
          onCancel={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await onChanged();
          }}
        />
      ) : mode === "idle" ? (
        <div className="flex flex-wrap gap-2">
          {steps.length === 0 ? <p className="text-sm text-text-secondary">Archived. No further changes.</p> : null}
          {zone.status !== "archived" ? (
            <button type="button" disabled={busy} aria-busy={busy} onClick={() => void openEditor()} className={quietButton}>
              {busy ? "Loading zone…" : "Edit zone"}
            </button>
          ) : null}
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

      {message ? <Outcome message={message} onReload={onChanged} /> : null}
      <ZoneExclusions zoneId={zone.id} archived={zone.status === "archived"} />
    </Panel>
  );
}

function EditZone({ zone, onCancel, onSaved }: { zone: Zone; onCancel: () => void; onSaved: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const conflicted = failure?.kind === "conflict";
  const [shapeText, setShapeText] = useState(JSON.stringify(zone.geometry) ?? "");

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || conflicted) return;
    const form = event.currentTarget;
    const data = new FormData(form);
    let geometry: unknown;
    try {
      geometry = JSON.parse(String(data.get("geometry")));
    } catch {
      setFailure({ kind: "validation", fields: { geometry: ["Enter a valid GeoJSON Polygon or MultiPolygon."] } });
      form.querySelector<HTMLElement>('[name="geometry"]')?.focus();
      return;
    }

    setBusy(true);
    setFailure(null);
    const result = await apiSend<Envelope<Zone>>("PATCH", `/api/v1/admin/zones/${zone.id}`, {
      version: zone.version,
      name: String(data.get("name")).trim(),
      priority: Number(data.get("priority")),
      geometry,
    });
    if (result.ok) {
      await onSaved();
    } else {
      setBusy(false);
      setFailure(result.failure);
      if (result.failure.kind === "validation") {
        focusFirstInvalid(
          [["name", '[name="name"]'], ["priority", '[name="priority"]'], ["geometry", '[name="geometry"]']],
          result.failure.fields,
          form,
        );
      }
    }
  }

  return (
    <form aria-label={`Edit ${zone.name}`} onSubmit={save} className="grid gap-4 sm:grid-cols-2">
      <label className="flex flex-col gap-1.5 text-sm font-medium">
        Name
        <input name="name" defaultValue={zone.name} maxLength={160} required readOnly={busy} className={fieldClass} />
      </label>
      <label className="flex flex-col gap-1.5 text-sm font-medium">
        Priority
        <input name="priority" type="number" defaultValue={zone.priority} min={-1000} max={1000} required readOnly={busy} className={fieldClass} />
      </label>
      <label className="flex flex-col gap-1.5 text-sm font-medium sm:col-span-2">
        Shape (GeoJSON)
        <textarea name="geometry" rows={8} value={shapeText} onChange={(event) => setShapeText(event.target.value)} required readOnly={busy} spellCheck={false} className={`${fieldClass} py-2 font-mono text-sm`} />
      </label>
      <div className="sm:col-span-2">
        <ZoneMap shapeText={shapeText} onDraw={busy ? undefined : setShapeText} />
      </div>
      {failure ? (
        <div className="sm:col-span-2">
          <Notice tone="problem">
            {conflicted
              ? "This zone changed while you were editing. Copy any changes you want to keep, then cancel and reopen the editor to load the latest version."
              : failure.kind === "validation"
                ? Object.values(failure.fields).flat().join(" ") || describeFailure(failure)
                : describeFailure(failure)}
          </Notice>
        </div>
      ) : null}
      <div className="flex flex-wrap gap-2 sm:col-span-2">
        <button type="submit" disabled={busy || conflicted} aria-busy={busy} className={primaryButton}>
          {busy ? "Saving…" : "Save zone"}
        </button>
        <button type="button" disabled={busy} onClick={onCancel} className={quietButton}>Cancel edit</button>
      </div>
    </form>
  );
}

function CreateZone({ cityId, onCreated }: { cityId: string; onCreated: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<Message>(null);
  const [shapeText, setShapeText] = useState("");

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
      focusFirstInvalid([["geometry", "#zone-geometry"]], { geometry: [] }, form);
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
      setShapeText("");
      setMessage({ tone: "ok", text: `Added ${result.value.data.name} as a draft.` });
      await onCreated();
      return;
    }

    if (result.failure.kind === "validation") {
      setFields(result.failure.fields);
      focusFirstInvalid(
        [["name", "#zone-name"], ["slug", "#zone-slug"], ["priority", "#zone-priority"], ["geometry", "#zone-geometry"]],
        result.failure.fields,
        form,
      );
    }
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
          Map drawing is not built yet and no map provider is chosen, so the shape is entered as GeoJSON; the map beside it only previews the shape once a style is configured. The server checks
          it and stores its own normalised copy.
        </p>
        <form onSubmit={onSubmit} noValidate className="grid gap-4 sm:grid-cols-3">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="zone-name" className="text-sm font-medium">
              Name
            </label>
            <input id="zone-name" name="name" maxLength={160} required aria-invalid={fields.name ? true : undefined} aria-describedby="zone-name-error" className={fieldClass} />
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
              aria-invalid={fields.slug ? true : undefined}
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
              aria-invalid={fields.priority ? true : undefined}
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
              value={shapeText}
              onChange={(event) => setShapeText(event.target.value)}
              aria-invalid={geometryErrors.length > 0 ? true : undefined}
              aria-describedby="zone-geometry-hint zone-geometry-error"
              className={`${fieldClass} py-2 font-mono text-sm`}
            />
            <p id="zone-geometry-hint" className="text-sm text-text-secondary">
              Positions are longitude first, then latitude. Each ring must end where it starts.
            </p>
            <FieldError id="zone-geometry-error" messages={geometryErrors} />
          </div>
          <div className="sm:col-span-3">
            <ZoneMap shapeText={shapeText} onDraw={busy ? undefined : setShapeText} />
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
