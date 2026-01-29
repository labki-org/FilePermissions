# Pitfalls Research

**Domain:** MediaWiki extension testing -- access control test suite for FilePermissions
**Researched:** 2026-01-29
**Confidence:** HIGH for MW PHPUnit pitfalls, HIGH for security testing gaps, MEDIUM for CI/Docker reliability

This document covers testing pitfalls specifically. The extension is already built (v1.0). The goal is a test suite that proves enforcement works across all access vectors, running automatically in CI.

---

## Critical Pitfalls

These produce test suites that silently lie -- giving false confidence that enforcement works when it does not, or tests that cannot run at all.

---

### Pitfall 1: PermissionService In-Process Cache Poisons Cross-Test Results

**What goes wrong:** `PermissionService` maintains `private array $levelCache = []` (PermissionService.php line 28). When the service instance is not reset between test methods, a level stored in cache during test A persists into test B. Test B reads a stale cached value instead of querying the database, causing silent false passes or false failures.

**Why it happens:** `MediaWikiIntegrationTestCase` resets the service container between tests, but if the test class holds a direct reference to the PermissionService object (stored in `$this->permService` during `setUp()`), the old object persists with its stale cache even after the container is reset. This is the well-documented MediaWiki singleton state problem -- "the moment you do `Classname::getInstance()` you don't know if that object was just generated or created earlier in a previous test and might already have state modified."

**How to avoid:** Fetch the service fresh in each test method via `$this->getServiceContainer()->getService('FilePermissions.PermissionService')`, or use `$this->setService()` in `setUp()` to register a fresh instance for each test. Do not store service references in class properties across test methods.

**Warning signs:** Tests pass individually but fail when run in a specific order. A test that sets level "public" on a file unexpectedly reads "confidential" because the previous test set that level on the same page ID.

**Phase to address:** PHPUnit integration tests -- establish the pattern in the very first integration test file.

---

### Pitfall 2: Config Static Methods Read Globals -- Tests Fail Without setMwGlobals

**What goes wrong:** `Config::getLevels()`, `Config::getGroupGrants()`, and all other Config methods read `global $wgFilePermLevels`, `$wgFilePermGroupGrants`, etc. directly. In `MediaWikiUnitTestCase`, no MediaWiki globals are populated, so all Config calls return fallback values. In `MediaWikiIntegrationTestCase`, the globals have whatever value was set by the test environment's LocalSettings.php, which may differ from test expectations.

**Why it happens:** The Config class uses static methods reading globals directly -- this is the standard MediaWiki pattern for extension configuration, but it means tests MUST explicitly set the globals they depend on. The official documentation states: "Tests should explicitly set all the configuration settings globals a test assumes, via setMwGlobals. Otherwise the test will test those globals, rather than whatever it's supposed to test."

**How to avoid:** Every integration test that touches Config or PermissionService MUST call `$this->overrideConfigValues()` or `$this->setMwGlobals()` in `setUp()` to set all four FilePermissions config variables:
```php
$this->setMwGlobals( [
    'wgFilePermLevels' => [ 'public', 'internal', 'confidential' ],
    'wgFilePermGroupGrants' => [
        'sysop' => [ '*' ],
        'user' => [ 'public', 'internal' ],
    ],
    'wgFilePermDefaultLevel' => null,
    'wgFilePermNamespaceDefaults' => [],
    'wgFilePermInvalidConfig' => false,
] );
```
For unit tests of Config, the static globals approach makes pure unit testing impractical -- test Config via integration tests instead, or accept the coupling.

**Warning signs:** Tests pass on the developer's Docker environment (which loads `LocalSettings.test.php` with matching config) but fail in CI or on another developer's machine. Or tests that don't set globals inherit stale state from a previous test that did.

**Phase to address:** PHPUnit unit and integration tests -- set up the boilerplate pattern in the first test file and reuse.

---

### Pitfall 3: RequestContext::getMain()->getUser() Returns Wrong User in Hook Tests

**What goes wrong:** `EnforcementHooks::onImgAuthBeforeStream()` and `onImageBeforeProduceHTML()` both call `RequestContext::getMain()->getUser()` to get the current user. In PHPUnit, the main RequestContext does not have a meaningful user by default -- it returns an anonymous `User` object. Tests that fail to set up the RequestContext user will always exercise the anonymous-user code path, never verifying that logged-in users with specific groups get the expected access.

