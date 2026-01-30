# Roadmap: FilePermissions

## Milestones

- [x] **v1.0 MVP** - Phases 1-6 (shipped 2026-01-29)
- [ ] **v1.1 Testing & CI** - Phases 7-10 (in progress)

## Phases

<details>
<summary>v1.0 MVP (Phases 1-6) - SHIPPED 2026-01-29</summary>

See `.planning/milestones/v1.0/` for archived v1.0 roadmap and plans.

**Delivered:** Group-based, byte-level access control for MediaWiki uploaded files with permission enforcement across all content paths and three upload integration points.

**Stats:** 6 phases, 13 plans, 20 files, 2,196 LOC, 74 commits

</details>

### v1.1 Testing & CI

**Milestone Goal:** Prove that the permission enforcement actually works -- from unit-level logic through HTTP-level leak checks -- and run it automatically on every PR.

**Phase Numbering:** Continues from v1.0 (phases 7-10).

- [x] **Phase 7: Test Infrastructure & Unit Tests** - Foundation for test discovery plus pure-logic tests for Config and PermissionService
- [ ] **Phase 8: Integration Tests** - Hook, API, and DB tests verifying enforcement wiring within MediaWiki runtime
- [ ] **Phase 9: E2E HTTP Leak Checks** - Live HTTP verification that unauthorized users cannot download protected file bytes
- [ ] **Phase 10: CI Pipeline** - GitHub Actions workflow running all test tiers automatically

## Phase Details

### Phase 7: Test Infrastructure & Unit Tests

**Goal:** Test discovery works and pure permission logic is verified without database or services

**Depends on:** v1.0 codebase (shipped)

**Requirements:** INFRA-01, INFRA-02, UNIT-01, UNIT-02, UNIT-03, UNIT-04, UNIT-05, UNIT-06

**Success Criteria** (what must be TRUE):
  1. Running `php vendor/bin/phpunit` from MW core discovers and executes FilePermissions unit tests (no manual path arguments needed)
  2. Config tests pass for all valid configuration scenarios (levels, grants, defaults) and fail-closed behavior triggers on invalid/missing configuration
  3. Config edge cases are covered: empty levels array, missing grants, unknown level names all produce correct behavior
  4. PermissionService tests verify grant matching, denial, and default level assignment using mocked dependencies (no database needed)
  5. PermissionService tests confirm that unknown/missing files (null level) are handled correctly

**Research flag:** Skip -- MediaWiki PHPUnit testing, TestAutoloadNamespaces, and MediaWikiUnitTestCase patterns are well-documented. Research already covers base class selection and globals patterns.

**Plans:** 2 plans

Plans:
- [x] 07-01-PLAN.md -- Test infrastructure (TestAutoloadNamespaces, directory structure) + Config unit tests
- [x] 07-02-PLAN.md -- PermissionService unit tests with mocked dependencies

---

### Phase 8: Integration Tests

**Goal:** Enforcement hooks, API modules, and database operations are verified within the MediaWiki runtime

**Depends on:** Phase 7 (test infrastructure must exist for MW to discover integration tests)

**Requirements:** INTG-01, INTG-02, INTG-03, INTG-04, INTG-05, INTG-06, INTG-07, INTG-08, INTG-09, INTG-10

**Success Criteria** (what must be TRUE):
  1. A logged-in user without the required group is denied access to a File: page, denied file download via img_auth.php, and sees a placeholder instead of an embedded protected image
  2. Uploads with invalid permission levels are rejected, and valid uploads store the permission level in the fileperm_levels table
  3. The API set-level endpoint enforces sysop authorization (non-sysop users are denied) and the query endpoint returns correct permission levels
  4. PermissionService round-trips setLevel/getLevel/removeLevel through the fileperm_levels table and the in-process cache does not poison cross-scenario tests
  5. All integration test classes use @group Database and fetch services fresh per test method (no cache poisoning, no stale state)

**Research flag:** Skip -- MediaWikiIntegrationTestCase, @group Database, overrideConfigValue(), RequestContext user injection all documented. Research covers all 7 critical pitfalls with solutions.

**Plans:** TBD

Plans:
- [ ] 08-01: TBD
- [ ] 08-02: TBD

---

### Phase 9: E2E HTTP Leak Checks

**Goal:** Live HTTP requests prove unauthorized users cannot download protected file bytes through any access vector

**Depends on:** Phase 7 (E2E phpunit.xml and bootstrap), Phase 8 (integration tests prove DB operations work, informing test data setup)

**Requirements:** INFRA-03, INFRA-04, LEAK-01, LEAK-02, LEAK-03, LEAK-04, LEAK-05, LEAK-06, LEAK-07, LEAK-08

**Success Criteria** (what must be TRUE):
  1. A test data setup script seeds files at each permission level with correct fileperm_levels entries, and E2E bootstrap authenticates test users via MW API (cookie-based sessions, not anonymous)
  2. An unauthorized logged-in user gets 403 from img_auth.php for both original file downloads and thumbnail paths of confidential files
  3. Direct /images/ and /images/thumb/ paths return 403 for all users (Apache blocks, not MW)
  4. Authorized users can download files at their granted permission levels, and public files are accessible to all authenticated users
  5. A full permission matrix is tested: 3 levels x 2 user roles x all access vectors, covering the complete security surface

**Research flag:** Skip -- Guzzle HTTP testing, MW API login flow, img_auth.php URL patterns all researched. Open questions (exact thumbnail path format, labki-platform PHPUnit availability) resolve during implementation, not research.

**Plans:** TBD

Plans:
- [ ] 09-01: TBD
- [ ] 09-02: TBD

---

### Phase 10: CI Pipeline

**Goal:** All test tiers run automatically on every PR and push, and both jobs must pass for mergeability

**Depends on:** Phase 7, Phase 8, Phase 9 (all test suites must exist to run in CI)

**Requirements:** CI-01, CI-02, CI-03, CI-04, CI-05

**Success Criteria** (what must be TRUE):
  1. A GitHub Actions workflow triggers on PRs to main and pushes to main
  2. Docker Compose starts the labki-platform environment with health checks (not fixed sleep) to confirm wiki readiness
  3. The PHPUnit job runs unit and integration tests inside the Docker container and reports pass/fail
  4. The E2E job runs HTTP leak checks against the live wiki and reports pass/fail
  5. A PR cannot be merged unless both the PHPUnit job and E2E job pass (enforced via required status checks)

**Research flag:** Skip -- GitHub Actions + Docker Compose patterns are standard. Health check patterns documented in research. Only open question (labki-platform PHPUnit availability) resolves with a single exec command during implementation.

**Plans:** TBD

Plans:
- [ ] 10-01: TBD

---

## Progress

**Execution Order:** Phase 7 -> Phase 8 -> Phase 9 -> Phase 10

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 7. Test Infrastructure & Unit Tests | v1.1 | 2/2 | Complete | 2026-01-29 |
| 8. Integration Tests | v1.1 | 0/TBD | Not started | - |
| 9. E2E HTTP Leak Checks | v1.1 | 0/TBD | Not started | - |
| 10. CI Pipeline | v1.1 | 0/TBD | Not started | - |

---
*Roadmap created: 2026-01-29*
*Last updated: 2026-01-29 (Phase 7 complete)*
