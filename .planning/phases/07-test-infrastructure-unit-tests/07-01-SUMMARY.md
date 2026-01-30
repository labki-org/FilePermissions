---
phase: 07-test-infrastructure-unit-tests
plan: 01
subsystem: testing
tags: [phpunit, mediawiki, unit-tests, config, fail-closed, security]

# Dependency graph
requires:
  - phase: v1.0 (phases 1-6)
    provides: Config.php static configuration class with 7 public methods
provides:
  - TestAutoloadNamespaces in extension.json for MW test discovery
  - tests/phpunit/unit/ and tests/phpunit/integration/ directory structure
  - ConfigTest.php with 43 test scenarios covering all Config methods
affects:
  - 07-02 (PermissionServiceTest uses same test infrastructure)
  - 08-integration-tests (uses integration/ directory and test patterns)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "MediaWikiUnitTestCase for pure unit tests (no services, no DB)"
    - "$GLOBALS manipulation for Config testing (setUp save, tearDown restore)"
    - "Static data providers for PHPUnit 10 future compatibility"
    - "Fail-closed test naming convention (_FailClosed, _NoGrantsMeansNoAccess)"

key-files:
  created:
    - tests/phpunit/unit/ConfigTest.php
    - tests/phpunit/integration/.gitkeep
  modified:
    - extension.json

key-decisions:
  - "Use setUp/tearDown with __UNSET__ sentinel for global save/restore pattern"
  - "Static data providers for PHPUnit 10 forward compatibility"
  - "Fail-closed tests use descriptive suffixes to make security guarantees visible"

patterns-established:
  - "Global save/restore pattern: save in setUp with __UNSET__ sentinel, restore in tearDown"
  - "Test naming: descriptive method names with security suffix (_FailClosed, _NoGrantsMeansNoAccess)"
  - "Section comments in test files: UNIT-01, UNIT-02, UNIT-03 for requirement traceability"

# Metrics
duration: 2min
completed: 2026-01-29
---

# Phase 7 Plan 01: Test Infrastructure & Config Unit Tests Summary

**PHPUnit test discovery via TestAutoloadNamespaces and 43 Config unit test scenarios proving fail-closed security behavior across valid, invalid, and edge-case configurations**

## Performance

- **Duration:** 2 min
- **Started:** 2026-01-30T00:58:53Z
- **Completed:** 2026-01-30T01:01:26Z
- **Tasks:** 2
- **Files modified:** 2 (extension.json, ConfigTest.php) + 1 directory placeholder

## Accomplishments
- Test infrastructure configured: TestAutoloadNamespaces in extension.json maps FilePermissions\Tests\ to tests/phpunit/
- Created directory structure for both unit and integration tests (tests/phpunit/unit/, tests/phpunit/integration/)
- 43 test scenarios (39 test methods + 6 data provider cases) covering all 7 Config public methods
- Fail-closed behavior exhaustively tested: unset globals, null globals, empty arrays, semantic errors all produce safe defaults
- Security guarantees visible in test names: _FailClosed, _NoGrantsMeansNoAccess, _NothingIsValid, _NoAutoAssignment, _ExplicitSelectionRequired

## Task Commits

Each task was committed atomically:

1. **Task 1: Configure test infrastructure and autoloading** - `dd0efed` (chore)
2. **Task 2: Implement exhaustive Config unit tests** - `482608c` (test)

**Plan metadata:** (pending final commit)

## Files Created/Modified
- `extension.json` - Added TestAutoloadNamespaces mapping FilePermissions\Tests\ to tests/phpunit/
- `tests/phpunit/unit/ConfigTest.php` - 534-line test class with 43 test scenarios covering Config
- `tests/phpunit/integration/.gitkeep` - Placeholder for Phase 8 integration tests

## Decisions Made
- **Global save/restore pattern:** Used setUp to save all 5 globals with `__UNSET__` sentinel value, tearDown restores or unsets. Prevents cross-test pollution while supporting both "null" and "truly unset" test cases.
- **Static data providers:** Made `provideIsValidLevelCases()` static for PHPUnit 10 forward compatibility (PHPUnit 10 requires static data providers).
- **Fail-closed naming convention:** Test methods that verify security guarantees use descriptive suffixes (_FailClosed, _NoGrantsMeansNoAccess) to make the security contract grep-able and self-documenting.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Test infrastructure ready for plan 07-02 (PermissionServiceTest)
- TestAutoloadNamespaces will discover any test class under tests/phpunit/ with FilePermissions\Tests\ namespace
- Integration test directory ready for Phase 8
- Global save/restore pattern established for reuse in subsequent test classes

---
*Phase: 07-test-infrastructure-unit-tests*
*Completed: 2026-01-29*