**Why it happens:** MediaWiki's `RequestContext::getMain()` is a global singleton. Integration tests do not automatically set a user on it. The `getUserPermissionsErrors` hook receives the user as a parameter (so it can be tested more directly), but the other two enforcement hooks fetch the user from global context -- this asymmetry is easy to miss.

**How to avoid:** In integration tests, explicitly set the user on the request context before testing ImgAuthBeforeStream or ImageBeforeProduceHTML:
```php
$user = $this->getTestUser( [ 'user' ] )->getUser();
RequestContext::getMain()->setUser( $user );
```
`MediaWikiIntegrationTestCase` resets the main RequestContext between tests via `RequestContext::resetMain()`, so this is safe. But it must be done in each test method or in `setUp()`.

**Warning signs:** All access-denied tests pass (anonymous user has no grants by default), but access-allowed tests also pass because assertions are accidentally inverted. Or: every hook test gives identical results regardless of which user is set, because they all test the anonymous path.

**Phase to address:** PHPUnit integration tests for enforcement hooks.

---

### Pitfall 4: Missing @group Database Causes Silent Skips or Real DB Writes

**What goes wrong:** PermissionService reads and writes to the `page_props` table. If an integration test class does NOT include the `@group Database` annotation, one of two things happens: (a) database operations silently return empty results and the test passes by accident (because "no level set" is the default), or (b) on some configurations, writes go to the real wiki database, corrupting its state.

**Why it happens:** The official MW documentation states: "Tests that require database connectivity should be put into group `Database`. This causes temporary tables to be overlaid over the real wiki database, so test cases can perform database operations without changing the actual wiki." Without this annotation, `MediaWikiIntegrationTestCase` does not set up temporary tables.

**How to avoid:** EVERY test class that interacts with PermissionService (`getLevel`, `setLevel`, `getEffectiveLevel`, `canUserAccessFile`) or creates wiki pages (via `insertPage()` or `getExistingTestPage()`) MUST have `@group Database` on the class. This includes test classes for EnforcementHooks, UploadHooks, ApiFilePermSetLevel, and PermissionService.

**Warning signs:** Tests pass on the developer machine but fail in CI with "table not found" or "Cannot access the database" errors. Or tests pass everywhere but a manual database check reveals leftover test data in the live wiki.

**Phase to address:** PHPUnit integration tests -- the annotation must be present from the very first integration test file.

---

### Pitfall 5: Direct /images/ Access Test Returns 403 for the Wrong Reason

**What goes wrong:** An E2E test verifies that `GET /images/a/ab/Secret.png` returns HTTP 403 and concludes that the Apache config is working. But the 403 is actually coming from MediaWiki itself (because the file does not exist at that path, or because MW returns its own error), not from Apache's `Require all denied` directive. The test passes, but if the Apache config is later removed or broken, the test still passes because MW produces a 403 too.

**Why it happens:** Multiple layers (Apache, MediaWiki, PHP) can all return HTTP 403. A test that only checks the status code cannot distinguish which layer generated the response. This is especially insidious because the "correctly working" and "incorrectly working" scenarios produce identical HTTP status codes.

