import { createServer } from "node:http";

createServer((request, response) => {
  if (request.method !== "GET" || request.url !== "/api/v1/public/config") {
    response.writeHead(404).end();
    return;
  }

  response.writeHead(200, { "Content-Type": "application/json" });
  response.end(JSON.stringify({ data: { service_open: false } }));
}).listen(3089, "127.0.0.1");
