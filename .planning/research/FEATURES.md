# Feature Landscape: Test Coverage for FilePermissions Extension

**Domain:** MediaWiki extension testing -- access control test coverage
**Researched:** 2026-01-29
**Confidence:** HIGH (codebase analysis + MediaWiki testing docs + OWASP access control patterns)

## Executive Summary

Testing an access-control extension demands a layered strategy. Pure-logic unit tests are fast and cheap, but they prove nothing about whether unauthorized bytes actually leak through the web server. Integration tests verify hook wiring inside MediaWiki's runtime, but still operate inside PHP process boundaries. Only E2E HTTP tests -- real HTTP requests against a running wiki with real Apache configuration -- can prove that no access vector leaks protected file data.

The existing FilePermissions codebase has two cleanly separable layers. **Config** and **PermissionService** are pure logic with injected dependencies -- ideal unit test targets. **Hook classes** (EnforcementHooks, UploadHooks, DisplayHooks) depend on MediaWiki runtime state and must be tested as integration tests using `MediaWikiIntegrationTestCase`. The **E2E leak checks** sit outside PHPUnit entirely: they are HTTP-level tests that authenticate as different users and hit every access vector to confirm 403 enforcement.

The three-permission-level / two-user-role design (public, internal, confidential x Admin/sysop, TestUser/user) creates a compact but complete permission matrix. Every test scenario derives from this matrix applied across each access vector.

---

## Table Stakes (Users Expect These)

Tests a credible test suite must include. Missing any of these means the suite cannot verify the extension's security claims.

### Layer 1: Unit Tests (PHPUnit, `MediaWikiUnitTestCase`)

| Test Category | Specific Scenarios | Target Class | Priority | Complexity |
|---------------|-------------------|--------------|----------|------------|
| **Config.getLevels** returns configured levels | Default levels; custom levels; deduplication of array_unique | `Config` | P0 | Low |
| **Config.getGroupGrants** returns grant map | Default grants; empty grants; custom multi-group grants | `Config` | P0 | Low |
| **Config.isValidLevel** validates correctly | Valid level returns true; invalid level returns false; case sensitivity | `Config` | P0 | Low |
| **Config.resolveDefaultLevel** resolution order | Namespace default wins over global default; global default used when no namespace default; null when neither set; invalid namespace default falls through to global; invalid global returns null | `Config` | P0 | Low |
| **Config.isInvalidConfig** fail-closed flag | Returns false normally; returns true when flag set | `Config` | P0 | Low |
| **PermissionService.canUserAccessLevel** grant logic | Sysop wildcard `*` grants all levels; user group gets public+internal only; user group denied confidential; unknown group gets nothing; fail-closed when isInvalidConfig is true | `PermissionService` | P0 | Medium |
| **PermissionService.canUserAccessFile** integration of getEffectiveLevel | File with explicit level checks that level; file with no explicit level falls back to default; file with no level and no default returns true (unrestricted/grandfathered) | `PermissionService` | P0 | Medium |
| **PermissionService.setLevel** validation | Rejects invalid level with InvalidArgumentException; rejects page ID 0; accepts valid level | `PermissionService` | P0 | Medium |
| **RegistrationHooks.validateConfiguration** | Valid config produces no errors; empty levels array triggers error; non-string level triggers error; grant referencing unknown level triggers error; invalid default level triggers error; invalid namespace default triggers error | `RegistrationHooks` | P1 | Low |

### Layer 2: Integration Tests (PHPUnit, `MediaWikiIntegrationTestCase`, `@group Database`)