**How to avoid:** Test the Apache block SEPARATELY from MW enforcement. The correct sequence:
1. Upload a real file (confirmed to exist on disk)
2. Access it as Admin via `img_auth.php` -- confirm HTTP 200 (proves the file exists and MW serves it)
3. Access the same file via direct `/images/` path -- must get HTTP 403
4. Verify the response body: Apache's 403 page has different content than img_auth.php's denial page. Check for Apache-specific markers (like "Forbidden" in plain text, or Apache's default error page structure)

**Warning signs:** All E2E tests pass in Docker, but disabling the Apache config (`tests/apache-filepermissions.conf`) still results in all tests passing. The tests never actually validated the web server layer.

**Phase to address:** E2E HTTP tests -- design the Apache-specific test first, before MW enforcement tests.

---

### Pitfall 6: Tests Only Use Anonymous Users -- MW Core Denies, Not the Extension

**What goes wrong:** When `$wgGroupPermissions['*']['read'] = false` is set (as required for img_auth.php enforcement), MediaWiki core itself denies anonymous access to File: pages and img_auth.php. A test that checks "anonymous user gets denied on File:Secret.png" passes, but the denial came from MW core's read restriction, NOT from the FilePermissions extension. If the extension is completely disabled or broken, the test still passes.

**Why it happens:** The private wiki configuration (`read=false` for anonymous) is a completely separate access control layer. Both layers deny anonymous access, so testing anonymous users cannot prove the extension works.

**How to avoid:** Test with a LOGGED-IN user who has read access to wiki pages but NOT to a specific file permission level. The critical test matrix:

| User | File Level | Expected | Proves Extension Works? |
|------|------------|----------|------------------------|
| Anonymous | any | denied | NO -- MW core denies |
| TestUser | public | allowed | YES |
| TestUser | internal | allowed | YES |
| TestUser | confidential | **denied** | **YES** -- only extension denies this |
| Admin | confidential | allowed | YES |
| TestUser | no level set | allowed | YES -- unrestricted file |

The confidential-denied-for-TestUser case is the single most important test. If it passes, the extension is provably enforcing access control.

**Warning signs:** The test suite only tests anonymous users or only tests "admin can access everything." No test exercises a logged-in user being denied by the extension specifically.

**Phase to address:** Both PHPUnit integration and E2E HTTP tests.

---

### Pitfall 7: Thumbnail Path Not Tested -- img_auth.php Resolves Differently

**What goes wrong:** Tests verify that `/img_auth.php/a/ab/Secret.png` returns 403 for unauthorized users, but never test `/img_auth.php/thumb/a/ab/Secret.png/120px-Secret.png`. The thumbnail path has different resolution logic in img_auth.php -- it extracts the filename using `wfBaseName(dirname($path))` instead of `wfBaseName($path)`. If the extension's hook or MW's title resolution fails specifically on thumbnail paths, the file bytes leak through thumbnails.

**Why it happens:** Developers think of "the file" as one URL. But MediaWiki generates thumbnails on demand and serves them through a different URL path structure. The img_auth.php source code has an explicit `if (strpos($path, '/thumb/') === 0)` branch that uses different path parsing. This separate code path is a distinct attack surface.

**How to avoid:** Always test BOTH URL patterns for every protected file:
- `/img_auth.php/a/ab/Secret.png` (original file)
- `/img_auth.php/thumb/a/ab/Secret.png/120px-Secret.png` (thumbnail)

To ensure a real thumbnail exists to test against, first view the file page as Admin (triggers thumbnail generation), then test access to the thumbnail URL as an unauthorized user.

**Warning signs:** If your E2E test URLs never contain the string `/thumb/`, you are missing thumbnail coverage entirely.

**Phase to address:** E2E HTTP tests.

---

## Moderate Pitfalls

These cause test delays, flaky CI failures, or incomplete coverage, but do not produce silently wrong results.

---

### Pitfall 8: Docker Container Not Ready -- Static sleep(30) Is Unreliable in CI

**What goes wrong:** The existing `reinstall_test_env.sh` script uses `sleep 30` to wait for MediaWiki to initialize. In GitHub Actions (especially on shared runners), the cold-start time varies significantly. 30 seconds may not be enough (causing "Connection refused"), or it may be too much (wasting 20+ seconds of CI time per run).

**Why it happens:** MariaDB goes through a documented multi-phase startup: initialize database -> shut down -> restart without `--skip-networking`. MediaWiki then needs its own initialization. The official MariaDB Docker documentation warns: "The biggest problem with a healthcheck is it will return success during database initialization, or during /docker-entrypoint-initdb.d initialization. After running these points the entrypoint will abruptly shutdown the server to start it up again."

**How to avoid:** Replace `sleep 30` with proper health checks. For MariaDB:
```yaml
db:
  healthcheck:
    test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
    start_period: 30s
    interval: 10s
    timeout: 5s
    retries: 5
```
For MediaWiki:
```yaml
wiki:
  depends_on:
    db:
      condition: service_healthy
  healthcheck:
    test: ["CMD", "curl", "-sf", "http://localhost/api.php?action=query&format=json"]
    start_period: 60s
    interval: 10s
    timeout: 5s
    retries: 10
```
Then use `docker compose up -d --wait` instead of `docker compose up -d && sleep 30`.

**Warning signs:** CI tests fail intermittently with "Connection refused," "database not available," or "cURL error 7" on the first API call. Re-running fixes it.

**Phase to address:** CI pipeline setup.

---

### Pitfall 9: Test File Uploads Skipped -- Permission Level Set on Non-Existent Page

**What goes wrong:** A test sets a permission level on `File:Secret.png` by directly inserting into `page_props`, but the file was never actually uploaded. `Title::getArticleID()` returns 0 because the page does not exist, so `PermissionService::getLevel()` short-circuits to `return null` (line 56: `if ($pageId === 0) { return null; }`). The test then checks access and gets "allowed" (because null level = unrestricted), wrongly concluding the system is broken or silently passing for the wrong reason.

**Why it happens:** The permission system REQUIRES `$title->getArticleID() !== 0` to look up levels. A Title object for a non-existent page has article ID 0. The tempting shortcut of inserting directly into page_props without first creating the file page bypasses this fundamental check.

**How to avoid:** For PHPUnit integration tests, create real pages:
```php
$title = Title::makeTitle( NS_FILE, 'Secret.png' );
$this->insertPage( $title, 'Test file description' );
```
Note: `insertPage` creates the wiki page but not the actual file. For full-fidelity tests involving file operations, use `$this->getExistingTestPage( 'File:Secret.png' )` or the maintenance upload command.

For E2E tests, upload via the MW API first (using the `test_upload.png` already in the repo), then set permission levels.

**Warning signs:** Tests that set permission levels always pass, even when enforcement code is commented out. Or: `PermissionService::getLevel()` always returns null in tests.

**Phase to address:** PHPUnit integration and E2E tests.

---

### Pitfall 10: E2E Tests Authenticate via UI Instead of API -- Fragile and Slow

**What goes wrong:** E2E tests that authenticate by POSTing to Special:UserLogin via HTML form submission are fragile. The form structure can change between MW versions, CSRF tokens must be extracted from HTML, redirect chains differ per skin, and the login may need JavaScript processing. Tests become slow (each login attempt takes 2-5 seconds with redirects) and break on MW upgrades.

**Why it happens:** Developers test the way they manually test -- through the UI. But E2E tests need programmatic, reliable authentication.

**How to avoid:** Use the MediaWiki API for authentication:
```bash
# Step 1: Get login token
TOKEN=$(curl -s -c cookies.txt \
  'http://localhost:8888/api.php?action=query&meta=tokens&type=login&format=json' \
  | jq -r '.query.tokens.logintoken')

# Step 2: Log in
curl -s -b cookies.txt -c cookies.txt \
  -d "action=login&lgname=Admin&lgpassword=dockerpass&lgtoken=${TOKEN}&format=json" \
  http://localhost:8888/api.php
```
This is faster (single round-trip), more reliable, version-stable, and does not depend on skin HTML structure. The cookie jar (`-c`/`-b`) maintains session state for subsequent requests.

**Warning signs:** Login-dependent tests are the first to break on MW upgrades. Tests take 30+ seconds for a small suite because each test case authenticates through the UI.

**Phase to address:** E2E test infrastructure -- solve authentication first, before writing any endpoint tests.

---

### Pitfall 11: ImageBeforeProduceHTML Test Misses Parser Cache Bypass Verification

**What goes wrong:** Tests verify that `onImageBeforeProduceHTML` returns a placeholder `<span>` for unauthorized users. But they do not verify that `$parser->getOutput()->updateCacheExpiry(0)` was called. If that line is removed from the extension, the unit test still passes -- but in production, the parser cache would store the admin's view (with the real image) and serve it to all subsequent users regardless of their permission level.

**Why it happens:** The cache expiry call is a side effect, not a return value. Standard assertion patterns (check return value, check output HTML) do not capture it. This is the MW-specific manifestation of the known "caching facilities do not currently support rights-specific caching" issue.

**How to avoid:** In integration tests, explicitly verify cache expiry:
```php
$parserOutput = $parser->getOutput();
// After hook fires on a protected file:
$this->assertSame( 0, $parserOutput->getCacheExpiry(),
    'Parser cache must be disabled for pages with protected embedded images' );
```

For E2E tests, test the actual parser cache scenario:
1. View a page containing `[[File:Secret.png]]` as Admin (populates cache)
2. View the same page as TestUser (should see placeholder, NOT Admin's cached view)

**Warning signs:** No test in the suite asserts on `getCacheExpiry()`. No E2E test loads the same page as two different users.

**Phase to address:** PHPUnit integration tests for ImageBeforeProduceHTML hook.

---

### Pitfall 12: labki-platform Image May Not Include PHPUnit

**What goes wrong:** PHPUnit integration tests need to run inside the MediaWiki Docker container (to access MW bootstrap, database, services). But the `ghcr.io/labki-org/labki-platform:latest` image is a production-style deployment image -- it may not include `vendor/bin/phpunit` or the PHPUnit dev dependencies.

**Why it happens:** Production Docker images typically run `composer install --no-dev` to exclude dev dependencies (including PHPUnit). The labki-platform image is designed for running MediaWiki, not for testing MediaWiki extensions.

**How to avoid:** Verify early:
```bash
docker compose exec wiki php vendor/bin/phpunit --version
```
If PHPUnit is not available, options are:
1. Install dev dependencies at CI time: `docker compose exec wiki composer install --dev`
2. Build a test-specific Docker image layer that adds PHPUnit
3. Run unit tests outside the container (they don't need MW bootstrap) and only run integration tests inside
4. Use `composer require --dev phpunit/phpunit` in the extension directory and run from there

**Warning signs:** CI step "Run PHPUnit" fails immediately with "command not found" or "class not found" for PHPUnit.

**Phase to address:** CI pipeline setup -- verify this before writing any tests.

---

### Pitfall 13: GitHub Actions CI Uses Default Runners with No Docker Layer Caching

**What goes wrong:** Each CI run pulls `ghcr.io/labki-org/labki-platform:latest` (likely 500MB-1GB) and `mariadb:10.11`, starts containers, and waits for initialization. Without Docker layer caching, every PR triggers full image downloads. Total setup time: 3-5 minutes per run, while the actual test suite might finish in 30 seconds.

**Why it happens:** GitHub Actions runners start fresh with empty Docker caches. Image pull time dominates CI duration for small-to-medium test suites.

**How to avoid:** Cache Docker images between CI runs:
```yaml
- uses: actions/cache@v4
  with:
    path: /tmp/docker-images
    key: docker-${{ hashFiles('docker-compose.yml') }}
- run: |
    if [ -f /tmp/docker-images/images.tar ]; then
      docker load < /tmp/docker-images/images.tar
    fi
# After docker compose up:
- run: |
    mkdir -p /tmp/docker-images
    docker save $(docker compose config --images) > /tmp/docker-images/images.tar
```

**Warning signs:** CI runs consistently take 4+ minutes even for trivial test changes. The "docker compose up" step dominates the pipeline duration.

**Phase to address:** GitHub Actions CI optimization (can be deferred to after initial CI is working).

---

## Minor Pitfalls

These cause annoyance or small coverage gaps but are easily fixed once identified.

---

### Pitfall 14: Test Files Not Named *Test.php Are Silently Ignored

**What goes wrong:** A test file named `EnforcementHooksTests.php` (plural) or `TestEnforcementHooks.php` (prefix instead of suffix) is never discovered by PHPUnit. The developer thinks tests are passing because PHPUnit reports 0 failures -- but 0 tests ran from that file.

**How to avoid:** Name every test file with the `Test.php` suffix (singular). Examples: `PermissionServiceTest.php`, `EnforcementHooksTest.php`, `ConfigTest.php`. Verify test count in CI output: add a CI step that asserts the expected minimum number of tests ran.

**Phase to address:** First test file creation.

---

### Pitfall 15: Missing @covers Annotation Breaks Coverage Reports

**What goes wrong:** PHPUnit's `forceCoversAnnotation` option (if enabled in the MW test suite) will skip tests without `@covers`. Even without it, coverage reports attribute lines to the wrong source classes or show artificially low coverage.

**How to avoid:** Add `@covers` to every test class:
```php
/**
 * @covers \FilePermissions\PermissionService
 * @group Database
 */
class PermissionServiceTest extends MediaWikiIntegrationTestCase {
```

**Phase to address:** First test file creation.

---

### Pitfall 16: Fail-Closed Test Only Checks the Downstream Consumer

**What goes wrong:** A test sets `$wgFilePermInvalidConfig = true` via `setMwGlobals` and verifies that `canUserAccessLevel()` returns false. This is correct but incomplete. It does not verify that `RegistrationHooks::onRegistration()` actually sets the global flag when it detects invalid config. The registration callback could be broken and the fail-closed path would never activate in production.

**How to avoid:** Test the FULL chain: set invalid config values (e.g., `$wgFilePermLevels = []`), call the registration validation logic, then verify the flag is set AND permission checks deny access. Do not test only the downstream consumer of the flag.

**Warning signs:** The fail-closed unit test passes, but a manual test with invalid config still allows access because the registration hook has a bug.

**Phase to address:** Integration tests for RegistrationHooks.

---

### Pitfall 17: setUp() Missing parent::setUp() Call

**What goes wrong:** A test class overrides `setUp()` but forgets to call `parent::setUp()` as its first statement. This prevents `MediaWikiIntegrationTestCase` from setting up temporary database tables, service overrides, and state reset machinery. Tests may fail cryptically or -- worse -- silently write to the live database.

**How to avoid:** Always call parent first:
```php
protected function setUp(): void {
    parent::setUp();
    // Your setup code here
}
```
Similarly, `tearDown()` must call `parent::tearDown()` as its LAST statement, not first.

**Phase to address:** First test file creation.

---

## Technical Debt Patterns

| Pattern | Risk | Impact | Detection |
|---------|------|--------|-----------|
| Static Config class reads globals directly | Tests require `setMwGlobals` for every config value | Medium -- boilerplate in every test | Review each test class for `setMwGlobals` calls covering all 5 config vars |
| RequestContext singleton in hooks | Tests must set user on global context | Medium -- easy to forget, hard to debug | Search hook code for `RequestContext::getMain()` calls |
| In-process cache in PermissionService | Stale cache across test methods | High -- silent false passes | Search service classes for `private array` cache properties |
| `page_props` as storage | Tests need real pages with article IDs > 0 | Medium -- extra setup per test | Verify tests call `insertPage()` before `setLevel()` |
| Hooks fetch user from global state | Cannot inject test user directly | Medium -- must use RequestContext workaround | Review hook signatures for missing `$user` parameter |

## Integration Gotchas

| Component | Gotcha | Mitigation |
|-----------|--------|------------|
| MariaDB in Docker | Double-restart during initialization (init -> shutdown -> restart) | Use `healthcheck.sh --connect --innodb_initialized` |
| labki-platform image | May not include PHPUnit or dev dependencies | Verify `vendor/bin/phpunit --version` inside container early |
| Apache config volume mount | `.conf` file mount may not trigger Apache reload | Verify `apache2ctl -S` shows the config, or add explicit reload |
| img_auth.php PATH_INFO | CGI-based PHP may not support PATH_INFO | Test that `img_auth.php/test` returns a meaningful response, not a blank page |
| File upload in tests | MW API upload requires a real image file (not empty/zero-byte) | Use `test_upload.png` (already in repo, 69 bytes) |
| MediaWiki job queue | Permission changes via API may trigger deferred updates | Call `DeferredUpdates::doUpdates()` after API calls in integration tests |
| Temporary DB tables | `@group Database` clones specific tables; custom tables may be missing | Verify `page_props` is accessible in test methods |
| Cookie jar management | curl `-c`/`-b` cookie files accumulate across tests | Use a fresh cookie jar file per test case or per authentication session |

## Security Test Mistakes

| Mistake | Consequence | Correct Test |
|---------|-------------|-------------|
| Only testing anonymous users | MW core denies anonymous access; extension never actually tested | Test with TestUser (logged in, has read access, lacks confidential grant) |
| Only testing File: page access | img_auth.php and embedded images are separate enforcement paths | Test all 3 hooks independently: getUserPermissionsErrors, ImgAuthBeforeStream, ImageBeforeProduceHTML |
| Not testing thumbnails | Thumbnail path resolution in img_auth.php is a separate code branch | Test `/img_auth.php/thumb/...` URLs explicitly |
| Not testing with `wgFilePermDefaultLevel` set | Default level enforcement is a separate code path from explicit levels | Set a default level, upload a file WITHOUT setting explicit level, verify enforcement |
| Not testing fail-closed behavior | Invalid config should deny ALL access, not allow all | Set `$wgFilePermLevels = []`, trigger validation, verify all access denied |
| Not testing level change propagation | After changing a file from public to confidential, TestUser must immediately lose access | Change level mid-test, re-check access in same test |
| Not testing files with no permission level | Grandfathered files (no level, no default) should be unrestricted | Verify a file with no `page_props` entry allows access for all logged-in users |
| Checking only HTTP status code | 403 could come from Apache, MW core, or extension | Verify response body contains extension-specific error messages |
| Not testing the wildcard grant | sysop has `['*']` grant meaning all levels; this is a distinct code path from explicit level grants | Verify Admin (sysop with wildcard) can access all three levels |
| Missing negative control test | Without a "no protection" control, you cannot distinguish "extension blocks" from "everything is blocked" | Include a test where an unprotected file IS accessible to TestUser |

## "Looks Done But Isn't" Checklist

Before declaring the test suite complete, verify each item:

- [ ] **Each enforcement hook has dedicated tests** -- not just PermissionService (there are 3 hooks: getUserPermissionsErrors, ImgAuthBeforeStream, ImageBeforeProduceHTML)
- [ ] **Tests include a negative control** -- an unprotected file that IS accessible to all logged-in users
- [ ] **All THREE permission levels are tested** -- public, internal, AND confidential, not just one
- [ ] **E2E tests verify response body**, not just HTTP status code
- [ ] **At least one test changes a permission level and re-checks access** (propagation)
- [ ] **Thumbnail URLs are tested in E2E** -- `/img_auth.php/thumb/...` path present
- [ ] **Direct /images/ path tested separately from img_auth.php** -- proving Apache blocks, not MW
- [ ] **Parser cache scenario is tested** -- two different users viewing the same page with embedded protected image
- [ ] **Fail-closed path tested end-to-end** -- invalid config causes all access denial
- [ ] **Test user HAS read access to wiki pages** -- proving denial comes from extension, not MW core read restriction
- [ ] **CI health checks replace static sleep** -- `docker compose up --wait` or equivalent
- [ ] **Test count is verified in CI** -- a step that fails if expected test count drops unexpectedly
- [ ] **Default level enforcement tested** -- file with no explicit level, namespace default active
- [ ] **Wildcard grant tested** -- sysop with `['*']` can access all levels

## Recovery Strategies

| Problem | Symptom | Recovery |
|---------|---------|----------|
| Tests pass but extension does not work | Manual testing contradicts automated results | Audit for false-pass patterns: wrong user, missing page, status-code-only assertions |
| Tests fail only in CI | "Connection refused" or "table not found" | Add health checks, increase startup timeouts, verify Docker volume mounts |
| Tests fail in random order | Pass individually, fail in suite run | In-process cache pollution; fetch services fresh per test, check for stale singletons |
| Coverage report shows 0% | Tests exist and pass but coverage is empty | Verify `@covers` annotations, confirm test files end in `Test.php`, check phpunit.xml includes extension path |
| E2E tests intermittently fail | Pass 90% of the time | Check for race conditions: file upload + permission set timing, use explicit waits or verification steps between operations |
| Permission changes don't take effect in test | setLevel succeeds but getLevel returns old value | In-process cache; reset service or use fresh service instance per assertion |
| Uploaded file not accessible via img_auth.php | 404 on valid upload | Thumbnail not yet generated; access file page first to trigger thumbnail creation |
| PHPUnit not found in container | "command not found" on vendor/bin/phpunit | Install dev dependencies or use a test-specific image layer |
| All hook tests give identical results | Same outcome for Admin and TestUser | RequestContext user not set; add `RequestContext::getMain()->setUser()` |

## Pitfall-to-Phase Mapping

| Phase | Pitfalls to Guard Against | Priority |
|-------|--------------------------|----------|
| PHPUnit unit tests | P2 (Config globals), P14 (file naming), P15 (covers annotation), P17 (parent setUp) | Foundation -- get conventions right here |
| PHPUnit integration tests | P1 (cache pollution), P2 (globals), P3 (RequestContext user), P4 (Database group), P6 (deny source confusion), P9 (page must exist), P11 (parser cache), P16 (fail-closed chain) | Core risk area -- most pitfalls concentrate here |
| E2E HTTP tests | P5 (403 source confusion), P6 (deny source), P7 (thumbnail paths), P9 (file must exist), P10 (API auth not UI auth) | Security critical -- proves byte-level enforcement |
| CI/Docker setup | P8 (health checks), P12 (PHPUnit availability), P13 (layer caching) | Reliability critical -- tests must actually run |
| CI pipeline hardening | P13 (caching), P14 (file discovery), test count validation | Maintenance -- prevents silent regressions |

## Sources

**HIGH Confidence (Official MediaWiki Documentation):**
- [Manual:PHP unit testing/Writing unit tests for extensions](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests_for_extensions) -- Extension test structure, base classes, annotations, directory layout
- [Manual:PHP unit testing/Writing unit tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests) -- Database groups, setUp/tearDown patterns, setMwGlobals usage
- [Manual:PHP unit testing/Running the tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Running_the_tests) -- Test runner configuration, autoloading, bootstrap requirements
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization) -- img_auth.php configuration, security requirements, PATH_INFO, thumbnail handling
- [Security issues with authorization extensions](https://www.mediawiki.org/wiki/Security_issues_with_authorization_extensions) -- Known bypass vectors, parser cache issues, multiple exit paths, enumerated flaws
- [Manual:Preventing access](https://www.mediawiki.org/wiki/Manual:Preventing_access) -- Read restriction limitations, file access is separate from page access
- [Manual:Security](https://www.mediawiki.org/wiki/Manual:Security) -- MW security model, extension quality risks
- [Continuous integration/Tutorials/Debugging PHPUnit Parallel Test Failures](https://www.mediawiki.org/wiki/Continuous_integration/Tutorials/Debugging_PHPUnit_Parallel_Test_Failures) -- State leaking, doLightweightServiceReset, RequestContext::resetMain, binary search debugging
- [Manual:Configuration for developers](https://www.mediawiki.org/wiki/Manual:Configuration_for_developers) -- setMwGlobals, overrideConfigValues, setService patterns
- [ImgAuthBeforeStreamHook Interface Reference](https://doc.wikimedia.org/mediawiki-core/master/php/interfaceMediaWiki_1_1Hook_1_1ImgAuthBeforeStreamHook.html) -- Hook signature and invocation context
- [img_auth.php source (master branch)](https://github.com/wikimedia/mediawiki/blob/master/img_auth.php) -- Thumbnail path resolution logic, public wiki check, file existence validation

**MEDIUM Confidence (Community/Third-Party, Verified Against Official):**
- [Testing MediaWiki code with PHPUnit - Kosta Harlan](https://www.kostaharlan.net/posts/mediawiki-phpunit/) -- Practical extension testing guide
- [Using Healthcheck - MariaDB Documentation](https://mariadb.com/docs/server/server-management/automated-mariadb-deployment-and-administration/docker-and-mariadb/using-healthcheck-sh/) -- healthcheck.sh flags, double-restart problem
- [MariaDB Docker Official Images Healthcheck](https://mariadb.org/mariadb-server-docker-official-images-healthcheck-without-mysqladmin/) -- Why mysqladmin ping is insufficient
- [Docker Compose Health Check Action](https://github.com/marketplace/actions/docker-compose-health-check) -- CI health check tooling
- [From CI Chaos to Orchestration - GitHub Actions + Docker Compose](https://medium.com/@sreeprad99/from-ci-chaos-to-orchestration-deep-dive-into-github-actions-service-containers-and-docker-compose-7cb2ff335864) -- Service container limitations, depends_on patterns

**Codebase Analysis (Direct Code Review):**
- `PermissionService.php` lines 28, 56 -- in-process cache and page ID check
- `EnforcementHooks.php` lines 76-91 -- RequestContext::getMain()->getUser() in ImgAuthBeforeStream
- `EnforcementHooks.php` lines 128-129 -- updateCacheExpiry(0) for parser cache
- `Config.php` lines 19-23 -- global variable access pattern
- `docker-compose.yml` -- current container configuration, no health checks
- `tests/LocalSettings.test.php` -- private wiki config, read=false for anonymous
- `tests/apache-filepermissions.conf` -- Apache directory block
