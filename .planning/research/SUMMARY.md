# Project Research Summary

**Project:** FilePermissions v1.1 Testing & CI
**Domain:** MediaWiki extension testing, CI automation, security verification
**Researched:** 2026-01-29
**Confidence:** HIGH

## Executive Summary

Testing a MediaWiki access-control extension demands a three-tier architecture. Pure-logic unit tests run fast but prove nothing about HTTP-level byte leaks. Integration tests verify hook wiring within MediaWiki's runtime. Only E2E HTTP tests against a live Apache+MediaWiki Docker environment prove that unauthorized users cannot download protected file bytes through any access vector.

The recommended approach uses MediaWiki 1.44's bundled PHPUnit 9.6 framework for unit and integration tests, with separate E2E HTTP tests via PHPUnit + Guzzle against the existing Docker Compose environment. Unit tests cover Config and PermissionService logic with mocked dependencies (MediaWikiUnitTestCase, no database). Integration tests verify hooks, API modules, and database operations (MediaWikiIntegrationTestCase, @group Database). E2E tests authenticate as different users via API and verify that img_auth.php returns 403 for unauthorized access across all vectors: original files, thumbnails, embedded images, and direct /images/ paths.

The critical risk is false-pass tests that claim enforcement works when it does not. This happens when: tests only check anonymous users (MediaWiki core denies them, not the extension), tests ignore thumbnail paths (separate code path in img_auth.php), tests verify HTTP 403 without checking response source (could be Apache, not extension), or tests reuse stale cached service instances between test methods (cache poisoning). Mitigation: test with logged-in TestUser denied confidential access, test both /img_auth.php and /img_auth.php/thumb/ URLs, verify response bodies contain extension-specific messages, and fetch services fresh per test method.

## Key Findings

### Recommended Stack

**Core testing technologies:**
- **PHPUnit 9.6.x**: MediaWiki 1.44 ships PHPUnit 9.6.21 via Composer. All unit and integration tests use MW's bundled PHPUnit, not a separate install.
- **MediaWikiUnitTestCase**: Base class for pure logic tests (Config validation, PermissionService grant logic). No database, no services, fast execution (milliseconds). Blocks access to MediaWikiServices to enforce isolation.
- **MediaWikiIntegrationTestCase**: Base class for tests needing MW runtime (hook handlers, API modules, DB operations). Provides `@group Database` for temporary tables, `overrideConfigValue()` for config injection, `setService()` for mock services, `insertPage()` for test data.
- **Guzzle 7.x + PHPUnit**: Separate E2E suite running outside MW framework. Makes real HTTP requests against Docker container on port 8888. Verifies img_auth.php enforcement, Apache /images/ blocking, cookie-based authentication.
- **GitHub Actions + Docker Compose**: CI orchestration using project's existing docker-compose.yml with labki-platform image. Native Docker support on ubuntu-latest runners, health checks for startup reliability.

**Key version constraint:** MW 1.44 uses PHPUnit 9.6, NOT PHPUnit 10. Data providers must be static for future PHPUnit 10 migration. Do not use PHPUnit 10/11 syntax.

**Critical pattern:** Tests run in two distinct modes. Unit/integration tests run INSIDE the MW Docker container using MW's test framework (`docker compose exec -T wiki php vendor/bin/phpunit`). E2E tests run ON THE HOST using Guzzle to make HTTP requests TO the container.

### Expected Features

**Must have (table stakes):**
- **Unit tests for Config class** (8-10 test methods): getLevels, getGroupGrants, isValidLevel, resolveDefaultLevel, isInvalidConfig. All static methods, all branch paths. Critical for fail-closed verification.
- **Unit tests for PermissionService.canUserAccessLevel** (5-7 test methods): Wildcard grant logic (sysop with `*`), user group gets subset, unknown group denied, fail-closed when isInvalidConfig=true. Mock UserGroupManager and IConnectionProvider.
- **Integration tests for EnforcementHooks** (6-8 test methods): getUserPermissionsErrors blocks File: page reads, ImgAuthBeforeStream returns 403 for unauthorized, ImageBeforeProduceHTML replaces embeds, parser cache disabled (cacheExpiry=0).
- **Integration tests for PermissionService DB operations** (4-6 test methods): setLevel/getLevel/removeLevel round-trip, levelCache populated after first read, getLevel returns null for non-existent page (articleID=0).
- **Integration tests for ApiFilePermSetLevel** (4-5 test methods): Rights check (edit-fileperm), CSRF token validation, level validation, success path, audit log creation.
- **E2E HTTP leak checks** (minimum 9 scenarios): TestUser denied confidential file (img_auth.php original + thumbnail + File: page), Admin allowed all files, TestUser allowed public+internal files, anonymous user denied, direct /images/ path returns 403 for everyone.

