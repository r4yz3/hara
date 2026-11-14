"use client";

import { useState, type FormEvent } from "react";
import type { Exclusion } from "@food-delivery/api-client";
import { apiGet, apiSend, describeFailure, isEnrolmentOnly, type ApiFailure } from "@food-delivery/session";
import { EnrolmentNotice, Notice, dangerButton, fieldClass, focusFirstInvalid, primaryButton, quietButton } from "@/components/ui";

type ExclusionPage = { data: Exclusion[]; meta: { next_cursor: string | null } };

export function ZoneExclusions({ zoneId, archived }: { zoneId: string; archived: boolean }) {
  const [open, setOpen] = useState(false);
  const [rows, setRows] = useState<Exclusion[] | null>(null);
  const [cursor, setCursor] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<{ text: string; enrolment: boolean } | null>(null);
  const [removing, setRemoving] = useState<string | null>(null);
  const path = `/api/v1/admin/zones/${zoneId}/exclusions`;

  async function load(after?: string) {
    setBusy(true);
    setProblem(null);
    const result = await apiGet<ExclusionPage>(after ? `${path}?cursor=${encodeURIComponent(after)}` : path);
    if (result.ok) {
      setRows((previous) => after ? [...(previous ?? []), ...result.value.data] : result.value.data);
      setCursor(result.value.meta.next_cursor);
    } else {
      setProblem({ text: describeFailure(result.failure), enrolment: isEnrolmentOnly(result.failure) });
    }
    setBusy(false);
  }

  function showFailure(failure: ApiFailure, form?: HTMLFormElement) {
    if (form && failure.kind === "validation") {
      focusFirstInvalid(
        [["reason", '[name="reason"]'], ["active_from", '[name="active_from"]'], ["active_until", '[name="active_until"]'], ["geometry", '[name="geometry"]']],
        failure.fields,
        form,
      );
    }
    setProblem({
      text: failure.kind === "validation"
        ? Object.values(failure.fields).flat().join(" ") || describeFailure(failure)
        : describeFailure(failure),
      enrolment: isEnrolmentOnly(failure),
    });
    setBusy(false);
  }

  async function add(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    const form = event.currentTarget;
    const data = new FormData(form);
    let geometry: unknown;
    try {
      geometry = JSON.parse(String(data.get("geometry")));
    } catch {
      setProblem({ text: "Enter a valid GeoJSON Polygon or MultiPolygon.", enrolment: false });
      form.querySelector<HTMLElement>('[name="geometry"]')?.focus();
      return;
    }
    setBusy(true);
    setProblem(null);
    const timestamp = (name: string) => {
      const value = String(data.get(name));
      return value ? `${value}:00Z` : null;
    };
    const result = await apiSend("POST", path, {
      reason: String(data.get("reason")).trim(),
      geometry,
      active_from: timestamp("active_from"),
      active_until: timestamp("active_until"),
    });
    if (!result.ok) return showFailure(result.failure, form);
    form.reset();
    await load();
  }

  async function remove(id: string) {
    if (busy) return;
    setBusy(true);
    setProblem(null);
    const result = await apiSend("DELETE", `/api/v1/admin/exclusions/${id}`);
    if (!result.ok) return showFailure(result.failure);
    setRemoving(null);
    setRows((previous) => previous?.filter((row) => row.id !== id) ?? null);
    setBusy(false);
  }

  return (
    <div className="flex flex-col gap-3 border-t border-border pt-3">
      <button
        type="button"
        className={`${quietButton} self-start`}
        aria-expanded={open}
        aria-controls={`exclusions-${zoneId}`}
        disabled={busy}
        onClick={() => {
          setOpen(!open);
          if (!open && rows === null) void load();
        }}
      >
        Coverage exclusions
      </button>
      <div id={`exclusions-${zoneId}`} hidden={!open}>
        {open ? (
          <div className="flex flex-col gap-3" aria-busy={busy}>
            {busy ? <p role="status">Working…</p> : null}
            {problem ? (problem.enrolment ? <EnrolmentNotice /> : <Notice tone="problem">{problem.text}</Notice>) : null}
            {rows === null && problem ? <button type="button" className={`${quietButton} self-start`} onClick={() => void load()}>Retry</button> : null}
            {rows?.length === 0 ? <p className="text-sm text-text-secondary">No coverage exclusions.</p> : null}
            {rows?.length ? (
              <ul className="flex flex-col gap-3">
                {rows.map((row) => (
                  <li key={row.id} className="flex flex-col gap-2 rounded-xl border border-border p-3">
                    <p className="break-words font-medium">{row.reason}</p>
                    <p className="text-sm text-text-secondary">{row.active_from ?? "Immediate start"} · {row.active_until ?? "No end time"}</p>
                    {removing === row.id ? (
                      <div className="flex flex-wrap gap-2">
                        <button type="button" disabled={busy} onClick={() => void remove(row.id)} className={dangerButton}>Confirm removal</button>
                        <button type="button" disabled={busy} onClick={() => setRemoving(null)} className={quietButton}>Cancel removal</button>
                      </div>
                    ) : (
                      <button type="button" disabled={busy} onClick={() => setRemoving(row.id)} className={`${quietButton} self-start`}>Remove exclusion</button>
                    )}
                  </li>
                ))}
              </ul>
            ) : null}
            {cursor ? <button type="button" disabled={busy} onClick={() => void load(cursor)} className={`${quietButton} self-start`}>Load more exclusions</button> : null}
            {!archived && rows !== null ? (
              <form aria-label="Add coverage exclusion" onSubmit={add} className="grid gap-3 sm:grid-cols-2">
                <label className="flex flex-col gap-1 text-sm font-medium sm:col-span-2">
                  Exclusion reason
                  <input name="reason" required maxLength={255} readOnly={busy} className={fieldClass} />
                </label>
                <label className="flex min-w-0 flex-col gap-1 text-sm font-medium">
                  Starts at (UTC, optional)
                  <input name="active_from" type="datetime-local" readOnly={busy} className={fieldClass} />
                </label>
                <label className="flex min-w-0 flex-col gap-1 text-sm font-medium">
                  Ends at (UTC, optional)
                  <input name="active_until" type="datetime-local" readOnly={busy} className={fieldClass} />
                </label>
                <label className="flex flex-col gap-1 text-sm font-medium sm:col-span-2">
                  Exclusion shape (GeoJSON)
                  <textarea name="geometry" rows={5} required readOnly={busy} spellCheck={false} className={`${fieldClass} py-2 font-mono`} />
                </label>
                <button type="submit" disabled={busy} className={`${primaryButton} w-fit`}>Add exclusion</button>
              </form>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  );
}
