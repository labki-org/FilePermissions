---
phase: 09-e2e-http-leak-checks
plan: 01
subsystem: testing-e2e
tags: [e2e, http, curl, phpunit, cookie-auth, file-seeding, apache, img-auth]
requires:
  - phase: 07-test-infrastructure-unit-tests
    provides: PHPUnit test infrastructure and discovery
  - phase: 08-integration-tests
    provides: Verified DB operations and enforcement wiring
provides:
  - E2E test base class with MW API auth, file seeding, HTTP helpers
  - Apache direct-path denial tests (LEAK-03, LEAK-04)
affects:
  - 09-02 (img_auth.php leak checks extend E2ETestBase)
  - 10-01 (CI pipeline runs E2E tests)
tech-stack:
  added: []
  patterns:
    - "Cookie-based MW API clientlogin for E2E authentication"
    - "PHP curl HTTP client (no external dependencies)"
    - "MW API upload + fileperm-set-level for test data seeding"
    - "Bootstrap verification pattern (skip all tests if prerequisites not met)"
key-files:
  created:
    - tests/phpunit/e2e/E2ETestBase.php
    - tests/phpunit/e2e/DirectPathAccessTest.php
  modified: []
key-decisions:
  - "PHP curl over Guzzle for HTTP client (zero dependency approach)"
  - "Cookie-based clientlogin over bot passwords (matches real user sessions)"
  - "Bootstrap skip pattern over hard failure (graceful when wiki unavailable)"
  - "Public test file for Apache denial tests (proves path-based not permission-based denial)"
patterns-established:
  - "E2E base class with setUpBeforeClass seeding and tearDownAfterClass cleanup"
  - "Static session caching for admin and test user cookies"
  - "MD5 hash path computation for direct Apache URL construction"
duration: 4min
completed: 2026-01-30
---

# Phase 9 Plan 1: E2E Test Infrastructure + Apache Direct-Path Denial Summary

**Cookie-based E2E test base class with MW API auth, 3-level file seeding, HTTP curl helpers, bootstrap checks, and 6 Apache direct-path denial tests covering LEAK-03/LEAK-04**

## Performance

- **Duration:** 4min
- **Started:** 2026-01-30T02:42:04Z
- **Completed:** 2026-01-30T02:46:25Z
- **Tasks:** 2/2
- **Files created:** 2

## Accomplishments

- Created abstract E2ETestBase class providing complete E2E test infrastructure for all HTTP leak check tests
- Implemented cookie-based MW API clientlogin authentication with session caching for admin and test user
- Built HTTP client helpers (httpGet, httpPost) using PHP curl with full cookie/header management
- Added bootstrap verification that skips all tests when wiki unreachable, img_auth.php inactive, or private wiki mode not configured
- Implemented test data seeding: uploads 3 PNG files via MW API and sets permission levels (public, internal, confidential) via fileperm-set-level API
- Provided URL helper methods for all access vectors: img_auth.php (original + thumb), direct Apache path (original + thumb)
- Created DirectPathAccessTest with 6 tests covering LEAK-03 and LEAK-04: 3 user types (admin, TestUser, anonymous) x 2 path types (/images/, /images/thumb/)

## Task Commits

1. Task 1: E2ETestBase with MW API auth, file seeding, and HTTP helpers - `6a85e16` (feat)
2. Task 2: DirectPathAccessTest for Apache-layer denial - `bec5cc9` (test)

## Files Created/Modified

- `tests/phpunit/e2e/E2ETestBase.php` - Abstract base class: HTTP client, MW API auth, file seeding, bootstrap checks, URL helpers (602 lines)
- `tests/phpunit/e2e/DirectPathAccessTest.php` - Apache direct path denial tests: 6 tests for LEAK-03 + LEAK-04 (132 lines)

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| PHP curl over Guzzle for HTTP client | Zero external dependency approach; curl is sufficient for E2E test HTTP needs |
| Cookie-based clientlogin over bot passwords | Matches real user browser sessions; tests actual authentication flow |
| Bootstrap skip pattern over hard failure | Gracefully skips when wiki unavailable (local dev without Docker); prevents false CI failures |
| Public test file for Apache denial tests | Proves Apache blocks by path regardless of file permission level (not a MW-level decision) |

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required. E2E tests auto-skip when Docker wiki environment is not running.

## Next Phase Readiness

**Ready for 09-02:** E2ETestBase provides everything 09-02 needs:
- Cookie-authenticated sessions for admin and test user
- 3 test files seeded at public/internal/confidential levels
- URL helpers for img_auth.php and direct paths
- HTTP GET/POST methods with cookie support

09-02 will extend E2ETestBase to test img_auth.php enforcement (LEAK-01, LEAK-02, LEAK-05, LEAK-06, LEAK-07) and the full permission matrix.
