"use client";

import { useEffect, useId, useRef, useState, type FormEvent } from "react";

import type { StaffMember } from "@food-delivery/api-client";
import { apiSend, describeFailure, isEnrolmentOnly } from "@food-delivery/session";

import { ActionNotice, FieldError, dangerButton, fieldClass, primaryButton, quietButton } from "@/components/ui";

const MAX_REASON = 500;

type Action = "suspend" | "reactivate";

/**
 * Suspend and reactivate buttons for one person, with a confirmation dialog.
 * Suspending needs a reason (kept in the audit log); reactivating may carry
 * one. The buttons appear only when the API says the caller may use them
 * (`can_suspend`, `can_reactivate`); the server checks again on every request.
 */
export function SuspensionControls({ member, onChanged }: { member: StaffMember; onChanged: (text: string) => Promise<void> }) {
  const [action, setAction] = useState<Action | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [fieldProblem, setFieldProblem] = useState<string[] | undefined>();
  const [problem, setProblem] = useState<{ text: string; enrolment: boolean } | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);
  const field = useRef<HTMLTextAreaElement>(null);
  const id = useId();

  useEffect(() => {
    if (action && !dialog.current?.open) {
      dialog.current?.showModal();
      field.current?.focus();
    }
  }, [action]);

  if (!member.can_suspend && !member.can_reactivate) return null;

  function open(next: Action) {
    setReason("");
    setProblem(null);
    setFieldProblem(undefined);
    setAction(next);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (action === null || busy) return;

    const text = reason.trim();
    if (action === "suspend" && text === "") {
      setFieldProblem(["Say why this account is being suspended."]);
      field.current?.focus();
      return;
    }

    setBusy(true);
    setProblem(null);
    setFieldProblem(undefined);
    const body = text === "" ? {} : { reason: text };
    const result = await apiSend<void>("POST", `/api/v1/admin/users/${member.id}/${action}`, body);
    setBusy(false);

    if (result.ok) {
      const done =
        action === "suspend"
          ? `Suspended ${member.name}. Their sessions ended and they cannot sign in until reactivated.`
          : `Reactivated ${member.name}. They can sign in again.`;
      dialog.current?.close();
      await onChanged(done);
      return;
    }

    if (result.failure.kind === "validation" && result.failure.fields.reason) {
      setFieldProblem(result.failure.fields.reason);
      field.current?.focus();
      return;
    }

    setProblem({ text: describeFailure(result.failure), enrolment: isEnrolmentOnly(result.failure) });
  }

  const suspending = action === "suspend";

  return (
    <>
      {member.can_suspend ? (
        <button type="button" onClick={() => open("suspend")} aria-label={`Suspend ${member.name}`} className={quietButton}>
          Suspend
        </button>
      ) : null}
      {member.can_reactivate ? (
        <button type="button" onClick={() => open("reactivate")} aria-label={`Reactivate ${member.name}`} className={quietButton}>
          Reactivate
        </button>
      ) : null}

      <dialog
        ref={dialog}
        aria-labelledby={`${id}-title`}
        aria-describedby={`${id}-description`}
        onClose={() => setAction(null)}
        onCancel={(event) => {
          if (busy) event.preventDefault();
        }}
        className="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-border-strong bg-surface p-5 text-text-primary backdrop:bg-black/50"
      >
        {action ? (
          <form onSubmit={submit} noValidate className="flex flex-col gap-4">
            <h3 id={`${id}-title`} className="text-lg font-semibold">
              {suspending ? `Suspend ${member.name}?` : `Reactivate ${member.name}?`}
            </h3>
            <p id={`${id}-description`} className="text-sm text-text-secondary">
              {suspending
                ? "They are signed out at once, their device tokens are removed, and they cannot sign in or use the admin console until someone reactivates them. Their roles stay. The reason is recorded in the audit log."
                : "They can sign in again with their password. Sessions and device tokens ended by the suspension are not restored. The note is recorded in the audit log."}
            </p>
            <div className="flex flex-col gap-1.5">
              <label htmlFor={`${id}-reason`} className="text-sm font-medium">
                {suspending ? "Reason" : "Note (optional)"}
              </label>
              <textarea
                id={`${id}-reason`}
                ref={field}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                rows={3}
                maxLength={MAX_REASON}
                required={suspending}
                aria-invalid={fieldProblem ? true : undefined}
                aria-describedby={`${id}-reason-hint ${id}-reason-error`}
                className={fieldClass}
              />
              <p id={`${id}-reason-hint`} className="text-sm text-text-secondary">
                Up to {MAX_REASON} characters. Do not put passwords or codes here.
              </p>
              <FieldError id={`${id}-reason-error`} messages={fieldProblem} />
            </div>
            <ActionNotice message={problem} />
            <div className="flex flex-wrap gap-2">
              <button type="button" disabled={busy} onClick={() => dialog.current?.close()} className={quietButton}>
                Cancel
              </button>
              <button type="submit" disabled={busy} aria-busy={busy} className={suspending ? dangerButton : primaryButton}>
                {busy ? (suspending ? "Suspending…" : "Reactivating…") : suspending ? "Yes, suspend" : "Yes, reactivate"}
              </button>
            </div>
          </form>
        ) : null}
      </dialog>
    </>
  );
}
