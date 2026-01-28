# Phase 1: Foundation & Infrastructure - Research

**Researched:** 2026-01-28
**Domain:** MediaWiki Extension Configuration, PageProps Storage, User Group Permissions
**Confidence:** HIGH

## Summary

Phase 1 establishes the permission model, configuration system, and storage layer for the FilePermissions extension. The research confirms that MediaWiki provides robust infrastructure for all required components: extension.json for configuration with callback-based validation, PageProps for persistent per-file storage, and UserGroupManager for group membership checks.

The primary approach uses MediaWiki's native patterns: configuration variables defined in extension.json with a registration callback for validation (fail-closed behavior), the PageProps service for storing/retrieving permission levels directly from the database, and dependency injection via ServiceWiring for the core permission service.

**Primary recommendation:** Use a service-based architecture with a `PermissionService` class registered via ServiceWiring, a static `Config` class for typed configuration access with early validation, and direct database queries via IConnectionProvider for PageProps reads (avoiding parser context dependency).

## Standard Stack

The established libraries/tools for this domain:

### Core

| Component | Version | Purpose | Why Standard |
|-----------|---------|---------|--------------|
| MediaWiki Core | 1.44.x | Base platform | Target version, LTS through June 2026 |
| PHP | 8.1 - 8.3 | Runtime | MW 1.44 requires PHP 8.1+; project targets 8.3 |
| extension.json | manifest_version 2 | Extension manifest | Required format since MW 1.25; enables DI |
| PSR-4 Autoloading | via AutoloadNamespaces | Class loading | Standard since MW 1.31 |

### Supporting

| Component | Purpose | When to Use |
|-----------|---------|-------------|
| ServiceOptions | Inject multiple config variables | When service needs several $wg variables |
| LoggerFactory | PSR-3 structured logging | All logging (prefer over wfDebugLog) |
| IConnectionProvider | Database access for PageProps | Reading/writing page properties outside parser context |
| UserGroupManager | Check user group membership | All permission checks |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Static Config class | ServiceOptions DI | Static is simpler for read-only config; DI better for testing |
| Direct DB for PageProps | ParserOutput::setPageProperty | Direct DB works outside parser context; ParserOutput only during parse |
| Service class | Static helpers | Service enables testing/mocking; static is simpler but less testable |

