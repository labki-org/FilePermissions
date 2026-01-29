# Stack Research: Testing and CI Infrastructure

**Domain:** MediaWiki extension testing and CI
**Researched:** 2026-01-29
**Confidence:** HIGH (PHPUnit infrastructure, Docker patterns), MEDIUM (GitHub Actions for custom Docker images)

This document covers the testing stack for the FilePermissions MediaWiki extension. It does NOT re-research the extension's core implementation stack (hooks, PageProps, DI) -- that was covered in the prior milestone's STACK.md. This focuses exclusively on PHPUnit testing, HTTP leak checks, and CI automation.

---

## Recommended Stack

### Core Testing Technologies

| Technology | Version | Purpose | Why | Confidence |
|------------|---------|---------|-----|------------|
| PHPUnit | 9.6.x | Test framework | MW 1.44 ships PHPUnit 9.6.21 via Composer; NOT yet on PHPUnit 10 | HIGH |
| MediaWikiUnitTestCase | MW 1.44 | Pure unit test base class | No DB, no globals, no services -- fast isolated tests | HIGH |
| MediaWikiIntegrationTestCase | MW 1.44 | Integration test base class | Provides DB access, service overrides, config overrides | HIGH |
| curl / shell scripts | System | HTTP E2E leak checks | Tests img_auth.php 403 responses and session-gated access | HIGH |
| GitHub Actions | N/A | CI automation | Docker Compose native support on ubuntu-latest runners | HIGH |
| Docker Compose | v2 | Test environment orchestration | Existing docker-compose.yml already provisions MW + MariaDB | HIGH |

### Supporting Libraries (Already in MW Core)

| Library | Version | Purpose | When to Use | Confidence |
|---------|---------|---------|-------------|------------|
| PHPUnit MockObject | 9.6.x | Mocking dependencies | Unit tests for hook classes with PermissionService mock | HIGH |
| MediaWikiTestCaseTrait | MW 1.44 | Utility assertions | Inherited via both test base classes | HIGH |
| `@group Database` | PHPUnit | DB test isolation | Integration tests that write to page_props | HIGH |
| `@covers` annotations | PHPUnit | Coverage tracking | Required by MW convention; validates coverage reports | HIGH |

---

## MediaWiki Test Base Class Hierarchy

This is the single most important decision for each test file. Choose wrong and tests break silently or run 10x slower.

### Decision Table

| Class | When to Use | Has DB? | Has Services? | Has Globals? | Speed |
|-------|-------------|---------|---------------|--------------|-------|
| `MediaWikiUnitTestCase` | Pure logic with mockable deps | NO | NO | NO | Fast (~ms) |
| `MediaWikiIntegrationTestCase` | Needs MW services, DB, or config | YES (with `@group Database`) | YES | YES | Slow (~100ms+) |

### MediaWikiUnitTestCase

Extends `PHPUnit\Framework\TestCase` with MW-specific additions. Blocks access to `MediaWikiServices` (will throw if you try). Forces pure unit tests.

**Use for FilePermissions:**
- `Config::getLevels()` -- but requires mocking globals (`$wgFilePermLevels`), which unit tests block. This class is better tested via integration tests due to its global access pattern.
- `PermissionService::canUserAccessLevel()` -- with mocked `UserGroupManager` and `IConnectionProvider`
- `PermissionService::canUserAccessFile()` -- with mocked dependencies
- `RegistrationHooks::onRegistration()` -- config validation logic

**Key constraints:**
- Cannot access `$wgXxx` globals (they are unset)
- Cannot call `MediaWikiServices::getInstance()`
- Cannot use database
- Must mock ALL dependencies via constructor injection

**Example pattern for this extension:**
```php
<?php
namespace FilePermissions\Tests\Unit;

use FilePermissions\PermissionService;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @covers \FilePermissions\PermissionService
 */
class PermissionServiceTest extends MediaWikiUnitTestCase {

    public function testCanUserAccessLevel_wildcardGrant(): void {
        $ugm = $this->createMock( UserGroupManager::class );
        $ugm->method( 'getUserEffectiveGroups' )
            ->willReturn( [ '*', 'user', 'sysop' ] );

        $dbProvider = $this->createMock( IConnectionProvider::class );

        $service = new PermissionService( $dbProvider, $ugm );

        // Note: canUserAccessLevel reads Config::getGroupGrants()
        // which uses globals -- this will NOT work in a unit test
        // unless Config is also mocked. See integration test instead.
    }
}
```

