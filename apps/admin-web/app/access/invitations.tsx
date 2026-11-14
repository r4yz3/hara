"use client";

import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";

import type { AssignableRole, City, StaffInvitation } from "@food-delivery/api-client";
import { apiGet, apiSend, describeFailure, isEnrolmentOnly, type ApiFailure } from "@food-delivery/session";

import {
  EnrolmentSetup,
  FieldError,
  Notice,
  Panel,
  dangerButton,
  fieldClass,
  focusFirstInvalid,
  primaryButton,
  quietButton,
} from "@/components/ui";

type Page<T> = { data: T; meta: { next_cursor?: string | null } };
type Offer = { roles: AssignableRole[]; cities: City[] } | null;

const PAGE_SIZE = 50;

/** Where `role` can be given: the whole platform, and the cities the caller covers. */
function scopeOptions(role: AssignableRole | null, offer: Offer): { value: string; label: string }[] {
  const scopes: { value: string; label: string }[] = [];
  if (!role || !offer) return scopes;

  if (role.global) scopes.push({ value: "global", label: "Whole platform" });
  for (const city of offer.cities) {
    if (role.scope_types.includes("city") && (role.all_cities || role.city_ids.includes(city.id))) {
      scopes.push({ value: `city:${city.id}`, label: city.name });
    }
  }

  return scopes;
}

function formatDay(iso: string): string {
  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? iso : `${date.toISOString().slice(0, 10)} UTC`;
}

function scopeLabel(invitation: StaffInvitation): string {
  if (invitation.scope_type === "global") return "Whole platform";

  return invitation.city_name ?? "A city you cannot name here";
}

const fieldOrder: [string, string][] = [
  ["name", "#invite-name"],
  ["email", "#invite-email"],
  ["role", "#invite-role"],
  ["scope", "#invite-scope"],
];

