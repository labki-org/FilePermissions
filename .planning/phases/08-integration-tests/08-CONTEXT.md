# Phase 8: Integration Tests - Context

**Gathered:** 2026-01-29
**Status:** Ready for planning

<domain>
## Phase Boundary

Enforcement hooks, API modules, and database operations are verified within the MediaWiki runtime. Tests prove that the permission wiring actually blocks/allows access correctly when MediaWiki services are running. Unit tests (Phase 7) and HTTP-level leak checks (Phase 9) are separate phases.

</domain>

<decisions>
## Implementation Decisions

### Claude's Discretion

User explicitly deferred all integration test design decisions to Claude with the guidance: **choose the more exhaustive option when unsure.**

This applies to all gray areas:

- **Hook test scenarios** — Which enforcement hooks to test, denial/allow combinations, how to set up protected files with specific levels. Prefer exhaustive coverage of all hook paths over minimal happy-path tests.
- **API test coverage** — Authorization edge cases for set-level and query endpoints, error response expectations. Test both success and failure paths for all API modules.
- **Test data strategy** — How to seed files with permission levels, user/group setup, shared fixtures vs independent tests. Prefer test isolation (independent setup per test) over shared fixtures to avoid state leakage.
- **Failure mode expectations** — Exact responses for denied users (HTTP codes, error messages, placeholder behavior). Verify specific error codes/messages, not just "request failed."

</decisions>

<specifics>
## Specific Ideas

- When in doubt between minimal and exhaustive test coverage, choose exhaustive
- All critical pitfalls from research (STATE.md) should be reflected in test design: cache poisoning prevention, RequestContext user injection, @group Database annotation, fileperm_levels table usage, all 5 config vars overridden in setUp()

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 08-integration-tests*
*Context gathered: 2026-01-29*
