# Architecture Patterns

**Domain:** MediaWiki File Permissions Extension
**Researched:** 2026-01-28
**Confidence:** MEDIUM (verified against official MediaWiki documentation and existing extension patterns)

## Recommended Architecture

The FilePermissions extension follows a layered architecture with clear separation between storage, enforcement, and UI components. The design integrates with MediaWiki's existing permission infrastructure rather than replacing it.

```
+------------------+     +-------------------+     +------------------+
|   Upload UI      |     |   Admin UI        |     |   View UI        |
|  (MsUpload       |     | (Special page or  |     | (Permission      |
|   JS Bridge)     |     |  file page form)  |     |  indicators)     |
+--------+---------+     +---------+---------+     +--------+---------+
         |                         |                        |
         v                         v                        v
+--------+---------+     +---------+---------+     +--------+---------+
|  ResourceLoader  |     |   API Module      |     |   OutputPage     |
|  Module          |     | (Set permissions) |     |   Hook           |
+--------+---------+     +---------+---------+     +--------+---------+
         |                         |                        |
         +------------+------------+------------------------+
                      |
                      v
         +------------+------------+
         |    Permission Manager   |
         |  (Core business logic)  |
         +------------+------------+
                      |
         +------------+------------+
         |      PageProps          |
         |  (Storage layer via     |
         |   page_props table)     |
         +------------+------------+
                      |
         +------------+------------+
         |    Enforcement Layer    |
         | GetUserPermissionsErrors|
         |  + ImgAuthBeforeStream  |
         +-------------------------+
```

### Component Boundaries

| Component | Responsibility | Communicates With |
|-----------|---------------|-------------------|
| **PermissionManager** | Core business logic: check if user can access file, get/set permission levels | PageProps storage, MW PermissionManager service |
| **PagePropsStorage** | Store/retrieve permission levels per file using page_props table | MW Database, ParserOutput |
| **HookHandler** | Register and handle all MW hooks in one place | PermissionManager, MW core hooks |
| **GetUserPermissionsErrors Hook** | Block read/edit for protected files in wiki UI | PermissionManager |
| **ImgAuthBeforeStream Hook** | Block direct file downloads via img_auth.php | PermissionManager |
| **ApiSetFilePermission** | API endpoint to set permission level on a file | PermissionManager, PagePropsStorage |
| **ResourceLoader Module** | JS bridge for MsUpload integration | MsUpload (DOM interaction), MW API |
| **SpecialFilePermissions** (optional) | Admin UI for bulk permission management | ApiSetFilePermission, PermissionManager |

### Data Flow

**Upload Flow (with MsUpload integration):**
```
1. User drags file to MsUpload area
2. MsUpload handles upload via MW API (action=upload)
3. Our JS bridge detects upload completion (DOM events/callback)
4. JS bridge prompts user for permission level selection
5. JS bridge calls our API (action=setfilepermission)
6. API validates user has upload rights
7. API stores permission level via PagePropsStorage
8. PagePropsStorage writes to page_props table
```

**Read Enforcement Flow (wiki pages):**
```
1. User requests File:Example.png page or embedded image
2. MW calls getUserPermissionsErrors hook for 'read' action
3. HookHandler::onGetUserPermissionsErrors receives title
4. Check if title is in File namespace
5. Query PermissionManager::canUserAccessFile($user, $title)
6. PermissionManager fetches permission level from PageProps
7. PermissionManager checks user group membership against level
8. Return error array if denied, empty if allowed
```

**Read Enforcement Flow (direct file access via img_auth.php):**
```
1. User requests /w/img_auth.php/a/ab/Example.png
2. img_auth.php runs ImgAuthBeforeStream hook
3. HookHandler::onImgAuthBeforeStream receives title and path
4. Query PermissionManager::canUserAccessFile($user, $title)
5. Return true to stream file, or set $result with error messages
```

## Component Details

### 1. PermissionManager Service

**Purpose:** Central business logic for all permission decisions.

