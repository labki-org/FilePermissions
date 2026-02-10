# Contributing to FilePermissions

## Development Setup

The project uses Docker Compose for local development.

1. Clone the repository and start the environment:

   ```bash
   docker compose up -d
   ```

2. Run the MediaWiki installer or restore a database snapshot (see `tests/scripts/`).

3. Default credentials for the test wiki are defined in `tests/LocalSettings.test.php`.

4. Create test users with different group memberships to exercise permission logic:

   ```bash
   docker exec filepermissions-wiki-1 php /var/www/html/maintenance/run.php \
       createAndPromote --force TestAdmin sysop
   ```

## Architecture Overview

### PHP Classes

| Class | Purpose |
|---|---|
| `Config` | Reads and validates `$wgFilePermLevels`, `$wgFilePermGroupGrants`, defaults |
| `PermissionService` | Read/write permission levels in `fileperm_levels` table (with in-process cache) |
| `EnforcementHooks` | Denies unauthorized access on File pages, img_auth.php, and embedded images |
| `UploadHooks` | Adds permission dropdown to Special:Upload; stores level on upload |
| `DisplayHooks` | Renders permission badge/editor on File pages; loads MsUpload/VE modules |
| `RegistrationHooks` | Validates configuration at extension registration time |
| `SchemaChangesHandler` | Registers `fileperm_levels` table with the schema updater |
| `ApiFilePermSetLevel` | `action=fileperm-set-level` write endpoint |
| `ApiQueryFilePermLevel` | `action=query&prop=fileperm` read endpoint |
| `ValidatePermissions` | Maintenance script to detect/repair orphaned levels |

### JavaScript Modules

| Module | Purpose |
|---|---|
| `ext.FilePermissions.edit` | OOUI dropdown + save button on File description pages |
| `ext.FilePermissions.shared` | XHR patch and post-upload verification (shared by MsUpload/VE) |
| `ext.FilePermissions.msupload` | Permission dropdown for the MsUpload drag-and-drop interface |
| `ext.FilePermissions.visualeditor` | Permission dropdown injected into the VE upload dialog |

### Services (Dependency Injection)

Services are wired in `includes/ServiceWiring.php` and registered in `extension.json`:

- `FilePermissions.Config` -- `Config` instance
- `FilePermissions.PermissionService` -- `PermissionService` instance

## Running Tests

### PHPUnit (unit tests)

```bash
docker exec filepermissions-wiki-1 php /var/www/html/tests/phpunit/phpunit.php \
    --testsuite extensions \
    --filter FilePermissions \
    --group FilePermissions
```

### PHPUnit (integration tests)

```bash
docker exec filepermissions-wiki-1 php /var/www/html/tests/phpunit/phpunit.php \
    /mw-user-extensions/FilePermissions/tests/phpunit/Integration/
```

**Important:** The `tests/phpunit/Integration/` directory must use an uppercase `I` to match the PSR-4 namespace `FilePermissions\Tests\Integration\`. The autoloader is case-sensitive on Linux.

### Playwright (browser tests)

Run from the host machine (not inside Docker):

```bash
npx playwright test
```

Individual spec files:

```bash
npx playwright test tests/playwright/upload-special.spec.ts
```

## Linting

### PHP

```bash
composer phpcs        # Check for violations
composer fix          # Auto-fix violations
```

Uses MediaWiki CodeSniffer (`mediawiki/mediawiki-codesniffer`).

### JavaScript

```bash
npm run lint          # Check for violations
npm run lint:fix      # Auto-fix violations
```

Uses `eslint-config-wikimedia`.

## Test Structure

```
tests/
  phpunit/
    unit/               # Pure unit tests (no DB, no MW services)
    Integration/        # Integration tests (DB, MW services, traits)
    e2e/                # End-to-end tests (HTTP requests against running wiki)
  playwright/           # Browser-based tests (Playwright)
```

## PR Guidelines

- Include tests for new functionality and bug fixes.
- Run `composer phpcs` and `npm run lint` before submitting.
- Update `CHANGELOG.md` for user-facing changes.
- Keep commits focused -- one logical change per commit.
