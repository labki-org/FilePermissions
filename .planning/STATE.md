# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-01-29)

**Core value:** Files are protected at the byte level - unauthorized users cannot view, embed, or download protected files, period.
**Current focus:** v1.1 Testing & CI -- Phase 8 (Integration Tests)

## Current Position

Phase: 8 of 10 (Integration Tests)
Plan: 1 of 2 in phase 8
Status: In progress
Last activity: 2026-01-30 -- Completed 08-01-PLAN.md

Progress: [####################........] ~38% (v1.1 phases 7-10; 3 plans complete, 08-02 + phases 9-10 remaining)

## Performance Metrics

**Velocity:**
- Total plans completed: 3 (v1.1)
- Average duration: 3min
- Total execution time: 9min

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 07 | 2/2 | 6min | 3min |
| 08 | 1/2 | 3min | 3min |

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

### Research Flags

- All v1.1 phases: SKIP research (high-confidence research already complete)
- Open questions to resolve during implementation:
  - labki-platform PHPUnit availability (verify with docker exec)
  - Exact img_auth.php thumbnail URL format (verify in E2E tests)
  - File upload in integration tests -- RESOLVED: insertPage works for File: pages (08-01)

### Critical Pitfalls (from research)

1. PermissionService cache poisoning -- RESOLVED: createService() helper in 07-02, resetServiceForTesting in 08-01
2. Test logged-in users, not anonymous -- MW core blocks anon on private wikis
3. Set RequestContext user explicitly in hook tests -- RESOLVED: implemented in 08-01
4. @group Database on every test class touching DB -- RESOLVED: applied in 08-01
5. Test both original and /thumb/ paths for img_auth.php
6. Distinguish Apache 403 from MW 403 in direct /images/ tests
7. Override all 5 FilePermissions config vars in integration setUp() -- RESOLVED: implemented in 08-01

### Pending Todos

None.

### Blockers/Concerns

- Deployment requires private wiki config for img_auth.php enforcement
- Parser cache disabled for pages with protected embedded images

## Session Continuity

Last session: 2026-01-30
Stopped at: Completed 08-01-PLAN.md (PermissionService DB + EnforcementHooks integration tests)
Resume file: None

*Updated after 08-01 execution*
