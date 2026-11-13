import { createServer } from "node:http";

// Stand-in for the API that the Next.js servers call while rendering
// (customer `/` banner: GET /api/v1/public/config; admin `/` row: GET /health/live).
// page.route() cannot intercept those calls because they leave the Next.js server, not the browser.
//
// Default: config says "closed" and liveness answers 200. A test changes what a route does with
//   POST /__mode  {"config": "<mode>", "health": "<mode>"}   (either key may be omitted; "ok" resets)
// Modes: ok, open (config only), error (HTTP 500), garbage (200, not the expected body),
//        slow (answers after 1.2 s), hang (never answers), drop (closes the socket, like a dead port).
// Tests that use it live in tests/browser/server-fetch-states.spec.ts and run one at a time (see playwright.config.ts).
const modes = { config: "ok", health: "ok" };
const known = new Set(["ok", "open", "error", "garbage", "slow", "hang", "drop"]);

function answer(response, mode, body) {
  if (mode === "error") return response.writeHead(500).end();
  if (mode === "garbage") return response.writeHead(200, { "Content-Type": "text/plain" }).end("<html>not json</html>");
  response.writeHead(200, { "Content-Type": "application/json" }).end(JSON.stringify(body));
}

function serve(request, response, mode, body) {
  if (mode === "drop") return request.socket.destroy();
  if (mode === "hang") return; // the caller's timeout ends it
  if (mode === "slow") return void setTimeout(() => answer(response, "ok", body), 1200);
  return answer(response, mode, body);
}

createServer((request, response) => {
  if (request.url === "/__mode" && request.method === "POST") {
    let raw = "";
    request.on("data", (chunk) => (raw += chunk));
    request.on("end", () => {
      try {
        const next = JSON.parse(raw);
        for (const key of ["config", "health"]) {
          if (key in next && known.has(next[key])) modes[key] = next[key];
        }
        response.writeHead(200, { "Content-Type": "application/json" }).end(JSON.stringify(modes));
      } catch {
        response.writeHead(400).end();
      }
    });
    return;
  }

  if (request.method === "GET" && request.url === "/api/v1/public/config") {
    return serve(request, response, modes.config, { data: { service_open: modes.config === "open" } });
  }
  if (request.method === "GET" && request.url === "/health/live") {
    return serve(request, response, modes.health, { status: "ok" });
  }
  response.writeHead(404).end();
}).listen(3089, "127.0.0.1");