| Test Category | Specific Scenarios | Target Hook/Class | Priority | Complexity |
|---------------|-------------------|-------------------|----------|------------|
| **getUserPermissionsErrors blocks File: reads** | Confidential file returns error array for unprivileged user; public file allows unprivileged user; non-File namespace is ignored; non-read actions pass through | `EnforcementHooks` | P0 | Medium |
| **ImgAuthBeforeStream blocks unauthorized downloads** | Returns 403 result array for unauthorized user; allows authorized user; correctly resolves title from thumbnail path | `EnforcementHooks` | P0 | Medium |
| **ImageBeforeProduceHTML replaces embeds** | Sets `$res` to placeholder HTML for unauthorized user; allows normal rendering for authorized user; disables parser cache (cacheExpiry=0) for pages with protected images | `EnforcementHooks` | P0 | Medium |
| **UploadVerifyUpload validates level selection** | Rejects empty selection when no default configured; accepts empty selection when default exists; rejects invalid level string; accepts valid level string | `UploadHooks` | P0 | Medium |
| **UploadComplete stores level via DeferredUpdates** | Stores explicit level in page_props; falls back to default level when none provided; DeferredUpdate uses fresh Title to get committed page ID | `UploadHooks` | P1 | High |
| **ApiFilePermSetLevel enforces rights and writes** | Rejects user without edit-fileperm right; rejects nonexistent page; rejects invalid level; stores level and returns success; creates audit log entry | `ApiFilePermSetLevel` | P0 | Medium |
| **PermissionService database round-trip** | setLevel + getLevel returns same value; removeLevel clears value; levelCache is populated after first read; getLevel returns null for non-File namespace; getLevel returns null for nonexistent page (articleID=0) | `PermissionService` | P0 | Medium |
| **DisplayHooks renders badge and edit controls** | Badge appears for files with a level; edit controls appear only for users with edit-fileperm right; no output for files without a level | `DisplayHooks` | P2 | Medium |

### Layer 3: E2E HTTP Leak Checks (Shell/curl scripts or PHP HTTP client)

| Access Vector | Test Scenarios | Expected Results | Priority | Complexity |
|---------------|---------------|------------------|----------|------------|
| **File: description page** | TestUser requests File:ConfidentialFile.png | HTTP 200 but page body contains "permission error" message, no file content | P0 | Medium |
| **img_auth.php original file** | TestUser requests /img_auth.php/X/Xx/ConfidentialFile.png | HTTP 403 (ImgAuthBeforeStream hook fires) | P0 | Medium |
| **img_auth.php thumbnail** | TestUser requests /img_auth.php/thumb/X/Xx/ConfidentialFile.png/120px-ConfidentialFile.png | HTTP 403 (MW resolves thumb path to source title) | P0 | Medium |
| **Direct /images/ path** | Any user requests /images/X/Xx/ConfidentialFile.png | HTTP 403 (Apache Require all denied) | P0 | Low |
| **Direct /images/thumb/ path** | Any user requests /images/thumb/X/Xx/ConfidentialFile.png/120px-ConfidentialFile.png | HTTP 403 (Apache Require all denied) | P0 | Low |
| **Authorized access works** | Admin requests all the same paths | HTTP 200 with actual file bytes | P0 | Medium |
| **Public file accessible to TestUser** | TestUser requests public-level file via all vectors | HTTP 200 via img_auth.php and File: page | P0 | Medium |
| **Internal file accessible to TestUser** | TestUser requests internal-level file | HTTP 200 (user group has public+internal) | P0 | Medium |
| **Anonymous user denied all files** | Unauthenticated request to img_auth.php path | HTTP 403 or redirect to login ($wgGroupPermissions['*']['read']=false) | P0 | Low |
| **Response body contains no file bytes on denial** | Parse 403 response body, confirm no image binary data | Body is HTML error message, not file content | P0 | Low |

---

## Differentiators (Competitive Advantage)

Tests that make this suite unusually thorough. Not expected in typical MW extension test suites, but valuable for a security-focused extension.