export function InviteStaff({
  offer,
  problem,
  onInvited,
}: {
  offer: Offer;
  problem: ApiFailure | null;
  onInvited: () => void;
}) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [roleSlug, setRoleSlug] = useState("");
  const [scope, setScope] = useState("");
  const [busy, setBusy] = useState(false);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [problemText, setProblemText] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  const role = offer?.roles.find((candidate) => candidate.slug === roleSlug) ?? null;
  const scopes = scopeOptions(role, offer);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;

    const form = event.currentTarget;
    const missing: Record<string, string[]> = {};
    if (name.trim() === "") missing.name = ["Enter their name."];
    if (email.trim() === "") missing.email = ["Enter their email address."];
    else if (!/^\S+@\S+$/.test(email.trim())) missing.email = ["Enter a valid email address."];
    if (roleSlug === "") missing.role = ["Choose a role."];
    if (scope === "") missing.scope = ["Choose where the role applies."];
    setSent(false);
    setProblemText(null);
    setFields(missing);
    if (Object.keys(missing).length > 0) {
      focusFirstInvalid(fieldOrder, missing, form);
      return;
    }

    const body =
      scope === "global"
        ? { name: name.trim(), email: email.trim(), role: roleSlug, scope_type: "global" }
        : { name: name.trim(), email: email.trim(), role: roleSlug, scope_type: "city", scope_id: scope.slice("city:".length) };

    setBusy(true);
    const result = await apiSend<unknown>("POST", "/api/v1/admin/invitations", body);
    setBusy(false);

    if (result.ok) {
      setName("");
      setEmail("");
      setRoleSlug("");
      setScope("");
      setSent(true);
      onInvited();
      return;
    }

    if (result.failure.kind === "validation") {
      const mapped: Record<string, string[]> = {};
      for (const [field, messages] of Object.entries(result.failure.fields)) mapped[field === "scope_id" || field === "scope_type" ? "scope" : field] = messages;
      setFields(mapped);
      focusFirstInvalid(fieldOrder, mapped, form);
    }
    setProblemText(
      result.failure.kind === "rate_limited"
        ? "Too many invitations to that address recently. Wait a while before sending another."
        : describeFailure(result.failure),
    );
  }

  return (
    <section aria-labelledby="invite-heading" className="flex max-w-2xl flex-col gap-3">
      <h2 id="invite-heading" className="text-lg font-semibold">
        Invite a staff member
      </h2>

      {problem ? (
        <Panel dashed>
          <p className="font-medium">{problem.kind === "forbidden" ? "Your role cannot invite staff." : "Which roles you can offer could not be loaded."}</p>
          {problem.kind === "forbidden" ? null : <p className="text-sm text-text-secondary">Use Try again under Give a role, then come back here.</p>}
        </Panel>
      ) : offer === null ? (
        <Panel>
          <p role="status" aria-busy="true" className="text-text-secondary">
            Checking which roles you can offer…
          </p>
        </Panel>
      ) : offer.roles.length === 0 ? (
        <Panel dashed>
          <p className="font-medium">You cannot invite staff</p>
          <p className="max-w-prose text-sm text-text-secondary">Inviting people needs a platform owner, platform admin or city manager role.</p>
        </Panel>
      ) : (
        <Panel>
          <p className="max-w-prose text-sm text-text-secondary">
            We email a single-use link. The person chooses a password (or confirms the one they already have) and then sets up two-step sign-in.
            Nothing is granted until they accept.
          </p>
          <form onSubmit={onSubmit} noValidate aria-label="Invite a staff member" className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-1.5">
              <label htmlFor="invite-name" className="text-sm font-medium">
                Name
              </label>
              <input
                id="invite-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                autoComplete="off"
                required
                maxLength={120}
                aria-invalid={fields.name ? true : undefined}
                aria-describedby="invite-name-error"
                className={fieldClass}
              />
              <FieldError id="invite-name-error" messages={fields.name} />
            </div>

            <div className="flex flex-col gap-1.5">
              <label htmlFor="invite-email" className="text-sm font-medium">
                Email address
              </label>
              <input
                id="invite-email"
                type="email"
                inputMode="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                autoComplete="off"
                required
                aria-invalid={fields.email ? true : undefined}
                aria-describedby="invite-email-error"
                className={fieldClass}
              />
              <FieldError id="invite-email-error" messages={fields.email} />
            </div>

            <div className="flex flex-col gap-1.5">
              <label htmlFor="invite-role" className="text-sm font-medium">
                Role
              </label>
              <select
                id="invite-role"
                value={roleSlug}
                onChange={(event) => {
                  setRoleSlug(event.target.value);
                  setScope("");
                }}
                required
                aria-invalid={fields.role ? true : undefined}
                aria-describedby="invite-role-error"
                className={fieldClass}
              >
                <option value="">Choose a role</option>
                {offer.roles.map((candidate) => (
                  <option key={candidate.slug} value={candidate.slug}>
                    {candidate.label}
                  </option>
                ))}
              </select>
              <FieldError id="invite-role-error" messages={fields.role} />
            </div>

            <div className="flex flex-col gap-1.5">
              <label htmlFor="invite-scope" className="text-sm font-medium">
                Applies to
              </label>
              <select
                id="invite-scope"
                value={scope}
                onChange={(event) => setScope(event.target.value)}
                required
                disabled={role === null}
                aria-invalid={fields.scope ? true : undefined}
                aria-describedby="invite-scope-hint invite-scope-error"
                className={fieldClass}
              >
                <option value="">{role === null ? "Choose a role first" : scopes.length === 0 ? "No place available" : "Choose where"}</option>
                {scopes.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
              <p id="invite-scope-hint" className="text-sm text-text-secondary">
                A role applies to one city or to the whole platform, never beyond what you cover.
              </p>
              <FieldError id="invite-scope-error" messages={fields.scope} />
            </div>

            <div className="flex flex-col gap-2 sm:col-span-2">
              <button type="submit" disabled={busy} aria-busy={busy} className={`${primaryButton} w-fit`}>
                {busy ? "Sending…" : "Send invitation"}
              </button>
              {problemText ? <Notice tone="problem">{problemText}</Notice> : null}
              {sent ? <Notice tone="ok">If the address can receive mail, an invitation is on its way. It expires in 72 hours.</Notice> : null}
            </div>
          </form>
        </Panel>
      )}
    </section>
  );
}

