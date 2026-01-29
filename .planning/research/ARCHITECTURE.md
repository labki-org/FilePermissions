# Architecture Research: Test Suite Structure and CI Integration

**Domain:** MediaWiki extension test suite structure, PHPUnit conventions, E2E HTTP testing, GitHub Actions CI
**Researched:** 2026-01-29
**Confidence:** HIGH (directory conventions, test base classes, extension.json config verified across official docs and multiple Wikimedia extensions)

## Standard Architecture

### System Overview

The test architecture has three distinct tiers: unit tests that run without MediaWiki, integration tests that run inside MediaWiki's test framework with database access, and E2E HTTP tests that run against a live Docker container over the network.

```
+------------------------------------------------------------------+
|                    GitHub Actions Workflow                         |
|                                                                   |
|  +--------------------+  +--------------------+  +--------------+ |
|  | Job: unit-tests    |  | Job: integration   |  | Job: e2e     | |
|  |                    |  |                    |  |              | |
|  | MW core checkout   |  | MW core checkout   |  | docker       | |
|  | composer install   |  | composer install   |  |  compose up  | |
|  | vendor/bin/phpunit |  | DB install         |  | health check | |
|  |   --testsuite unit |  | vendor/bin/phpunit |  | PHPUnit via  | |
|  |                    |  |   --testsuite      |  |  Guzzle HTTP | |
|  | NO DB required     |  |   integration      |  |              | |
|  | NO services        |  | Temp DB tables     |  | Real HTTP    | |
|  | Fast (<5 sec)      |  | Full MW bootstrap  |  | Real DB      | |
|  +--------------------+  +--------------------+  +--------------+ |
+------------------------------------------------------------------+

Test Directory Layout:
tests/
  phpunit/
    unit/                              <-- MediaWikiUnitTestCase
      ConfigTest.php                       No DB, no services, fast
      PermissionServiceTest.php            Mock all dependencies
    integration/                       <-- MediaWikiIntegrationTestCase
      PermissionServiceIntegrationTest.php @group Database
      EnforcementHooksTest.php             @group Database
      UploadHooksTest.php                  @group Database
      ApiFilePermSetLevelTest.php          @group Database
    e2e/                               <-- PHPUnit + GuzzleHttp\Client
      HttpLeakTest.php                     Real HTTP against Docker
      ImgAuthAccessTest.php                Tests img_auth.php paths
```

### Component Responsibilities

| Component | Responsibility | Runs Where |
|-----------|---------------|------------|
| **Unit tests** (`tests/phpunit/unit/`) | Test pure logic without MW dependencies. Config validation, permission level checking, grant resolution. | MW phpunit:unit suite. No DB, no services. |
| **Integration tests** (`tests/phpunit/integration/`) | Test classes within MW environment. DB storage, service wiring, hook behavior, API module execution. | MW phpunit:entrypoint suite. Temporary DB tables. |
| **E2E HTTP tests** (`tests/phpunit/e2e/`) | Test full HTTP request/response cycle. Verify img_auth.php blocks unauthorized access, verify /images/ is blocked. | Standalone PHPUnit with Guzzle. Docker container on port 8888. |
| **Test bootstrap** (`tests/phpunit/bootstrap.php`) | Minimal bootstrap for E2E tests that loads Guzzle autoloader. | Only for E2E suite. |
| **CI workflow** (`.github/workflows/ci.yml`) | Orchestrate all three tiers. Spin up Docker, run tests, report results. | GitHub Actions runner. |

## Recommended Project Structure

```
FilePermissions/
  extension.json                        # Add TestAutoloadNamespaces
  composer.json                         # Add require-dev: guzzlehttp/guzzle
  tests/
    LocalSettings.test.php              # (existing) Test wiki configuration
    apache-filepermissions.conf         # (existing) Apache /images/ block
    scripts/
      reinstall_test_env.sh             # (existing) Docker environment setup
    phpunit/
      phpunit.xml                       # PHPUnit config for E2E suite
      bootstrap.php                     # Autoloader for E2E tests (Guzzle)
      unit/
        ConfigTest.php                  # Config::getLevels, isValidLevel, resolveDefaultLevel
        PermissionServiceTest.php       # canUserAccessLevel logic with mocks
      integration/
        PermissionServiceIntegrationTest.php  # DB read/write, cache behavior
        EnforcementHooksTest.php        # getUserPermissionsErrors, ImgAuthBeforeStream
        UploadHooksTest.php             # Upload form descriptor, validation
        ApiFilePermSetLevelTest.php     # API module execution
      e2e/
        HttpLeakTest.php               # /images/ directory blocked (403/404)
        ImgAuthAccessTest.php           # img_auth.php enforces per-user per-file
  .github/
    workflows/
      ci.yml                           # GitHub Actions workflow
```

## Architectural Patterns

### Pattern 1: extension.json TestAutoloadNamespaces

**What:** Register a PSR-4 namespace for test classes so MW's test suite can autoload them.

