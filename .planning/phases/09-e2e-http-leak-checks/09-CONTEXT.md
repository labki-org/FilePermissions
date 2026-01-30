# Phase 9: E2E HTTP Leak Checks - Context

**Gathered:** 2026-01-29
**Status:** Ready for planning

<domain>
## Phase Boundary

Live HTTP requests prove unauthorized users cannot download protected file bytes through any access vector. Tests hit the labki-platform Docker wiki over HTTP and verify enforcement at both the Apache layer (direct /images/ paths) and MediaWiki layer (img_auth.php). Creating test infrastructure or CI pipeline is out of scope — this phase produces the E2E test suite only.

</domain>

<decisions>
## Implementation Decisions

### Test environment targeting
- Tests send HTTP requests to the labki-platform Docker container (already exposes an HTTP port)
- Claude decides whether tests execute inside the container or from the host
- Bootstrap must verify that img_auth.php is the active file serving path before running leak tests — fail early if private wiki config is missing
- Wiki must be set to private mode per the README, with main namespace whitelisted so anonymous users can access regular pages (but not protected file bytes)

### User session strategy
- Three distinct user roles: authorized (has group grants), unauthorized (logged in, no grants), and sysop/admin
- Also test anonymous (not-logged-in) requests to verify private wiki blocking
- Claude decides whether sessions are established once per class or per method
- Claude decides whether test users are pre-seeded or created per run

### Failure evidence & reporting
- Claude decides the level of evidence per assertion (status code only vs. status code + body inspection)
- Distinguish Apache-level 403 (direct /images/ path blocked by server config) from MediaWiki-level 403 (img_auth.php denial) — separate test groups
- Produce a human-readable permission matrix summary after tests run: user x file x vector = allowed/blocked
- On leak detection, continue running all checks — don't halt on first failure. Show the full picture of what's leaking

### Test data seeding
- Seed files at every permission level defined in config — full coverage, not a subset
- Claude decides seeding method (MW API upload vs. direct DB/filesystem)
- Clean up test data after E2E tests run (remove seeded files and users)
- Claude decides file types used for test uploads (sufficient to prove byte-level blocking)

### Claude's Discretion
- Whether tests run inside container or from host (pick what's simplest and most realistic)
- Session lifecycle strategy (per-class vs per-method)
- Test user management approach (pre-seeded vs created per run)
- Level of evidence per assertion (status code vs status code + body)
- Seeding method (API upload vs direct DB/filesystem)
- File types for test uploads
- HTTP client library choice
- Test class organization and naming

</decisions>

<specifics>
## Specific Ideas

- Wiki private mode + main namespace whitelisted is a key config requirement from the README — E2E setup must enforce this so anonymous users can access allowed pages with protected embedded files but cannot download file bytes directly
- Distinguishing Apache 403 from MW 403 matters for debugging — these are different enforcement layers and should be testable independently
- The permission matrix summary is for quick scanning in CI output — should show the complete user x file x vector grid at a glance

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 09-e2e-http-leak-checks*
*Context gathered: 2026-01-29*