**Interface:**
```php
namespace MediaWiki\Extension\FilePermissions;

class PermissionManager {
    public function __construct(
        PagePropsStorage $storage,
        \MediaWiki\Permissions\PermissionManager $mwPermManager
    );

    public function canUserAccessFile(User $user, Title $title): bool;
    public function getPermissionLevel(Title $title): ?string;
    public function setPermissionLevel(Title $title, string $level): void;
    public function getRequiredGroups(string $level): array;
}
```

**Registration:** Use HookHandlers with dependency injection in extension.json:
```json
{
  "HookHandlers": {
    "main": {
      "class": "MediaWiki\\Extension\\FilePermissions\\HookHandler",
      "services": ["FilePermissions.PermissionManager"]
    }
  }
}
```

### 2. PagePropsStorage

**Purpose:** Abstract storage of permission levels using MW's page_props table.

**Key considerations:**
- Use `setPageProperty()` for persistent, queryable storage
- Property name: `fp_permission_level` (namespaced to avoid conflicts)
- Values must be strings (per MW 1.42+ deprecation of non-string values)
- Permission levels: `"public"`, `"user"`, `"trusted"`, `"admin"`, or custom

**Interface:**
```php
namespace MediaWiki\Extension\FilePermissions;

class PagePropsStorage {
    public function getLevel(int $pageId): ?string;
    public function setLevel(int $pageId, string $level): void;
    public function removeLevel(int $pageId): void;

    // For bulk queries (admin UI)
    public function getFilesWithLevel(string $level, int $limit, int $offset): array;
}
```

**Important:** Do NOT use `setExtensionData()` - that's only for same-request data. PageProps is correct for persistent permission storage that survives parser cache invalidation.

### 3. HookHandler

**Purpose:** Single class implementing all hook interfaces, with injected PermissionManager.

**Hooks to implement:**

| Hook | Interface | Purpose |
|------|-----------|---------|
| `getUserPermissionsErrors` | `GetUserPermissionsErrorsHook` | Block wiki page access |
| `getUserPermissionsErrorsExpensive` | `GetUserPermissionsErrorsExpensiveHook` | Full permission check |
| `ImgAuthBeforeStream` | `ImgAuthBeforeStreamHook` | Block direct file downloads |
| `BeforePageDisplay` | `BeforePageDisplayHook` | Inject permission indicators |
| `UploadComplete` | `UploadCompleteHook` | Set default permission on upload |

**Structure:**
```php
namespace MediaWiki\Extension\FilePermissions;

use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Hook\ImgAuthBeforeStreamHook;
// ... other hook interfaces

class HookHandler implements
    GetUserPermissionsErrorsHook,
    ImgAuthBeforeStreamHook,
    BeforePageDisplayHook,
    UploadCompleteHook
{
    private PermissionManager $permissionManager;

    public function __construct(PermissionManager $permissionManager) {
        $this->permissionManager = $permissionManager;
    }

    public function onGetUserPermissionsErrors($title, $user, $action, &$result) {
        // Only check File namespace
        if ($title->getNamespace() !== NS_FILE) {
            return true;
        }
        // Only check 'read' action
        if ($action !== 'read') {
            return true;
        }
        if (!$this->permissionManager->canUserAccessFile($user, $title)) {
            $result = ['filepermissions-access-denied'];
            return false;
        }
        return true;
    }

    public function onImgAuthBeforeStream(&$title, &$path, &$name, &$result) {
        $user = RequestContext::getMain()->getUser();
        if (!$this->permissionManager->canUserAccessFile($user, $title)) {
            $result = ['img-auth-accessdenied', 'filepermissions-access-denied'];
            return false;
        }
        return true;
    }
}
```

### 4. API Module

**Purpose:** Allow JavaScript (and external tools) to set file permissions.

**Endpoint:** `action=setfilepermission`

