# ADR-004: Time handling

- Status: accepted
- Date: 2026-11-12

## Decision
- Database columns are `timestamptz`. The application and database sessions run in UTC.
- API timestamps are ISO 8601 with an explicit UTC indicator.
- Each city carries its own IANA `timezone`, set by an administrator at creation. There is no code default for a city's timezone. `DEFAULT_DISPLAY_TIMEZONE` applies only to screens with no city context.
- Clients render in the city timezone, and operational screens show the zone name.
- Time-windowed rules (exclusion `active_from` / `active_until`) are evaluated with database `NOW()`, not a client clock. Later-phase countdowns use server time.
- Tests freeze time explicitly; no test depends on the wall clock.

## Consequences
A city in another timezone needs no schema change. Client clocks are never authoritative.
