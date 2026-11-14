import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

import { cleanStyleUrl, geometryBounds, polygonFromPoints, resolveMapState } from "../src/map-state.ts";

// Synthetic shape, not a real place.
const square = { type: "Polygon", coordinates: [[[10, 20], [12, 20], [12, 23], [10, 23], [10, 20]]] };

test("an empty or missing style URL gives the placeholder", () => {
  for (const styleUrl of ["", "   ", null, undefined, 5]) {
    assert.equal(resolveMapState({ styleUrl }).kind, "placeholder");
  }
});

test("only an https style URL turns the map on", () => {
  assert.equal(cleanStyleUrl("http://example.test/style.json"), null);
  assert.equal(cleanStyleUrl("javascript:alert(1)"), null);
  assert.equal(cleanStyleUrl("not a url"), null);
  assert.equal(resolveMapState({ styleUrl: " https://example.test/style.json " }).kind, "map");
});

test("there is no default centre: no shape means no bounds and no centre field", () => {
  for (const state of [resolveMapState({ styleUrl: "" }), resolveMapState({ styleUrl: "https://example.test/s.json" })]) {
    assert.equal(state.bounds, null);
    assert.ok(!("center" in state) && !("zoom" in state));
  }
});

test("bounds come only from the supplied shape", () => {
  assert.deepEqual(geometryBounds(square), { west: 10, south: 20, east: 12, north: 23 });
  assert.deepEqual(
    geometryBounds({ type: "MultiPolygon", coordinates: [square.coordinates, [[[-5, -6], [-4, -6], [-4, -5], [-5, -6]]]] }),
    { west: -5, south: -6, east: 12, north: 23 },
  );
  assert.equal(geometryBounds({ type: "Point", coordinates: [1, 2] }), null);
  assert.equal(geometryBounds({ type: "Polygon", coordinates: [] }), null);
  assert.equal(geometryBounds({ type: "Polygon", coordinates: [[[500, 500], [501, 501]]] }), null);
  assert.equal(geometryBounds(undefined), null);
});

test("the sources hold no centre, zoom or coordinate literals", () => {
  for (const file of ["../src/map-view.tsx", "../src/map-state.ts"]) {
    const source = readFileSync(new URL(file, import.meta.url), "utf8");
    assert.doesNotMatch(source, /\bcenter\s*:|\bzoom\s*:|setCenter|setZoom|flyTo|jumpTo/, file);
    assert.doesNotMatch(source, /-?\d{1,3}\.\d{3,}/, `${file}: decimal coordinate literal`);
  }
});

test("the view always renders an attribution element", () => {
  const source = readFileSync(new URL("../src/map-view.tsx", import.meta.url), "utf8");
  assert.match(source, /data-testid="map-attribution"/);
  assert.match(source, /AttributionControl/);
});

test("polygonFromPoints needs 3 points, closes the ring and rounds to 6 decimals", () => {
  assert.equal(polygonFromPoints([]), null);
  assert.equal(polygonFromPoints([[1, 2], [3, 4]]), null);
  const polygon = polygonFromPoints([[1.123456789, 2.1], [3, 4.9999999], [5, 6]]);
  assert.deepEqual(polygon, {
    type: "Polygon",
    coordinates: [[[1.123457, 2.1], [3, 5], [5, 6], [1.123457, 2.1]]],
  });
});
