"use client";

import { useEffect, useRef, useState } from "react";

import { geometryBounds, polygonFromPoints, resolveMapState, type Bounds, type Position } from "./map-state";

export { polygonFromPoints };

// Map area for the web apps (backlog U-04, ADR-007).
//
// - Client only: nothing map-related runs during server rendering.
// - With no style URL it shows a labelled placeholder and loads nothing.
// - There is no default centre or zoom anywhere in this file. The view is only
//   ever fitted to the bounds of a shape the caller passes in.
// - Attribution is always rendered.
//
// Tiles: self-hosted Protomaps PMTiles (ADR-015). The style at MAP_STYLE_URL
// may reference `pmtiles://https://...` sources; the protocol is registered
// once, the first time the library loads. The CSS is imported by the app.

type MapClick = { lngLat: { lng: number; lat: number } };
type GeoSource = { setData: (data: unknown) => void };

type MapInstance = {
  fitBounds: (bounds: [[number, number], [number, number]], options?: Record<string, unknown>) => void;
  addControl: (control: unknown) => void;
  remove: () => void;
  on: (event: string, listener: (event: MapClick) => void) => void;
  addSource: (id: string, source: Record<string, unknown>) => void;
  getSource: (id: string) => GeoSource | undefined;
  addLayer: (layer: Record<string, unknown>) => void;
  getCanvas: () => HTMLCanvasElement;
};

/** The zone shape and the points placed so far, as one GeoJSON collection for the overlay. */
function overlay(geometry: unknown, points: Position[]) {
  const features: unknown[] = [];
  if (geometryBounds(geometry) !== null) features.push({ type: "Feature", properties: { kind: "zone" }, geometry });
  if (points.length >= 2) {
    features.push({ type: "Feature", properties: { kind: "draft" }, geometry: { type: "LineString", coordinates: points } });
  }
  for (const point of points) {
    features.push({ type: "Feature", properties: { kind: "draft" }, geometry: { type: "Point", coordinates: point } });
  }
  return { type: "FeatureCollection", features };
}

export type MapLibre = {
  Map: new (options: Record<string, unknown>) => MapInstance;
  AttributionControl: new (options?: Record<string, unknown>) => unknown;
};

export type LoadMapLibrary = () => Promise<MapLibre | null>;

let registered = false;

/** Dynamic import, called only when a style URL is set, so nothing map-related is in the server bundle. */
export const loadMapLibrary: LoadMapLibrary = async () => {
  const [maplibre, pmtiles] = await Promise.all([import("maplibre-gl"), import("pmtiles")]);
  if (!registered) {
    // MapLibre finds its worker next to its own module, which the bundler
    // moves; this reference makes the bundler emit the worker as an asset.
    maplibre.setWorkerUrl(new URL("maplibre-gl/dist/maplibre-gl-worker.mjs", import.meta.url).href);
    const protocol = new pmtiles.Protocol();
    maplibre.addProtocol("pmtiles", protocol.tile);
    registered = true;
  }
  return maplibre as unknown as MapLibre;
};