| Test Category | Specific Scenarios | Value | Priority | Complexity |
|---------------|-------------------|-------|----------|------------|
| **Permission matrix exhaustive coverage** | 3 levels x 2 user roles x 5 access vectors = 30 scenarios, every cell tested | Proves complete coverage, not just spot checks | P1 | Medium |
| **Fail-closed under invalid config** | Set `$wgFilePermInvalidConfig = true`, verify ALL access vectors return 403 for ALL users including sysop | Proves fail-closed is real, not just documented | P1 | Medium |
| **Cache poisoning resistance** | Request page with protected embed as Admin first, then as TestUser -- verify TestUser sees placeholder, not cached Admin view | Proves parser cache disable (cacheExpiry=0) works | P1 | High |
| **Race condition in DeferredUpdates** | Upload file, immediately request it before DeferredUpdate fires -- verify no window of unrestricted access | Proves no temporal leak during upload pipeline | P2 | High |
| **Level change takes immediate effect** | Change file from public to confidential via API, immediately test TestUser access -- verify 403 on next request | Proves no stale-cache window after permission change | P1 | Medium |
| **API CSRF enforcement** | Attempt fileperm-set-level without valid token -- verify rejection | Proves API security is real | P1 | Low |
| **API right enforcement** | Attempt fileperm-set-level as TestUser (no edit-fileperm right) -- verify rejection | Proves right check works | P1 | Low |
| **Audit log completeness** | Change permission via API, query Special:Log/fileperm, verify old+new level recorded | Proves audit trail is complete | P2 | Medium |
| **Grandfathered file behavior** | File with no permission level and no default configured -- verify all users can access | Proves backward compatibility for pre-extension files | P1 | Low |
| **Namespace default application** | Configure NS_FILE default, upload without explicit level, verify default applied | Proves namespace default works end-to-end | P1 | Medium |
| **Double-encoded path bypass attempt** | Request /img_auth.php/%2e%2e/images/X/Xx/File.png or similar path traversal | Verify MW/Apache reject it (not extension responsibility, but validates deployment) | P2 | Medium |
| **Case sensitivity in permission levels** | Attempt to set level "Public" when "public" is configured | Verify strict case comparison catches this | P2 | Low |
| **Concurrent level change** | Two API requests changing the same file's level simultaneously | Verify database serialization produces consistent result | P3 | High |

---

## Anti-Features (Testing Approaches to Deliberately Avoid)

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| **Browser/Selenium E2E tests** | Massive overhead: needs real browser, flaky, slow, hard to run in CI Docker. The extension's security surface is HTTP-level, not DOM-level | Use curl/HTTP client for E2E leak checks. All access vectors are HTTP requests, not browser interactions |
| **Mocking MediaWiki's database layer in unit tests** | PermissionService depends on IConnectionProvider, but MW's database mocking is brittle and changes between versions | Use `MediaWikiIntegrationTestCase` with `@group Database` for anything touching the DB. Reserve pure unit tests for Config logic only |
| **Testing MediaWiki core hook dispatch** | Verifying that MW calls `getUserPermissionsErrors` at the right time is MW core's responsibility, not ours | Test that our handler returns correct results given correct inputs. Trust MW to call the hook |
| **Testing MsUpload/VisualEditor JS bridges** | JS bridge testing requires browser automation (Selenium/Playwright). The security surface is server-side; JS is UX only | Focus on server-side: verify that the PHP hooks and API correctly enforce permissions regardless of how the request arrived |
| **Full-text search leak testing** | MW search result snippets are a known leak vector for ALL authorization extensions. FilePermissions protects file bytes, not page text metadata | Document as known limitation. The extension's scope is file byte protection, not search result filtering |
| **Testing with >3 permission levels** | The 3-level x 2-role matrix is already 30+ scenarios across access vectors. Adding more levels multiplies test count without testing new code paths | The permission logic is level-agnostic. 3 levels cover all branches (granted, denied, wildcard). Parameterized tests handle the matrix |
| **Performance/load testing in CI** | CI containers have variable performance. Timing-based tests are flaky | Performance testing belongs in manual benchmarking, not automated CI |
| **Snapshot testing for HTML output** | DisplayHooks HTML output contains OOUI widgets that change between MW versions. Snapshot tests break on MW upgrades | Assert presence of key CSS classes and data attributes, not exact HTML strings |

---

## Feature Dependencies

