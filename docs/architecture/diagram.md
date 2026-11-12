# Architecture diagram

Phase 1 view. Dotted edges are future integrations that do not exist yet. Source: spec section 2.

```mermaid
flowchart TD
  C[Customer Next.js PWA] --> EDGE[Reverse proxy / WAF / TLS]
  M[Merchant Next.js portal] --> EDGE
  A[Admin and dispatch Next.js portal] --> EDGE
  R[Flutter rider Android] --> EDGE
  EDGE --> API[Laravel 13 JSON API]
  API --> ID[Identity and scoped authorization]
  API --> GEO[Cities, zones, serviceability]
  API --> MER[Merchant shell]
  API -.future.-> ORD[Orders and payments]
  API -.future.-> DIS[Dispatch and tracking]
  API --> DB[(PostgreSQL + PostGIS)]
  API --> REDIS[(Redis: cache, queue, sessions)]
  API --> OBJ[(S3-compatible storage)]
  API --> OUT[Outbox table]
  DIS -.future.-> ROUTE[Valhalla]
  C --> MAP[MapLibre map]
  A --> MAP
  MAP --> TILE[Licensed style and tiles, or placeholder]
  OUT -.future.-> EVT[Workers, WebSocket, notifications]
```

Decisions behind each box are in `decisions/`. Trust boundaries are in `docs/security/threat-model.md`.