**Important caveat for this extension:** The `Config` class uses `global` variables directly (`global $wgFilePermLevels` etc.), which `MediaWikiUnitTestCase` unsets. Any class that calls `Config::` methods cannot be fully unit tested without refactoring `Config` to accept injected values, or by using integration tests instead. This is a known pattern in MW extensions that use static config accessors.

### MediaWikiIntegrationTestCase

Full MW environment with database, services, and config overrides.

**Use for FilePermissions:**
- `PermissionService` methods that touch the database (getLevel, setLevel, removeLevel)
- Hook handlers that need `RequestContext`, `Title`, `User` objects
- `ApiFilePermSetLevel` API module tests
- Config validation (RegistrationHooks) with real MW config
- Any test needing `overrideConfigValue()` or `setService()`

**Key capabilities:**
- `$this->overrideConfigValue( 'FilePermLevels', [...] )` -- override MW config for test
- `$this->overrideConfigValues( [...] )` -- override multiple config values
- `$this->setService( 'FilePermissions.PermissionService', $mock )` -- replace DI service
- `$this->getServiceContainer()` -- access MediaWikiServices
- `$this->db` -- database connection (when using `@group Database`)
- `$this->insertPage( 'File:Test.png' )` -- create test pages

**Example pattern for this extension:**
```php
<?php
namespace FilePermissions\Tests\Integration;

use FilePermissions\PermissionService;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \FilePermissions\PermissionService
 */
class PermissionServiceIntegrationTest extends MediaWikiIntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->overrideConfigValues( [
            'FilePermLevels' => [ 'public', 'internal', 'confidential' ],
            'FilePermGroupGrants' => [
                'sysop' => [ '*' ],
                'user' => [ 'public', 'internal' ],
            ],
            'FilePermDefaultLevel' => null,
            'FilePermNamespaceDefaults' => [],
            'FilePermInvalidConfig' => false,
        ] );
    }

    public function testSetAndGetLevel(): void {
        // Insert a test page in NS_FILE
        $page = $this->insertPage( 'Test.png', '', NS_FILE );
        $title = $page['title'];

        $service = $this->getServiceContainer()
            ->getService( 'FilePermissions.PermissionService' );

        $service->setLevel( $title, 'confidential' );
        $this->assertSame( 'confidential', $service->getLevel( $title ) );
    }
}
```

### What About MediaWikiLargeTest?

There is NO separate `MediaWikiLargeTest` base class. "Large" tests in MW are simply integration tests annotated with `@group large` for PHPUnit's timeout handling. Not needed for this project.

---

## Test Directory Structure

Following MW convention exactly.

```
FilePermissions/
  tests/
    phpunit/
      unit/
        ConfigTest.php
        PermissionServiceTest.php
        Hooks/
          EnforcementHooksTest.php
      integration/
        PermissionServiceIntegrationTest.php
        Hooks/
          EnforcementHooksIntegrationTest.php
          UploadHooksIntegrationTest.php
        Api/
          ApiFilePermSetLevelTest.php
    http/
      run_leak_checks.sh
    LocalSettings.test.php        (existing)
    apache-filepermissions.conf   (existing)
    scripts/
      reinstall_test_env.sh       (existing)
```

**Naming convention:** Test files MUST end in `Test.php` or PHPUnit will not discover them. Class names must match filenames.

**Directory mirroring:** Unit test paths mirror the source structure. `includes/Hooks/EnforcementHooks.php` gets `tests/phpunit/unit/Hooks/EnforcementHooksTest.php`.

---

## PHPUnit Configuration

MW extensions do NOT need their own `phpunit.xml.dist`. Tests are discovered by MW core's `phpunit.xml.dist` which scans `extensions/**/tests/phpunit/unit/` and `extensions/**/tests/phpunit/integration/`.