**Structure:**
```php
namespace MediaWiki\Extension\FilePermissions\Api;

class ApiSetFilePermission extends ApiBase {
    public function execute() {
        $params = $this->extractRequestParams();
        $title = Title::newFromText($params['title'], NS_FILE);

        // Verify user has upload permission
        $this->checkUserRightsAny('upload');

        // Verify file exists
        if (!$title || !$title->exists()) {
            $this->dieWithError('apierror-missingtitle');
        }

        // Set permission
        $this->permissionManager->setPermissionLevel(
            $title,
            $params['level']
        );

        $this->getResult()->addValue(null, 'success', true);
    }

    public function getAllowedParams() {
        return [
            'title' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => true,
            ],
            'level' => [
                ApiBase::PARAM_TYPE => ['public', 'user', 'trusted', 'admin'],
                ApiBase::PARAM_REQUIRED => true,
            ],
        ];
    }

    public function needsToken() {
        return 'csrf';
    }
}
```

**Registration in extension.json:**
```json
{
  "APIModules": {
    "setfilepermission": "MediaWiki\\Extension\\FilePermissions\\Api\\ApiSetFilePermission"
  }
}
```

### 5. ResourceLoader Module (JS Bridge for MsUpload)

**Purpose:** Intercept MsUpload completion events and prompt for permission level.

**Approach:** Do NOT fork MsUpload. Instead:
1. Load our module on edit pages where MsUpload is active
2. Hook into MsUpload's JavaScript events or DOM changes
3. Present permission selection UI
4. Call our API to set permission

**Module structure:**
```json
{
  "ResourceModules": {
    "ext.FilePermissions.msupload": {
      "packageFiles": [
        "msupload-bridge.js"
      ],
      "styles": [
        "msupload-bridge.less"
      ],
      "dependencies": [
        "mediawiki.api",
        "oojs-ui-core",
        "oojs-ui-widgets"
      ],
      "messages": [
        "filepermissions-select-level",
        "filepermissions-level-public",
        "filepermissions-level-user",
        "filepermissions-level-trusted",
        "filepermissions-level-admin"
      ]
    }
  }
}
```

**JS Pattern (IIFE as specified):**
```javascript
( function () {
    'use strict';

    var api = new mw.Api();

    function setFilePermission(filename, level) {
        return api.postWithToken('csrf', {
            action: 'setfilepermission',
            title: filename,
            level: level
        });
    }

    function showPermissionDialog(filename) {
        // OOUI dialog for permission selection
        // On confirm, call setFilePermission()
    }

    function observeMsUpload() {
        // Watch for MsUpload success events
        // MsUpload adds status elements we can observe
        // Use MutationObserver or direct event binding if available
    }

    mw.hook('wikipage.content').add(function () {
        if (document.getElementById('msupload-container')) {
            observeMsUpload();
        }
    });
}() );
```

## Patterns to Follow

### Pattern 1: Service Registration via ServiceWiring

**What:** Register PermissionManager as a MediaWiki service for dependency injection.

**When:** Always - enables proper testing and hook handler injection.

**Example:**
```php
// ServiceWiring.php
return [
    'FilePermissions.PermissionManager' => function (MediaWikiServices $services) {
        return new PermissionManager(
            new PagePropsStorage($services->getDBLoadBalancer()),
            $services->getPermissionManager()
        );
    },
];
```

```json
// extension.json
{
  "ServiceWiringFiles": ["includes/ServiceWiring.php"]
}
```

### Pattern 2: Hook Handler with Dependency Injection (MW 1.35+)

**What:** Use HookHandlers in extension.json with services array.

**When:** All hook registrations - enables testing and proper service access.

**Example:**
```json
{
  "Hooks": {
    "getUserPermissionsErrors": "main",
    "ImgAuthBeforeStream": "main"
  },
  "HookHandlers": {
    "main": {
      "class": "MediaWiki\\Extension\\FilePermissions\\HookHandler",
      "services": ["FilePermissions.PermissionManager"]
    }
  }
}
```

### Pattern 3: Early Return for Irrelevant Contexts

**What:** Check namespace and action before doing expensive permission lookups.

**When:** All hook handlers.

**Example:**
```php
public function onGetUserPermissionsErrors($title, $user, $action, &$result) {
    // Fast path: not a file
    if ($title->getNamespace() !== NS_FILE) {
        return true;
    }
    // Fast path: not a read action
    if ($action !== 'read') {
        return true;
    }
    // Now do expensive permission check
    // ...
}
```

