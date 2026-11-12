# ADR-009: Frontend workspace

- Status: accepted
- Date: 2026-11-12

## Decision
- One pnpm workspace holds `apps/customer-web`, `apps/merchant-web`, `apps/admin-web` and `packages/{design-tokens,ui,api-client,schemas,config}`.
- All three web apps are Next.js App Router with TypeScript strict mode. This follows the spec over the master plan (ADR-000 item 1). Each app is a separate origin with its own build; admin code is never bundled into the customer app.
- Apps import shared code only from `packages/`. An ESLint rule forbids imports from another app's directory.
- `packages/ui` holds the React components (shadcn/ui based) and Storybook. `packages/design-tokens` is the single source of colours, type and spacing; the token palette in the spec is a starting point whose contrast must be measured before use.
- `packages/api-client` is generated from the OpenAPI document and never edited by hand.
- `apps/rider-flutter` is outside the pnpm workspace with its own `pubspec.lock` and CI workflow. It uses a handwritten API wrapper with serialization tests in Phase 1.
- The pnpm version is pinned through `packageManager`. Dependency versions are exact in the lockfile; TypeScript's major version is chosen in Sprint 2 after checking toolchain support (see `versions.md`).

## Consequences
Three Next.js apps cost more build time than one, in exchange for hard separation of the admin surface.