**When:** Required for unit and integration tests that run inside MW's test framework. This is how MediaWiki discovers your test classes when running via `vendor/bin/phpunit` from the core directory.

**Configuration:**

```json
{
  "AutoloadNamespaces": {
    "FilePermissions\\": "includes/"
  },
  "TestAutoloadNamespaces": {
    "FilePermissions\\Tests\\": "tests/phpunit/"
  }
}
```

This maps `FilePermissions\Tests\Unit\ConfigTest` to `tests/phpunit/unit/ConfigTest.php` and `FilePermissions\Tests\Integration\EnforcementHooksTest` to `tests/phpunit/integration/EnforcementHooksTest.php`.

**Real-world precedent:** JsonConfig extension uses `"TestAutoloadNamespaces": { "JsonConfig\\Tests\\": "tests/phpunit/" }`. Translate extension uses a similar pattern. SyntaxHighlight extension uses `"TestAutoloadNamespaces": { "MediaWiki\\SyntaxHighlight\\Tests\\": "tests/phpunit/" }`.

**Confidence:** HIGH -- verified in multiple Wikimedia extension repositories.

**Source:** [Manual:PHP unit testing/Writing unit tests for extensions](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests_for_extensions), [JsonConfig extension.json](https://github.com/wikimedia/mediawiki-extensions-JsonConfig/blob/master/extension.json)

---

### Pattern 2: MediaWikiUnitTestCase for Pure Logic

**What:** Extend `MediaWikiUnitTestCase` for tests that do not need MediaWiki's global state, services, or database. This base class actively strips away MW globals to ensure isolation.

**When:** Testing Config.php (static methods with global variable access can be tested by setting globals directly), testing PermissionService logic with mocked dependencies.

**What it provides:**
- Strips MW global state -- tests run in isolation
- No database -- fast execution
- No service container -- must mock all dependencies
- Runs via `composer phpunit:unit` from MW core

**What it prohibits:**
- No `$this->getServiceContainer()`
- No `$this->overrideConfigValue()`
- No `@group Database`
- No access to MediaWikiServices at all

**Example for this project:**

```php
namespace FilePermissions\Tests\Unit;

use MediaWikiUnitTestCase;
use FilePermissions\Config;

/**
 * @covers \FilePermissions\Config
 */
class ConfigTest extends MediaWikiUnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        // Set globals directly for unit tests
        $GLOBALS['wgFilePermLevels'] = [ 'public', 'internal', 'confidential' ];
        $GLOBALS['wgFilePermGroupGrants'] = [
            'sysop' => [ '*' ],
            'user' => [ 'public', 'internal' ],
        ];
        $GLOBALS['wgFilePermDefaultLevel'] = null;
        $GLOBALS['wgFilePermInvalidConfig'] = false;
    }

    public function testGetLevelsReturnsConfiguredLevels(): void {
        $this->assertSame(
            [ 'public', 'internal', 'confidential' ],
            Config::getLevels()
        );
    }

    public function testIsValidLevelReturnsTrueForConfiguredLevel(): void {
        $this->assertTrue( Config::isValidLevel( 'public' ) );
    }

    public function testIsValidLevelReturnsFalseForUnknownLevel(): void {
        $this->assertFalse( Config::isValidLevel( 'secret' ) );
    }
}
```

**Confidence:** HIGH -- base class usage well-documented.

**Source:** [Manual:PHP unit testing/Writing unit tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests), [Changes and improvements to PHPUnit testing in MediaWiki](https://phabricator.wikimedia.org/phame/post/view/169/changes_and_improvements_to_phpunit_testing_in_mediawiki/)

---

### Pattern 3: MediaWikiIntegrationTestCase with @group Database

**What:** Extend `MediaWikiIntegrationTestCase` for tests that need the full MW environment -- database access, service container, configuration overrides.

**When:** Testing PermissionService.getLevel/setLevel (real DB), testing hooks that depend on services, testing the API module.

**What it provides:**
- `$this->getServiceContainer()` -- access MediaWikiServices
- `$this->overrideConfigValue( $key, $value )` -- override config per test (since MW 1.39)
- `$this->setService( $name, $service )` -- replace a service (since MW 1.27)
- `$this->db` -- database connection when `@group Database` is used
- `$this->getTestUser( $groups )` -- create mutable test user with specified groups
- `$this->getTestSysop()` -- shorthand for `getTestUser( ['sysop', 'bureaucrat'] )`
- Database transactions are rolled back after each test method
- Temporary tables created with `unittest_` prefix

**Critical annotation:** `@group Database` must be added to any test class or method that reads/writes the database. This tells MW to set up temporary table overlays.

**Example for this project:**

```php
namespace FilePermissions\Tests\Integration;

use MediaWikiIntegrationTestCase;
use FilePermissions\PermissionService;

/**
 * @covers \FilePermissions\PermissionService
 * @group Database
 */
class PermissionServiceIntegrationTest extends MediaWikiIntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->overrideConfigValue( 'FilePermLevels',
            [ 'public', 'internal', 'confidential' ] );
        $this->overrideConfigValue( 'FilePermGroupGrants', [
            'sysop' => [ '*' ],
            'user' => [ 'public', 'internal' ],
        ] );
        $this->overrideConfigValue( 'FilePermDefaultLevel', null );
        $this->overrideConfigValue( 'FilePermInvalidConfig', false );
    }

    private function getService(): PermissionService {
        return $this->getServiceContainer()->getService(
            'FilePermissions.PermissionService'
        );
    }

    public function testSetAndGetLevel(): void {
        // Insert a test page in NS_FILE
        $title = $this->insertPage( 'Test.png', 'test', NS_FILE )['title'];
        $service = $this->getService();

        $service->setLevel( $title, 'confidential' );
        $this->assertSame( 'confidential', $service->getLevel( $title ) );
    }

    public function testCanUserAccessLevel_SysopAccessesAll(): void {
        $sysop = $this->getTestSysop()->getUser();
        $service = $this->getService();
        $this->assertTrue(
            $service->canUserAccessLevel( $sysop, 'confidential' )
        );
    }

    public function testCanUserAccessLevel_UserDeniedConfidential(): void {
        $user = $this->getTestUser()->getUser();
        $service = $this->getService();
        $this->assertFalse(
            $service->canUserAccessLevel( $user, 'confidential' )
        );
    }
}
```

**Confidence:** HIGH -- pattern verified in official docs and real extensions.

**Source:** [Manual:PHP unit testing/Writing unit tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests), [MediaWikiIntegrationTestCase source](https://fossies.org/linux/mediawiki/tests/phpunit/MediaWikiIntegrationTestCase.php)

---

### Pattern 4: E2E HTTP Tests with Guzzle (Separate PHPUnit Suite)

**What:** Use PHPUnit + GuzzleHttp\Client to make real HTTP requests against the Docker container, verifying that file access control works end-to-end. These do NOT run inside MW's test framework -- they run as a standalone PHPUnit suite.

**When:** Testing that /images/ directory returns 403 (Apache block), testing that img_auth.php denies unauthorized users, testing that authorized users can download files. These are the "HTTP leak checks" that verify the full stack works.

**Why separate:** MW's PHPUnit framework tests code within the MW process. E2E tests need to make external HTTP requests to a running wiki instance. They cannot extend MediaWikiUnitTestCase or MediaWikiIntegrationTestCase.

**Structure:**

```php
namespace FilePermissions\Tests\E2E;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\TestCase;

/**
 * E2E HTTP tests for file access control.
 *
 * Requires a running Docker environment:
 *   docker compose up -d
 *   php vendor/bin/phpunit -c tests/phpunit/phpunit.xml --testsuite e2e
 */
class ImgAuthAccessTest extends TestCase {

    private static Client $client;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void {
        self::$baseUrl = getenv( 'MW_TEST_URL' ) ?: 'http://localhost:8888';
        self::$client = new Client( [
            'base_uri' => self::$baseUrl,
            'http_errors' => false,  // Don't throw on 4xx/5xx
            'timeout' => 10,
        ] );
    }

    /**
     * Log in via MW API and return a cookie jar for authenticated requests.
     */
    private function loginAs( string $username, string $password ): CookieJar {
        $jar = new CookieJar();
        $client = new Client( [
            'base_uri' => self::$baseUrl,
            'http_errors' => false,
            'cookies' => $jar,
        ] );

        // Step 1: Get login token
        $resp = $client->get( '/api.php', [
            'query' => [
                'action' => 'query',
                'meta' => 'tokens',
                'type' => 'login',
                'format' => 'json',
            ],
        ] );
        $data = json_decode( $resp->getBody(), true );
        $token = $data['query']['tokens']['logintoken'];

        // Step 2: Log in
        $client->post( '/api.php', [
            'form_params' => [
                'action' => 'login',
                'lgname' => $username,
                'lgpassword' => $password,
                'lgtoken' => $token,
                'format' => 'json',
            ],
        ] );

        return $jar;
    }

    public function testDirectImagesPathBlocked(): void {
        $resp = self::$client->get( '/images/' );
        $this->assertContains(
            $resp->getStatusCode(),
            [ 403, 404 ],
            'Direct /images/ access must be blocked'
        );
    }
}
```

**phpunit.xml for E2E suite:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="bootstrap.php"
         colors="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="e2e">
            <directory>e2e</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**bootstrap.php:**

```php
<?php
// Autoload Guzzle and test classes for E2E tests
require_once __DIR__ . '/../../vendor/autoload.php';
```

**Confidence:** HIGH for the pattern (PHPUnit + Guzzle is a standard approach). MEDIUM for MW-specific details (API login flow verified via existing LocalSettings.test.php notes).

**Source:** [Using Guzzle and PHPUnit for REST API Testing (Cloudflare)](https://blog.cloudflare.com/using-guzzle-and-phpunit-for-rest-api-testing/), [Guzzle Testing Documentation](https://docs.guzzlephp.org/en/stable/testing.html)

---

### Pattern 5: GitHub Actions Workflow with Docker Compose

**What:** A CI workflow that runs all three test tiers on every push and PR.

**Architecture decision:** Use the project's own `docker-compose.yml` rather than `edwardspec/github-action-build-mediawiki` because this extension depends on a custom Docker image (`ghcr.io/labki-org/labki-platform:latest`) with specific LocalSettings, Apache config, and pre-installed extensions (VisualEditor, MsUpload). The standard MW build action would not replicate this environment.

For unit and integration tests, they could theoretically run inside the Docker container via `docker compose exec`. However, given that the labki-platform image is a production-oriented image (not a dev image with vendor/ and phpunit installed), the recommended approach is:

- **E2E tests:** Run from the host against the Docker container (Guzzle HTTP)
- **Unit/Integration tests:** Run inside the Docker container using MW's PHPUnit setup

The practical approach for this project:

```
Job 1: unit-integration (runs inside Docker container)
  1. docker compose up -d
  2. Wait for wiki to be healthy
  3. docker compose exec wiki composer install (if vendor/ not in image)
  4. docker compose exec wiki php vendor/bin/phpunit
       extensions/FilePermissions/tests/phpunit/unit/
  5. docker compose exec wiki php tests/phpunit/phpunit.php
       extensions/FilePermissions/tests/phpunit/integration/

Job 2: e2e-http (runs on host against container)
  1. docker compose up -d
  2. Wait for wiki to be healthy
  3. Create test user
  4. Upload test file + set permission level (via API or SQL)
  5. composer install (for Guzzle)
  6. vendor/bin/phpunit -c tests/phpunit/phpunit.xml --testsuite e2e
```

**Important caveat about the labki-platform image:** If the Docker image does not include `vendor/bin/phpunit` and MW's test framework, then unit and integration tests cannot run inside it. In that case, the approach changes to: use the `edwardspec/github-action-build-mediawiki` action for MW-internal tests (separate job with standard MW checkout), and use Docker Compose only for E2E tests. This needs to be validated during implementation.

**Confidence:** MEDIUM -- the exact capabilities of `ghcr.io/labki-org/labki-platform:latest` (whether it includes composer/vendor) need verification. The pattern itself is HIGH confidence.

**Source:** [edwardspec/github-action-build-mediawiki](https://github.com/edwardspec/github-action-build-mediawiki), [Docker Compose Health Check Action](https://github.com/marketplace/actions/docker-compose-health-check), [GitHub Actions with Docker Compose](https://github.com/peter-evans/docker-compose-actions-workflow)

---

### Pattern 6: Test Data Management

**What:** How to set up files, permissions, and users for testing.

**Unit tests:** No test data needed. Mock everything.

**Integration tests:** MW provides built-in helpers:
- `$this->getTestUser( ['group1'] )` -- creates a test user with specified groups, cleaned up after test
- `$this->getTestSysop()` -- shorthand for sysop+bureaucrat user
- `$this->insertPage( 'Pagename', 'content', $namespace )` -- creates a page, returns title
- `@group Database` -- uses temporary table overlay, rolled back per test
- `addDBData()` -- override to insert custom data before test methods run
- Database changes are automatically rolled back after each test method

**Integration test data example:**

```php
/**
 * @group Database
 */
class EnforcementHooksTest extends MediaWikiIntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->overrideConfigValue( 'FilePermLevels',
            [ 'public', 'internal', 'confidential' ] );
        $this->overrideConfigValue( 'FilePermGroupGrants', [
            'sysop' => [ '*' ],
            'user' => [ 'public', 'internal' ],
        ] );
    }

    private function createFileWithLevel( string $name, string $level ): \MediaWiki\Title\Title {
        $result = $this->insertPage( $name, 'test content', NS_FILE );
        $title = $result['title'];
        $service = $this->getServiceContainer()
            ->getService( 'FilePermissions.PermissionService' );
        $service->setLevel( $title, $level );
        return $title;
    }

    public function testConfidentialFileDeniedForRegularUser(): void {
        $title = $this->createFileWithLevel( 'Secret.png', 'confidential' );
        $user = $this->getTestUser()->getUser();
        // ... test hook behavior
    }
}
```

**E2E test data:** Must be set up via the MW API or direct SQL before tests run:
- Create test user via `maintenance/run.php createAndPromote`
- Upload test file via MW API (`action=upload`)
- Set permission level via extension API (`action=fileperm-set-level`) or direct SQL

The existing `reinstall_test_env.sh` script shows the pattern: `docker compose exec -T wiki php maintenance/run.php createAndPromote TestUser testpass123`.

**Confidence:** HIGH for integration test patterns (well-documented MW convention). MEDIUM for E2E data setup (project-specific, follows existing script pattern).

**Source:** [Manual:PHP unit testing/Writing unit tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests)

---

### Pattern 7: Test Isolation in MediaWiki

**What:** How MW ensures tests do not contaminate each other.

**Database isolation:**
- `@group Database` creates temporary table overlays using `CREATE TEMPORARY TABLE`
- Tables are prefixed with `unittest_`
- Only schema is copied, not data -- `addCoreDBData()` adds minimal data (UTSysop user, UTPage)
- Database changes are rolled back at the beginning of each test method
- `PHPUNIT_USE_NORMAL_TABLES=1` env var disables temporary tables (for debugging)

**Service isolation:**
- `$this->setService()` stashes the previous service and restores it in `tearDown()`
- `$this->overrideConfigValue()` restores original values in `tearDown()`

**User isolation:**
- `getTestUser()` creates users in the temporary database, cleaned up automatically
- Test users do not leak to the production database (except on PostgreSQL with known bugs)

**Hook isolation:**
- `setTemporaryHook()` registers hooks that are removed in `tearDown()`

**Global state:**
- `MediaWikiUnitTestCase` actively unsets hundreds of MW globals
- `MediaWikiIntegrationTestCase` saves and restores global state

**Confidence:** HIGH -- these are core MW test framework behaviors.

**Source:** [Manual:PHP unit testing](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing), [Manual:PHP unit testing/Running the tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Running_the_tests)

## Data Flow

### Test Execution Flow: Unit Tests

```
1. GitHub Actions checks out MW core + extension
2. composer install in MW core directory
3. vendor/bin/phpunit extensions/FilePermissions/tests/phpunit/unit/
4. MediaWikiUnitTestCase strips global state
5. Each test method:
   a. setUp() sets required globals
   b. Test exercises Config/PermissionService with mocks
   c. tearDown() restores state
6. Exit code 0 = pass, non-zero = fail
```

### Test Execution Flow: Integration Tests

```
1. GitHub Actions checks out MW core + extension
2. composer install in MW core directory
3. Database service available (MySQL/MariaDB)
4. MW install.php creates database schema
5. Extension loaded via LocalSettings.php
6. vendor/bin/phpunit extensions/FilePermissions/tests/phpunit/integration/
7. MediaWikiIntegrationTestCase bootstraps MW
8. Each @group Database test:
   a. setUp() creates temporary table overlay
   b. overrideConfigValue() sets extension config
   c. insertPage() creates test pages
   d. Service accessed via getServiceContainer()
   e. Assertions run
   f. Temporary tables dropped, config restored
9. Exit code propagated to CI
```

### Test Execution Flow: E2E HTTP Tests

```
1. docker compose up -d (wiki + db containers)
2. Wait for wiki health: curl --retry 10 http://localhost:8888/api.php
3. Create test user: docker compose exec wiki php maintenance/run.php createAndPromote ...
4. Set up test data:
   a. Upload test file (via API with Admin credentials)
   b. Set permission level on file (via extension API)
5. composer install (on host, for Guzzle)
6. vendor/bin/phpunit -c tests/phpunit/phpunit.xml --testsuite e2e
7. Each test method:
   a. Create Guzzle client
   b. Optionally login as specific user (Admin or TestUser)
   c. Make HTTP request to file URL
   d. Assert status code (200 for allowed, 403 for denied)
8. Exit code propagated to CI
```

## Anti-Patterns

### Anti-Pattern 1: Running E2E Tests Inside MediaWiki's Test Framework

**What:** Trying to extend `MediaWikiIntegrationTestCase` and make HTTP requests from within.

**Why bad:** MW's test framework runs inside the MW process. HTTP requests to the same server would deadlock or bypass the normal request lifecycle. The test framework is designed for in-process testing.

**Instead:** Use a separate PHPUnit suite with Guzzle that runs outside MW, against the Docker container.

### Anti-Pattern 2: Using Shell Scripts for E2E Tests

**What:** Writing bash scripts with curl commands and grep for test assertions.

**Why bad:** No structured test reporting, no proper assertions, no integration with CI test result parsers, hard to maintain, no parallelization, no data-driven testing.

**Instead:** Use PHPUnit + Guzzle. PHPUnit provides structured assertions, JUnit XML output for CI, setUp/tearDown lifecycle, data providers for parameterized tests.

### Anti-Pattern 3: Testing Against Live/Production Wiki

**What:** Running unit or integration tests against a wiki with real data.

**Why bad:** MediaWiki's test framework creates temporary tables and test users. Running against a production database risks data corruption. The official docs explicitly warn: "Do not run tests on a real website -- bad things will happen!"

**Instead:** Always use a dedicated test database. Docker provides perfect isolation.

### Anti-Pattern 4: Skipping @group Database Annotation

**What:** Writing tests that read/write the database without the `@group Database` annotation.

**Why bad:** Without the annotation, MW does not set up temporary table overlays. The test will modify the actual test database, and changes will not be rolled back. Tests become order-dependent and flaky.

**Instead:** Always add `@group Database` to test classes or methods that touch the database.

### Anti-Pattern 5: Putting E2E Tests in tests/phpunit/integration/

**What:** Mixing HTTP-based E2E tests with MW integration tests in the same directory.

**Why bad:** MW's test discovery scans `tests/phpunit/` for classes extending MW base test cases. E2E tests extend `PHPUnit\Framework\TestCase` and would either fail to load (missing MW bootstrap) or be incorrectly categorized.

**Instead:** Put E2E tests in `tests/phpunit/e2e/` with their own `phpunit.xml` and bootstrap that does NOT load MW core.

### Anti-Pattern 6: Hard-coding Docker Container URLs

**What:** Using `http://localhost:8888` directly in test code.

**Why bad:** Port may differ in CI environments. Container hostname may differ inside Docker networks.

**Instead:** Use environment variable `MW_TEST_URL` with fallback: `getenv('MW_TEST_URL') ?: 'http://localhost:8888'`.

## Integration Points

### 1. extension.json Changes

Add `TestAutoloadNamespaces` to enable MW test discovery:

```json
{
  "TestAutoloadNamespaces": {
    "FilePermissions\\Tests\\": "tests/phpunit/"
  }
}
```

This is the only extension.json change needed. MW's `ExtensionsTestSuite.php` auto-discovers tests by scanning `{extension_path}/tests/phpunit/` for all loaded extensions.

### 2. composer.json (New File)

Create `composer.json` for Guzzle dependency (E2E tests):

```json
{
  "require-dev": {
    "guzzlehttp/guzzle": "^7.0"
  },
  "autoload-dev": {
    "psr-4": {
      "FilePermissions\\Tests\\E2E\\": "tests/phpunit/e2e/"
    }
  }
}
```

Note: Unit and integration tests do NOT need this composer.json -- they use MW core's autoloader. Only E2E tests need Guzzle.

### 3. phpunit.xml (E2E Test Configuration)

Located at `tests/phpunit/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="bootstrap.php"
         colors="true"
         failOnWarning="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="e2e">
            <directory>e2e</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="MW_TEST_URL" value="http://localhost:8888" force="false"/>
        <env name="MW_ADMIN_USER" value="Admin" force="false"/>
        <env name="MW_ADMIN_PASS" value="dockerpass" force="false"/>
        <env name="MW_TEST_USER" value="TestUser" force="false"/>
        <env name="MW_TEST_PASS" value="testpass123" force="false"/>
    </php>
</phpunit>
```

### 4. GitHub Actions Workflow

Located at `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [ main ]
  pull_request:
    branches: [ main ]

jobs:
  # ---------------------------------------------------------------
  # Job 1: Unit + Integration tests inside MW framework
  # ---------------------------------------------------------------
  # OPTION A: If labki-platform image includes composer/phpunit
  phpunit:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout extension
        uses: actions/checkout@v4

      - name: Start Docker environment
        run: docker compose up -d

      - name: Wait for wiki to be ready
        run: |
          for i in $(seq 1 30); do
            if curl -sf http://localhost:8888/api.php?action=query&format=json > /dev/null 2>&1; then
              echo "Wiki is ready"
              exit 0
            fi
            echo "Waiting for wiki... ($i/30)"
            sleep 5
          done
          echo "Wiki failed to start"
          exit 1

      - name: Run unit tests
        run: |
          docker compose exec -T wiki php vendor/bin/phpunit \
            extensions/FilePermissions/tests/phpunit/unit/

      - name: Run integration tests
        run: |
          docker compose exec -T wiki php tests/phpunit/phpunit.php \
            extensions/FilePermissions/tests/phpunit/integration/

  # ---------------------------------------------------------------
  # OPTION B: If labki-platform does NOT include phpunit
  # Use edwardspec/github-action-build-mediawiki instead
  # ---------------------------------------------------------------
  # phpunit:
  #   runs-on: ubuntu-latest
  #   services:
  #     mariadb:
  #       image: mariadb:10.11
  #       env:
  #         MARIADB_ROOT_PASSWORD: root
  #       ports:
  #         - 3306:3306
  #       options: >-
  #         --health-cmd="healthcheck.sh --connect --innodb_initialized"
  #         --health-interval=10s
  #         --health-timeout=5s
  #         --health-retries=10
  #   steps:
  #     - uses: actions/checkout@v4
  #     - uses: shivammathur/setup-php@v2
  #       with:
  #         php-version: '8.3'
  #         extensions: mbstring, intl, mysqli
  #     - uses: edwardspec/github-action-build-mediawiki@v1
  #       with:
  #         branch: REL1_44
  #         extraLocalSettings: tests/ci/LocalSettings.ci.php
  #         dbtype: mysql
  #         dbserver: 127.0.0.1:3306
  #         dbname: testwiki
  #         dbpass: root
  #     - name: Run unit tests
  #       run: |
  #         cd /home/runner/builddir/w
  #         php vendor/bin/phpunit extensions/FilePermissions/tests/phpunit/unit/
  #     - name: Run integration tests
  #       run: |
  #         cd /home/runner/builddir/w
  #         php tests/phpunit/phpunit.php \
  #           extensions/FilePermissions/tests/phpunit/integration/

  # ---------------------------------------------------------------
  # Job 2: E2E HTTP leak checks against Docker
  # ---------------------------------------------------------------
  e2e:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install Guzzle
        run: composer install --working-dir=$GITHUB_WORKSPACE

      - name: Start Docker environment
        run: docker compose up -d

      - name: Wait for wiki to be ready
        run: |
          for i in $(seq 1 30); do
            if curl -sf http://localhost:8888/api.php?action=query&format=json > /dev/null 2>&1; then
              echo "Wiki is ready"
              exit 0
            fi
            echo "Waiting for wiki... ($i/30)"
            sleep 5
          done
          echo "Wiki failed to start"
          exit 1

      - name: Create test user
        run: |
          docker compose exec -T wiki php maintenance/run.php \
            createAndPromote TestUser testpass123 || true

      - name: Set up test data
        run: |
          # Upload a test file and set its permission level
          # (setup script or API calls here)

      - name: Run E2E tests
        run: |
          php vendor/bin/phpunit \
            -c tests/phpunit/phpunit.xml \
            --testsuite e2e \
            --log-junit test-results/e2e.xml

      - name: Upload test results
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: e2e-test-results
          path: test-results/
```

**Health check pattern:** Use a bash loop with `curl -sf` and `--retry` to wait for the wiki API to respond. The API endpoint `?action=query&format=json` is lightweight and confirms both Apache and PHP are running. 30 attempts at 5-second intervals gives 2.5 minutes, which is sufficient for the labki-platform image to initialize (the existing script uses a 30-second sleep).

### 5. Docker Compose Health Checks (Enhancement)

Add health checks to `docker-compose.yml` for native Docker orchestration:

```yaml
services:
  wiki:
    image: ghcr.io/labki-org/labki-platform:latest
    healthcheck:
      test: ["CMD", "curl", "-sf", "http://localhost/api.php?action=query&format=json"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 30s
    # ... rest of config

  db:
    image: mariadb:10.11
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
    # ... rest of config
```

This allows `depends_on: wiki: condition: service_healthy` for future service dependencies.

**Source:** [Docker Compose Health Check patterns](https://github.com/peter-evans/docker-compose-healthcheck), [Docker Compose Specification](https://docs.docker.com/compose/compose-file/05-services/#healthcheck)

## Suggested Build Order for the Test Suite

Based on dependency analysis and risk prioritization:

### Phase A: Test Infrastructure (Build First)

1. **extension.json** -- Add `TestAutoloadNamespaces`
2. **composer.json** -- Add Guzzle dev dependency
3. **tests/phpunit/phpunit.xml** -- E2E suite config
4. **tests/phpunit/bootstrap.php** -- E2E autoloader

**Rationale:** Infrastructure must exist before any tests can run. These are small files with no logic.

### Phase B: Unit Tests (Build Second)

5. **tests/phpunit/unit/ConfigTest.php** -- Test Config.php (static methods, no deps)
6. **tests/phpunit/unit/PermissionServiceTest.php** -- Test canUserAccessLevel with mocked IConnectionProvider and UserGroupManager

**Rationale:** Unit tests are fastest to write, fastest to run, and exercise the core permission logic. They validate the existing code without requiring any infrastructure. If Config or PermissionService has bugs, better to find them here.

### Phase C: Integration Tests (Build Third)

7. **tests/phpunit/integration/PermissionServiceIntegrationTest.php** -- Test DB read/write through real service
8. **tests/phpunit/integration/EnforcementHooksTest.php** -- Test hook logic with real services
9. **tests/phpunit/integration/ApiFilePermSetLevelTest.php** -- Test API module (may extend ApiTestCase)
10. **tests/phpunit/integration/UploadHooksTest.php** -- Test upload form/validation hooks

**Rationale:** Integration tests depend on the MW test framework working correctly with the extension. They are more complex to write but test the actual wiring (services, hooks, DB). Build after unit tests are green.

### Phase D: E2E HTTP Tests (Build Fourth)

11. **tests/phpunit/e2e/HttpLeakTest.php** -- Test /images/ directory blocked, basic responses
12. **tests/phpunit/e2e/ImgAuthAccessTest.php** -- Test img_auth.php per-user, per-file enforcement

**Rationale:** E2E tests require a running Docker environment and test data. They are the final validation layer. Build them after integration tests confirm the logic works.

### Phase E: CI Workflow (Build Last)

13. **.github/workflows/ci.yml** -- GitHub Actions workflow tying everything together

**Rationale:** CI workflow depends on all test tiers existing. Cannot verify the workflow works until tests exist to run. May need iteration based on what the labki-platform image supports.

## What This Extension Needs to Test: Coverage Map

| Source File | Unit Test | Integration Test | E2E Test |
|-------------|-----------|------------------|----------|
| `Config.php` | getLevels, isValidLevel, resolveDefaultLevel, isInvalidConfig | -- | -- |
| `PermissionService.php` | canUserAccessLevel (mock), canUserAccessFile (mock) | getLevel/setLevel/removeLevel (DB), getEffectiveLevel (DB), canUserAccessFile (DB+groups) | -- |
| `EnforcementHooks.php` | -- | onGetUserPermissionsErrors, onImgAuthBeforeStream, onImageBeforeProduceHTML | img_auth.php 403 for unauthorized, 200 for authorized |
| `UploadHooks.php` | -- | onUploadFormInitDescriptor (form fields), onUploadVerifyUpload (validation) | -- |
| `ApiFilePermSetLevel.php` | -- | execute (set level via API), needsToken, mustBePosted | -- |
| `DisplayHooks.php` | -- | onImagePageAfterImageLinks (badge HTML), onBeforePageDisplay (module loading) | -- |
| Apache config | -- | -- | /images/ returns 403 |
| img_auth.php routing | -- | -- | Authorized user gets 200, unauthorized gets 403 |

## Open Questions

1. **Does labki-platform include composer/vendor/phpunit?** This determines whether unit+integration tests run inside the Docker container or via a separate MW checkout in CI. Needs verification by inspecting the Docker image.

2. **PHPUnit version compatibility.** MW 1.44 targets PHPUnit 10.x. The E2E suite's `phpunit.xml` schema reference should match the PHPUnit version available. If running E2E tests on the host, the host PHPUnit version must be compatible.

3. **Test file upload in integration tests.** `insertPage()` creates a wiki page but not an actual uploaded file with physical bytes on disk. For testing hooks that depend on `UploadBase` or `LocalFile`, specialized setup may be needed. MW's `ApiTestCase` and `@group Upload` annotation may help.

4. **img_auth.php path in E2E tests.** The existing config sets `$wgUploadPath = "$wgScriptPath/img_auth.php"`, so file URLs look like `/img_auth.php/a/ab/File.png`. Need to verify the exact URL format the E2E tests should request.

## Sources

### HIGH Confidence (Official Documentation)

- [Manual:PHP unit testing/Writing unit tests for extensions](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests_for_extensions) -- Extension test structure, TestAutoloadNamespaces, directory layout
- [Manual:PHP unit testing/Writing unit tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests) -- MediaWikiUnitTestCase vs MediaWikiIntegrationTestCase, test helpers, @group Database
- [Manual:PHP unit testing/Running the tests](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Running_the_tests) -- How to run tests, suite.xml, composer scripts
- [Manual:PHP unit testing](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing) -- PHPUnit overview, test categories, database handling
- [Changes and improvements to PHPUnit testing in MediaWiki](https://phabricator.wikimedia.org/phame/post/view/169/changes_and_improvements_to_phpunit_testing_in_mediawiki/) -- Unit/integration split history, base class changes

### MEDIUM Confidence (Verified Against Real Extensions)

- [JsonConfig extension.json](https://github.com/wikimedia/mediawiki-extensions-JsonConfig/blob/master/extension.json) -- TestAutoloadNamespaces real-world usage
- [Translate extension.json](https://github.com/wikimedia/mediawiki-extensions-Translate/blob/master/extension.json) -- TestAutoloadNamespaces variant
- [edwardspec/github-action-build-mediawiki](https://github.com/edwardspec/github-action-build-mediawiki) -- GitHub Action for MW extension CI
- [Testing MediaWiki code with PHPUnit (Kosta Harlan)](https://www.kostaharlan.net/posts/mediawiki-phpunit/) -- Practitioner guide to MW testing
- [MediaWikiIntegrationTestCase source (Fossies)](https://fossies.org/linux/mediawiki/tests/phpunit/MediaWikiIntegrationTestCase.php) -- overrideConfigValue, getServiceContainer, setService signatures

### MEDIUM Confidence (General Testing Patterns)

- [Using Guzzle and PHPUnit for REST API Testing (Cloudflare)](https://blog.cloudflare.com/using-guzzle-and-phpunit-for-rest-api-testing/) -- PHPUnit + Guzzle E2E pattern
- [Guzzle Testing Documentation](https://docs.guzzlephp.org/en/stable/testing.html) -- Mock handler, history middleware
- [Docker Compose Health Check patterns](https://github.com/peter-evans/docker-compose-healthcheck) -- healthcheck + depends_on
- [Docker Compose Actions Workflow](https://github.com/peter-evans/docker-compose-actions-workflow) -- GitHub Actions + Docker Compose pattern

### LOW Confidence (Community / Synthesized)

- E2E test data setup via API calls -- synthesized from existing `reinstall_test_env.sh` and `LocalSettings.test.php` patterns; specific API call sequences need validation
- GitHub Actions workflow for this specific Docker image -- untested; labki-platform image capabilities unknown
