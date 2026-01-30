---
phase: 07-test-infrastructure-unit-tests
plan: 02
subsystem: testing
tags: [phpunit, mediawiki, unit-tests, permission-service, mocking, fail-closed, security]

# Dependency graph
requires:
  - phase: v1.0 (phases 1-6)
    provides: PermissionService.php with constructor injection (IConnectionProvider, UserGroupManager)
  - phase: 07-01
    provides: TestAutoloadNamespaces, directory structure, global save/restore pattern
provides:
  - PermissionServiceTest.php with 53 test scenarios covering all 6 public methods
  - Mocking patterns for IConnectionProvider, UserGroupManager, Title, UserIdentity
  - DB query builder chain mocking (SelectQueryBuilder, ReplaceQueryBuilder, DeleteQueryBuilder)
affects:
  - 08-integration-tests (integration tests can focus on DB + service wiring, not logic)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Constructor injection mocking: IConnectionProvider + UserGroupManager via createMock"
    - "SelectQueryBuilder fluent chain mocking with willReturnSelf"
    - "createNeverCalledDbProvider for tests that must NOT touch DB"
    - "createMockDbProvider with configurable fetchRow result and call count expectations"
    - "Fresh service instance per test via createService() helper (cache poisoning prevention)"
    - "willReturnOnConsecutiveCalls for multi-query cache independence tests"

key-files:
  created:
    - tests/phpunit/unit/PermissionServiceTest.php
  modified: []

key-decisions:
  - "Helper method pattern: 7 private helpers (createService, createMockDbProvider, createNeverCalledDbProvider, createMockUserGroupManager, createMockFileTitle, createMockNonFileTitle, createMockUser) for DRY test setup"
  - "DB call count assertions: createMockDbProvider accepts expectedCallCount param for caching verification"
  - "Sane defaults in setUp: all 5 globals set to standard config, individual tests override as needed"

patterns-established:
  - "createService() helper enforces fresh instance per test (prevents $levelCache poisoning)"
  - "createNeverCalledDbProvider() for pure logic tests (no DB, strict assertion)"
  - "Fail-closed naming: _FailClosed suffix on security-critical denial tests"
  - "Unrestricted file naming: _UnrestrictedFile suffix on backward-compat access tests"
  - "Section comments: UNIT-04, UNIT-05, UNIT-06 for requirement traceability"

# Metrics
duration: 4min
completed: 2026-01-29
---

# Phase 7 Plan 02: PermissionService Unit Tests Summary

**53 test scenarios covering all 6 PermissionService public methods with fully mocked dependencies, proving grant matching, fail-closed denial, default level fallback, and unrestricted file backward compatibility**

## Performance

- **Duration:** 4 min
- **Started:** 2026-01-30T01:04:57Z
- **Completed:** 2026-01-30T01:08:35Z
- **Tasks:** 1
- **Files created:** 1 (PermissionServiceTest.php, 1293 lines)

## Accomplishments

- 50 test methods + 3 data provider cases = 53 test scenarios total
- All 6 PermissionService public methods covered: getLevel, setLevel, removeLevel, canUserAccessLevel, getEffectiveLevel, canUserAccessFile
- UNIT-04 (permission checks): 16 tests covering grant matching, wildcard, multi-group, empty grants, missing group, fail-closed
- UNIT-05 (default level assignment): 10 tests covering explicit > namespace > global > null fallback chain
- UNIT-06 (unknown/missing files): 9 tests covering nonexistent pages, wrong namespace, unrestricted files, default for nonexistent
- Additional coverage: setLevel/removeLevel DB writes, cache behavior, independent cache entries per page ID
- Fail-closed as most visible property: 4 dedicated tests (invalid config denies all, denies all levels, skips group check, propagates through canUserAccessFile)
- Unrestricted file backward compat: 4 dedicated tests (no level + no default = accessible, even to groupless users)

## Test Coverage Matrix

| Requirement | Tests | Key Scenarios |
|-------------|-------|---------------|
| UNIT-04: Grant matching | 16 | group grants level, group lacks level, wildcard, multi-group, no groups, empty grants, missing group, fail-closed |
| UNIT-05: Default levels | 10 | explicit level, namespace default, global default, null (no defaults), fallback priority |
| UNIT-06: Unknown/missing | 9 | page ID 0, wrong namespace, unrestricted file, default for nonexistent |
| setLevel/removeLevel | 5 | throws for nonexistent page, throws for invalid level, DB write + cache, delete + cache null, no-op for page ID 0 |
| Cache behavior | 4 | caches result, caches null, independent per page ID, independent per instance |

## Task Commits

Each task was committed atomically:

1. **Task 1: Implement PermissionService unit tests with mocked dependencies** - `b0674e2` (test)

## Files Created/Modified

- `tests/phpunit/unit/PermissionServiceTest.php` - 1293-line test class with 53 test scenarios covering all PermissionService public methods

## Decisions Made

- **Helper method pattern:** 7 private helpers encapsulate mock creation. `createService()` enforces fresh instance per test (cache poisoning prevention). `createNeverCalledDbProvider()` uses `$this->never()` expectation for strict DB isolation in pure logic tests.
- **DB call count assertions:** `createMockDbProvider` accepts `expectedCallCount` param, enabling precise verification that caching prevents redundant DB queries.
- **Sane defaults in setUp:** All 5 globals initialized to standard config values. Individual tests override specific globals as needed, reducing boilerplate while keeping test intent clear.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 7 (Test Infrastructure & Unit Tests) complete
- All Config and PermissionService unit tests written
- Integration tests (Phase 8) can focus on DB + service wiring, knowing pure logic is proven
- Mocking patterns established for reuse in hook tests if needed
- Global save/restore pattern consistent between ConfigTest and PermissionServiceTest

---
*Phase: 07-test-infrastructure-unit-tests*
*Completed: 2026-01-29*
