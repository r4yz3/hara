// Builds the self-hosted map style JSON (ADR-015, docs/operations/map-tiles.md).
//
// Operator tool, run on a workstation. It is NOT part of any web build and the web
// apps do not depend on it. It needs @protomaps/basemaps, which is installed into a
// scratch folder outside the repository (see the runbook), so no dependency is added
// to the workspace. After that install it runs with no network.
//
// The style holds no coordinates: no centre, zoom, bounds or bounding box. The map
// component fits the view to the zone shape (packages/ui/src/map-view.tsx).
//
// Usage (all values are public):
//   node infra/map/build-style.mjs --basemaps <path to @protomaps/basemaps/dist/esm/index.js>
//        --tiles-origin https://tiles.example.com --pmtiles hara-staging.pmtiles
//        [--flavor light] [--lang en] [--out style.json]

import { writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

const FLAVORS = ["light", "dark", "white", "black", "grayscale"];

function args(argv) {
  const out = {};
  for (let i = 0; i < argv.length; i += 2) {
    const key = argv[i];
    if (!key?.startsWith("--") || argv[i + 1] === undefined) throw new Error(`Bad argument near "${key ?? ""}"`);
    out[key.slice(2)] = argv[i + 1];
  }
  return out;
}

/** The style object. Pure, so it can be tested; `basemaps` is the loaded @protomaps/basemaps module. */
export function buildStyle({ basemaps, tilesOrigin, pmtiles, flavor = "light", lang = "en" }) {
  const origin = new URL(tilesOrigin);
  if (origin.protocol !== "https:" || origin.pathname !== "/" || origin.search !== "" || origin.hash !== "") {
    throw new Error("--tiles-origin must be a bare https origin such as https://tiles.example.com");
  }
  if (!/^[A-Za-z0-9._-]+\.pmtiles$/.test(pmtiles)) throw new Error("--pmtiles must be a plain file name ending in .pmtiles");
  if (!FLAVORS.includes(flavor)) throw new Error(`--flavor must be one of ${FLAVORS.join(", ")}`);
  const base = origin.origin;
  return {
    version: 8,
    glyphs: `${base}/fonts/{fontstack}/{range}.pbf`,
    sprite: `${base}/sprites/v4/${flavor}`,
    sources: {
      protomaps: {
        type: "vector",
        url: `pmtiles://${base}/${pmtiles}`,
        attribution:
          '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a> · <a href="https://protomaps.com" target="_blank" rel="noopener noreferrer">Protomaps</a>',
      },
    },
    layers: basemaps.layers("protomaps", basemaps.namedFlavor(flavor), { lang }),
  };
}

const entry = process.argv[1] ? pathToFileURL(resolve(process.argv[1])).href : "";
if (import.meta.url === entry) {
  const a = args(process.argv.slice(2));
  for (const required of ["basemaps", "tiles-origin", "pmtiles"]) {
    if (!a[required]) throw new Error(`Missing --${required}`);
  }
  const basemaps = await import(pathToFileURL(resolve(a.basemaps)).href);
  const style = buildStyle({ basemaps, tilesOrigin: a["tiles-origin"], pmtiles: a.pmtiles, flavor: a.flavor, lang: a.lang });
  const file = a.out ?? "style.json";
  writeFileSync(file, `${JSON.stringify(style)}\n`);
  console.log(`wrote ${file}: ${style.layers.length} layers`);
}