type PendingState =
  | { phase: "loading" }
  | { phase: "failed"; failure: ApiFailure }
  | { phase: "ready"; items: StaffInvitation[]; next: string | null };

async function loadPending(cursor: string | null): Promise<ApiFailure | { items: StaffInvitation[]; next: string | null }> {
  const query = new URLSearchParams({ limit: String(PAGE_SIZE) });
  if (cursor !== null) query.set("cursor", cursor);

  const result = await apiGet<Page<StaffInvitation[]>>(`/api/v1/admin/invitations?${query}`);

  return result.ok ? { items: result.value.data, next: result.value.meta.next_cursor ?? null } : result.failure;
}

export function PendingInvitations({ refreshKey }: { refreshKey: number }) {
  const [state, setState] = useState<PendingState>({ phase: "loading" });
  const [outcome, setOutcome] = useState<{ tone: "ok" | "problem"; text: string } | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);

  const load = useCallback(async () => {
    const result = await loadPending(null);
    setState("items" in result ? { phase: "ready", ...result } : { phase: "failed", failure: result });
  }, []);

  useEffect(() => {
    let cancelled = false;

    void loadPending(null).then((result) => {
      if (!cancelled) setState("items" in result ? { phase: "ready", ...result } : { phase: "failed", failure: result });
    });

    return () => {
      cancelled = true;
    };
  }, [refreshKey]);

  async function loadMore() {
    if (state.phase !== "ready" || state.next === null || loadingMore) return;

    setLoadingMore(true);
    const result = await loadPending(state.next);
    setLoadingMore(false);

    if (!("items" in result)) {
      setOutcome({ tone: "problem", text: describeFailure(result) });
      return;
    }

    setOutcome(null);
    setState({ phase: "ready", items: [...state.items, ...result.items], next: result.next });
  }

  async function revoked(text: string) {
    setOutcome({ tone: "ok", text });
    await load();
    heading.current?.focus();
  }

  return (
    <section aria-labelledby="pending-heading" className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <h2 id="pending-heading" ref={heading} tabIndex={-1} className="text-lg font-semibold">
          Pending invitations
        </h2>
        <p className="max-w-prose text-sm text-text-secondary">Invitations nobody has accepted yet. Revoking one stops its link working at once.</p>
      </div>

      {outcome ? <Notice tone={outcome.tone}>{outcome.text}</Notice> : null}

      {state.phase === "loading" ? (
        <Panel>
          <p role="status" aria-busy="true" className="text-text-secondary">
            Loading invitations…
          </p>
        </Panel>
      ) : state.phase === "failed" && isEnrolmentOnly(state.failure) ? (
        <EnrolmentSetup />
      ) : state.phase === "failed" ? (
        <Panel>
          <p role="alert" className="font-medium">
            {state.failure.kind === "forbidden" ? "Your role cannot see invitations." : describeFailure(state.failure)}
          </p>
          {state.failure.kind === "forbidden" ? null : (
            <button
              type="button"
              onClick={() => {
                setState({ phase: "loading" });
                void load();
              }}
              className={`${quietButton} w-fit`}
            >
              Try again
            </button>
          )}
        </Panel>
      ) : state.items.length === 0 ? (
        <Panel dashed>
          <p className="font-medium">No pending invitations</p>
          <p className="max-w-prose text-sm text-text-secondary">Invitations you can see appear here until they are accepted, revoked or expire.</p>
        </Panel>
      ) : (
        <ul className="flex flex-col gap-3">
          {state.items.map((invitation) => (
            <li key={invitation.id}>
              <InvitationRow invitation={invitation} onRevoked={revoked} />
            </li>
          ))}
        </ul>
      )}

      {state.phase === "ready" && state.next !== null ? (
        <button type="button" onClick={() => void loadMore()} disabled={loadingMore} aria-busy={loadingMore} className={`${quietButton} w-fit`}>
          {loadingMore ? "Loading…" : "Load more invitations"}
        </button>
      ) : null}
    </section>
  );
}