**Should have (competitive advantage):**
- **Permission matrix exhaustive coverage** (3 levels x 2 user roles x 5 vectors = 30 scenarios): Proves complete coverage, not just spot checks. Differentiates this from typical MW extension test suites.
- **Fail-closed under invalid config** (integration + E2E): Set `$wgFilePermInvalidConfig = true`, verify ALL access vectors return 403 for ALL users including sysop. Proves fail-closed is real.
- **Cache poisoning resistance** (integration test): Request page with protected embed as Admin first, then as TestUser. Verify TestUser sees placeholder, not cached Admin view. Proves `cacheExpiry=0` works.
- **Level change takes immediate effect** (E2E): Change file from public to confidential via API, immediately test TestUser access. Verify 403 on next request (no stale cache window).
- **Grandfathered file behavior** (integration test): File with no permission level and no default configured. Verify all users can access (backward compatibility for pre-extension files).

**Defer (v2+ or out of scope):**
- Browser/Selenium E2E tests (massive overhead, not needed for HTTP-level verification)
- MsUpload/VisualEditor JS bridge testing (browser automation required, UX not security surface)
- Full-text search leak testing (MW search is a known limitation for ALL authorization extensions)
- Performance/load testing in CI (timing-based tests are flaky)
- DisplayHooks HTML snapshot testing (breaks on MW upgrades, low security value)
- Race condition / DeferredUpdates timing tests (hard to reproduce reliably in CI)

### Architecture Approach

The test architecture has three isolated tiers with different execution contexts. Unit tests (tests/phpunit/unit/) extend MediaWikiUnitTestCase, run without DB or services, test Config and PermissionService logic with mocked dependencies. Integration tests (tests/phpunit/integration/) extend MediaWikiIntegrationTestCase with @group Database, create real pages via insertPage(), test hooks and API with real services, use overrideConfigValue() for config injection. E2E tests (tests/phpunit/e2e/) extend PHPUnit\Framework\TestCase, use Guzzle HTTP client, authenticate via MW API (not UI forms), verify actual HTTP responses from Apache+img_auth.php.

**Major components:**
1. **extension.json TestAutoloadNamespaces** — Registers `"FilePermissions\\Tests\\": "tests/phpunit/"` for MW test discovery. Required for unit and integration tests. E2E tests use separate composer.json autoloader.
2. **PHPUnit via MW core** — Unit and integration tests run via `vendor/bin/phpunit` from MW core directory inside Docker container. Uses MW's phpunit.xml.dist for bootstrap. Tests discovered by scanning extension paths.
3. **E2E HTTP suite** — Separate phpunit.xml at tests/phpunit/phpunit.xml, standalone bootstrap.php loads Guzzle, tests run on host machine against http://localhost:8888. Requires Docker container running with healthy status.
4. **GitHub Actions workflow** — Two jobs: (1) phpunit job runs unit and integration tests inside container via docker compose exec, (2) e2e job starts container, creates test users/files, runs HTTP tests on host. Health checks replace static sleep(30) for reliability.

**Critical directory structure:**
```
tests/
  phpunit/
    unit/            # MediaWikiUnitTestCase, no DB
    integration/     # MediaWikiIntegrationTestCase, @group Database
    e2e/             # PHPUnit + Guzzle, HTTP client
    phpunit.xml      # E2E suite config only
    bootstrap.php    # E2E autoloader only
```

**Data flow patterns:**
- Unit tests set globals directly (`$GLOBALS['wgFilePermLevels']`) because Config uses static methods reading globals
- Integration tests use `$this->overrideConfigValue()` for MW-standard config injection
- Integration tests fetch service fresh per test via `getServiceContainer()->getService()` to avoid cache poisoning
- E2E tests authenticate via API login token flow (not UI forms), use cookie jar for session state, verify HTTP status codes AND response body content

### Critical Pitfalls

1. **PermissionService cache poisoning across tests** — The private `$levelCache` array persists if service instance is reused between test methods. Stale cache causes silent false passes. Solution: fetch service fresh per test via `getServiceContainer()->getService()`, never store in class properties. Warning sign: tests pass individually but fail when run in specific order.

