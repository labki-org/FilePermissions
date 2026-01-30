# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-01-29)

**Core value:** Files are protected at the byte level - unauthorized users cannot view, embed, or download protected files, period.
**Current focus:** v1.1 Testing & CI -- COMPLETE

## Current Position

Phase: 10 of 10 (CI Pipeline) -- COMPLETE
Plan: 1 of 1 in phase 10
Status: v1.1 complete
Last activity: 2026-01-30 -- Completed 10-01-PLAN.md

Progress: [########################################] 100% (v1.1 phases 7-10; 7 plans complete, all phases done)

## Performance Metrics

**Velocity:**
- Total plans completed: 7 (v1.1)
- Average duration: 3min
- Total execution time: 20min

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 07 | 2/2 | 6min | 3min |
| 08 | 2/2 | 6min | 3min |
| 09 | 2/2 | 6min | 3min |
| 10 | 1/1 | 2min | 2min |

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
| PHP curl over Guzzle for E2E HTTP client | 09-01 | Zero external dependency approach; curl is sufficient for E2E test HTTP needs |
| Cookie-based clientlogin over bot passwords | 09-01 | Matches real user browser sessions; tests actual authentication flow |
| Bootstrap skip pattern over hard failure | 09-01 | Gracefully skips when wiki unavailable; prevents false CI failures |
| Public test file for Apache denial tests | 09-01 | Proves Apache blocks by path regardless of file permission level |
| Separate test classes for targeted denial vs exhaustive matrix | 09-02 | ImgAuthLeakTest has descriptive names; PermissionMatrixTest uses data provider for completeness |
| Data provider pattern for 18-case matrix | 09-02 | Single parameterized test method with static provider; PHPUnit 10 compatible |
| Single job with sequential steps for CI | 10-01 | Avoids spinning up Docker twice; unit/integration and E2E share one environment lifecycle |
| E2E tests run from runner via PHPUnit phar | 10-01 | E2ETestBase hardcodes localhost:8888; runner can reach Docker port mapping directly |
| Health check polls MW API endpoint | 10-01 | Proper readiness check via curl -sf, not fixed sleep; 120s timeout with 5s intervals |
| CI-05 merge gate as manual repo setting | 10-01 | Branch protection rules cannot be configured via workflow file; documented in comments |

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
5. Test both original and /thumb/ paths for img_auth.php -- RESOLVED: E2ETestBase provides URL helpers for both; PermissionMatrixTest covers both vectors (09-01, 09-02)
6. Distinguish Apache 403 from MW 403 in direct /images/ tests -- RESOLVED: DirectPathAccessTest assertion messages include "Apache direct path block" (09-01)
7. Override all 5 FilePermissions config vars in integration setUp() -- RESOLVED: implemented in 08-01, 08-02

### Pending Todos

None.

### Blockers/Concerns

- Deployment requires private wiki config for img_auth.php enforcement
- Parser cache disabled for pages with protected embedded images
- CI-05 merge gate requires manual GitHub repo configuration (branch protection rules)

## Session Continuity

Last session: 2026-01-30
Stopped at: Completed 10-01-PLAN.md (CI Pipeline -- Phase 10 complete, v1.1 complete)
Resume file: None

*Updated after 10-01 execution*