function InvitationRow({ invitation, onRevoked }: { invitation: StaffInvitation; onRevoked: (text: string) => Promise<void> }) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    if (open && !dialog.current?.open) dialog.current?.showModal();
  }, [open]);

  async function revoke() {
    if (busy) return;

    setBusy(true);
    setProblem(null);
    const result = await apiSend<void>("DELETE", `/api/v1/admin/invitations/${invitation.id}`);
    setBusy(false);

    if (result.ok) {
      dialog.current?.close();
      await onRevoked(`Revoked the invitation for ${invitation.name} (${invitation.role_label}, ${scopeLabel(invitation)}).`);
      return;
    }

    setProblem(describeFailure(result.failure));
  }

  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0">
          <p className="font-medium">
            {invitation.name}
            {invitation.invited_by_me ? (
              <span className="ml-2 rounded-full border border-border-strong px-2 py-0.5 text-xs font-medium">Invited by you</span>
            ) : null}
          </p>
          <p className="break-all font-mono text-sm text-text-secondary">{invitation.email}</p>
          {invitation.email_masked ? <p className="text-sm text-text-secondary">Address hidden for your role.</p> : null}
        </div>
        {invitation.can_revoke ? (
          <button
            type="button"
            onClick={() => {
              setProblem(null);
              setOpen(true);
            }}
            aria-label={`Revoke the invitation for ${invitation.name}`}
            className={quietButton}
          >
            Revoke
          </button>
        ) : (
          <span className="text-sm text-text-secondary">You cannot revoke this</span>
        )}
      </div>
      <p className="text-sm">
        <span className="font-medium">{invitation.role_label}</span>
        <span className="text-text-secondary">
          {" "}
          · {scopeLabel(invitation)} · expires <time dateTime={invitation.expires_at}>{formatDay(invitation.expires_at)}</time>
        </span>
      </p>

      <dialog
        ref={dialog}
        aria-labelledby={`revoke-invite-title-${invitation.id}`}
        aria-describedby={`revoke-invite-description-${invitation.id}`}
        onClose={() => setOpen(false)}
        onCancel={(event) => {
          if (busy) event.preventDefault();
        }}
        className="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-border-strong bg-surface p-5 text-text-primary backdrop:bg-black/50"
      >
        {open ? (
          <div className="flex flex-col gap-4">
            <h3 id={`revoke-invite-title-${invitation.id}`} className="text-lg font-semibold">
              Revoke the invitation for {invitation.name}?
            </h3>
            <p id={`revoke-invite-description-${invitation.id}`} className="text-sm text-text-secondary">
              {invitation.role_label} · {scopeLabel(invitation)}. The link in their email stops working at once. You can invite them again later. The
              revocation is recorded in the audit log.
            </p>
            {problem ? <Notice tone="problem">{problem}</Notice> : null}
            <div className="flex flex-wrap gap-2">
              <button type="button" disabled={busy} onClick={() => dialog.current?.close()} className={quietButton}>
                Cancel
              </button>
              <button type="button" disabled={busy} aria-busy={busy} onClick={() => void revoke()} className={dangerButton}>
                {busy ? "Revoking…" : "Yes, revoke"}
              </button>
            </div>
          </div>
        ) : null}
      </dialog>
    </div>
  );
}
