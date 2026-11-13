import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import { render } from "../generate.mjs";

test("generated types match the OpenAPI document", async () => {
  const committed = (await readFile(new URL("../src/schema.d.ts", import.meta.url), "utf8")).replace(/\r\n/g, "\n");
  assert.equal(committed, await render(), "Run: pnpm --filter @food-delivery/api-client generate");
});

test("an invalid response description fails OpenAPI generation", async () => {
  await assert.rejects(render({
    openapi: "3.1.0",
    info: { title: "Synthetic contract", version: "1" },
    paths: {
      "/test": {
        get: { operationId: "test", responses: { 200: { description: false } } },
      },
    },
  }), /Expected type `string`/i);
});

test("every documented operation is typed", async () => {
  const yaml = await readFile(new URL("../../../backend/openapi/openapi.yaml", import.meta.url), "utf8");
  const types = await readFile(new URL("../src/schema.d.ts", import.meta.url), "utf8");
  const ids = [...yaml.matchAll(/^\s+operationId:\s*(\w+)/gm)].map((m) => m[1]);

  assert.ok(ids.length > 20);
  for (const id of ids) {
    assert.ok(types.includes(`operations["${id}"]`), `operation ${id} is missing from the generated types`);
  }
});
