# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-01-29)

**Core value:** Files are protected at the byte level - unauthorized users cannot view, embed, or download protected files, period.
**Current focus:** v1.1 Testing & CI -- Phase 9 (E2E HTTP Leak Checks)

## Current Position

Phase: 8 of 10 (Integration Tests) -- COMPLETE
Plan: 2 of 2 in phase 8
Status: Phase 8 complete
Last activity: 2026-01-30 -- Completed 08-02-PLAN.md

Progress: [########################....] ~50% (v1.1 phases 7-10; 4 plans complete, phases 9-10 remaining)

## Performance Metrics

**Velocity:**
- Total plans completed: 4 (v1.1)
- Average duration: 3min
- Total execution time: 12min

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 07 | 2/2 | 6min | 3min |
| 08 | 2/2 | 6min | 3min |

## Accumulated Context

### Decisions

All v1.0 decisions logged in PROJECT.md Key Decisions table.

| Decision | Phase | Rationale |
|----------|-------|-----------|
| setUp/tearDown global save/restore with __UNSET__ sentinel | 07-01 | Supports both null and truly-unset global test cases without cross-test pollution |
| Static data providers for PHPUnit 10 compat | 07-01 | PHPUnit 10 requires static data providers; future-proof now |
| Fail-closed test naming suffix convention | 07-01 | Makes security guarantees grep-able and self-documenting (_FailClosed, _NoGrantsMeansNoAccess) |
| createService() helper enforces fresh instance per test | 07-02 | Prevents PermissionService $levelCache poisoning across tests |
| createNeverCalledDbProvider() for pure logic tests | 07-02 | Strict assertion that DB is never touched in canUserAccessLevel tests |
| Sane defaults in setUp, individual tests override | 07-02 | Reduces boilerplate while keeping test intent clear |
| overrideConfigValue instead of global save/restore for integration tests | 08-01 | MediaWikiIntegrationTestCase handles config isolation automatically |
| insertPage for file pages in integration tests | 08-01 | Creates real page records through MW framework, not mocked Titles |
| Mock Parser/ParserOutput for cache expiry test | 08-01 | Hook does not receive parser from service container; mock enables updateCacheExpiry assertion |
| FauxRequest + RequestContext::setRequest() for upload hook parameter simulation | 08-02 | UploadHooks reads wpFilePermLevel from RequestContext; FauxRequest is idiomatic MW approach |
| Mock UploadBase chain rather than full upload lifecycle | 08-02 | onUploadComplete only needs getLocalFile()->getTitle(); avoids unnecessary complexity |
| ApiUsageException for all denial assertions | 08-02 | MW ApiTestCase throws ApiUsageException for permission denials, invalid params, missing tokens |
| Direct PermissionService for query test setup | 08-02 | Setting levels via setLevel() is faster than API round-trip for query test data |

### Research Flags

- All v1.1 phases: SKIP research (high-confidence research already complete)
- Open questions to resolve during implementation:
  - labki-platform PHPUnit availability (verify with docker exec)
  - Exact img_auth.php thumbnail URL format (verify in E2E tests)
  - File upload in integration tests -- RESOLVED: insertPage works for File: pages (08-01)

### Critical Pitfalls (from research)

1. PermissionService cache poisoning -- RESOLVED: createService() helper in 07-02, resetServiceForTesting in 08-01/08-02
2. Test logged-in users, not anonymous -- MW core blocks anon on private wikis
3. Set RequestContext user explicitly in hook tests -- RESOLVED: implemented in 08-01, 08-02
4. @group Database on every test class touching DB -- RESOLVED: applied in 08-01, 08-02
5. Test both original and /thumb/ paths for img_auth.php
6. Distinguish Apache 403 from MW 403 in direct /images/ tests
7. Override all 5 FilePermissions config vars in integration setUp() -- RESOLVED: implemented in 08-01, 08-02

### Pending Todos

None.

### Blockers/Concerns

- Deployment requires private wiki config for img_auth.php enforcement
- Parser cache disabled for pages with protected embedded images

## Session Continuity

Last session: 2026-01-30
Stopped at: Completed 08-02-PLAN.md (UploadHooks + API module integration tests -- phase 8 complete)
Resume file: None

*Updated after 08-02 execution*
