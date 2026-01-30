---
phase: 09-e2e-http-leak-checks
plan: 02
subsystem: testing-e2e
tags: [e2e, http, img-auth, permission-matrix, leak-check, phpunit, data-provider]
requires:
  - phase: 09-e2e-http-leak-checks
    provides: E2ETestBase with cookie auth, file seeding, HTTP helpers
provides:
  - img_auth.php denial tests for unauthorized and anonymous access (LEAK-01, LEAK-02)
  - Authorized access verification for granted permission levels (LEAK-05, LEAK-06)
  - Exhaustive 18-scenario permission matrix (LEAK-07)
affects:
  - 10-01 (CI pipeline runs E2E tests including matrix)
tech-stack:
  added: []
  patterns:
    - "PHPUnit data provider for exhaustive matrix testing (18 parameterized cases)"
    - "Static result collection for cross-test summary output in tearDownAfterClass"
key-files:
  created:
    - tests/phpunit/e2e/ImgAuthLeakTest.php
    - tests/phpunit/e2e/PermissionMatrixTest.php
  modified: []
key-decisions:
  - "Separate test classes for targeted denial vs exhaustive matrix (clarity of purpose)"
  - "Data provider pattern for 18-case matrix (single test method, static provider)"
patterns-established:
  - "Parameterized permission matrix testing via PHPUnit data provider"
  - "Human-readable matrix summary output via fwrite(STDOUT) in tearDownAfterClass"
duration: 2min
completed: 2026-01-30
---

# Phase 9 Plan 2: img_auth.php Leak Checks + Permission Matrix Summary

**img_auth.php denial tests (8 methods) plus exhaustive 18-scenario permission matrix covering 3 levels x 3 users x 2 vectors with human-readable summary output**

## Performance

- **Duration:** 2min
- **Started:** 2026-01-30T02:50:18Z
- **Completed:** 2026-01-30T02:52:03Z
- **Tasks:** 2/2
- **Files created:** 2

## Accomplishments

- Created ImgAuthLeakTest with 8 test methods proving img_auth.php denies unauthorized access (403) while allowing authorized downloads (200 with file bytes)
- Covers LEAK-01 (unauthorized confidential original denied), LEAK-02 (unauthorized thumbnail denied), LEAK-05 (authorized users download at granted levels), LEAK-06 (public files accessible to all authenticated)
- Created PermissionMatrixTest with data provider yielding 18 parameterized test cases covering the complete security surface
- Matrix dimensions: 3 levels (public, internal, confidential) x 3 users (admin, testuser, anonymous) x 2 vectors (original, thumbnail)
- Human-readable matrix summary printed to stdout after all matrix tests complete
- Anonymous users denied all files on private wiki (403 for any request without cookies)

## Task Commits

1. Task 1: ImgAuthLeakTest for img_auth.php denial checks - `493fe77` (test)
2. Task 2: PermissionMatrixTest for exhaustive 18-scenario coverage - `7adb3eb` (test)

## Files Created/Modified

- `tests/phpunit/e2e/ImgAuthLeakTest.php` - Targeted img_auth.php denial and access tests: 8 methods covering LEAK-01, LEAK-02, LEAK-05, LEAK-06 (204 lines)
- `tests/phpunit/e2e/PermissionMatrixTest.php` - Exhaustive permission matrix: 18 parameterized cases with summary output covering LEAK-07 (196 lines)

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| Separate test classes for targeted denial vs exhaustive matrix | ImgAuthLeakTest has descriptive method names for specific scenarios; PermissionMatrixTest uses data provider for completeness |
| Data provider pattern for 18-case matrix | Single parameterized test method with static provider; PHPUnit 10 compatible |

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - E2E tests auto-skip when Docker wiki environment is not running.

## Next Phase Readiness

**Phase 9 complete.** All E2E HTTP leak checks are implemented:

- 09-01: E2ETestBase infrastructure + 6 Apache direct-path denial tests (LEAK-03, LEAK-04)
- 09-02: 8 img_auth.php denial/access tests (LEAK-01, LEAK-02, LEAK-05, LEAK-06) + 18-scenario matrix (LEAK-07)

**Total E2E coverage:** 32 test scenarios (6 Apache + 8 img_auth + 18 matrix)

**Ready for Phase 10 (CI Pipeline):** All test tiers (unit, integration, E2E) exist and can be wired into GitHub Actions.
