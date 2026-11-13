"use client";

import { useEffect, useRef, useState } from "react";

import { resolveMapState, type Bounds } from "./map-state";

// Map area for the web apps (backlog U-04, ADR-007).
//
// - Client only: nothing map-related runs during server rendering.
// - With no style URL it shows a labelled placeholder and loads nothing.
// - There is no default centre or zoom anywhere in this file. The view is only
//   ever fitted to the bounds of a shape the caller passes in.
// - Attribution is always rendered.
//
// TODO(U-04, ADR-007): maplibre-gl is NOT installed (the pinned version is chosen
// in Sprint 6, and adding a package needs the operator's approval), so
// `loadMapLibrary` reports "unavailable" and the component says so instead of
// pretending to draw. When the package is added, replace its body with
// `return await import("maplibre-gl");` and extend the CSP in packages/config
// (img-src, connect-src, worker-src) for the chosen style host.

type MapInstance = {
  fitBounds: (bounds: [[number, number], [number, number]], options?: Record<string, unknown>) => void;
  addControl: (control: unknown) => void;
  remove: () => void;
  on: (event: string, listener: () => void) => void;
};

export type MapLibre = {
  Map: new (options: Record<string, unknown>) => MapInstance;
  AttributionControl: new (options?: Record<string, unknown>) => unknown;
};

export type LoadMapLibrary = () => Promise<MapLibre | null>;

/** Dynamic import, called only when a style URL is set. Returns null while the library is not installed. */
export const loadMapLibrary: LoadMapLibrary = async () => {
  // TODO(U-04): when maplibre-gl is installed, return `await import("maplibre-gl")`.
  return null;
};

type Props = {
  /** The style URL from public config (`map_style_url`). Empty or null shows the placeholder. */
  styleUrl: string | null | undefined;
  /** A GeoJSON Polygon or MultiPolygon to fit the view to. Optional. */
  geometry?: unknown;
  label: string;
  /** Test seam, and where the real library is wired in. */
  load?: LoadMapLibrary;
};

const frame =
  "flex min-h-56 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border-strong bg-surface-2 p-4 text-center";

function BoundsSummary({ bounds }: { bounds: Bounds | null }) {
  return (
    <p data-testid="map-bounds" className="text-sm text-text-secondary">
      {bounds
        ? `Zone bounds: west ${bounds.west}, south ${bounds.south}, east ${bounds.east}, north ${bounds.north} (longitude, latitude).`
        : "No zone shape yet, so the map has no position. It is not centred anywhere."}
    </p>
  );
}

export function MapView({ styleUrl, geometry, label, load = loadMapLibrary }: Props) {
  const state = resolveMapState({ styleUrl, geometry });
  const bounds = state.bounds;
  const boundsKey = bounds ? `${bounds.west},${bounds.south},${bounds.east},${bounds.north}` : "";
  const mapUrl = state.kind === "map" ? state.styleUrl : null;
  const container = useRef<HTMLDivElement>(null);
  const [status, setStatus] = useState<"loading" | "ready" | "unavailable" | "failed">("loading");
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    if (mapUrl === null) return;
    let cancelled = false;
    let remove: (() => void) | null = null;
    setStatus("loading");

    load()
      .then((library) => {
        if (cancelled) return;
        if (library === null || container.current === null) {
          setStatus("unavailable");
          return;
        }
        // No `center` and no `zoom` are passed; the view comes from fitBounds only.
        const map = new library.Map({ container: container.current, style: mapUrl, attributionControl: false });
        map.addControl(new library.AttributionControl({ compact: false }));
        map.on("error", () => !cancelled && setStatus("failed"));
        map.on("load", () => !cancelled && setStatus("ready"));
        if (boundsKey !== "") {
          const [west, south, east, north] = boundsKey.split(",").map(Number);
          map.fitBounds([[west, south], [east, north]], { padding: 24, animate: false });
        }
        remove = () => map.remove();
      })
      .catch(() => !cancelled && setStatus("failed"));

    return () => {
      cancelled = true;
      remove?.();
    };
  }, [mapUrl, boundsKey, load, attempt]);

  const placeholder = state.kind === "placeholder";
  const mapDrawn = !placeholder && (status === "loading" || status === "ready");

  return (
    <figure
      aria-label={label}
      data-testid="map-view"
      data-map-state={placeholder ? "placeholder" : status}
      className="flex flex-col gap-2"
    >
      {placeholder ? (
        <div className={frame}>
          <p className="font-semibold text-text-primary">Map not configured</p>
          <p className="text-sm text-text-secondary">
            No map style is set (MAP_STYLE_URL is empty), so no map is drawn. The GeoJSON field is the complete way to edit
            this shape.
          </p>
        </div>
      ) : mapDrawn ? (
        <div ref={container} aria-busy={status === "loading"} className="min-h-56 rounded-xl border border-border-strong bg-surface-2" />
      ) : (
        <div className={frame}>
          <p className="font-semibold text-text-primary">
            {status === "failed" ? "The map could not load" : "Map library not installed"}
          </p>
          <p className="text-sm text-text-secondary">
            {status === "failed"
              ? "The map style did not load. You can still enter and save the shape as GeoJSON."
              : "A map style is set but the map library is not installed in this build. You can still enter and save the shape as GeoJSON."}
          </p>
          {status === "failed" ? (
            <button
              type="button"
              onClick={() => setAttempt((n) => n + 1)}
              className="min-h-11 rounded-xl border border-border-strong bg-surface px-4 font-medium text-text-primary"
            >
              Retry map
            </button>
          ) : null}
        </div>
      )}
      <BoundsSummary bounds={bounds} />
      <figcaption data-testid="map-attribution" className="text-sm text-text-secondary">
        {mapDrawn
          ? "Map attribution: data and style credits are shown on the map, bottom right."
          : "Map attribution: no map tiles are loaded, so none applies yet. It is shown here whenever a style is in use."}
      </figcaption>
    </figure>
  );
}
