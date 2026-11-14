"use client";

import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";

import type { AssignableRole, City, StaffGrant, StaffMember } from "@food-delivery/api-client";
import { apiGet, apiSend, describeFailure, type ApiFailure } from "@food-delivery/session";

import { InviteStaff, PendingInvitations } from "./invitations";
import {
  FieldError,
  LoadProblem,
  Notice,
  Panel,
  StatusPill,
  dangerButton,
  fieldClass,
  focusFirstInvalid,
  primaryButton,
  quietButton,
} from "@/components/ui";

type Page<T> = { data: T; meta: { next_cursor?: string | null } };
type Envelope<T> = { data: T };

type State =
  | { phase: "loading" }
  | { phase: "failed"; failure: ApiFailure }
  | { phase: "ready"; members: StaffMember[]; next: string | null };

/** What the grant form can offer. `null` while loading; an empty list means this person may hand out no roles. */
type Offer = { roles: AssignableRole[]; cities: City[] } | null;

const PAGE_SIZE = 50;

async function loadMembers(cursor: string | null): Promise<ApiFailure | { members: StaffMember[]; next: string | null }> {
  const query = new URLSearchParams({ limit: String(PAGE_SIZE) });
  if (cursor !== null) query.set("cursor", cursor);

  const result = await apiGet<Page<StaffMember[]>>(`/api/v1/admin/users?${query}`);

  return result.ok ? { members: result.value.data, next: result.value.meta.next_cursor ?? null } : result.failure;
}

async function loadFirst(): Promise<State> {
  const result = await loadMembers(null);

  return "members" in result ? { phase: "ready", ...result } : { phase: "failed", failure: result };
}

/** Which roles the person may hand out, with the city names their scopes need. */
async function fetchOffer(): Promise<{ offer: Offer; problem: ApiFailure | null }> {
  const roles = await apiGet<Page<AssignableRole[]>>("/api/v1/admin/roles");
  if (!roles.ok) return { offer: null, problem: roles.failure };

  // City names for the scope list. If this fails, only whole-platform grants can be offered.
  const cities = roles.value.data.length > 0 ? await apiGet<Page<City[]>>("/api/v1/admin/cities?limit=100") : null;

  return { offer: { roles: roles.value.data, cities: cities?.ok ? cities.value.data : [] }, problem: null };
}
function formatDay(iso: string): string {
  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? iso : `${date.toISOString().slice(0, 10)} UTC`;
}

function scopeLabel(grant: StaffGrant): string {
  if (grant.scope_type === "global") return "Whole platform";

  return grant.city_name ?? "A city you cannot name here";
}

