# Production image for one Next.js web app (backlog E-07). One file builds all
# three apps; choose with --build-arg APP=customer-web|merchant-web|admin-web.
# Build from the repository root:
#   docker build -f infra/docker/prod/web.Dockerfile \
#     --build-arg APP=customer-web \
#     --build-arg NEXT_PUBLIC_API_URL=https://<api host> \
#     -t food-delivery-customer-web:<release> .
#
# Next.js `output: "standalone"` (switched on by NEXT_OUTPUT below; see each
# app's next.config.ts) copies only the files the server needs, so the final
# image holds no pnpm, no source and no dev dependencies, and runs as the
# unprivileged `node` user.
#
# NEXT_PUBLIC_* values are written into the browser bundle at build time, so
# they are build arguments, not run-time settings. They are public by
# definition: never pass a secret through them. The image holds no secret.
#
# Not yet run on a host (E-07). Base images are pinned by tag, not digest;
# build a release once and run that same image everywhere.

ARG NODE_IMAGE=node:24.11.1-alpine3.23

FROM ${NODE_IMAGE} AS base
# apk upgrade first: the base image can lag behind Alpine's security fixes.
RUN apk upgrade --no-cache
WORKDIR /repo

FROM base AS build
# Same pnpm as package.json "packageManager".
RUN npm install --global --no-fund --no-audit pnpm@9.15.9
ARG APP
RUN test -n "$APP" || (echo "Pass --build-arg APP=customer-web|merchant-web|admin-web" >&2; exit 1)

# Manifests first, so the install layer is reused until a dependency changes.
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY apps/customer-web/package.json apps/customer-web/package.json
COPY apps/merchant-web/package.json apps/merchant-web/package.json
COPY apps/admin-web/package.json apps/admin-web/package.json
COPY packages/api-client/package.json packages/api-client/package.json
COPY packages/config/package.json packages/config/package.json
COPY packages/design-tokens/package.json packages/design-tokens/package.json
COPY packages/schemas/package.json packages/schemas/package.json
COPY packages/session/package.json packages/session/package.json
COPY packages/ui/package.json packages/ui/package.json
RUN pnpm install --frozen-lockfile --filter "${APP}..."

COPY apps ./apps
COPY packages ./packages

ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_MAP_STYLE_URL=""
ARG NEXT_PUBLIC_APP_NAME
RUN test -n "$NEXT_PUBLIC_API_URL" || (echo "Pass --build-arg NEXT_PUBLIC_API_URL=https://<api host>" >&2; exit 1)
ENV NEXT_OUTPUT=standalone \
    NEXT_TELEMETRY_DISABLED=1 \
    NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL} \
    NEXT_PUBLIC_MAP_STYLE_URL=${NEXT_PUBLIC_MAP_STYLE_URL}
# An empty NEXT_PUBLIC_APP_NAME would replace the apps' "Food Delivery" fallback
# with an empty string, so it is removed from the build environment when empty.
RUN if [ -z "${NEXT_PUBLIC_APP_NAME:-}" ]; then unset NEXT_PUBLIC_APP_NAME; fi; \
    pnpm --filter "${APP}" build

FROM ${NODE_IMAGE} AS run
RUN apk upgrade --no-cache
ARG APP
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0 \
    APP_DIR=apps/${APP}
WORKDIR /srv
# The standalone tree mirrors the monorepo layout (outputFileTracingRoot is the
# repository root). static files and public/ are not traced; copy them beside server.js.
COPY --from=build --chown=node:node /repo/apps/${APP}/.next/standalone ./
COPY --from=build --chown=node:node /repo/apps/${APP}/.next/static ./apps/${APP}/.next/static
COPY --from=build --chown=node:node /repo/apps/${APP}/public ./apps/${APP}/public
USER node
EXPOSE 3000
CMD ["sh", "-c", "exec node \"$APP_DIR/server.js\""]
