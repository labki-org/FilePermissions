# Phase 7: Test Infrastructure & Unit Tests - Context

**Gathered:** 2026-01-29
**Status:** Ready for planning

<domain>
## Phase Boundary

Test discovery works and pure permission logic is verified without database or services. This phase delivers the PHPUnit infrastructure (autoloading, config, base classes) and unit tests for Config and PermissionService using mocked dependencies only.

</domain>

<decisions>
## Implementation Decisions

### Edge case coverage
- **Exhaustive permutations** for config testing: test every meaningful combination of 0/1/many levels, each grant type, missing fields, invalid values (~25-40 test methods)
- **Fail-closed explicit**: every unknown/missing state (no level set, no groups, deleted file) gets its own dedicated test proving access is DENIED, with security posture visible in test names
- **Test semantic errors**: verify behavior when config is structurally valid but semantically wrong (grant references nonexistent level, default level not defined) — system must handle gracefully
- **Boundary testing**: cover 0, 1, and many for each dimension (levels, groups, grants) — classic boundary analysis applied to all axes
- **Guiding principle**: this extension protects files and data — when in doubt, lean toward MORE exhaustive testing, not less. Security-critical code demands thorough coverage.

### Test data patterns

### Claude's Discretion
- Config construction approach: hardcoded arrays vs helper methods — pick what best fits the test structure
- Permission level naming in tests: realistic names vs abstract — pick what best communicates test intent
- Mock user setup pattern: named personas vs inline per-test — pick what avoids shared-state problems while keeping tests readable
- Test isolation pattern: self-contained tests vs shared setUp() — pick based on isolation requirements and readability

</decisions>

<specifics>
## Specific Ideas

- "This extension has to do with protecting files and data, so when not sure, we should always lean towards more exhaustive testing rather than less" — this is the overriding test philosophy
- Fail-closed behavior should be the most visibly tested property: test names should make the security guarantee obvious

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 07-test-infrastructure-unit-tests*
*Context gathered: 2026-01-29*
