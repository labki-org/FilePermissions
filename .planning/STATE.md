# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-01-29)

**Core value:** Files are protected at the byte level - unauthorized users cannot view, embed, or download protected files, period.
**Current focus:** v1.1 Testing & CI -- Phase 7 (Test Infrastructure & Unit Tests)

## Current Position

Phase: 7 of 10 (Test Infrastructure & Unit Tests)
Plan: -- (not yet planned)
Status: Ready to plan
Last activity: 2026-01-29 -- Roadmap created for v1.1

Progress: [##########..........] 0% (v1.1 phases 7-10)

## Performance Metrics

**Velocity:**
- Total plans completed: 0 (v1.1)
- Average duration: --
- Total execution time: --

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| - | - | - | - |

## Accumulated Context

### Decisions

All v1.0 decisions logged in PROJECT.md Key Decisions table.
No v1.1 decisions yet.

### Research Flags

- All v1.1 phases: SKIP research (high-confidence research already complete)
- Open questions to resolve during implementation:
  - labki-platform PHPUnit availability (verify with docker exec)
  - Exact img_auth.php thumbnail URL format (verify in E2E tests)
  - File upload in integration tests (insertPage vs ApiTestCase patterns)

### Critical Pitfalls (from research)

1. PermissionService cache poisoning -- fetch service fresh per test method
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
Stopped at: Roadmap created for v1.1 Testing & CI
Resume file: None

*Updated after v1.1 roadmap creation*
