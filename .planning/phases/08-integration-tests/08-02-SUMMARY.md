---
phase: 08-integration-tests
plan: 02
subsystem: testing
tags: [phpunit, integration-tests, upload-hooks, api-modules, mediawiki, csrf, authorization]

# Dependency graph
requires:
  - phase: 07-test-infrastructure
    provides: Test discovery, TestAutoloadNamespaces, integration test directory
  - phase: 08-integration-tests (plan 01)
    provides: PermissionServiceDbTest patterns, DB round-trip tests, service container usage
provides:
  - Integration tests for UploadHooks (INTG-04, INTG-05)
  - Integration tests for ApiFilePermSetLevel (INTG-06, INTG-07)
  - Integration tests for ApiQueryFilePermLevel (INTG-08)
affects:
  - 09-e2e-http-leak-checks (tests prove DB wiring works, informing E2E data setup)
  - 10-ci-pipeline (new test files to include in CI phpunit run)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - FauxRequest for simulating form parameters on RequestContext in hook tests
    - ApiTestCase with doApiRequestWithToken for CSRF-protected write API tests
    - DeferredUpdates::doUpdates() to flush deferred callbacks in test assertions
    - Mock UploadBase chain (getLocalFile -> getTitle) for upload completion tests

key-files:
  created:
    - tests/phpunit/integration/UploadHooksTest.php
    - tests/phpunit/integration/ApiFilePermTest.php
  modified: []

key-decisions:
  - "FauxRequest + RequestContext::getMain()->setRequest() for upload hook parameter simulation"
  - "Mock UploadBase with getLocalFile chain rather than full upload lifecycle"
  - "ApiUsageException catch for all denial/error API test assertions"
  - "Direct PermissionService->setLevel() for query test setup (faster than API round-trip)"

patterns-established:
  - "FauxRequest injection: save/restore original request in setUp/tearDown to prevent cross-test pollution"
  - "Mock UploadBase chain: mock getLocalFile()->getTitle() with real Title from insertPage"
  - "API authorization tests: expectException(ApiUsageException) for permission denial assertions"
  - "API query verification: extract page data from $data['query']['pages'] and check by title key"

# Metrics
duration: 3min
completed: 2026-01-30
---

# Phase 8 Plan 02: Upload Hooks and API Integration Tests Summary

**22 integration tests covering UploadHooks verification/completion and API set-level/query with real DB, FauxRequest parameter simulation, and CSRF/authorization enforcement**

## Performance

- **Duration:** 3 min
- **Started:** 2026-01-30T01:35:24Z
- **Completed:** 2026-01-30T01:38:39Z
- **Tasks:** 2
- **Files created:** 2

## Accomplishments
- UploadHooks integration tests prove upload validation rejects invalid levels and stores valid levels in fileperm_levels via DeferredUpdates (INTG-04, INTG-05)
- API integration tests prove sysop authorization for set-level, permission denial for regular/anonymous users, CSRF token requirement, and POST method enforcement (INTG-06, INTG-07)
- Query API tests prove correct level retrieval for protected files, absent level for unprotected files, multi-page queries, and non-sysop read access (INTG-08)
- All tests use real database, real service container, and fresh service instances per test

## Task Commits

Each task was committed atomically:

1. **Task 1: Implement UploadHooks integration tests** - `e9402ab` (test)
2. **Task 2: Implement API module integration tests** - `2fedf68` (test)

## Files Created/Modified
- `tests/phpunit/integration/UploadHooksTest.php` - 10 tests covering INTG-04 (upload verification rejection/acceptance) and INTG-05 (upload completion level storage via DeferredUpdates)
- `tests/phpunit/integration/ApiFilePermTest.php` - 12 tests covering INTG-06 (authorized set-level), INTG-07 (authorization denial, CSRF, POST), INTG-08 (level query)

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| FauxRequest + RequestContext::setRequest() for parameter simulation | UploadHooks reads wpFilePermLevel from RequestContext::getMain()->getRequest(); FauxRequest is the idiomatic MW approach |
| Mock UploadBase chain rather than full upload lifecycle | onUploadComplete only needs getLocalFile()->getTitle(); full upload would add unnecessary complexity |
| ApiUsageException for all denial assertions | MW ApiTestCase throws ApiUsageException for permission denials, invalid params, and missing tokens -- consistent pattern |
| Direct PermissionService for query test setup | Setting levels via PermissionService->setLevel() is faster and more direct than routing through the set-level API for query test data |
| Save/restore RequestContext in setUp/tearDown | Prevents FauxRequest from leaking to other tests in the same process |

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- All Phase 8 integration tests implemented (plans 01 and 02)
- INTG-01 through INTG-10 all have corresponding test coverage
- Ready for Phase 9 (E2E HTTP leak checks) which builds on proven DB and API wiring
- CI pipeline (Phase 10) can now run both unit and integration test suites

---
*Phase: 08-integration-tests*
*Completed: 2026-01-30*
