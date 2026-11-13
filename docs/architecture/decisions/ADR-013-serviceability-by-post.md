# ADR-013: The serviceability check is a POST

- Status: accepted (security fix); departs from spec 7.2 and the master plan, which list `GET /api/v1/locations/serviceability`
- Date: 2026-11-13

## Context
The check takes a customer's latitude and longitude. As first built (commit `87669f7` and earlier) these were query parameters on a GET, as the specification writes it. An automated security review of that commit flagged it: a precise position in a URL is written to access logs by web servers, proxies, load balancers and WAFs by default, and can also end up in browser history. A delivery position is usually someone's home. The spec itself says to avoid logging exact customer coordinates (11.3) and to minimise personal data.

The application never logged the position, but it could not control what infrastructure in front of it does with URLs.

## Decision
- The endpoint is `POST /api/v1/locations/serviceability` with a JSON body `{latitude, longitude}`. The GET form is removed, not deprecated: it answers 405.
- A POST that carries `latitude` or `longitude` in its query string is refused with 422, even if the body is valid, so the old habit fails loudly instead of leaking quietly.
- The body accepts a decimal string or a JSON number.
- The API still neither stores nor logs the position.
- The customer app rounds a device-supplied position to four decimals (about 11 metres) before sending. Values a person types are sent as typed.

## Consequences
- The request is no longer cacheable or bookmarkable, which is correct for personal data.
- Browser clients must send the CSRF token, so the check costs one extra request (the token fetch) and gives an anonymous visitor a session cookie.
- Request bodies must stay out of logs too. Nothing logs them today; backlog E-01 (structured logging) must keep it that way, and the reverse proxy configuration in E-05 must not enable body logging.
- The specification and master plan still say GET. They are source documents and are left as written; this ADR and ADR-000 record the difference.

## Not done
- Positions sent before this change may already be in local development logs. No deployed environment existed, so no real customer data was exposed.
- There is no automated check that infrastructure logs exclude bodies; that needs a deployed environment.
