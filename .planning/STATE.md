# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-01-29)

**Core value:** Files are protected at the byte level - unauthorized users cannot view, embed, or download protected files, period.
**Current focus:** v1.1 Testing & CI -- Phase 8 (Integration Tests)

## Current Position

Phase: 7 of 10 (Test Infrastructure & Unit Tests) -- COMPLETE
Plan: 2 of 2 in phase 7
Status: Phase 7 complete
Last activity: 2026-01-29 -- Completed 07-02-PLAN.md

Progress: [################....] ~25% (v1.1 phases 7-10; 2 plans complete, phases 8-10 remaining)

## Performance Metrics

**Velocity:**
- Total plans completed: 2 (v1.1)
- Average duration: 3min
- Total execution time: 6min

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 07 | 2/2 | 6min | 3min |

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

### Research Flags

- All v1.1 phases: SKIP research (high-confidence research already complete)
- Open questions to resolve during implementation:
  - labki-platform PHPUnit availability (verify with docker exec)
  - Exact img_auth.php thumbnail URL format (verify in E2E tests)
  - File upload in integration tests (insertPage vs ApiTestCase patterns)

### Critical Pitfalls (from research)

1. PermissionService cache poisoning -- RESOLVED: createService() helper in 07-02
2. Test logged-in users, not anonymous -- MW core blocks anon on private wikis
3. Set RequestContext user explicitly in hook tests
4. @group Database on every test class touching DB
5. Test both original and /thumb/ paths for img_auth.php
6. Distinguish Apache 403 from MW 403 in direct /images/ tests
7. Override all 5 FilePermissions config vars in integration setUp()

### Pending Todos

None.

### Blockers/Concerns

- Storage is now fileperm_levels table (not PageProps) -- integration tests must use correct table
- Deployment requires private wiki config for img_auth.php enforcement
- Parser cache disabled for pages with protected embedded images

## Session Continuity

Last session: 2026-01-29
Stopped at: Completed 07-02-PLAN.md (PermissionService unit tests -- phase 7 complete)
Resume file: None

*Updated after 07-02 execution*