export function AccessScreen() {
  const [state, setState] = useState<State>({ phase: "loading" });
  const [offer, setOffer] = useState<Offer>(null);
  const [offerProblem, setOfferProblem] = useState<ApiFailure | null>(null);
  const [outcome, setOutcome] = useState<{ tone: "ok" | "problem"; text: string } | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [invitesVersion, setInvitesVersion] = useState(0);
  const heading = useRef<HTMLHeadingElement>(null);

  const loadOffer = useCallback(async () => {
    const next = await fetchOffer();
    setOffer(next.offer);
    setOfferProblem(next.problem);
  }, []);

  useEffect(() => {
    let cancelled = false;

    void loadFirst().then((next) => {
      if (!cancelled) setState(next);
    });
    void fetchOffer().then((next) => {
      if (cancelled) return;
      setOffer(next.offer);
      setOfferProblem(next.problem);
    });

    return () => {
      cancelled = true;
    };
  }, []);

  const retry = useCallback(() => {
    setState({ phase: "loading" });
    void loadFirst().then(setState);
    void loadOffer();
  }, [loadOffer]);

  /** After a change: fetch again, swap the list in place and say what happened. */
  const changed = useCallback(
    async (text: string) => {
      setOutcome({ tone: "ok", text });
      const next = await loadFirst();
      setState(next);
      await loadOffer();
      heading.current?.focus();
    },
    [loadOffer],
  );

  async function loadMore() {
    if (state.phase !== "ready" || state.next === null || loadingMore) return;

    setLoadingMore(true);
    const result = await loadMembers(state.next);
    setLoadingMore(false);

    if (!("members" in result)) {
      setOutcome({ tone: "problem", text: describeFailure(result) });
      return;
    }

    setOutcome(null);
    setState({ phase: "ready", members: [...state.members, ...result.members], next: result.next });
  }

  if (state.phase === "loading") {
    return (
      <Panel>
        <p role="status" aria-busy="true" className="text-text-secondary">
          Loading people…
        </p>
      </Panel>
    );
  }

  if (state.phase === "failed") {
    return <LoadProblem failure={state.failure} returnTo="/access" onRetry={retry} />;
  }

  return (
    <>
      <section aria-labelledby="people-heading" className="flex flex-col gap-3">
        <div className="flex flex-col gap-1">
          <h2 id="people-heading" ref={heading} tabIndex={-1} className="text-lg font-semibold">
            People with staff access
          </h2>
          <p className="max-w-prose text-sm text-text-secondary">
            You see the people and roles inside your own reach. Every grant and removal is recorded in the audit log.
          </p>
        </div>

        {outcome ? <Notice tone={outcome.tone}>{outcome.text}</Notice> : null}

        {state.members.length === 0 ? (
          <Panel>
            <p className="font-medium">Nobody to show</p>
            <p className="max-w-prose text-sm text-text-secondary">
              No staff member is visible to your role, or nobody holds a staff role yet.
            </p>
          </Panel>
        ) : (
          <ul className="flex flex-col gap-3">
            {state.members.map((member) => (
              <li key={member.id}>
                <Member member={member} onChanged={changed} />
              </li>
            ))}
          </ul>
        )}

        {state.next !== null ? (
          <button type="button" onClick={() => void loadMore()} disabled={loadingMore} aria-busy={loadingMore} className={`${quietButton} w-fit`}>
            {loadingMore ? "Loading…" : "Load more people"}
          </button>
        ) : null}
      </section>

      <GrantRole members={state.members} offer={offer} problem={offerProblem} onChanged={changed} onRetry={() => void loadOffer()} />

      <InviteStaff offer={offer} problem={offerProblem} onInvited={() => setInvitesVersion((version) => version + 1)} />
      <PendingInvitations refreshKey={invitesVersion} />
    </>
  );
}

function Member({ member, onChanged }: { member: StaffMember; onChanged: (text: string) => Promise<void> }) {
  const [revoking, setRevoking] = useState<StaffGrant | null>(null);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    if (revoking && !dialog.current?.open) dialog.current?.showModal();
  }, [revoking]);

  async function revoke() {
    if (revoking === null || busy) return;

    setBusy(true);
    setProblem(null);
    const result = await apiSend<void>("DELETE", `/api/v1/admin/users/${member.id}/roles/${revoking.id}`);
    setBusy(false);

    if (result.ok) {
      const done = `Removed ${revoking.role_label} (${scopeLabel(revoking)}) from ${member.name}.`;
      dialog.current?.close();
      await onChanged(done);
      return;
    }

    setProblem(describeFailure(result.failure));
  }

  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0">
          <p className="font-medium">
            {member.name}
            {member.is_self ? <span className="ml-2 rounded-full border border-border-strong px-2 py-0.5 text-xs font-medium">You</span> : null}
          </p>
          <p className="break-all font-mono text-sm text-text-secondary">{member.email}</p>
          {member.email_masked ? <p className="text-sm text-text-secondary">Address hidden for your role.</p> : null}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <StatusPill status={member.status} />
          <span className="inline-flex min-h-7 items-center rounded-full border border-border-strong px-3 text-sm text-text-secondary">
            Two-step sign-in: {member.mfa_enrolled ? "set up" : "not set up"}
          </span>
        </div>
      </div>

      <ul aria-label={`Roles of ${member.name}`} className="flex flex-col gap-2">
        {member.grants.map((grant) => (
          <li key={grant.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-border bg-surface-2 px-3 py-2">
            <p className="min-w-0 text-sm">
              <span className="font-medium">{grant.role_label}</span>
              <span className="text-text-secondary">
                {" "}
                · {scopeLabel(grant)} · since <time dateTime={grant.granted_at}>{formatDay(grant.granted_at)}</time>
              </span>
            </p>
            {grant.can_revoke ? (
              <button
                type="button"
                onClick={() => {
                  setProblem(null);
                  setRevoking(grant);
                }}
                aria-label={`Remove ${grant.role_label} (${scopeLabel(grant)}) from ${member.name}`}
                className={quietButton}
              >
                Remove
              </button>
            ) : (
              <span className="text-sm text-text-secondary">{member.is_self ? "Your own role" : "You cannot remove this"}</span>
            )}
          </li>
        ))}
      </ul>

      <dialog
        ref={dialog}
        aria-labelledby={`revoke-title-${member.id}`}
        aria-describedby={`revoke-description-${member.id}`}
        onClose={() => setRevoking(null)}
        onCancel={(event) => {
          if (busy) event.preventDefault();
        }}
        className="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-border-strong bg-surface p-5 text-text-primary backdrop:bg-black/50"
      >
        {revoking ? (
          <div className="flex flex-col gap-4">
            <h3 id={`revoke-title-${member.id}`} className="text-lg font-semibold">
              Remove {revoking.role_label} from {member.name}?
            </h3>
            <p id={`revoke-description-${member.id}`} className="text-sm text-text-secondary">
              Scope: {scopeLabel(revoking)}.{" "}
              {member.grants.length === 1
                ? "This is their only staff role, so they lose access to the admin console on their next request."
                : "They keep their other roles."}{" "}
              The removal is recorded in the audit log.
            </p>
            {problem ? <Notice tone="problem">{problem}</Notice> : null}
            <div className="flex flex-wrap gap-2">
              <button type="button" disabled={busy} onClick={() => dialog.current?.close()} className={quietButton}>
                Cancel
              </button>
              <button type="button" disabled={busy} aria-busy={busy} onClick={() => void revoke()} className={dangerButton}>
                {busy ? "Removing…" : "Yes, remove"}
              </button>
            </div>
          </div>
        ) : null}
      </dialog>
    </div>
  );
}