2. **Tests only use anonymous users** — When `$wgGroupPermissions['*']['read'] = false`, MW core denies anonymous access to File: pages and img_auth.php. Tests that check "anonymous user denied" pass, but the denial comes from MW core, NOT the extension. Solution: test with logged-in TestUser (has read access) denied confidential-level file. This proves extension enforcement works. The TestUser-denied-confidential case is the single most important test.

3. **RequestContext user not set in hook tests** — EnforcementHooks calls `RequestContext::getMain()->getUser()`. In PHPUnit, this returns anonymous user by default. All hook tests exercise anonymous path only. Solution: explicitly set user on RequestContext in setUp() or per test: `RequestContext::getMain()->setUser($this->getTestUser()->getUser())`.

4. **Missing @group Database annotation** — PermissionService reads/writes page_props table. Without @group Database, temporary tables are not created. Tests silently return empty results or write to live wiki database. Solution: EVERY test class touching DB must have `@group Database` annotation. Affects EnforcementHooks, UploadHooks, ApiFilePermSetLevel, PermissionService integration tests.

5. **Thumbnail paths not tested** — Tests verify `/img_auth.php/a/ab/File.png` returns 403, but never test `/img_auth.php/thumb/a/ab/File.png/120px-File.png`. Thumbnail path resolution in img_auth.php uses different code path (wfBaseName(dirname($path)) vs wfBaseName($path)). Solution: test BOTH URL patterns for every protected file. Generate real thumbnail by viewing as Admin first, then test unauthorized access.

6. **Direct /images/ test returns 403 for wrong reason** — Test verifies `GET /images/a/ab/File.png` returns 403 and concludes Apache block works. But 403 could come from MW itself (file does not exist), not Apache `Require all denied`. Solution: (1) upload real file, (2) verify Admin gets 200 via img_auth.php, (3) verify direct /images/ path gets 403, (4) check response body for Apache-specific markers vs MW error page.

7. **Config globals not set in tests** — Config class reads `global $wgFilePermLevels` etc. directly. Tests inherit whatever LocalSettings.php set, or get fallback values in unit tests. Solution: EVERY integration test must call `$this->overrideConfigValue()` in setUp() for all 5 FilePermissions config vars. For unit tests, set `$GLOBALS` directly or test Config via integration tests instead.

## Implications for Roadmap

Based on research, suggested phase structure:

### Phase 1: Test Infrastructure
**Rationale:** Foundation must exist before any tests can run. Small config changes with no logic to test.
**Delivers:** extension.json with TestAutoloadNamespaces, composer.json with Guzzle dependency, tests/phpunit/phpunit.xml for E2E suite, tests/phpunit/bootstrap.php for E2E autoloader.
**Addresses:** Prerequisite for all 3 test tiers.
**Avoids:** P14 (file naming convention established), P15 (@covers annotation pattern documented).

### Phase 2: Unit Tests
**Rationale:** Fastest to write, fastest to run, validate core permission logic without infrastructure dependencies. Catch bugs early.
**Delivers:** ConfigTest.php (8-10 test methods for all static methods, all branch paths), PermissionServiceTest.php (5-7 test methods for canUserAccessLevel with mocked dependencies).
**Addresses:** Table stakes feature "unit tests for Config class" and "unit tests for PermissionService.canUserAccessLevel."
**Avoids:** P2 (Config globals pattern established), P17 (parent::setUp() pattern established).

### Phase 3: Integration Tests - Core Services
**Rationale:** After unit tests green, verify actual wiring with MW services and database. Most complex tests, highest risk of pitfalls.
**Delivers:** PermissionServiceIntegrationTest.php (DB round-trip for setLevel/getLevel/removeLevel, cache behavior), EnforcementHooksTest.php (getUserPermissionsErrors, ImgAuthBeforeStream, ImageBeforeProduceHTML).
**Addresses:** Table stakes features "integration tests for PermissionService DB operations" and "integration tests for EnforcementHooks."
**Avoids:** P1 (cache poisoning avoided by fetching service fresh), P3 (RequestContext user set explicitly), P4 (@group Database annotation on all DB tests).

### Phase 4: Integration Tests - API & Upload
**Rationale:** After core enforcement verified, test secondary features (API, upload hooks).
**Delivers:** ApiFilePermSetLevelTest.php (rights check, CSRF, validation, success path, audit log), UploadHooksTest.php (form descriptor, validation).
**Addresses:** Table stakes feature "integration tests for ApiFilePermSetLevel."
**Avoids:** P4 (@group Database for API tests), P16 (fail-closed tested end-to-end from registration hook to permission denial).