```
Unit Tests (Config, PermissionService logic)
    |
    | [no external dependencies -- can run without MW services]
    |
    v
Integration Tests (Hook handlers, API, DB round-trips)
    |
    | [requires: MediaWiki runtime, database, extension loaded]
    | [must run inside MW test framework with @group Database]
    |
    v
E2E HTTP Leak Checks (all access vectors)
    |
    | [requires: running wiki Docker container, Apache with /images/ blocked,
    |  img_auth.php configured, test users created, test files uploaded with
    |  known permission levels set in page_props]
    |
    v
CI Pipeline (GitHub Actions)
    |
    | [requires: Docker Compose with labki-platform image, all above layers
    |  orchestrated in correct order: start containers -> run PHPUnit unit ->
    |  run PHPUnit integration -> create test users -> upload test files ->
    |  set permission levels -> run E2E leak checks]
```

### Test Data Dependencies

```
E2E tests require seeded data:
    1. TestUser created (user group, no sysop)
    2. Admin user exists (sysop group)
    3. Three test files uploaded:
       - PublicTestFile.png    -> level: public
       - InternalTestFile.png  -> level: internal
       - ConfidentialTestFile.png -> level: confidential
    4. Permission levels set in page_props (via API or direct SQL)
    5. Apache config blocks /images/ directory
    6. img_auth.php routes all file access
```

---

## MVP Definition

### Must Have for Initial Test Suite

1. **Unit tests for Config** -- All static methods, all branch paths (8-10 test methods)
2. **Unit tests for PermissionService.canUserAccessLevel** -- Grant logic with mocked dependencies (5-7 test methods)
3. **Integration tests for EnforcementHooks** -- getUserPermissionsErrors and ImgAuthBeforeStream with real DB (6-8 test methods)
4. **Integration tests for PermissionService DB operations** -- setLevel/getLevel/removeLevel round-trip (4-6 test methods)
5. **Integration tests for ApiFilePermSetLevel** -- Rights check, validation, success path (4-5 test methods)
6. **E2E: Confidential file denied to TestUser** -- img_auth.php original, img_auth.php thumbnail, direct /images/ path (3 scenarios)
7. **E2E: Confidential file allowed for Admin** -- Same 3 paths, verify 200 (3 scenarios)
8. **E2E: Public/internal files allowed for TestUser** -- Verify positive access (2 scenarios)
9. **E2E: Direct /images/ path blocked for everyone** -- Verify Apache config works (1 scenario)
10. **GitHub Actions workflow** -- Runs all 3 layers in order

### Defer to Later

- Cache poisoning resistance tests (complex setup, parser cache behavior)
- Race condition / DeferredUpdates timing tests (hard to reproduce reliably)
- Concurrent write tests (DB serialization is MW core responsibility)
- MsUpload/VisualEditor JS bridge tests (browser automation needed)
- DisplayHooks output testing (low security value, high maintenance cost)
- Namespace default E2E tests (unit/integration coverage is sufficient)

---

## Feature Prioritization Matrix

| Test | Security Value | Implementation Cost | Flakiness Risk | Priority |
|------|---------------|--------------------|--------------:|----------|
| Config unit tests | Medium | Low | None | P0 |
| PermissionService.canUserAccessLevel unit tests | High | Low | None | P0 |
| PermissionService.canUserAccessFile unit tests | High | Medium | None | P0 |
| EnforcementHooks integration tests | Critical | Medium | Low | P0 |
| PermissionService DB round-trip integration | High | Medium | Low | P0 |
| ApiFilePermSetLevel integration tests | High | Medium | Low | P0 |
| UploadVerifyUpload integration tests | Medium | Medium | Low | P0 |
| E2E: img_auth.php denial (confidential) | Critical | Medium | Medium | P0 |
| E2E: img_auth.php allow (authorized) | High | Low | Medium | P0 |
| E2E: /images/ direct path blocked | Critical | Low | Low | P0 |
| E2E: thumbnail path denial | Critical | Medium | Medium | P0 |
| E2E: anonymous user denied | High | Low | Low | P0 |
| Fail-closed config tests | High | Low | None | P1 |
| Level change immediate effect E2E | High | Medium | Medium | P1 |
| API CSRF/rights enforcement | Medium | Low | None | P1 |
| Grandfathered file behavior | Medium | Low | Low | P1 |
| Permission matrix exhaustive | Medium | High | Medium | P1 |
| UploadComplete DeferredUpdates | Medium | High | High | P2 |
| Cache poisoning resistance | Medium | High | High | P2 |
| DisplayHooks output | Low | Medium | Medium | P2 |
| Audit log completeness | Low | Medium | Low | P2 |