**Source:** [Context7 MediaWiki Documentation](https://context7.com/wikimedia/mediawiki), [MediaWiki Dependency Injection](https://www.mediawiki.org/wiki/Dependency_Injection)

## Architecture Patterns

### Recommended Project Structure

```
FilePermissions/
├── extension.json                 # Extension manifest with config and hooks
├── includes/
│   ├── Config.php                # Static typed config access + validation
│   ├── PermissionService.php     # Core business logic (registered service)
│   ├── ServiceWiring.php         # Service registration
│   └── Hooks/
│       └── RegistrationHooks.php # Registration callback for validation
├── i18n/
│   └── en.json                   # Messages
└── tests/
    └── phpunit/
        └── Unit/
            └── ConfigTest.php    # Config validation tests
```

### Pattern 1: Registration Callback for Configuration Validation

**What:** Use extension.json `callback` key to run validation immediately after LocalSettings.php is processed.

**When to use:** Configuration validation that must fail-closed on invalid config.

**Example:**
```php
// extension.json
{
    "callback": "FilePermissions\\Hooks\\RegistrationHooks::onRegistration"
}
```

```php
// includes/Hooks/RegistrationHooks.php
namespace FilePermissions\Hooks;

use MediaWiki\Logger\LoggerFactory;

class RegistrationHooks {
    public static function onRegistration(): void {
        $logger = LoggerFactory::getInstance( 'FilePermissions' );

        // Validate configuration - fail closed on invalid
        $errors = self::validateConfiguration();

        if ( !empty( $errors ) ) {
            // Set deny-all flag that PermissionService checks
            $GLOBALS['wgFilePermInvalidConfig'] = true;

            foreach ( $errors as $error ) {
                $logger->warning( 'FilePermissions: Invalid configuration - {error}', [
                    'error' => $error
                ] );
            }
        }
    }

    private static function validateConfiguration(): array {
        global $wgFilePermLevels, $wgFilePermGroupGrants;
        $errors = [];

        // Validate levels is non-empty array of strings
        if ( !is_array( $wgFilePermLevels ) || empty( $wgFilePermLevels ) ) {
            $errors[] = '$wgFilePermLevels must be a non-empty array';
        } else {
            foreach ( $wgFilePermLevels as $level ) {
                if ( !is_string( $level ) || $level === '' ) {
                    $errors[] = '$wgFilePermLevels must contain only non-empty strings';
                    break;
                }
            }
        }

        // Validate grants references valid levels
        if ( is_array( $wgFilePermGroupGrants ) ) {
            $validLevels = array_flip( $wgFilePermLevels ?? [] );
            foreach ( $wgFilePermGroupGrants as $group => $levels ) {
                if ( !is_array( $levels ) ) {
                    $errors[] = "Grant for group '$group' must be an array";
                    continue;
                }
                foreach ( $levels as $level ) {
                    if ( $level !== '*' && !isset( $validLevels[$level] ) ) {
                        $errors[] = "Grant for group '$group' references unknown level '$level'";
                    }
                }
            }
        }

        return $errors;
    }
}
```

**Source:** [Manual:Extension registration](https://www.mediawiki.org/wiki/Manual:Extension_registration)

### Pattern 2: Static Config Class with Typed Access

**What:** Centralize configuration access in a static class that provides typed getters with fallbacks.

**When to use:** All configuration access throughout the extension.

**Example:**
```php
// includes/Config.php
namespace FilePermissions;

class Config {
    public static function getLevels(): array {
        global $wgFilePermLevels;
        return $wgFilePermLevels ?? [ 'public' ];
    }

    public static function getGroupGrants(): array {
        global $wgFilePermGroupGrants;
        return $wgFilePermGroupGrants ?? [];
    }

    public static function getDefaultLevel(): ?string {
        global $wgFilePermDefaultLevel;
        return $wgFilePermDefaultLevel ?? null;
    }

    public static function getNamespaceDefaults(): array {
        global $wgFilePermNamespaceDefaults;
        return $wgFilePermNamespaceDefaults ?? [];
    }

    public static function isInvalidConfig(): bool {
        global $wgFilePermInvalidConfig;
        return $wgFilePermInvalidConfig ?? false;
    }

    public static function isValidLevel( string $level ): bool {
        return in_array( $level, self::getLevels(), true );
    }
}
```

**Source:** Sibling extension pattern (MSAssistant, SemanticSchemas conventions from PROJECT.md)

### Pattern 3: Service with Dependency Injection via ServiceWiring

**What:** Register the core PermissionService via ServiceWiring for proper DI.

**When to use:** Core business logic that needs database access and user services.

**Example:**
```php
// includes/ServiceWiring.php
use MediaWiki\MediaWikiServices;
use FilePermissions\PermissionService;

return [
    'FilePermissions.PermissionService' => static function (
        MediaWikiServices $services
    ): PermissionService {
        return new PermissionService(
            $services->getDBLoadBalancerFactory(),
            $services->getUserGroupManager()
        );
    },
];
```

```json
// extension.json
{
    "ServiceWiringFiles": [ "includes/ServiceWiring.php" ]
}
```

**Source:** [MediaWiki Dependency Injection](https://www.mediawiki.org/wiki/Dependency_Injection), [Context7 ServiceOptions example](https://github.com/wikimedia/mediawiki/blob/master/docs/Injection.md)

### Pattern 4: Direct Database Query for PageProps

**What:** Query page_props table directly via IConnectionProvider instead of relying on ParserOutput.

**When to use:** Reading/writing page properties outside of parsing context (e.g., in hooks, API calls).

**Example:**
```php
// includes/PermissionService.php
namespace FilePermissions;

use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;

class PermissionService {
    private const PROP_NAME = 'fileperm_level';

    private IConnectionProvider $dbProvider;
    private UserGroupManager $userGroupManager;

    public function __construct(
        IConnectionProvider $dbProvider,
        UserGroupManager $userGroupManager
    ) {
        $this->dbProvider = $dbProvider;
        $this->userGroupManager = $userGroupManager;
    }

    public function getLevel( Title $title ): ?string {
        if ( $title->getNamespace() !== NS_FILE ) {
            return null;
        }

        $pageId = $title->getArticleID();
        if ( $pageId === 0 ) {
            return null;
        }

        $dbr = $this->dbProvider->getReplicaDatabase();
        $level = $dbr->newSelectQueryBuilder()
            ->select( 'pp_value' )
            ->from( 'page_props' )
            ->where( [
                'pp_page' => $pageId,
                'pp_propname' => self::PROP_NAME,
            ] )
            ->caller( __METHOD__ )
            ->fetchField();

        return $level !== false ? $level : null;
    }

    public function setLevel( Title $title, string $level ): void {
        $pageId = $title->getArticleID();
        if ( $pageId === 0 ) {
            throw new \InvalidArgumentException( 'Cannot set level on non-existent page' );
        }

        if ( !Config::isValidLevel( $level ) ) {
            throw new \InvalidArgumentException( "Invalid permission level: $level" );
        }

        $dbw = $this->dbProvider->getPrimaryDatabase();
        $dbw->newReplaceQueryBuilder()
            ->replaceInto( 'page_props' )
            ->uniqueIndexFields( [ 'pp_page', 'pp_propname' ] )
            ->row( [
                'pp_page' => $pageId,
                'pp_propname' => self::PROP_NAME,
                'pp_value' => $level,
                'pp_sortkey' => null,
            ] )
            ->caller( __METHOD__ )
            ->execute();
    }
}
```

**Source:** [Manual:page_props table](https://www.mediawiki.org/wiki/Manual:Page_props_table), [Manual:Database access](https://www.mediawiki.org/wiki/Manual:Database_access)

### Pattern 5: User Group Permission Check

**What:** Check if user has access to a permission level via their group memberships.

**When to use:** All canAccess checks.

**Example:**
```php
// In PermissionService
public function canUserAccessLevel( UserIdentity $user, string $level ): bool {
    // Fail closed on invalid config
    if ( Config::isInvalidConfig() ) {
        return false;
    }

    $grants = Config::getGroupGrants();
    $userGroups = $this->userGroupManager->getUserEffectiveGroups( $user );

    foreach ( $userGroups as $group ) {
        if ( !isset( $grants[$group] ) ) {
            continue;
        }

        $grantedLevels = $grants[$group];

        // Wildcard grants access to all levels
        if ( in_array( '*', $grantedLevels, true ) ) {
            return true;
        }

        if ( in_array( $level, $grantedLevels, true ) ) {
            return true;
        }
    }

    return false;
}
```

**Source:** [MediaWiki UserGroupManager Class Reference](https://doc.wikimedia.org/mediawiki-core/master/php/classMediaWiki_1_1User_1_1UserGroupManager.html)

### Anti-Patterns to Avoid

- **Using ParserOutput::setPageProperty() for permission storage:** Only works during parsing; permissions need to be read/written outside parse context. Use direct database queries instead.
- **Using $wgExtensionFunctions for config validation:** Too late - services may already be instantiated. Use registration callback.
- **Storing complex objects in PageProps:** MW 1.42+ deprecates non-string values. Store only the level name string.
- **Using deprecated User::getGroups():** Use UserGroupManager service instead.
- **Throwing exceptions on invalid config:** Blocks wiki entirely. Log warning and fail-closed instead.

## Don't Hand-Roll

Problems that look simple but have existing solutions:

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| User group membership | Custom group parsing | UserGroupManager::getUserEffectiveGroups() | Handles implicit groups (*, user), autopromote |
| Database connection | Direct mysqli/PDO | IConnectionProvider from DI | Handles replication, transactions, pooling |
| Configuration access | Direct $GLOBALS | Static Config class | Type safety, validation, defaults |
| Logging | error_log(), wfDebug() | LoggerFactory::getInstance() | Structured logging, log levels, configurable |
| Service access | MediaWikiServices::getInstance() everywhere | Dependency injection | Testability, clear dependencies |

**Key insight:** MediaWiki's service container and dependency injection pattern provide tested, cached implementations. Custom solutions will lack proper caching and break on edge cases.

## Common Pitfalls

### Pitfall 1: Validation Timing

**What goes wrong:** Validating configuration in SetupAfterCache or ExtensionFunctions when services have already been instantiated.

**Why it happens:** These hooks seem like natural places for initialization, but some services may already have read config values.

**How to avoid:** Use the registration callback (`"callback"` in extension.json) which runs immediately after LocalSettings.php is processed but before service instantiation.

**Warning signs:** Config changes don't take effect; different behavior in fresh vs cached requests.

**Source:** [Manual:Extension registration](https://www.mediawiki.org/wiki/Manual:Extension_registration)

### Pitfall 2: PageProps Context Dependency

**What goes wrong:** Using ParserOutput::setPageProperty() outside of a parser hook context, resulting in lost data or errors.

**Why it happens:** ParserOutput is only available during page parsing. Permission changes need to work from API calls, form submissions, hooks.

**How to avoid:** Use direct database queries via IConnectionProvider for all PageProps read/write operations.

**Warning signs:** Permission level not persisted after setting; "null" errors when accessing ParserOutput.

**Source:** [Manual:page_props table](https://www.mediawiki.org/wiki/Manual:Page_props_table)

### Pitfall 3: Fail-Open on Invalid Config

**What goes wrong:** Extension continues to operate with partial or default permissions when configuration is invalid.

**Why it happens:** Default behavior is to use fallbacks rather than deny access.

**How to avoid:** Set a global flag on validation failure; check this flag in all permission checks and deny if set.

**Warning signs:** Files accessible when they shouldn't be; no errors in logs about config issues.

### Pitfall 4: Implicit Group Handling

**What goes wrong:** Not considering implicit groups (`*`, `user`) when checking permissions.

**Why it happens:** Developers only think about explicit group assignments like `sysop`.

**How to avoid:** Use UserGroupManager::getUserEffectiveGroups() which includes all implicit and autopromoted groups.

**Warning signs:** Anonymous users have access they shouldn't; logged-in users missing expected access.

**Source:** [MediaWiki UserGroupManager](https://doc.wikimedia.org/mediawiki-core/master/php/classMediaWiki_1_1User_1_1UserGroupManager.html)

### Pitfall 5: String Type in PageProps

**What goes wrong:** Storing non-string values (arrays, null) in page properties.

**Why it happens:** Legacy code patterns; assumption that PHP handles type coercion.

**How to avoid:** Always cast to string before storing; use empty string or delete property instead of null.

**Warning signs:** Deprecation warnings in MW 1.42+; unexpected property values.

**Source:** [Manual:page_props table](https://www.mediawiki.org/wiki/Manual:Page_props_table)

## Code Examples

Verified patterns from official sources:

### Configuration Definition in extension.json

```json
// Source: Manual:Extension.json/Schema
{
    "name": "FilePermissions",
    "version": "1.0.0",
    "author": ["Your Name"],
    "license-name": "GPL-2.0-or-later",
    "requires": {
        "MediaWiki": ">= 1.44.0"
    },
    "AutoloadNamespaces": {
        "FilePermissions\\": "includes/"
    },
    "callback": "FilePermissions\\Hooks\\RegistrationHooks::onRegistration",
    "ServiceWiringFiles": ["includes/ServiceWiring.php"],
    "config": {
        "FilePermLevels": {
            "value": ["public", "internal", "confidential"],
            "description": "Available permission levels for files"
        },
        "FilePermGroupGrants": {
            "value": {
                "sysop": ["*"],
                "user": ["public", "internal"]
            },
            "description": "Map of user groups to granted permission levels"
        },
        "FilePermDefaultLevel": {
            "value": null,
            "description": "Default permission level for new uploads (null = require explicit selection)"
        },
        "FilePermNamespaceDefaults": {
            "value": {},
            "description": "Map of namespace IDs to default permission levels"
        }
    },
    "manifest_version": 2
}
```

### PSR-3 Structured Logging

```php
// Source: Manual:Structured logging
use MediaWiki\Logger\LoggerFactory;

$logger = LoggerFactory::getInstance( 'FilePermissions' );

// INFO level - state changes
$logger->info( 'Permission level set on file', [
    'file' => $title->getPrefixedDBkey(),
    'level' => $level,
    'user' => $user->getName(),
] );

// WARNING level - configuration issues
$logger->warning( 'Invalid configuration detected: {error}', [
    'error' => 'Unknown permission level in grants',
    'group' => $group,
    'level' => $invalidLevel,
] );
```

### Default Resolution Chain

```php
// Determine default level for a file in a namespace
public function resolveDefaultLevel( int $namespace ): ?string {
    // Check namespace-specific default first
    $namespaceDefaults = Config::getNamespaceDefaults();
    if ( isset( $namespaceDefaults[$namespace] ) ) {
        $level = $namespaceDefaults[$namespace];
        if ( Config::isValidLevel( $level ) ) {
            return $level;
        }
    }

    // Fall back to global default
    $globalDefault = Config::getDefaultLevel();
    if ( $globalDefault !== null && Config::isValidLevel( $globalDefault ) ) {
        return $globalDefault;
    }

    // No default configured - require explicit selection
    return null;
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| User::getGroups() | UserGroupManager::getUserGroups() | MW 1.35 | User method deprecated |
| $wgHooks array | extension.json Hooks | MW 1.25 | Legacy hooks still work but discouraged |
| wfDebugLog() | LoggerFactory | MW 1.25 | Legacy still works but PSR-3 preferred |
| Non-string PageProps | String-only PageProps | MW 1.42 | Non-string values deprecated |
| SetupAfterCache for config | Registration callback | Always | Services may be instantiated before SetupAfterCache |

**Deprecated/outdated:**
- `User::getGroups()` - use `UserGroupManager::getUserGroups()`
- `ParserOutput::getProperty()` - use `getPageProperty()`
- Storing NULL in page_props - use empty string or delete

## Open Questions

Things that couldn't be fully resolved:

1. **Exact service name for PageProps**
   - What we know: PageProps service exists in MW core
   - What's unclear: Whether to use IConnectionProvider directly or inject PageProps service
   - Recommendation: Use IConnectionProvider for direct DB access (more flexibility, works in all contexts)

2. **Group-centric vs level-centric grant format**
   - What we know: User decided Claude's discretion on format
   - What's unclear: Which is more intuitive for MediaWiki admins
   - Recommendation: Group-centric format (matches $wgGroupPermissions pattern):
     ```php
     $wgFilePermGroupGrants = [
         'sysop' => ['*'],
         'user' => ['public', 'internal'],
     ];
     ```

3. **Behavior when upload has no selection and no default**
   - What we know: User wants to force explicit selection
   - What's unclear: Implementation approach (block form submission vs server-side rejection)
   - Recommendation: Validate on server side in UploadComplete hook; return error if no level and no default

## Sources

### Primary (HIGH confidence)

- [Context7 MediaWiki Documentation](https://context7.com/wikimedia/mediawiki) - Extension patterns, ServiceOptions, hook handlers
- [Manual:Extension registration](https://www.mediawiki.org/wiki/Manual:Extension_registration) - Callback registration pattern
- [MediaWiki Dependency Injection](https://www.mediawiki.org/wiki/Dependency_Injection) - Service architecture
- [Manual:page_props table](https://www.mediawiki.org/wiki/Manual:Page_props_table) - PageProps storage patterns
- [MediaWiki UserGroupManager](https://doc.wikimedia.org/mediawiki-core/master/php/classMediaWiki_1_1User_1_1UserGroupManager.html) - Group membership API

### Secondary (MEDIUM confidence)

- [Extension:Lockdown source](https://github.com/wikimedia/mediawiki-extensions-Lockdown) - Reference implementation of permission extension
- [Manual:Structured logging](https://www.mediawiki.org/wiki/Manual:Structured_logging/en) - PSR-3 logging patterns
- [Manual:Database access](https://www.mediawiki.org/wiki/Manual:Database_access) - Query builder patterns

### Tertiary (LOW confidence)

- WebSearch results on fail-closed patterns - no canonical MediaWiki documentation found; recommendation based on general security principles

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - All components from official MW documentation
- Architecture: HIGH - Patterns verified in Context7 and reference extensions
- Configuration validation: MEDIUM - No canonical pattern; synthesized from registration callback docs
- Fail-closed behavior: MEDIUM - Custom implementation; no MW-standard pattern

**Research date:** 2026-01-28
**Valid until:** 2026-02-28 (stable domain, 30 days)