type Props = {
  /** The style URL from public config (`map_style_url`). Empty or null shows the placeholder. */
  styleUrl: string | null | undefined;
  /** A GeoJSON Polygon or MultiPolygon to fit the view to. Optional. */
  geometry?: unknown;
  label: string;
  /** Test seam, and where the real library is wired in. */
  load?: LoadMapLibrary;
  /** When set, the map offers drawing a polygon and hands the result here. Omit to make the map view-only. */
  onDraw?: (geometry: { type: "Polygon"; coordinates: Position[][] }) => void;
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

export function MapView({ styleUrl, geometry, label, load = loadMapLibrary, onDraw }: Props) {
  const state = resolveMapState({ styleUrl, geometry });
  const bounds = state.bounds;
  const boundsKey = bounds ? `${bounds.west},${bounds.south},${bounds.east},${bounds.north}` : "";
  const mapUrl = state.kind === "map" ? state.styleUrl : null;
  const container = useRef<HTMLDivElement>(null);
  const [status, setStatus] = useState<"loading" | "ready" | "unavailable" | "failed">("loading");
  const [attempt, setAttempt] = useState(0);
  const mapRef = useRef<MapInstance | null>(null);
  const [drawing, setDrawing] = useState(false);
  const [points, setPoints] = useState<Position[]>([]);
  const drawingRef = useRef(false);
  const geometryRef = useRef<unknown>(geometry);
  useEffect(() => {
    drawingRef.current = drawing;
    geometryRef.current = geometry;
  });

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
        map.on("load", () => {
          if (cancelled) return;
          const zone = ["==", ["get", "kind"], "zone"];
          const draft = (shape: string) => ["all", ["==", ["get", "kind"], "draft"], ["==", ["geometry-type"], shape]];
          map.addSource("overlay", { type: "geojson", data: overlay(geometryRef.current, []) });
          map.addLayer({ id: "zone-fill", type: "fill", source: "overlay", filter: zone, paint: { "fill-color": "#1d4ed8", "fill-opacity": 0.15 } });
          map.addLayer({ id: "zone-line", type: "line", source: "overlay", filter: zone, paint: { "line-color": "#1d4ed8", "line-width": 2 } });
          map.addLayer({ id: "draft-line", type: "line", source: "overlay", filter: draft("LineString"), paint: { "line-color": "#b91c1c", "line-width": 2, "line-dasharray": [2, 1] } });
          map.addLayer({ id: "draft-points", type: "circle", source: "overlay", filter: draft("Point"), paint: { "circle-radius": 5, "circle-color": "#b91c1c" } });
          mapRef.current = map;
          setStatus("ready");
        });
        map.on("click", (event) => {
          if (!drawingRef.current) return;
          const { lng, lat } = event.lngLat;
          setPoints((current) => [...current, [lng, lat]]);
        });
        if (boundsKey !== "") {
          const [west, south, east, north] = boundsKey.split(",").map(Number);
          map.fitBounds([[west, south], [east, north]], { padding: 24, animate: false });
        }
        remove = () => map.remove();
      })
      .catch(() => !cancelled && setStatus("failed"));

    return () => {
      cancelled = true;
      mapRef.current = null;
      remove?.();
    };
  }, [mapUrl, boundsKey, load, attempt]);

  // Keep the overlay in step with the shape text and the points placed so far.
  const geometryKey = JSON.stringify(geometry ?? null);
  useEffect(() => {
    const map = mapRef.current;
    if (map === null) return;
    map.getSource("overlay")?.setData(overlay(geometryRef.current, points));
    map.getCanvas().style.cursor = drawing ? "crosshair" : "";
  }, [geometryKey, points, drawing, status]);

  const stopDrawing = () => {
    setDrawing(false);
    setPoints([]);
  };
  const finish = () => {
    const polygon = polygonFromPoints(points);
    if (polygon === null || onDraw === undefined) return;
    onDraw(polygon);
    stopDrawing();
  };
  const button = "min-h-11 rounded-xl border border-border-strong bg-surface px-4 font-medium text-text-primary disabled:opacity-60";

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
      {onDraw !== undefined && status === "ready" && !placeholder ? (
        <div role="group" aria-label="Draw a shape" className="flex flex-col gap-2">
          {drawing ? (
            <>
              <p data-testid="map-draw-status" aria-live="polite" className="text-sm text-text-secondary">
                {points.length === 0
                  ? "Click the map to place the first corner."
                  : `${points.length} ${points.length === 1 ? "corner" : "corners"} placed.${points.length < 3 ? " A shape needs at least 3." : ""}`}
              </p>
              <div className="flex flex-wrap gap-2">
                <button type="button" onClick={finish} disabled={points.length < 3} className={button}>
                  Use this shape
                </button>
                <button type="button" onClick={() => setPoints((current) => current.slice(0, -1))} disabled={points.length === 0} className={button}>
                  Remove last corner
                </button>
                <button type="button" onClick={stopDrawing} className={button}>
                  Cancel drawing
                </button>
              </div>
            </>
          ) : (
            <div className="flex flex-col gap-1">
              <button type="button" onClick={() => setDrawing(true)} className={`${button} w-fit`}>
                Draw shape on map
              </button>
              <p className="text-sm text-text-secondary">
                Drawing needs a pointer; the GeoJSON field does the same job from the keyboard. A drawn shape replaces the
                field&apos;s text, and nothing is saved until you submit.
              </p>
            </div>
          )}
        </div>
      ) : null}
      <BoundsSummary bounds={bounds} />
      <figcaption data-testid="map-attribution" className="text-sm text-text-secondary">
        {mapDrawn
          ? "Map attribution: data and style credits are shown on the map, bottom right."
          : "Map attribution: no map tiles are loaded, so none applies yet. It is shown here whenever a style is in use."}
      </figcaption>
    </figure>
  );
}