---

## Test Architecture Recommendations

### Unit Test Structure

Unit tests should extend `MediaWikiUnitTestCase` (no MW services, no DB). Config tests set global variables directly. PermissionService tests use mock `IConnectionProvider` and `UserGroupManager`.

```
tests/phpunit/unit/
    ConfigTest.php              -- Config static methods
    PermissionServiceTest.php   -- canUserAccessLevel, canUserAccessFile logic
    RegistrationHooksTest.php   -- validateConfiguration logic
```

### Integration Test Structure

Integration tests extend `MediaWikiIntegrationTestCase` with `@group Database`. They create real pages, set real page_props, and invoke hooks with real MW objects.

```
tests/phpunit/integration/
    PermissionServiceDbTest.php    -- setLevel/getLevel/removeLevel with real DB
    EnforcementHooksTest.php       -- getUserPermissionsErrors, ImgAuthBeforeStream, ImageBeforeProduceHTML
    UploadHooksTest.php            -- UploadVerifyUpload validation
    ApiFilePermSetLevelTest.php    -- API module with real services
```

### E2E Test Structure

E2E tests are standalone scripts (bash+curl or PHP with HTTP client) that run against the Docker container. They authenticate as different users and verify HTTP status codes and response bodies.

```
tests/e2e/
    run_leak_checks.sh          -- Main runner, exits non-zero on any failure
    helpers/
        auth.sh                 -- Login helpers (cookie-based auth)
        upload.sh               -- Upload test files via API
        setup.sh                -- Create users, upload files, set levels
    scenarios/
        test_confidential_denied.sh
        test_public_allowed.sh
        test_direct_images_blocked.sh
        test_thumbnail_denied.sh
        test_anonymous_denied.sh
```

### CI Pipeline Structure

GitHub Actions workflow orchestrates all three layers:

```yaml
# Conceptual structure (not literal workflow syntax)
jobs:
  test:
    services: [mariadb]
    container: labki-platform
    steps:
      1. Checkout extension
      2. Start MediaWiki (or use service container)
      3. Run PHPUnit unit tests (no DB needed)
      4. Run PHPUnit integration tests (@group Database)
      5. Create test users
      6. Upload test files + set permission levels
      7. Run E2E leak check scripts
```

---

## Edge Cases Commonly Missed in Access Control Testing

These are drawn from OWASP Broken Access Control (A01:2025) and MediaWiki's documented security issues with authorization extensions.

| Edge Case | Why Missed | How to Test |
|-----------|-----------|-------------|
| **Thumbnail paths bypass source-file checks** | Developers test original file path but forget thumbnails are separate URLs. MW core resolves thumb path to source Title before ImgAuthBeforeStream, but this must be verified | E2E test: request /img_auth.php/thumb/... for confidential file as TestUser |
| **Archive/old revision paths** | Old file versions live under /images/archive/. If Apache blocks /images/ recursively this is covered, but img_auth.php archive paths need testing | E2E test: request /img_auth.php/archive/... if applicable |
| **Cached parser output serving stale embeds** | Parser cache stores one HTML version. If Admin views page with protected embed, cached HTML includes the image. TestUser then gets cached Admin version | Integration test: verify cacheExpiry=0 set. E2E: difficult but can request same embed page as both users |
| **API bypassing hook enforcement** | api.php action=query can sometimes bypass getUserPermissionsErrors. The UserCan hook should prevent this, but verify | E2E test: query file info via api.php as TestUser, verify no file URL leaked |
| **Empty permission level string** | A file with level="" in page_props (empty string, not NULL). Does canUserAccessLevel("") return true or false? | Unit test: canUserAccessLevel with empty string -- should deny (no group grants empty string) |
| **Group removed from grants after file leveled** | File set to "confidential" when sysop group had wildcard. Admin later changes $wgFilePermGroupGrants to remove sysop wildcard. File still confidential but nobody can access it | Unit test: canUserAccessLevel where user's groups have no matching grants |
| **Race between upload and DeferredUpdate** | File uploaded, page created, but DeferredUpdate to set permission level has not yet fired. In this window, file has no level and is unrestricted | Document as known limitation. DeferredUpdate fires same-request for Special:Upload, but verify timing |
| **Non-File namespace titles passed to hooks** | getUserPermissionsErrors fires for ALL namespaces. Our handler must exit early for non-File namespace without throwing | Integration test: verify non-File namespace returns true (no interference) |
| **Title with articleID=0 (nonexistent page)** | getLevel called for a title that does not exist in the database. Must return null, not error | Unit test: getLevel with mocked title where getArticleID returns 0 |
| **Wildcard grant is literal asterisk** | The `*` in grants is meant as "all levels" wildcard. Verify it does not match the literal MW anonymous user group `*` in an unintended way | Unit test: verify `*` in grants array means all levels, and separately verify anonymous group (also `*`) behavior |

