"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";

import { apiGet, apiSend, describeFailure, type ApiFailure } from "@food-delivery/session";

// Shape of GET /api/v1/me/addresses (backend/openapi/openapi.yaml, SavedAddress).
// An address is a person's home position: it is only ever sent in request
// bodies, and it is never put in a URL, in browser storage or in the console (ADR-013).
export type SavedAddress = {
  id: string;
  tag: string | null;
  address_line: string;
  landmark: string;
  latitude: number;
  longitude: number;
  is_default: boolean;
  city: { id: string; name: string } | null;
  serviceable: boolean;
  created_at: string;
  updated_at: string;
};

const LIMIT = 20;
const DECIMAL = /^-?\d{1,3}(\.\d{1,12})?$/;
const button = "min-h-12 rounded-xl border border-border-strong bg-surface px-4 text-base font-semibold disabled:opacity-70";
const primary = "min-h-12 rounded-xl bg-brand-600 px-4 text-base font-semibold text-on-brand disabled:opacity-70";
const danger = "min-h-12 rounded-xl bg-danger px-4 text-base font-semibold text-on-danger disabled:opacity-70";
const field = "min-h-12 w-full rounded-xl border border-border-strong bg-surface px-4 text-base text-text-primary";

type Notice = { tone: "ok" | "problem"; text: string } | null;
type FieldName = "address_line" | "landmark" | "tag" | "latitude" | "longitude" | "is_default";
type Errors = Partial<Record<FieldName, string>>;
// Order matters: focus goes to the first refused field in this order.
const ORDER: FieldName[] = ["address_line", "landmark", "tag", "latitude", "longitude", "is_default"];

function checkNumber(value: string, limit: number, name: string): string | undefined {
  if (value === "") return `Enter the ${name}.`;
  if (!DECIMAL.test(value)) return `The ${name} must be a plain number such as 12.345.`;
  if (Math.abs(Number(value)) > limit) return `The ${name} must be between -${limit} and ${limit}.`;

  return undefined;
}

/** The API keeps 6 decimals; show no more, and never an exponent. */
function plain(value: number): string {
  return String(Number(value.toFixed(6)));
}

function coverage(address: SavedAddress): string {
  return address.serviceable && address.city
    ? `Inside a delivery area (${address.city.name})`
    : "Not inside an open delivery area yet";
}

/**
 * The signed-in customer's saved delivery addresses (spec 8.7 states included).
 * There is no map: no tiles are hosted yet, so a position is typed in or taken
 * from this device, and nothing here searches for an address.
 */
