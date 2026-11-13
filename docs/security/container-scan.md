# Container image scan (backlog E-04)

## What runs

The `image` job in `.github/workflows/security.yml` builds the API image from
`infra/docker/php/Dockerfile` and scans it with Trivy 0.76.2. It runs on every
push to `main`, every pull request, weekly (Monday 03:17 UTC) and on demand.

It fails when the image contains:

- a HIGH or CRITICAL vulnerability for which a fixed version exists, or
- a secret (token, key, password file) anywhere in its layers.

MEDIUM and LOW findings, and vulnerabilities with no fix yet, are reported in the
log but do not fail the job. A finding can be accepted only by adding its ID to
`.trivyignore` with a reason and a review date, and recording it below.

## Why Trivy runs from a pinned image, not the GitHub Action

In March 2026 attackers used stolen credentials to rewrite 76 of 77 version tags of
`aquasecurity/trivy-action`, all tags of `aquasecurity/setup-trivy`, and to publish
malicious Trivy releases and Docker Hub images (advisory GHSA-69fq-xp46-6x23). The
action also downloads a second action and the Trivy binary while the workflow
runs, so pinning the action alone still leaves two moving parts.

The job instead runs `aquasec/trivy@sha256:8f6bb947…768fb`, the multi-platform
index digest of 0.76.2. Trivy 0.76.2 is a GitHub immutable release, and the
advisory lists images referenced by digest as unaffected. The scanner never sees
the GitHub token or any secret: it gets a read-only mount of the image tarball and
of `.trivyignore`, nothing else.

What is still not pinned: Trivy downloads its vulnerability database at run time.
That is how it learns of new vulnerabilities, so pinning it would defeat the scan.

Dependabot does not update the digest. To move to a newer Trivy: confirm the
release is immutable on GitHub, wait for it to have been out a while, run
`docker buildx imagetools inspect aquasec/trivy:<version>`, and replace the digest
and the version comment in the workflow.

## Findings

| Date | Image | Finding | Classification | Action |
|---|---|---|---|---|
| 2026-11-13 | API image as built from cache | `libpq` 18.6-r0: 38 HIGH (PostgreSQL client library CVEs, code execution and denial of service) | Real, fixable: Alpine ships 18.7-r0 | Fixed: the Dockerfile now runs `apk upgrade` before installing packages; rebuilt image has 18.7-r0 |
| 2026-11-13 | API image after the fix | none at any severity; no secrets | — | — |

Accepted findings (`.trivyignore`): none.

## How it was checked

Run locally on 2026-11-13 with the workflow's own script and GitHub's shell flags
(`bash -eo pipefail`); output in `docs/qa/evidence/sprint7-container-scan-check.txt`:

- fixed image: exit 0;
- the earlier image with `libpq` 18.6: exit 1, 38 HIGH;
- the fixed image plus a planted, never-valid GitHub token: exit 1, one CRITICAL secret;
- an image name that does not exist: exit 1 (no stale tarball is scanned).

The job has not run on GitHub yet: the commits are not pushed.

## Not covered

- The web apps and the rider app are not built as container images, so they are
  not scanned here. Their dependencies are audited by `pnpm audit --prod` in the web
  workflow; the Flutter dependencies have no audit step.
- `pnpm audit --prod` leaves out development dependencies.
- The API image is a development image (it runs `artisan serve` as root). A
  production image does not exist yet (E-05); it will need the same scan.