### Pattern 4: Configuration via Static Config Class

**What:** Centralize configuration in a Config.php class that reads from $wgFilePermissions*.

**When:** All configurable behavior.

**Example:**
```php
namespace MediaWiki\Extension\FilePermissions;

class Config {
    public static function getPermissionLevels(): array {
        global $wgFilePermissionsLevels;
        return $wgFilePermissionsLevels ?? [
            'public' => [],
            'user' => ['user'],
            'trusted' => ['trusted', 'sysop'],
            'admin' => ['sysop'],
        ];
    }

    public static function getDefaultLevel(): string {
        global $wgFilePermissionsDefaultLevel;
        return $wgFilePermissionsDefaultLevel ?? 'public';
    }
}
```

## Anti-Patterns to Avoid

### Anti-Pattern 1: Direct Database Queries for PageProps

**What:** Using raw SQL to read/write page_props instead of MW APIs.

**Why bad:** Bypasses caching, breaks on schema changes, skips hooks.

**Instead:** Use `WikiPage::getPageProperty()` or query via `PageProps` service, set via `ParserOutput::setPageProperty()` during parsing or direct DB via PagePropsStorage class with proper LoadBalancer usage.

### Anti-Pattern 2: Storing Complex Objects in PageProps

**What:** Serializing arrays or objects as page property values.

**Why bad:** MW 1.42+ deprecates non-string values; breaks querying.

**Instead:** Store simple string identifiers (permission level names), keep level-to-groups mapping in configuration.

### Anti-Pattern 3: Forking MsUpload

**What:** Copying MsUpload code and modifying it directly.

**Why bad:** Maintenance nightmare, breaks on MsUpload updates, duplicates bugs.

**Instead:** Create a bridge module that observes MsUpload behavior via DOM/events and adds our UI on top.

### Anti-Pattern 4: Using getUserPermissionsErrors for Non-Essential UI

**What:** Using the expensive hook for things like showing/hiding UI elements.

**Why bad:** Performance impact on every page load.

**Instead:** Use `getUserPermissionsErrors` only for actual access control; use `BeforePageDisplay` or client-side checks for UI hints.

### Anti-Pattern 5: Checking Permissions Without img_auth.php Integration

**What:** Only implementing wiki page access control, not direct file access.

**Why bad:** Files remain accessible via direct URL, defeating the entire purpose.

**Instead:** MUST implement `ImgAuthBeforeStream` hook AND configure wiki to use img_auth.php (non-public upload directory).

## Directory Structure

```
FilePermissions/
├── extension.json              # Extension manifest
├── includes/
│   ├── Config.php             # Static configuration class
│   ├── PermissionManager.php  # Core business logic
│   ├── PagePropsStorage.php   # Storage abstraction
│   ├── HookHandler.php        # All hook implementations
│   ├── ServiceWiring.php      # Service registration
│   ├── Api/
│   │   └── ApiSetFilePermission.php
│   ├── Hooks/                  # (Alternative: split hooks by category)
│   │   └── PermissionHooks.php
│   └── Special/               # (Optional: admin UI)
│       └── SpecialFilePermissions.php
├── resources/
│   ├── msupload-bridge.js     # MsUpload integration
│   └── msupload-bridge.less   # Styles
├── i18n/
│   └── en.json                # Messages
└── tests/
    └── phpunit/
        ├── PermissionManagerTest.php
        └── HookHandlerTest.php
```

## Build Order Implications

Based on component dependencies, the recommended build order is:

### Phase 1: Storage Foundation
1. **Config.php** - Define permission levels, no dependencies
2. **PagePropsStorage.php** - Storage layer, depends only on MW core
3. **PermissionManager.php** - Business logic, depends on PagePropsStorage + Config

**Rationale:** These form the core that everything else depends on. Can be unit tested in isolation.

### Phase 2: Enforcement Layer
4. **HookHandler.php** (GetUserPermissionsErrors only) - Wiki page access
5. **HookHandler.php** (ImgAuthBeforeStream) - Direct file access
6. **ServiceWiring.php** - Wire everything together