export function AddressesSection() {
  const router = useRouter();
  const [phase, setPhase] = useState<"loading" | "ready" | "error">("loading");
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const [items, setItems] = useState<SavedAddress[]>([]);
  const [notice, setNotice] = useState<Notice>(null);
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<SavedAddress | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const busy = useRef(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const dialog = useRef<HTMLDialogElement>(null);

  const signedOut = useCallback(() => router.replace(`/login?next=${encodeURIComponent("/account")}`), [router]);

  const load = useCallback(async () => {
    const result = await apiGet<{ data: SavedAddress[] }>("/api/v1/me/addresses");
    if (result.ok) {
      setItems(result.value.data);
      setPhase("ready");
    } else {
      // /me already answered for this person, so a 401 here means the session ended meanwhile: say so with a link, no surprise redirect.
      setFailure(result.failure);
      setPhase("error");
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch on mount; the state is set after the await
    void load();
  }, [load]);

  useEffect(() => {
    if (deleting && !dialog.current?.open) dialog.current?.showModal();
  }, [deleting]);

  /** A 404 means the address is gone (deleted elsewhere): say so and show the real list. */
  async function gone() {
    setEditing(null);
    setDeleting(null);
    dialog.current?.close();
    setNotice({ tone: "problem", text: "That address no longer exists. The list has been reloaded." });
    await load();
  }

  async function run(id: string, action: () => Promise<boolean>) {
    if (busy.current) return;
    busy.current = true;
    setBusyId(id);
    setNotice(null);
    try {
      await action();
    } finally {
      busy.current = false;
      setBusyId(null);
    }
  }

  async function makeDefault(address: SavedAddress) {
    await run(address.id, async () => {
      const result = await apiSend("POST", `/api/v1/me/addresses/${address.id}/default`);
      if (result.ok) {
        setNotice({ tone: "ok", text: `${address.tag ?? "Address"} is now your default address.` });
        await load();
      } else if (result.failure.kind === "signed_out") signedOut();
      else if (result.failure.kind === "not_found") await gone();
      else setNotice({ tone: "problem", text: describeFailure(result.failure) });
      return result.ok;
    });
  }

  async function confirmDelete() {
    if (!deleting) return;
    const address = deleting;
    await run(address.id, async () => {
      const result = await apiSend("DELETE", `/api/v1/me/addresses/${address.id}`);
      if (result.ok) {
        dialog.current?.close();
        setNotice({ tone: "ok", text: `${address.tag ?? "Address"} was deleted.` });
        await load();
        heading.current?.focus();
      } else if (result.failure.kind === "signed_out") signedOut();
      else if (result.failure.kind === "not_found") await gone();
      else {
        dialog.current?.close();
        setNotice({ tone: "problem", text: describeFailure(result.failure) });
      }
      return result.ok;
    });
  }

  const full = items.length >= LIMIT;

  return (
    <section aria-labelledby="addresses-heading" className="flex flex-col gap-4 rounded-3xl border border-border bg-surface p-5">
      <h2 id="addresses-heading" ref={heading} tabIndex={-1} className="font-semibold">
        Delivery addresses
      </h2>
      <p className="text-sm text-text-secondary">
        A map pin is not available yet, so you give a position as two numbers or from this device. Riders find the place by the
        address and landmark you write.
      </p>

      <p role="status" className="min-h-6 text-sm font-medium text-success">
        {notice?.tone === "ok" ? notice.text : null}
      </p>
      {notice?.tone === "problem" ? (
        <p role="alert" className="text-sm font-medium text-danger">
          {notice.text}
        </p>
      ) : null}

      {phase === "loading" ? (
        <p role="status" aria-busy="true" className="text-text-secondary">
          Loading your addresses…
        </p>
      ) : null}

      {phase === "error" && failure ? (
        <div role="alert" className="flex flex-col items-start gap-3">
          <p className="font-medium text-danger">
            {failure.kind === "network"
              ? "We could not reach the server. Check your connection and try again."
              : failure.kind === "signed_out"
                ? "You are signed out. Sign in again to see your addresses."
                : "Your addresses could not be loaded right now. Try again shortly."}
          </p>
          {failure.kind === "signed_out" ? (
            <Link href={`/login?next=${encodeURIComponent("/account")}`} className={`${button} inline-flex items-center`}>
              Sign in
            </Link>
          ) : null}
          <button
            type="button"
            className={button}
            onClick={() => {
              setPhase("loading");
              void load();
            }}
          >
            Reload addresses
          </button>
        </div>
      ) : null}

      {phase === "ready" ? (
        <>
          {items.length === 0 ? (
            <div className="flex flex-col gap-1">
              <p className="font-medium">No saved addresses yet</p>
              <p className="text-sm text-text-secondary">Add one below. It will be your default.</p>
            </div>
          ) : (
            <ul aria-label="Saved addresses" className="flex flex-col gap-3">
              {items.map((address) => (
                <li key={address.id} className="rounded-2xl border border-border p-4">
                  {editing === address.id ? (
                    <AddressForm
                      key={address.id}
                      existing={address}
                      onSignedOut={signedOut}
                      onGone={gone}
                      onCancel={() => setEditing(null)}
                      onSaved={async () => {
                        setEditing(null);
                        setNotice({ tone: "ok", text: "Address saved." });
                        await load();
                      }}
                    />
                  ) : (
                    <AddressRow
                      address={address}
                      pending={busyId === address.id}
                      disabled={busyId !== null}
                      onEdit={() => {
                        setAdding(false);
                        setNotice(null);
                        setEditing(address.id);
                      }}
                      onDelete={() => setDeleting(address)}
                      onDefault={() => void makeDefault(address)}
                    />
                  )}
                </li>
              ))}
            </ul>
          )}

          {full ? (
            <p className="text-sm font-medium">
              You have {LIMIT} saved addresses, the most we keep. Delete one to add another.
            </p>
          ) : items.length === 0 || adding ? (
            <AddressForm
              onSignedOut={signedOut}
              onGone={gone}
              onCancel={items.length > 0 ? () => setAdding(false) : undefined}
              onSaved={async () => {
                setAdding(false);
                setNotice({ tone: "ok", text: "Address saved." });
                await load();
                heading.current?.focus();
              }}
            />
          ) : (
            <button
              type="button"
              className={`${button} self-start`}
              onClick={() => {
                setEditing(null);
                setNotice(null);
                setAdding(true);
              }}
            >
              Add an address
            </button>
          )}
        </>
      ) : null}

      <dialog
        ref={dialog}
        aria-labelledby="address-delete-title"
        aria-describedby="address-delete-description"
        onClose={() => setDeleting(null)}
        onCancel={(event) => {
          if (busy.current) event.preventDefault();
        }}
        className="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-border-strong bg-surface p-5 text-text-primary backdrop:bg-black/50"
      >
        {deleting ? (
          <div className="flex flex-col gap-4">
            <h3 id="address-delete-title" className="text-lg font-semibold">
              Delete {deleting.tag ?? "this address"}?
            </h3>
            <p id="address-delete-description" className="break-words text-sm text-text-secondary">
              {deleting.address_line} will be removed from your saved addresses. This cannot be undone.
              {deleting.is_default ? " You will have no default address until you pick another." : ""}
            </p>
            <div className="flex flex-wrap gap-2">
              <button type="button" autoFocus disabled={busyId !== null} onClick={() => dialog.current?.close()} className={button}>
                Cancel
              </button>
              <button type="button" disabled={busyId !== null} aria-busy={busyId !== null} onClick={() => void confirmDelete()} className={danger}>
                {busyId !== null ? "Deleting…" : "Yes, delete"}
              </button>
            </div>
          </div>
        ) : null}
      </dialog>
    </section>
  );
}

function AddressRow({
  address,
  pending,
  disabled,
  onEdit,
  onDelete,
  onDefault,
}: {
  address: SavedAddress;
  pending: boolean;
  disabled: boolean;
  onEdit: () => void;
  onDelete: () => void;
  onDefault: () => void;
}) {
  const label = address.tag ?? "Address";

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        <h3 className="font-semibold">{label}</h3>
        {address.is_default ? (
          <span className="rounded-full bg-brand-600 px-2.5 py-0.5 text-xs font-semibold text-on-brand">Default</span>
        ) : null}
      </div>
      <p className="break-words">{address.address_line}</p>
      <p className="break-words text-sm text-text-secondary">Landmark: {address.landmark}</p>
      <p className={`text-sm font-medium ${address.serviceable && address.city ? "text-success" : "text-text-secondary"}`}>{coverage(address)}</p>
      <div className="flex flex-wrap gap-2 pt-1">
        <button type="button" disabled={disabled} onClick={onEdit} className={button} aria-label={`Edit ${label}: ${address.address_line}`}>
          Edit
        </button>
        {address.is_default ? null : (
          <button type="button" disabled={disabled} aria-busy={pending} onClick={onDefault} className={button} aria-label={`Make default, ${label}: ${address.address_line}`}>
            {pending ? "Saving…" : "Make default"}
          </button>
        )}
        <button type="button" disabled={disabled} onClick={onDelete} className={button} aria-label={`Delete ${label}: ${address.address_line}`}>
          Delete
        </button>
      </div>
    </div>
  );
}

function AddressForm({
  existing,
  onSaved,
  onCancel,
  onSignedOut,
  onGone,
}: {
  existing?: SavedAddress;
  onSaved: () => Promise<void>;
  onCancel?: () => void;
  onSignedOut: () => void;
  onGone: () => Promise<void>;
}) {
  const prefix = existing ? `edit-${existing.id}` : "new-address";
  const form = useRef<HTMLFormElement>(null);
  const sending = useRef(false);
  const [addressLine, setAddressLine] = useState(existing?.address_line ?? "");
  const [landmark, setLandmark] = useState(existing?.landmark ?? "");
  const [tag, setTag] = useState(existing?.tag ?? "");
  const [latitude, setLatitude] = useState(existing ? plain(existing.latitude) : "");
  const [longitude, setLongitude] = useState(existing ? plain(existing.longitude) : "");
  const [makeDefault, setMakeDefault] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [locating, setLocating] = useState(false);
  const [locateNote, setLocateNote] = useState<string | null>(null);

  function focusFirst(next: Errors) {
    const first = ORDER.find((name) => next[name]);
    if (first) (form.current?.elements.namedItem(first) as HTMLElement | null)?.focus();
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (sending.current) return;

    const lat = latitude.trim();
    const lon = longitude.trim();
    const next: Errors = {};
    if (addressLine.trim() === "") next.address_line = "Enter the address.";
    if (landmark.trim() === "") next.landmark = "Enter a landmark riders can look for.";
    const latError = checkNumber(lat, 90, "latitude");
    const lonError = checkNumber(lon, 180, "longitude");
    if (latError) next.latitude = latError;
    if (lonError) next.longitude = lonError;
    setErrors(next);
    setProblem(null);
    if (Object.keys(next).length > 0) {
      focusFirst(next);
      return;
    }

    // The position travels in the body only (ADR-013).
    const body: Record<string, unknown> = {
      address_line: addressLine.trim(),
      landmark: landmark.trim(),
      tag: tag.trim() === "" ? null : tag.trim(),
      latitude: lat,
      longitude: lon,
    };
    if (!existing && makeDefault) body.is_default = true;
    if (!existing && body.tag === null) delete body.tag;

    sending.current = true;
    setSaving(true);
    const result = existing
      ? await apiSend("PATCH", `/api/v1/me/addresses/${existing.id}`, body)
      : await apiSend("POST", "/api/v1/me/addresses", body);

    if (result.ok) {
      sending.current = false;
      await onSaved();
      return;
    }

    sending.current = false;
    setSaving(false);
    const failure = result.failure;
    if (failure.kind === "signed_out") return onSignedOut();
    if (failure.kind === "not_found") return void (await onGone());
    if (failure.kind === "validation") {
      const refused: Errors = {};
      for (const name of ORDER) {
        const messages = failure.fields[name];
        if (messages && messages.length > 0) refused[name] = messages.join(" ");
      }
      if (Object.keys(refused).length === 0) {
        setProblem(describeFailure(failure));
      } else {
        setErrors(refused);
        focusFirst(refused);
      }
      return;
    }
    setProblem(describeFailure(failure));
  }

  function useDeviceLocation() {
    setLocateNote(null);

    if (!("geolocation" in navigator)) {
      setLocateNote("This browser cannot share its location. Enter the numbers instead.");
      return;
    }

    setLocating(true);
    // Asked only when this button is pressed, never on page load.
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setLocating(false);
        // Four decimals is about 11 metres: enough to place a point in a delivery area.
        setLatitude(position.coords.latitude.toFixed(4));
        setLongitude(position.coords.longitude.toFixed(4));
        setErrors((current) => ({ ...current, latitude: undefined, longitude: undefined }));
        setLocateNote("Filled in from this device. Check the numbers, then save.");
      },
      () => {
        setLocating(false);
        setLocateNote("We could not get this device's location. Enter the numbers instead.");
      },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 },
    );
  }

  function input(name: FieldName, label: string, value: string, set: (value: string) => void, extra: { hint?: string; mono?: boolean; maxLength?: number; required?: boolean; inputMode?: "decimal"; autoComplete?: string }) {
    const id = `${prefix}-${name}`;
    const describedBy = [extra.hint ? `${id}-hint` : null, errors[name] ? `${id}-error` : null].filter(Boolean).join(" ");

    return (
      <div className="flex flex-col gap-1.5">
        <label htmlFor={id} className="text-sm font-medium">
          {label}
          {extra.required ? null : <span className="font-normal text-text-secondary"> (optional)</span>}
        </label>
        <input
          id={id}
          name={name}
          value={value}
          onChange={(event) => set(event.target.value)}
          maxLength={extra.maxLength}
          inputMode={extra.inputMode}
          autoComplete={extra.autoComplete ?? "off"}
          aria-invalid={errors[name] ? true : undefined}
          aria-describedby={describedBy || undefined}
          className={`${field}${extra.mono ? " font-mono" : ""}`}
        />
        {extra.hint ? (
          <p id={`${id}-hint`} className="text-sm text-text-secondary">
            {extra.hint}
          </p>
        ) : null}
        {errors[name] ? (
          <p id={`${id}-error`} className="text-sm font-medium text-danger">
            {errors[name]}
          </p>
        ) : null}
      </div>
    );
  }

  return (
    <form ref={form} onSubmit={submit} noValidate aria-label={existing ? `Edit ${existing.tag ?? "address"}` : "Add an address"} className="flex flex-col gap-4">
      {existing ? null : <h3 className="font-semibold">Add an address</h3>}

      {input("address_line", "Address", addressLine, setAddressLine, { maxLength: 500, required: true, autoComplete: "street-address" })}
      {input("landmark", "Landmark", landmark, setLandmark, {
        maxLength: 255,
        required: true,
        hint: "Riders use this to find the place, for example a store, gate or building colour.",
      })}
      {input("tag", "Label", tag, setTag, { maxLength: 40, hint: "For example Home or Work." })}

      <fieldset className="flex flex-col gap-3">
        <legend className="text-sm font-medium">Position</legend>
        <p className="text-sm text-text-secondary">
          A map pin is not available yet. Type the two numbers or use this device&apos;s location. It is sent only when you save, never in a web address.
        </p>
        <div className="grid gap-4 sm:grid-cols-2">
          {input("latitude", "Latitude", latitude, setLatitude, { mono: true, required: true, inputMode: "decimal", hint: "North or south, from -90 to 90." })}
          {input("longitude", "Longitude", longitude, setLongitude, { mono: true, required: true, inputMode: "decimal", hint: "East or west, from -180 to 180." })}
        </div>
        <button type="button" onClick={useDeviceLocation} disabled={locating} className={`${button} self-start`}>
          {locating ? "Asking this device…" : "Use this device's location"}
        </button>
        <p className="text-sm text-text-secondary">Sharing your device&apos;s location is optional.</p>
        {locateNote ? (
          <p role="status" className="text-sm font-medium">
            {locateNote}
          </p>
        ) : null}
      </fieldset>

      {existing ? null : (
        <label className="flex min-h-11 items-center gap-3 text-base">
          <input
            type="checkbox"
            name="is_default"
            checked={makeDefault}
            onChange={(event) => setMakeDefault(event.target.checked)}
            className="size-5"
          />
          Make this my default
        </label>
      )}

      {problem ? (
        <p role="alert" className="text-sm font-medium text-danger">
          {problem}
        </p>
      ) : null}

      <div className="flex flex-wrap gap-2">
        <button type="submit" disabled={saving} aria-busy={saving} className={primary}>
          {saving ? "Saving…" : existing ? "Save changes" : "Save address"}
        </button>
        {onCancel ? (
          <button type="button" disabled={saving} onClick={onCancel} className={button}>
            Cancel
          </button>
        ) : null}
      </div>
    </form>
  );
}
