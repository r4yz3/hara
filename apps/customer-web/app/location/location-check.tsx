"use client";

import { useRef, useState, type FormEvent } from "react";

import { apiSend, describeFailure } from "@food-delivery/session";

type Named = { id: string; name: string };
type Decision = { serviceable: boolean; reason: "NO_ACTIVE_ZONE" | "OUTSIDE_COVERAGE" | null; city: Named | null; zone: Named | null };

type Outcome =
  | { kind: "idle" }
  | { kind: "decided"; decision: Decision }
  | { kind: "problem"; text: string };

type FieldErrors = { latitude?: string; longitude?: string };

// The same shape the API accepts: a plain decimal, no exponent.
const DECIMAL = /^-?\d{1,3}(\.\d{1,12})?$/;

function check(value: string, limit: number, name: string): string | undefined {
  if (value === "") return `Enter the ${name}.`;
  if (!DECIMAL.test(value)) return `The ${name} must be a plain number such as 12.345.`;
  if (Math.abs(Number(value)) > limit) return `The ${name} must be between -${limit} and ${limit}.`;

  return undefined;
}

const field = "min-h-12 w-full rounded-xl border border-border-strong bg-surface px-4 font-mono text-base text-text-primary";

/**
 * Asks the API whether a position is inside an open delivery area.
 * The position stays in this form: it is not stored in the browser, and the
 * API neither stores nor logs it.
 */
export function LocationCheck() {
  const [latitude, setLatitude] = useState("");
  const [longitude, setLongitude] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [outcome, setOutcome] = useState<Outcome>({ kind: "idle" });
  const [busy, setBusy] = useState(false);
  const [locating, setLocating] = useState(false);
  const [locateNote, setLocateNote] = useState<string | null>(null);
  const latitudeRef = useRef<HTMLInputElement>(null);
  const longitudeRef = useRef<HTMLInputElement>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;

    const lat = latitude.trim();
    const lon = longitude.trim();
    const next: FieldErrors = { latitude: check(lat, 90, "latitude"), longitude: check(lon, 180, "longitude") };
    setErrors(next);
    setOutcome({ kind: "idle" });

    if (next.latitude || next.longitude) {
      (next.latitude ? latitudeRef : longitudeRef).current?.focus();
      return;
    }

    setBusy(true);
    // In the request body, never the URL: a position in a query string is
    // written to access logs along the way (ADR-013).
    const result = await apiSend<{ data: Decision }>("POST", "/api/v1/locations/serviceability", {
      latitude: lat,
      longitude: lon,
    });
    setBusy(false);

    if (result.ok) {
      setOutcome({ kind: "decided", decision: result.value.data });
      return;
    }

    if (result.failure.kind === "validation") {
      setErrors({ latitude: result.failure.fields.latitude?.join(" "), longitude: result.failure.fields.longitude?.join(" ") });
      // The server rejected a value: put focus on the first one it named.
      (result.failure.fields.latitude ? latitudeRef : result.failure.fields.longitude ? longitudeRef : null)?.current?.focus();
    }
    setOutcome({ kind: "problem", text: describeFailure(result.failure) });
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
        // Four decimals is about 11 metres: enough to place a point in a
        // delivery area, and no more precise than the question needs.
        setLatitude(position.coords.latitude.toFixed(4));
        setLongitude(position.coords.longitude.toFixed(4));
        setLocateNote("Filled in from this device. Check the numbers, then press Check.");
      },
      () => {
        setLocating(false);
        setLocateNote("We could not get this device's location. Enter the numbers instead.");
      },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 },
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4 rounded-3xl border border-border bg-surface p-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="latitude" className="text-sm font-medium">
              Latitude
            </label>
            <input
              ref={latitudeRef}
              id="latitude"
              value={latitude}
              onChange={(event) => setLatitude(event.target.value)}
              inputMode="decimal"
              autoComplete="off"
              aria-invalid={errors.latitude ? true : undefined}
              aria-describedby="latitude-hint latitude-error"
              className={field}
            />
            <p id="latitude-hint" className="text-sm text-text-secondary">
              North or south, from -90 to 90.
            </p>
            {errors.latitude ? (
              <p id="latitude-error" className="text-sm font-medium text-danger">
                {errors.latitude}
              </p>
            ) : null}
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="longitude" className="text-sm font-medium">
              Longitude
            </label>
            <input
              ref={longitudeRef}
              id="longitude"
              value={longitude}
              onChange={(event) => setLongitude(event.target.value)}
              inputMode="decimal"
              autoComplete="off"
              aria-invalid={errors.longitude ? true : undefined}
              aria-describedby="longitude-hint longitude-error"
              className={field}
            />
            <p id="longitude-hint" className="text-sm text-text-secondary">
              East or west, from -180 to 180.
            </p>
            {errors.longitude ? (
              <p id="longitude-error" className="text-sm font-medium text-danger">
                {errors.longitude}
              </p>
            ) : null}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <button
            type="submit"
            disabled={busy}
            aria-busy={busy}
            className="min-h-12 rounded-full bg-cta px-6 font-semibold text-on-cta disabled:opacity-70"
          >
            {busy ? "Checking…" : "Check"}
          </button>
          <button
            type="button"
            onClick={useDeviceLocation}
            disabled={locating}
            className="min-h-12 rounded-full border border-border-strong bg-surface px-5 font-medium disabled:opacity-70"
          >
            {locating ? "Asking this device…" : "Use this device's location"}
          </button>
        </div>

        <p className="text-sm text-text-secondary">
          Sharing your device&apos;s location is optional. The position is used for this one answer and is not saved.
        </p>
        {locateNote ? (
          <p role="status" className="text-sm font-medium">
            {locateNote}
          </p>
        ) : null}
      </form>

      <div aria-live="polite">
        {outcome.kind === "decided" ? <Result decision={outcome.decision} /> : null}
        {outcome.kind === "problem" ? (
          <p role="alert" className="rounded-3xl border border-border-strong bg-surface p-5 font-medium text-danger">
            {outcome.text}
          </p>
        ) : null}
      </div>
    </div>
  );
}

function Result({ decision }: { decision: Decision }) {
  if (decision.serviceable && decision.city && decision.zone) {
    return (
      <div className="flex flex-col gap-1 rounded-3xl border border-success bg-surface p-5">
        <p className="text-lg font-semibold text-success">✓ Yes, this spot is inside a delivery area</p>
        <p>
          {decision.zone.name}, {decision.city.name}.
        </p>
        <p className="text-sm text-text-secondary">Ordering is not built yet, so there is nothing to order here today.</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-1 rounded-3xl border border-border-strong bg-surface p-5">
      <p className="text-lg font-semibold">
        {decision.reason === "NO_ACTIVE_ZONE" ? "We are not delivering anywhere yet" : "Not yet: this spot is outside our delivery areas"}
      </p>
      <p className="text-text-secondary">
        {decision.reason === "NO_ACTIVE_ZONE"
          ? "No delivery area has been opened."
          : "We do not deliver to this spot yet. There is no waiting list to join yet."}
      </p>
    </div>
  );
}
