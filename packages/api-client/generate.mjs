// Generates src/schema.d.ts from the OpenAPI document. With --check it
// writes nothing and exits 1 if the committed file differs.
import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { createConfig } from "@redocly/openapi-core";
import openapiTS, { astToString } from "openapi-typescript";

const source = new URL("../../backend/openapi/openapi.yaml", import.meta.url);
const target = new URL("./src/schema.d.ts", import.meta.url);

const header =
  "// GENERATED from backend/openapi/openapi.yaml. Do not edit.\n" +
  "// Regenerate: pnpm --filter @food-delivery/api-client generate\n\n";

export async function render(document = source) {
  const redocly = await createConfig({
    rules: {
      struct: "error",
      "no-unresolved-refs": "error",
      "operation-operationId-unique": "error",
    },
  });
  const ast = await openapiTS(document, { redocly });
  return (header + astToString(ast)).replace(/\r\n/g, "\n");
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const output = await render();
  if (process.argv.includes("--check")) {
    const current = (await readFile(target, "utf8").catch(() => "")).replace(/\r\n/g, "\n");
    if (current !== output) {
      console.error("src/schema.d.ts is out of date with backend/openapi/openapi.yaml. Run the generate script.");
      process.exit(1);
    }
  } else {
    await writeFile(target, output);
  }
}
