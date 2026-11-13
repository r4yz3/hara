"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";

import type { AuditEntry } from "@food-delivery/api-client";
import { apiGet, describeFailure, type ApiFailure } from "@food-delivery/session";

import { LoadProblem, Notice, Panel, fieldClass, primaryButton, quietButton } from "@/components/ui";

type Page = { data: AuditEntry[]; meta: { next_cursor?: string | null } };
type Filters = { action: string; subject_type: string };

type State =
  | { phase: "loading" }
  | { phase: "failed"; failure: ApiFailure }
  | { phase: "ready"; entries: AuditEntry[]; next: string | null };

const PAGE_SIZE = 25;

async function loadPage(filters: Filters, cursor: string | null) {
  const query = new URLSearchParams({ limit: String(PAGE_SIZE) });
  if (filters.action !== "") query.set("action", filters.action);
  if (filters.subject_type !== "") query.set("subject_type", filters.subject_type);
  if (cursor !== null) query.set("cursor", cursor);

  return apiGet<Page>(`/api/v1/admin/audit-logs?${query}`);
}

async function loadFirst(filters: Filters): Promise<State> {
  const result = await loadPage(filters, null);

  return result.ok
    ? { phase: "ready", entries: result.value.data, next: result.value.meta.next_cursor ?? null }
    : { phase: "failed", failure: result.failure };
}

/** Times are stored in UTC; shown in UTC so staff in different places read the same value. */
function formatUtc(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;

  return `${date.toISOString().slice(0, 19).replace("T", " ")} UTC`;
}

export function AuditScreen() {
  const [filters, setFilters] = useState<Filters>({ action: "", subject_type: "" });
  const [state, setState] = useState<State>({ phase: "loading" });
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreProblem, setMoreProblem] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    void loadFirst(filters).then((next) => {
      if (!cancelled) setState(next);
    });

    return () => {
      cancelled = true;
    };
  }, [filters]);

  const retry = useCallback(() => {
    setState({ phase: "loading" });
    void loadFirst(filters).then(setState);
  }, [filters]);

  function onFilter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    setMoreProblem(null);
    setState({ phase: "loading" });
    setFilters({ action: String(data.get("action") ?? "").trim(), subject_type: String(data.get("subject_type") ?? "").trim() });
  }

  async function loadMore() {
    if (state.phase !== "ready" || state.next === null || loadingMore) return;

    setLoadingMore(true);
    setMoreProblem(null);
    const result = await loadPage(filters, state.next);
    setLoadingMore(false);

    if (!result.ok) {
      setMoreProblem(describeFailure(result.failure));
      return;
    }

    setState({ phase: "ready", entries: [...state.entries, ...result.value.data], next: result.value.meta.next_cursor ?? null });
  }

  return (
    <>
      <section aria-labelledby="filter-heading" className="flex max-w-3xl flex-col gap-3">
        <h2 id="filter-heading" className="text-lg font-semibold">
          Filter
        </h2>
        <Panel>
          <form onSubmit={onFilter} className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <div className="flex flex-col gap-1.5">
              <label htmlFor="filter-action" className="text-sm font-medium">
                Action
              </label>
              <input
                id="filter-action"
                name="action"
                maxLength={120}
                aria-describedby="filter-action-hint"
                className={`${fieldClass} font-mono`}
              />
              <p id="filter-action-hint" className="text-sm text-text-secondary">
                Exact name, for example city.created. Leave empty for all.
              </p>
            </div>
            <div className="flex flex-col gap-1.5">
              <label htmlFor="filter-subject" className="text-sm font-medium">
                Record type
              </label>
              <input
                id="filter-subject"
                name="subject_type"
                maxLength={80}
                aria-describedby="filter-subject-hint"
                className={`${fieldClass} font-mono`}
              />
              <p id="filter-subject-hint" className="text-sm text-text-secondary">
                For example city, delivery_zone or user.
              </p>
            </div>
            <button type="submit" className={`${primaryButton} sm:mb-7`}>
              Apply
            </button>
          </form>
        </Panel>
      </section>

      <section aria-labelledby="entries-heading" className="flex flex-col gap-3">
        <div className="flex flex-col gap-1">
          <h2 id="entries-heading" className="text-lg font-semibold">
            Entries, newest first
          </h2>
          <p className="max-w-prose text-sm text-text-secondary">
            Every privileged change is recorded and cannot be edited or deleted. Passwords, tokens and exact positions
            are removed before an entry is stored.
          </p>
        </div>

        {state.phase === "loading" ? (
          <Panel>
            <p aria-busy="true" className="text-text-secondary">
              Loading entries…
            </p>
          </Panel>
        ) : state.phase === "failed" ? (
          <LoadProblem failure={state.failure} returnTo="/audit" onRetry={retry} />
        ) : state.entries.length === 0 ? (
          <Panel>
            <p className="font-medium">No entries</p>
            <p className="text-sm text-text-secondary">Nothing matches, or nothing has been recorded yet.</p>
          </Panel>
        ) : (
          <>
            <ul className="flex flex-col gap-2">
              {state.entries.map((entry) => (
                <li key={entry.id}>
                  <Entry entry={entry} />
                </li>
              ))}
            </ul>
            {state.next !== null ? (
              <button type="button" onClick={() => void loadMore()} disabled={loadingMore} aria-busy={loadingMore} className={`${quietButton} w-fit`}>
                {loadingMore ? "Loading…" : "Load older entries"}
              </button>
            ) : (
              <p className="text-sm text-text-secondary">End of the log.</p>
            )}
            {moreProblem ? <Notice tone="problem">{moreProblem}</Notice> : null}
          </>
        )}
      </section>
    </>
  );
}

function Entry({ entry }: { entry: AuditEntry }) {
  const hasDetail = entry.before !== null || entry.after !== null;

  return (
    <div className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
        <p className="font-mono font-medium">{entry.action}</p>
        <p className="text-sm text-text-secondary">
          <time dateTime={entry.created_at}>{formatUtc(entry.created_at)}</time>
        </p>
      </div>
      <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
        <div className="min-w-0">
          <dt className="text-text-secondary">Record</dt>
          <dd className="truncate font-mono">
            {entry.subject_type}
            {entry.subject_id ? ` ${entry.subject_id}` : ""}
          </dd>
        </div>
        <div className="min-w-0">
          <dt className="text-text-secondary">Done by</dt>
          <dd className="truncate font-mono">{entry.actor_user_id ?? "the system"}</dd>
        </div>
        <div className="min-w-0">
          <dt className="text-text-secondary">City</dt>
          <dd className="truncate font-mono">{entry.city_id ?? "platform-wide"}</dd>
        </div>
      </dl>
      {hasDetail ? (
        <details>
          <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium text-brand-600">
            Before and after
          </summary>
          <div className="grid gap-3 md:grid-cols-2">
            {(["before", "after"] as const).map((side) => (
              <div key={side} className="min-w-0">
                <p className="text-sm text-text-secondary">{side === "before" ? "Before" : "After"}</p>
                <pre className="overflow-x-auto rounded-xl border border-border bg-surface-2 p-3 font-mono text-sm">
                  {entry[side] === null ? "—" : JSON.stringify(entry[side], null, 2)}
                </pre>
              </div>
            ))}
          </div>
        </details>
      ) : null}
    </div>
  );
}
