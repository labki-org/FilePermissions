---
phase: 08-integration-tests
plan: 01
subsystem: testing
tags: [integration-tests, database, enforcement-hooks, permission-service, mediawiki]

dependency_graph:
  requires: [phase-07]
  provides: [intg-01, intg-02, intg-03, intg-09, intg-10, integration-test-patterns]
  affects: [phase-08-plan-02, phase-09, phase-10]

tech_stack:
  added: []
  patterns:
    - MediaWikiIntegrationTestCase with @group Database
    - overrideConfigValue for config isolation
    - resetServiceForTesting for cache poisoning prevention
    - getTestUser with specific groups for role-based testing
    - RequestContext::getMain()->setUser for hook user injection
    - insertPage for real page creation in integration tests

file_tracking:
  key_files:
    created:
      - tests/phpunit/integration/PermissionServiceDbTest.php
      - tests/phpunit/integration/EnforcementHooksTest.php
    modified: []

decisions:
  - id: intg-overrideConfigValue-pattern
    summary: Use overrideConfigValue instead of global save/restore for integration tests
    rationale: MediaWikiIntegrationTestCase provides overrideConfigValue which handles config isolation automatically, unlike unit tests which need manual global save/restore
  - id: intg-insertPage-for-file-pages
    summary: Use insertPage('File:name', content, NS_FILE) for test page creation
    rationale: Creates real page records with real page IDs through MW framework; avoids direct DB manipulation
  - id: intg-mock-parser-for-cache-test
    summary: Mock Parser and ParserOutput for ImageBeforeProduceHTML cache expiry tests
    rationale: Parser is not fetched from service container in hook calls; mock allows assertion on updateCacheExpiry(0) without full parser setup

metrics:
  duration: 3min
  completed: 2026-01-30
---

# Phase 8 Plan 01: PermissionService DB + EnforcementHooks Integration Tests Summary

DB round-trip integration tests for PermissionService (INTG-09, INTG-10) and all 3 enforcement hooks (INTG-01, INTG-02, INTG-03) verified with real MW services and database.

## What Was Done

### Task 1: PermissionServiceDbTest (261 lines, 12 tests)

**INTG-09 -- DB round-trip (8 tests):**
- setLevel/getLevel round-trip proves data persists in fileperm_levels table
- setLevel overwrites previous level (REPLACE semantics verified)
- getLevel returns null when no level set
- removeLevel deletes row, getLevel returns null afterward
- removeLevel on page with no level is a safe no-op
- setLevel throws InvalidArgumentException for nonexistent page (articleID 0)
- setLevel throws InvalidArgumentException for invalid level string
- getLevel returns null for non-File namespace (NS_MAIN)

**INTG-10 -- Cache behavior (4 tests):**
- Cache returns correct value immediately after setLevel (no second DB query)
- Fresh service instances do not share cache (resetServiceForTesting between)
- Cache reflects removal: set, get, remove, get returns null on same instance
- Multiple pages have independent cache entries

### Task 2: EnforcementHooksTest (449 lines, 14 tests)

**INTG-01 -- getUserPermissionsErrors (6 tests):**
- Denies unauthorized user (viewer) access to confidential File: page
- Allows authorized user (sysop wildcard) access to confidential File: page
- Allows access to unprotected File: page for any user
- Ignores non-File namespace titles
- Ignores non-read actions (e.g., 'edit')
- Fail-closed: denies sysop when wgFilePermInvalidConfig is true

**INTG-02 -- ImgAuthBeforeStream (3 tests):**
- Denies unauthorized file download with img-auth-accessdenied result
- Allows authorized file download
- Allows download of unprotected file for any user

**INTG-03 -- ImageBeforeProduceHTML (5 tests):**
- Replaces protected image with placeholder (fileperm-placeholder class)
- Allows embedding for authorized user (returns true, res unchanged)
- Placeholder contains SVG lock icon (data:image/svg+xml data URI)
- Placeholder uses provided width/height dimensions
- Parser cache disabled (updateCacheExpiry(0)) for protected images

## Integration Test Patterns Established

1. **Service from container:** `getServiceContainer()->getService('FilePermissions.PermissionService')` -- proves ServiceWiring.php works
2. **Fresh instance per test:** `resetServiceForTesting()` in setUp and getService helper -- prevents $levelCache poisoning
3. **Config isolation:** `overrideConfigValue()` for all 5 FilePermissions config vars
4. **Real pages:** `insertPage('File:Name', content, NS_FILE)` -- real page IDs, no mocked Titles
5. **Role-based users:** `getTestUser(['sysop'])`, `getTestUser(['viewer'])`, `getTestUser([])` -- tests specific group grants
6. **RequestContext injection:** `RequestContext::getMain()->setUser($user)` for hooks that read from context (ImgAuthBeforeStream, ImageBeforeProduceHTML)
7. **Context cleanup:** tearDown restores original RequestContext user

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| overrideConfigValue instead of global save/restore | MediaWikiIntegrationTestCase handles config isolation automatically |
| insertPage for file pages | Creates real page records through MW framework |
| Mock Parser/ParserOutput for cache expiry test | Hook does not receive parser from service container; mock enables updateCacheExpiry assertion |

## Deviations from Plan

None -- plan executed exactly as written.

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| 1 | ef38e80 | test(08-01): implement PermissionService database integration tests |
| 2 | bfff940 | test(08-01): implement EnforcementHooks integration tests |

## Test Coverage Summary

| Requirement | Tests | Status |
|-------------|-------|--------|
| INTG-01: getUserPermissionsErrors | 6 | Covered |
| INTG-02: ImgAuthBeforeStream | 3 | Covered |
| INTG-03: ImageBeforeProduceHTML | 5 | Covered |
| INTG-09: DB round-trip | 8 | Covered |
| INTG-10: Cache behavior | 4 | Covered |
| **Total** | **26** | **All covered** |

## Next Phase Readiness

**Phase 8 Plan 02** (UploadHooks + API module integration tests) can proceed immediately. The patterns established here (overrideConfigValue, insertPage, getTestUser, resetServiceForTesting) apply directly to those tests.

No blockers or concerns identified.
