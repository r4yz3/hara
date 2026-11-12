# ADR-012: Development object storage

- Status: **open**
- Date: 2026-11-13

## Context
Both source documents name MinIO as the S3-compatible store for development. On 2026-11-13 the `minio/minio` repository on Docker Hub returned 404, so there is no image tag to pin.

## Decision
None yet. The Compose stack ships without an object-storage service. Nothing in Phase 1 before Sprint 6 reads or writes object storage, so this does not block current work.

The application will use Laravel's S3 filesystem driver against whatever S3-compatible endpoint is configured, so the choice affects only `infra/compose.yaml` and environment values.

## Options to evaluate (none verified)
1. A maintained S3-compatible server with a published, pinnable image.
2. MinIO from another registry or built from source, if its licence and maintenance status are acceptable.
3. A real cloud bucket per developer, which costs money and needs credentials.

## Needed
A human decision before the first feature that stores files (merchant or rider documents, Phase 2). The spec's `.env.example` still lists MinIO variables; they are unused until this is closed.