### Phase 5: E2E HTTP Leak Checks
**Rationale:** Final validation layer. Requires running Docker environment and test data. Only after integration tests prove logic works.
**Delivers:** HttpLeakTest.php (direct /images/ blocked, basic responses), ImgAuthAccessTest.php (img_auth.php per-user per-file enforcement, thumbnail paths, authenticated sessions).
**Addresses:** Table stakes features "E2E: img_auth.php denial/allow", "E2E: /images/ direct path blocked", "E2E: thumbnail path denial."
**Avoids:** P5 (Apache-specific test separate from MW enforcement), P6 (test logged-in TestUser denied confidential), P7 (thumbnail URLs explicitly tested), P10 (API auth not UI forms).

### Phase 6: CI Workflow
**Rationale:** Cannot verify workflow works until tests exist to run. May need iteration based on labki-platform image capabilities.
**Delivers:** .github/workflows/ci.yml (GitHub Actions workflow: docker compose up with health checks, unit tests inside container, integration tests inside container, E2E tests on host against container).
**Addresses:** Automated execution of all 3 test tiers.
**Avoids:** P8 (health checks replace static sleep), P12 (verify PHPUnit availability early), P13 (can add layer caching later).

### Phase Ordering Rationale

- **Infrastructure first** because extension.json changes are required for MW test discovery, and composer.json is required for Guzzle E2E tests.
- **Unit tests before integration** because they validate core logic without needing Docker, database, or services. Faster feedback loop, catch bugs early.
- **Integration tests core services before API/upload** because EnforcementHooks and PermissionService are the security surface. API and upload hooks are secondary features depending on core enforcement.
- **E2E tests after integration** because E2E requires running Docker environment, test users, uploaded files, and permission levels set in database. Cannot bootstrap this until integration tests prove the DB operations work.
- **CI last** because it orchestrates all test tiers. Cannot debug CI workflow without tests to run. Workflow structure depends on which tests exist and how they're organized.

This ordering minimizes dependencies and allows parallel work: Phase 1 (infrastructure) is sequential, Phases 2-3 could be parallel (unit and integration in separate files), Phase 4 extends Phase 3, Phase 5 requires Phase 1 complete (for E2E config), Phase 6 requires all others complete.

### Research Flags

**Phases needing deeper research during planning:**
- **None** — This is a well-trodden path. MediaWiki PHPUnit testing is extensively documented. Docker Compose + GitHub Actions patterns are standard. HTTP testing with Guzzle is established practice.

**Phases with standard patterns (skip research-phase):**
- **All phases** — The STACK, FEATURES, ARCHITECTURE, and PITFALLS research already covers:
  - PHPUnit base class selection (MediaWikiUnitTestCase vs MediaWikiIntegrationTestCase)
  - Test directory structure (unit/, integration/, e2e/)
  - Docker health check patterns
  - API authentication flow for E2E tests
  - GitHub Actions workflow structure
  - All 7 critical pitfalls with concrete solutions

**Open questions to resolve during implementation:**
- Does labki-platform image include composer/vendor/phpunit? (Verify with `docker compose exec wiki php vendor/bin/phpunit --version` before writing CI workflow)
- What is the exact img_auth.php URL format? (Existing config sets `$wgUploadPath = "$wgScriptPath/img_auth.php"`, verify in E2E tests)
- Do integration tests need specialized setup for file uploads? (insertPage() creates wiki page but not uploaded file; may need ApiTestCase patterns)

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | PHPUnit 9.6 version verified in MW 1.44 Composer files, test base classes documented in official MW docs, Docker Compose + GitHub Actions patterns verified across multiple extension repos |
| Features | HIGH | Test scenarios derived from codebase analysis (3 hooks, 7 PermissionService methods, API module), cross-referenced with OWASP access control testing requirements, MW security docs enumerate known bypass vectors |
| Architecture | HIGH | Directory structure verified in JsonConfig/Translate extensions (TestAutoloadNamespaces), MediaWikiIntegrationTestCase patterns verified in MW core source, Guzzle E2E pattern verified in multiple projects |
| Pitfalls | HIGH | Critical pitfalls sourced from official MW documentation (Security issues with authorization extensions, Debugging PHPUnit Parallel Test Failures), cache poisoning and RequestContext issues verified in MW source code, Docker health check issues documented in MariaDB official docs |

**Overall confidence:** HIGH