function GrantRole({
  members,
  offer,
  problem,
  onChanged,
  onRetry,
}: {
  members: StaffMember[];
  offer: Offer;
  problem: ApiFailure | null;
  onChanged: (text: string) => Promise<void>;
  onRetry: () => void;
}) {
  const [person, setPerson] = useState("");
  const [roleSlug, setRoleSlug] = useState("");
  const [scope, setScope] = useState("");
  const [busy, setBusy] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);

  const others = members.filter((member) => !member.is_self && member.status === "active");
  const role = offer?.roles.find((candidate) => candidate.slug === roleSlug) ?? null;

  // Where this role can be granted: whole platform, and the cities the caller covers.
  const scopes: { value: string; label: string }[] = [];
  if (role && offer) {
    if (role.global) scopes.push({ value: "global", label: "Whole platform" });
    for (const city of offer.cities) {
      if (role.scope_types.includes("city") && (role.all_cities || role.city_ids.includes(city.id))) {
        scopes.push({ value: `city:${city.id}`, label: city.name });
      }
    }
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;

    const form = event.currentTarget;
    const missing: Record<string, string[]> = {};
    if (person === "") missing.person = ["Choose a person."];
    if (roleSlug === "") missing.role = ["Choose a role."];
    if (scope === "") missing.scope = ["Choose where the role applies."];
    setMessage(null);
    setFields(missing);
    if (Object.keys(missing).length > 0) {
      focusFirstInvalid([["person", "#grant-person"], ["role", "#grant-role"], ["scope", "#grant-scope"]], missing, form);
      return;
    }

    const member = members.find((candidate) => candidate.id === person);
    const body =
      scope === "global" ? { role: roleSlug, scope_type: "global" } : { role: roleSlug, scope_type: "city", scope_id: scope.slice("city:".length) };

    setBusy(true);
    const result = await apiSend<Envelope<StaffMember>>("POST", `/api/v1/admin/users/${person}/roles`, body);
    setBusy(false);

    if (result.ok) {
      const label = scopes.find((option) => option.value === scope)?.label ?? "";
      setPerson("");
      setRoleSlug("");
      setScope("");
      await onChanged(`Granted ${role?.label ?? "the role"} (${label}) to ${member?.name ?? "the person"}.`);
      return;
    }

    if (result.failure.kind === "validation") {
      const mapped: Record<string, string[]> = {};
      for (const [name, messages] of Object.entries(result.failure.fields)) mapped[name === "scope_id" || name === "scope_type" ? "scope" : name] = messages;
      setFields(mapped);
      focusFirstInvalid([["person", "#grant-person"], ["role", "#grant-role"], ["scope", "#grant-scope"]], mapped, form);
    }
    setMessage(describeFailure(result.failure));
  }

  return (
    <section aria-labelledby="grant-heading" className="flex max-w-2xl flex-col gap-3">
      <h2 id="grant-heading" className="text-lg font-semibold">
        Give a role
      </h2>

      {problem ? (
        <Panel>
          <p role="alert" className="font-medium">
            {problem.kind === "forbidden" ? "Your role cannot give roles." : describeFailure(problem)}
          </p>
          {problem.kind === "forbidden" ? null : (
            <button type="button" onClick={onRetry} className={`${quietButton} w-fit`}>
              Try again
            </button>
          )}
        </Panel>
      ) : offer === null ? (
        <Panel>
          <p role="status" aria-busy="true" className="text-text-secondary">
            Checking which roles you can give…
          </p>
        </Panel>
      ) : offer.roles.length === 0 ? (
        <Panel dashed>
          <p className="font-medium">You can view access but not change it</p>
          <p className="max-w-prose text-sm text-text-secondary">
            Giving and removing roles needs a platform owner, platform admin or city manager role.
          </p>
        </Panel>
      ) : (
        <Panel>
          <p className="max-w-prose text-sm text-text-secondary">
            Choose someone who already holds a staff role. To bring in a new person, invite them below.You cannot change your own roles.
          </p>
          <form onSubmit={onSubmit} noValidate aria-label="Give a role" className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-1.5 sm:col-span-2">
              <label htmlFor="grant-person" className="text-sm font-medium">
                Person
              </label>
              <select
                id="grant-person"
                value={person}
                onChange={(event) => setPerson(event.target.value)}
                required
                aria-invalid={fields.person ? true : undefined}
                aria-describedby="grant-person-error"
                className={fieldClass}
              >
                <option value="">Choose a person</option>
                {others.map((member) => (
                  <option key={member.id} value={member.id}>
                    {member.name} ({member.email})
                  </option>
                ))}
              </select>
              <FieldError id="grant-person-error" messages={fields.person} />
            </div>

            <div className="flex flex-col gap-1.5">
              <label htmlFor="grant-role" className="text-sm font-medium">
                Role
              </label>
              <select
                id="grant-role"
                value={roleSlug}
                onChange={(event) => {
                  setRoleSlug(event.target.value);
                  setScope("");
                }}
                required
                aria-invalid={fields.role ? true : undefined}
                aria-describedby="grant-role-error"
                className={fieldClass}
              >
                <option value="">Choose a role</option>
                {offer.roles.map((candidate) => (
                  <option key={candidate.slug} value={candidate.slug}>
                    {candidate.label}
                  </option>
                ))}
              </select>
              <FieldError id="grant-role-error" messages={fields.role} />
            </div>

            <div className="flex flex-col gap-1.5">
              <label htmlFor="grant-scope" className="text-sm font-medium">
                Applies to
              </label>
              <select
                id="grant-scope"
                value={scope}
                onChange={(event) => setScope(event.target.value)}
                required
                disabled={role === null}
                aria-invalid={fields.scope ? true : undefined}
                aria-describedby="grant-scope-hint grant-scope-error"
                className={fieldClass}
              >
                <option value="">{role === null ? "Choose a role first" : scopes.length === 0 ? "No place available" : "Choose where"}</option>
                {scopes.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
              <p id="grant-scope-hint" className="text-sm text-text-secondary">
                A role applies to one city or to the whole platform, never beyond what you cover.
              </p>
              <FieldError id="grant-scope-error" messages={fields.scope} />
            </div>

            <div className="flex flex-col gap-2 sm:col-span-2">
              <button type="submit" disabled={busy} aria-busy={busy} className={`${primaryButton} w-fit`}>
                {busy ? "Giving…" : "Give role"}
              </button>
              {message ? <Notice tone="problem">{message}</Notice> : null}
            </div>
          </form>
        </Panel>
      )}
    </section>
  );
}
