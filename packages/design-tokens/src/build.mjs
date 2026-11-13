import { writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { renderCss, renderDart, renderReport } from "./generate.mjs";

const at = (relative) => fileURLToPath(new URL(relative, import.meta.url));

writeFileSync(at("../tokens.css"), renderCss());
writeFileSync(at("../../../docs/ux/contrast-report.md"), renderReport());
writeFileSync(at("../../../apps/rider-flutter/lib/core/tokens.g.dart"), renderDart());

console.log("Wrote tokens.css, the contrast report and the rider app's tokens.g.dart");