**Rationale:** Enforcement must work before UI. ImgAuthBeforeStream is critical - without it, permissions are meaningless.

### Phase 3: API Layer
7. **ApiSetFilePermission.php** - Enable programmatic permission setting
8. Extension.json API registration

**Rationale:** API is needed before JS bridge. Can be tested via curl/API sandbox.

### Phase 4: Upload Integration
9. **ResourceLoader module** - JS bridge for MsUpload
10. Hook into upload workflow

**Rationale:** UI comes last, depends on working API and enforcement.

### Phase 5: Admin UI (Optional)
11. **SpecialFilePermissions.php** - Bulk management interface

**Rationale:** Nice-to-have, not required for core functionality.

## Integration Points

### MediaWiki Core

| Integration | MW Component | Our Component |
|-------------|--------------|---------------|
| Permission checks | PermissionManager service | PermissionManager |
| Page properties | page_props table | PagePropsStorage |
| Hook system | HookContainer | HookHandler |
| API framework | ApiBase | ApiSetFilePermission |
| ResourceLoader | ResourceLoader | ext.FilePermissions.* |
| File serving | img_auth.php | ImgAuthBeforeStream hook |

### MsUpload Extension

| Integration Point | MsUpload Side | Our Side |
|-------------------|---------------|----------|
| Upload completion | DOM elements with upload status | MutationObserver in bridge |
| UI injection point | #msupload-container | Append permission selector |
| No code changes | MsUpload unchanged | Bridge module only |

### img_auth.php Requirements

For the extension to work, the wiki MUST be configured for image authorization:

```php
// LocalSettings.php
$wgUploadDirectory = '/var/www/private/images'; // Non-web-accessible
$wgUploadPath = '/w/img_auth.php';              // Route through script
$wgImgAuthDetails = false;                       // Don't reveal why access denied
```

Without this configuration, direct file URLs bypass all permission checks.

## Scalability Considerations

| Concern | At 100 files | At 10K files | At 100K files |
|---------|--------------|--------------|---------------|
| PageProps queries | Direct lookup | Still direct lookup | Add caching layer |
| Permission checks | Per-request | Per-request | Consider memcached |
| Admin UI listing | Simple query | Pagination required | Background jobs for stats |
| Group membership | MW core handles | MW core handles | MW core handles |

**Note:** For most private wikis, 10K files is a lot. The PageProps approach scales well because it's indexed by page_id and leverages MW's existing infrastructure.

## Sources

### HIGH Confidence (Official Documentation)
- [Manual:Hooks/getUserPermissionsErrors](https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors) - Permission hook interface
- [Manual:Hooks/ImgAuthBeforeStream](https://www.mediawiki.org/wiki/Manual:Hooks/ImgAuthBeforeStream) - Image authorization hook
- [Manual:Page props table](https://www.mediawiki.org/wiki/Manual:Page_props_table) - PageProps storage
- [Manual:Extension.json/Schema](https://www.mediawiki.org/wiki/Manual:Extension.json/Schema) - Extension registration
- [ResourceLoader/Developing with ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader/Developing_with_ResourceLoader) - JS modules

### MEDIUM Confidence (Verified Patterns)
- [Extension:Lockdown](https://www.mediawiki.org/wiki/Extension:Lockdown) - Similar permission extension
- [Extension:NSFileRepo](https://www.mediawiki.org/wiki/Extension:NSFileRepo) - File namespace permissions
- [Extension:MsUpload](https://www.mediawiki.org/wiki/Extension:MsUpload) - Upload extension to integrate with
- [GitHub: mediawiki-extensions-Lockdown](https://github.com/wikimedia/mediawiki-extensions-Lockdown) - Reference implementation
- [GitHub: mediawiki-extensions-MsUpload](https://github.com/wikimedia/mediawiki-extensions-MsUpload) - MsUpload structure

### LOW Confidence (Community Sources)
- WebSearch results on MediaWiki extension architecture patterns - general guidance only
