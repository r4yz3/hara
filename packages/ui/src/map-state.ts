// Pure decisions for the map component (ADR-007). No React, no map library, so
// they are unit-tested directly. Nothing here may contain a coordinate: the view
// is derived only from the style URL and from the zone shape the admin supplies.

export type Bounds = { west: number; south: number; east: number; north: number };

export type MapState =
  | { kind: "placeholder"; reason: "no-style"; bounds: Bounds | null }
  | { kind: "map"; styleUrl: string; bounds: Bounds | null };

function isPosition(value: unknown): value is [number, number] {
  return (
    Array.isArray(value) &&
    value.length >= 2 &&
    typeof value[0] === "number" &&
    typeof value[1] === "number" &&
    Number.isFinite(value[0]) &&
    Number.isFinite(value[1])
  );
}

function visit(value: unknown, into: Bounds | null): Bounds | null {
  if (isPosition(value)) {
    const [x, y] = value;
    if (x < -180 || x > 180 || y < -90 || y > 90) return into;
    return into === null
      ? { west: x, south: y, east: x, north: y }
      : {
          west: Math.min(into.west, x),
          south: Math.min(into.south, y),
          east: Math.max(into.east, x),
          north: Math.max(into.north, y),
        };
  }
  if (Array.isArray(value)) {
    let next = into;
    for (const item of value) next = visit(item, next);
    return next;
  }
  return into;
}

/** Bounds of a GeoJSON Polygon or MultiPolygon, or null when there is no usable shape. */
export function geometryBounds(geometry: unknown): Bounds | null {
  if (typeof geometry !== "object" || geometry === null) return null;
  const { type, coordinates } = geometry as { type?: unknown; coordinates?: unknown };
  if (type !== "Polygon" && type !== "MultiPolygon") return null;
  return visit(coordinates, null);
}

/** Only an https URL counts as a style. Empty, missing or anything else means placeholder. */
export function cleanStyleUrl(value: unknown): string | null {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  if (trimmed === "") return null;
  try {
    return new URL(trimmed).protocol === "https:" ? trimmed : null;
  } catch {
    return null;
  }
}

export function resolveMapState(input: { styleUrl: unknown; geometry?: unknown }): MapState {
  const bounds = geometryBounds(input.geometry);
  const styleUrl = cleanStyleUrl(input.styleUrl);
  return styleUrl === null ? { kind: "placeholder", reason: "no-style", bounds } : { kind: "map", styleUrl, bounds };
}
