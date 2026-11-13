// Types only. The request helpers still live in @food-delivery/session;
// moving them onto these types is tracked in docs/backlog.md (C-11).
import type { components, operations, paths } from "./schema";

export type { components, operations, paths };

export type Schemas = components["schemas"];
export type City = Schemas["City"];
export type PublicCity = Schemas["PublicCity"];
export type Zone = Schemas["Zone"];
export type Exclusion = Schemas["Exclusion"];
export type AuditEntry = Schemas["AuditEntry"];
export type Serviceability = Schemas["Serviceability"];