However, for running tests inside a Docker container where the extension is mounted separately (not under core's `extensions/` directory), a lightweight runner script is needed.

### Running Tests via MW Core's PHPUnit

When tests are placed in the standard directory structure and the extension is loaded in LocalSettings.php, MW core discovers them automatically.

**From MW core directory (inside container):**

```bash
# Run all extension unit tests
php vendor/bin/phpunit --testsuite extensions:unit

# Run a specific test file
php vendor/bin/phpunit extensions/FilePermissions/tests/phpunit/unit/PermissionServiceTest.php

# Run a specific test class with filter
php vendor/bin/phpunit --filter PermissionServiceTest

# Run only FilePermissions tests by group
php vendor/bin/phpunit --group FilePermissions

# Run integration tests (requires DB)
php vendor/bin/phpunit extensions/FilePermissions/tests/phpunit/integration/

# Using composer script
composer phpunit -- extensions/FilePermissions/tests/phpunit/
```

### Group Annotations

Every test class in this extension should use:

```php
/**
 * @group FilePermissions
 * @group Database          // Only if test needs DB
 * @covers \FilePermissions\ClassName
 */
```

The `@group FilePermissions` annotation enables running only this extension's tests:
```bash
php vendor/bin/phpunit --group FilePermissions
```

The `@group Database` annotation tells MW to set up temporary database tables. Without it, DB access fails.

The `@covers` annotation is required by MW convention and is enforced by `MediaWikiCoversValidator` trait. Each test class must declare what production code it covers.

---

## Docker Test Execution

The project uses `ghcr.io/labki-org/labki-platform` which is a custom MW 1.44 Docker image. The extension is mounted at `/mw-user-extensions/FilePermissions`.

### Key Insight: Extension Mount Path

The docker-compose.yml mounts the extension at `/mw-user-extensions/FilePermissions`, NOT at the standard MW `extensions/` directory. This means MW core's phpunit.xml.dist auto-discovery of `extensions/**/tests/phpunit/` will NOT find these tests.

**Solution:** The labki-platform image likely symlinks or configures user extensions to be discoverable. If not, tests must be run by specifying the path directly or by creating a symlink.

### Docker Exec Commands

```bash
# Ensure MW container is running
docker compose up -d

# Wait for initialization
docker compose exec -T wiki php maintenance/run.php version

# Run unit tests (no DB required)
docker compose exec -T wiki php vendor/bin/phpunit \
  /mw-user-extensions/FilePermissions/tests/phpunit/unit/

# Run integration tests (needs DB)
docker compose exec -T wiki php vendor/bin/phpunit \
  /mw-user-extensions/FilePermissions/tests/phpunit/integration/

# Run all FilePermissions tests
docker compose exec -T wiki php vendor/bin/phpunit \
  /mw-user-extensions/FilePermissions/tests/phpunit/

# Run with coverage report
docker compose exec -T wiki php vendor/bin/phpunit \
  --coverage-text \
  /mw-user-extensions/FilePermissions/tests/phpunit/
```

**The `-T` flag** disables pseudo-TTY allocation, which is required in CI (GitHub Actions has no TTY).

### Bootstrap Consideration

When running tests by path outside MW core's standard extension directory, PHPUnit needs MW's bootstrap. The MW core `phpunit.xml.dist` at the repo root handles this. If running tests by path, the working directory should be MW core's root so that `phpunit.xml.dist` is auto-detected.

If the container has `phpunit.xml.dist` at `/var/www/html/phpunit.xml.dist` (standard MW core location), then running from that directory works:

```bash
docker compose exec -T -w /var/www/html wiki \
  php vendor/bin/phpunit \
  /mw-user-extensions/FilePermissions/tests/phpunit/
```

---

## HTTP Leak Check Testing (E2E)

These tests verify that img_auth.php returns proper 403 responses and that session cookies correctly gate file access. They run against the live Docker environment via HTTP.

### Recommended Approach: Shell Script with curl

**Why curl over PHP HTTP clients:**
- Tests the actual HTTP path (Apache -> img_auth.php -> hook)
- No PHP bootstrap needed -- tests the server as a black box
- Can verify HTTP status codes, headers, and cookie behavior directly
- Simple, no additional dependencies
- Matches how a real attacker would probe for leaks

**Why NOT PHPUnit for these tests:**
- PHPUnit runs inside PHP, bypassing Apache and img_auth.php
- MW's test harness uses internal request handling, not real HTTP
- We need to test the actual HTTP endpoint, not the PHP function

### Authentication Flow for curl Tests

MediaWiki uses `action=clientlogin` for session establishment:

```bash
# Step 1: Get login token
COOKIE_JAR=$(mktemp)
TOKEN=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  'http://localhost:8888/api.php?action=query&meta=tokens&type=login&format=json' \
  | jq -r '.query.tokens.logintoken')

# Step 2: Log in via clientlogin
curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  -d "action=clientlogin&username=Admin&password=dockerpass&loginreturnurl=http://localhost:8888&logintoken=$TOKEN&format=json" \
  'http://localhost:8888/api.php'

# Step 3: Request protected file (should succeed for Admin)
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' \
  -b "$COOKIE_JAR" \
  'http://localhost:8888/img_auth.php/TestProtectedFile.png')

# Step 4: Verify
if [ "$HTTP_CODE" = "200" ]; then
  echo "PASS: Admin can access protected file"
else
  echo "FAIL: Expected 200, got $HTTP_CODE"
fi
```

### Leak Check Categories

| Test | Method | Expected | What It Proves |
|------|--------|----------|---------------|
| Anonymous access to protected file | curl with no cookies | 403 | img_auth.php denies anonymous |
| Unauthorized user access | curl with TestUser session | 403 | Group grants enforced at HTTP level |
| Authorized user access | curl with Admin session | 200 | Legitimate access works |
| Direct /images/ bypass | curl to /images/... path | 403 | Apache config blocks direct access |
| Thumbnail access | curl to /img_auth.php/thumb/... | 403 | Thumbnail path also protected |

### Script Structure

```bash
#!/bin/bash
# tests/http/run_leak_checks.sh
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8888}"
PASS=0
FAIL=0

assert_http_code() {
  local description="$1"
  local expected="$2"
  local actual="$3"
  if [ "$actual" = "$expected" ]; then
    echo "PASS: $description (HTTP $actual)"
    ((PASS++))
  else
    echo "FAIL: $description (expected $expected, got $actual)"
    ((FAIL++))
  fi
}

login_as() {
  local user="$1" pass="$2" jar="$3"
  local token
  token=$(curl -s -b "$jar" -c "$jar" \
    "$BASE_URL/api.php?action=query&meta=tokens&type=login&format=json" \
    | jq -r '.query.tokens.logintoken')
  curl -s -b "$jar" -c "$jar" \
    -d "action=clientlogin&username=$user&password=$pass&loginreturnurl=$BASE_URL&logintoken=$token&format=json" \
    "$BASE_URL/api.php" > /dev/null
}

# ... test cases ...

echo "Results: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
```

---

## GitHub Actions CI Workflow

### Recommended Architecture

Two approaches exist for MW extension CI. Given that this project has an existing Docker Compose setup with a custom image, the Docker Compose approach is strongly preferred over `edwardspec/github-action-build-mediawiki`.

**Why Docker Compose over `github-action-build-mediawiki`:**
- The project already has a working `docker-compose.yml` with the `labki-platform` image
- The labki-platform image includes PHPUnit and all required extensions
- Same environment for local dev and CI (no "works on my machine" drift)
- HTTP leak tests need a running Apache server, which Docker Compose provides
- `github-action-build-mediawiki` downloads vanilla MW, which lacks the custom platform setup

### Workflow Structure

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  phpunit:
    name: PHPUnit Tests
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Start test environment
        run: docker compose up -d --wait
        timeout-minutes: 5

      - name: Wait for MediaWiki initialization
        run: |
          for i in $(seq 1 60); do
            if docker compose exec -T wiki php maintenance/run.php version 2>/dev/null; then
              echo "MediaWiki is ready"
              break
            fi
            echo "Waiting for MediaWiki... ($i/60)"
            sleep 5
          done

      - name: Run unit tests
        run: |
          docker compose exec -T -w /var/www/html wiki \
            php vendor/bin/phpunit \
            /mw-user-extensions/FilePermissions/tests/phpunit/unit/

      - name: Run integration tests
        run: |
          docker compose exec -T -w /var/www/html wiki \
            php vendor/bin/phpunit \
            /mw-user-extensions/FilePermissions/tests/phpunit/integration/

      - name: Tear down
        if: always()
        run: docker compose down -v

  http-leak-checks:
    name: HTTP Leak Checks
    runs-on: ubuntu-latest
    needs: phpunit  # Run after PHPUnit passes
    steps:
      - uses: actions/checkout@v4

      - name: Start test environment
        run: docker compose up -d --wait
        timeout-minutes: 5

      - name: Wait for MediaWiki initialization
        run: |
          for i in $(seq 1 60); do
            if docker compose exec -T wiki php maintenance/run.php version 2>/dev/null; then
              echo "MediaWiki is ready"
              break
            fi
            sleep 5
          done

      - name: Create test user
        run: |
          docker compose exec -T wiki \
            php maintenance/run.php createAndPromote TestUser testpass123 \
            || echo "User may already exist"

      - name: Upload test file and set permission
        run: |
          # Upload via API and set permission via SQL
          # (specific commands depend on test setup script)
          docker compose exec -T wiki \
            php maintenance/run.php eval --code '
              // Upload and configure test files
            '

      - name: Run HTTP leak checks
        run: |
          chmod +x tests/http/run_leak_checks.sh
          tests/http/run_leak_checks.sh

      - name: Tear down
        if: always()
        run: docker compose down -v
```

### GitHub Actions Considerations

| Concern | Solution |
|---------|----------|
| Docker Compose availability | Pre-installed on `ubuntu-latest` runners |
| Container startup time | `--wait` flag + poll loop; `timeout-minutes` as safety net |
| No TTY in CI | Always use `-T` flag with `docker compose exec` |
| Container registry auth | `ghcr.io` public images need no auth; private ones need `docker login` step |
| Volume mounts | `./ ` checkout path works; volumes use runner's filesystem |
| MariaDB readiness | Health check or poll for `mysqladmin ping` |
| Test artifacts | Capture PHPUnit JUnit XML via `--log-junit` for GitHub test reporting |

### Potential Issue: Private Container Registry

If `ghcr.io/labki-org/labki-platform` is private, the CI workflow needs a login step:

```yaml
- name: Login to GitHub Container Registry
  uses: docker/login-action@v3
  with:
    registry: ghcr.io
    username: ${{ github.actor }}
    password: ${{ secrets.GITHUB_TOKEN }}
```

---

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| Test framework | PHPUnit 9.6 (via MW) | Pest PHP | MW core bundles PHPUnit; Pest not compatible with MW test base classes |
| Unit test base | MediaWikiUnitTestCase | PHPUnit\Framework\TestCase | MW base adds covers validation, global cleanup, MediaWiki-specific utilities |
| Integration base | MediaWikiIntegrationTestCase | MediaWikiTestCase (old name) | Same class, just the old name; use the new name |
| HTTP tests | curl shell scripts | PHPUnit + GuzzleHTTP | Guzzle adds dependency; curl tests the actual HTTP path through Apache |
| HTTP tests | curl shell scripts | Playwright/Selenium | Overkill for HTTP status code checks; browser automation not needed |
| CI approach | Docker Compose in GH Actions | `edwardspec/github-action-build-mediawiki` | Project has custom Docker image; the action downloads vanilla MW |
| CI approach | Docker Compose in GH Actions | `gesinn-it-pub/docker-compose-ci` | Adds submodule complexity; our docker-compose.yml already works |
| CI approach | Docker Compose in GH Actions | Wikimedia's Zuul/Jenkins | Only for extensions hosted on Gerrit; not applicable here |
| Config mocking | `overrideConfigValue()` | Refactor Config class | Override is the MW-standard pattern; refactoring adds complexity |
| Service mocking | `setService()` / constructor mock | ServiceWiring override | setService is purpose-built for tests |

---

## What NOT to Use

| Avoid | Why | Use Instead | Confidence |
|-------|-----|-------------|------------|
| PHPUnit 10/11 syntax | MW 1.44 uses PHPUnit 9.6; PHPUnit 10 migration not complete | PHPUnit 9.6 API | HIGH |
| `@dataProvider` as non-static | Will break when MW moves to PHPUnit 10; data providers must be static | Static data provider methods | HIGH |
| `MediaWikiTestCase` | Renamed to `MediaWikiIntegrationTestCase`; old name may be removed | `MediaWikiIntegrationTestCase` | HIGH |
| `phpunit.php` maintenance script | Deprecated entry point | `vendor/bin/phpunit` or `composer phpunit` | HIGH |
| `bootstrap.integration.php` | Deprecated | `bootstrap.php` (auto-detected via phpunit.xml.dist) | HIGH |
| `suite.xml` | Deprecated | `phpunit.xml.dist` at MW root | HIGH |
| Own `phpunit.xml.dist` in extension | Not needed; MW core discovers extension tests | Rely on MW core's phpunit.xml.dist | MEDIUM |
| Selenium/Playwright for leak checks | Overkill; we need HTTP codes, not DOM testing | curl scripts | HIGH |
| `$this->setMwGlobals()` | Deprecated in favor of config overrides | `$this->overrideConfigValue()` | HIGH |

---

## Stack Patterns by Test Type

### Pattern 1: Pure Unit Test (Config/Logic)

**For:** Classes with no MW service dependencies, pure computation
**Base class:** `MediaWikiUnitTestCase`
**Location:** `tests/phpunit/unit/`
**DB:** None
**Speed:** Milliseconds

**Applicable to:** Limited applicability in this extension because `Config` uses globals. Best for testing `PermissionService` methods where all dependencies can be mocked.

### Pattern 2: Integration Test with DB

**For:** Service classes that read/write database, hook handlers
**Base class:** `MediaWikiIntegrationTestCase`
**Location:** `tests/phpunit/integration/`
**DB:** Yes (`@group Database`)
**Speed:** Hundreds of milliseconds

**Applicable to:** Most tests in this extension. `PermissionService::setLevel()`, `PermissionService::getLevel()`, `EnforcementHooks`, `UploadHooks`, `ApiFilePermSetLevel`.

### Pattern 3: Integration Test with Config Override

**For:** Testing behavior under different configuration scenarios
**Base class:** `MediaWikiIntegrationTestCase`
**Location:** `tests/phpunit/integration/`
**DB:** Maybe
**Speed:** Hundreds of milliseconds

**Applicable to:** Testing fail-closed behavior (`FilePermInvalidConfig = true`), different level configurations, namespace defaults.

```php
public function testFailClosedOnInvalidConfig(): void {
    $this->overrideConfigValue( 'FilePermInvalidConfig', true );
    // ... assert all access denied
}
```

### Pattern 4: HTTP Leak Check (E2E)

**For:** Verifying actual HTTP responses from img_auth.php
**Tool:** curl via shell script
**Location:** `tests/http/`
**DB:** Full running MW with test data
**Speed:** Seconds (network I/O)

**Applicable to:** All five enforcement vectors (File page, img_auth.php raw, thumbnails, direct /images/ bypass, embedded image paths).

---

## Version Compatibility

| Component | Version in MW 1.44 | Notes |
|-----------|--------------------|-------|
| PHPUnit | 9.6.21 | Bundled via Composer; do NOT install separately |
| PHP | 8.1 - 8.3 | MW 1.44 requires PHP 8.1+ |
| MariaDB | 10.11 | Used in docker-compose.yml |
| Docker Compose | v2+ | Required for `--wait` flag and health checks |
| GitHub Actions runner | ubuntu-latest | Has Docker + Docker Compose pre-installed |

### Future-Proofing for PHPUnit 10 Migration

MW core will eventually migrate to PHPUnit 10 (tracked at Phabricator T328919). To minimize future migration pain:

1. **Make data providers static** -- PHPUnit 10 requires it
2. **Use `@covers` on every test** -- PHPUnit 10 enforces it
3. **Avoid removed assertions** -- `assertFileNotExists()` becomes `assertFileDoesNotExist()`
4. **Do not extend deprecated classes** -- Use `MediaWikiIntegrationTestCase`, not `MediaWikiTestCase`

---

## Installation / Setup

No separate installation needed. PHPUnit is bundled with MediaWiki core via Composer.

```bash
# In Docker container (MW core directory):
# PHPUnit is already at vendor/bin/phpunit

# Verify PHPUnit is available:
docker compose exec -T wiki php vendor/bin/phpunit --version
# Expected output: PHPUnit 9.6.21

# Run FilePermissions tests:
docker compose exec -T -w /var/www/html wiki \
  php vendor/bin/phpunit \
  /mw-user-extensions/FilePermissions/tests/phpunit/

# For HTTP tests, ensure jq is available:
docker compose exec -T wiki which jq || \
  docker compose exec -T wiki apt-get install -y jq
```

For local development, tests can also be run via `docker compose exec` without any additional setup.

---

## Component-to-Test-Type Mapping

This maps each extension component to the recommended test approach.

| Component | Test Type | Base Class | Annotations | Priority |
|-----------|-----------|------------|-------------|----------|
| `PermissionService::canUserAccessLevel` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P0 |
| `PermissionService::canUserAccessFile` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P0 |
| `PermissionService::getEffectiveLevel` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P0 |
| `PermissionService::setLevel` / `getLevel` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P0 |
| `PermissionService::removeLevel` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P1 |
| `Config::isValidLevel` | Integration | `MediaWikiIntegrationTestCase` | `@group FilePermissions` | P0 |
| `Config::resolveDefaultLevel` | Integration | `MediaWikiIntegrationTestCase` | `@group FilePermissions` | P0 |
| `Config::isInvalidConfig` (fail-closed) | Integration | `MediaWikiIntegrationTestCase` | `@group FilePermissions` | P0 |
| `EnforcementHooks::onGetUserPermissionsErrors` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P0 |
| `EnforcementHooks::onImgAuthBeforeStream` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P0 |
| `EnforcementHooks::onImageBeforeProduceHTML` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P1 |
| `UploadHooks::onUploadVerifyUpload` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P1 |
| `ApiFilePermSetLevel::execute` | Integration | `MediaWikiIntegrationTestCase` | `@group Database`, `@group FilePermissions` | P1 |
| `RegistrationHooks::onRegistration` | Integration | `MediaWikiIntegrationTestCase` | `@group FilePermissions` | P1 |
| img_auth.php anonymous denied | HTTP/E2E | Shell script (curl) | N/A | P0 |
| img_auth.php unauthorized user denied | HTTP/E2E | Shell script (curl) | N/A | P0 |
| img_auth.php authorized user allowed | HTTP/E2E | Shell script (curl) | N/A | P0 |
| Direct /images/ path blocked | HTTP/E2E | Shell script (curl) | N/A | P0 |
| Thumbnail path denied | HTTP/E2E | Shell script (curl) | N/A | P1 |

**Priority key:** P0 = must have for first test milestone, P1 = important but can follow.

---

## Sources

### Official MediaWiki Documentation (HIGH confidence)
- [Manual:PHP unit testing](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing) -- PHPUnit setup overview
- [Manual:PHP unit testing/Writing unit tests for extensions](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests_for_extensions) -- Extension test conventions
- [Manual:PHP unit testing/Running the tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Running_the_tests) -- Test execution commands
- [Manual:PHP unit testing/Writing unit tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests) -- Base class details
- [Manual:img_auth.php](https://www.mediawiki.org/wiki/Manual:Img_auth.php) -- Image authorization script

### MW Community / Phabricator (HIGH confidence)
- [Changes and improvements to PHPUnit testing in MediaWiki](https://phabricator.wikimedia.org/phame/post/view/169/changes_and_improvements_to_phpunit_testing_in_mediawiki/) -- Test suite restructuring, bootstrap changes
- [T243600: Migrate to PHPUnit 9](https://phabricator.wikimedia.org/T243600) -- PHPUnit version history
- [Manual:Configuration for developers](https://www.mediawiki.org/wiki/Manual:Configuration_for_developers) -- overrideConfigValue pattern

### CI Tooling (MEDIUM confidence)
- [edwardspec/github-action-build-mediawiki](https://github.com/edwardspec/github-action-build-mediawiki) -- GitHub Action for MW extension CI (considered but not recommended for this project)
- [edwardspec/mediawiki-moderation workflow](https://github.com/edwardspec/mediawiki-moderation) -- Real-world MW extension CI example
- [gesinn-it-pub/docker-compose-ci](https://github.com/gesinn-it-pub/docker-compose-ci) -- Docker Compose CI submodule approach

### PHPUnit Documentation (HIGH confidence)
- [PHPUnit 9.6 XML Configuration](https://docs.phpunit.de/en/9.6/configuration.html) -- phpunit.xml schema

### GitHub Actions (HIGH confidence)
- [Docker Compose Health Check Action](https://github.com/marketplace/actions/docker-compose-health-check) -- Service readiness
- [Wait Docker-Compose Healthy Action](https://github.com/marketplace/actions/wait-docker-compose-healthy) -- Alternative health wait

### MediaWiki API (HIGH confidence)
- [API:Login](https://www.mediawiki.org/wiki/API:Login) -- Session establishment for curl tests
- [Manual:SessionManager and AuthManager](https://www.mediawiki.org/wiki/Manual:SessionManager_and_AuthManager) -- Cookie/session behavior