The research synthesizes official MediaWiki documentation (PHPUnit testing manuals, security guidelines, img_auth.php reference), verified against real extension codebases (JsonConfig, Translate, edwardspec/mediawiki-moderation), cross-referenced with OWASP access control testing patterns, and validated against the existing FilePermissions codebase structure. All 7 critical pitfalls have concrete code-level evidence (PermissionService.php line 28 cache, EnforcementHooks.php line 76 RequestContext, Config.php global access pattern).

### Gaps to Address

**During implementation (not blockers):**
- **labki-platform PHPUnit availability** — Verify `docker compose exec wiki php vendor/bin/phpunit --version` works before writing CI workflow. If PHPUnit not available, install dev dependencies or use separate test image layer.
- **img_auth.php thumbnail path format** — Confirm exact URL pattern (`/img_auth.php/thumb/a/ab/File.png/120px-File.png`) by manual test before writing E2E tests. MW version differences may affect path structure.
- **File upload in integration tests** — `insertPage()` creates wiki page but not uploaded file bytes. May need `ApiTestCase` or maintenance upload script for tests requiring real file objects. Document workaround when encountered.
- **Parser cache test setup complexity** — Cache poisoning test (Admin view then TestUser view of same page) may require explicit parser cache clear or TTL manipulation. Mark as P2 priority, implement after basic enforcement tests pass.

**Validation during execution:**
- **Test count verification** — Add CI step that fails if fewer than N tests ran (catches silent test discovery failures).
- **Coverage baseline** — Establish minimum coverage threshold (80%+ for PermissionService, EnforcementHooks). Config class 100% (all static methods, simple logic).
- **E2E response body checks** — Verify Apache 403 response differs from MW 403 response (distinct content, headers, or error page structure). Document expected patterns.

## Sources

### Primary (HIGH confidence)
- [Manual:PHP unit testing](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing) — PHPUnit setup overview, test base classes, annotations
- [Manual:PHP unit testing/Writing unit tests for extensions](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests_for_extensions) — Extension test conventions, TestAutoloadNamespaces, directory layout
- [Manual:PHP unit testing/Writing unit tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests) — MediaWikiUnitTestCase vs MediaWikiIntegrationTestCase, @group Database, setMwGlobals
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization) — img_auth.php configuration, thumbnail path handling
- [Security issues with authorization extensions](https://www.mediawiki.org/wiki/Security_issues_with_authorization_extensions) — Parser cache bypass, multiple exit paths, enumerated bypass vectors
- [Continuous integration/Tutorials/Debugging PHPUnit Parallel Test Failures](https://www.mediawiki.org/wiki/Continuous_integration/Tutorials/Debugging_PHPUnit_Parallel_Test_Failures) — State leaking, RequestContext::resetMain, cache poisoning patterns
- MediaWiki core source — img_auth.php thumbnail path logic, MediaWikiIntegrationTestCase methods, PHPUnit 9.6.21 version

### Secondary (MEDIUM confidence)
- [JsonConfig extension.json](https://github.com/wikimedia/mediawiki-extensions-JsonConfig/blob/master/extension.json) — Real-world TestAutoloadNamespaces usage
- [edwardspec/github-action-build-mediawiki](https://github.com/edwardspec/github-action-build-mediawiki) — GitHub Actions MW extension CI pattern
- [Testing MediaWiki code with PHPUnit - Kosta Harlan](https://www.kostaharlan.net/posts/mediawiki-phpunit/) — Practitioner guide
- [Using Healthcheck - MariaDB Documentation](https://mariadb.com/docs/server/server-management/automated-mariadb-deployment-and-administration/docker-and-mariadb/using-healthcheck-sh/) — healthcheck.sh flags, double-restart problem
- [Using Guzzle and PHPUnit for REST API Testing (Cloudflare)](https://blog.cloudflare.com/using-guzzle-and-phpunit-for-rest-api-testing/) — E2E HTTP test pattern

### Tertiary (Codebase analysis)
- FilePermissions/includes/PermissionService.php — Line 28 cache array, line 56 page ID check
- FilePermissions/includes/EnforcementHooks.php — Line 76 RequestContext::getMain(), line 128 updateCacheExpiry(0)
- FilePermissions/includes/Config.php — Global variable access pattern
- FilePermissions/tests/LocalSettings.test.php — Private wiki config, TestUser setup
- FilePermissions/docker-compose.yml — Existing container structure (no health checks currently)

---
*Research completed: 2026-01-29*
*Ready for roadmap: yes*