---

## Sources

### HIGH Confidence (Official MediaWiki Documentation)
- [Manual:PHP unit testing/Writing unit tests for extensions](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests_for_extensions) -- MW extension test structure, MediaWikiUnitTestCase vs MediaWikiIntegrationTestCase, @group Database
- [Manual:PHP unit testing/Writing unit tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests) -- Arrange-act-assert pattern, @covers annotations, setUp/tearDown
- [Manual:PHP unit testing](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing) -- Test runner, composer commands, directory conventions
- [Security issues with authorization extensions](https://www.mediawiki.org/wiki/Security_issues_with_authorization_extensions) -- Known flaws, caching leaks, multiple exit paths
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization) -- img_auth.php configuration, direct URL bypass risk, thumbnail handling
- [Manual:Security](https://www.mediawiki.org/wiki/Manual:Security) -- MW security model limitations, upload directory risks

### HIGH Confidence (OWASP)
- [A01 Broken Access Control -- OWASP Top 10:2025](https://owasp.org/Top10/2025/A01_2025-Broken_Access_Control/) -- #1 vulnerability category, testing approaches, deny-by-default
- [Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html) -- Centralized authorization, server-side enforcement, testing patterns
- [Access Control Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Access_Control_Cheat_Sheet.html) -- Principle of least privilege, extensive testing requirements

### MEDIUM Confidence (WebSearch Verified)
- [Testing MediaWiki code with PHPUnit](https://www.kostaharlan.net/posts/mediawiki-phpunit/) -- Practical guide to MW extension testing
- [Continuous integration/Tutorials/Generating PHP test coverage](https://www.mediawiki.org/wiki/Continuous_integration/Tutorials/Generating_PHP_test_coverage_for_a_MediaWiki_extension) -- Code coverage for extensions in CI
- [Manual:Preventing access](https://www.mediawiki.org/wiki/Manual:Preventing_access) -- Comprehensive list of access restriction methods and their limitations

### Codebase Analysis (Direct)
- `Config.php` -- 6 static methods, all unit-testable, no external dependencies beyond globals
- `PermissionService.php` -- 7 methods, 2 dependencies (IConnectionProvider, UserGroupManager), cache logic
- `EnforcementHooks.php` -- 3 hook handlers (getUserPermissionsErrors, ImgAuthBeforeStream, ImageBeforeProduceHTML)
- `UploadHooks.php` -- 3 hook handlers with DeferredUpdates complexity
- `ApiFilePermSetLevel.php` -- CSRF + rights + validation + audit logging
- `RegistrationHooks.php` -- Configuration validation logic
- `extension.json` -- Hook registration, service wiring, right definitions
- `tests/LocalSettings.test.php` -- Test environment configuration with 2 user roles
- `tests/apache-filepermissions.conf` -- Apache /images/ blocking
- `docker-compose.yml` -- Docker test environment structure
