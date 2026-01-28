# Technology Stack: FilePermissions Extension

**Project:** FilePermissions - MediaWiki per-file group-based access control extension
**Researched:** 2026-01-28
**Target Platform:** MediaWiki 1.44

---

## Recommended Stack

### Core Platform

| Technology | Version | Purpose | Why | Confidence |
|------------|---------|---------|-----|------------|
| MediaWiki | 1.44.x | Base platform | Target version specified; LTS through June 2026 | HIGH |
| PHP | 8.1 - 8.3 | Runtime | MW 1.44 requires PHP 8.1+; production runs 8.3 | HIGH |

**Source:** [MediaWiki Compatibility](https://www.mediawiki.org/wiki/Compatibility), [MediaWiki 1.44 Release](https://www.mediawiki.org/wiki/MediaWiki_1.44)

### Extension Framework

| Component | Implementation | Purpose | Why | Confidence |
|-----------|----------------|---------|-----|------------|
| Registration | `extension.json` | Extension manifest | Required format since MW 1.25; enables DI | HIGH |
| Autoloading | PSR-4 via `AutoloadNamespaces` | Class loading | Standard since MW 1.31; cleaner than class maps | HIGH |
| Namespace | `FilePermissions\` | PHP namespace | Follows MW convention; enables clear imports | HIGH |
| Directory | `includes/` with subdirs | Structure | Sibling pattern: `Api/`, `Hooks/`, `Special/` | HIGH |

**Configuration in extension.json:**
```json
{
  "AutoloadNamespaces": {
    "FilePermissions\\": "includes/"
  }
}
```

**Source:** [MediaWiki PSR-4 Autoloading](https://www.mediawiki.org/wiki/Manual:Extension.json/Schema), [Wikibase Autoloading History](https://www.mediawiki.org/wiki/Wikibase/History/Autoloading)

---

## Hook System (Critical)

### Primary Permission Hook

| Hook | Interface | Purpose | Confidence |
|------|-----------|---------|------------|
| `getUserPermissionsErrors` | `GetUserPermissionsErrorsHook` | Block actions on File pages | HIGH |

**Why this hook:**
- Called during permission checks in `Title.php`
- Can return custom error messages to users
- Used by existing permission extensions (Lockdown, AccessControl)
- NOT deprecated (unlike `userCan` which was deprecated in 1.37)

**Hook signature:**
```php
public function onGetUserPermissionsErrors(
    $title,
    $user,
    $action,
    &$result
): bool|void;
```

**Return behavior:**
- Return `true` to continue checking other hooks (allow)
- Return `false` with `$result` set to deny with message
- `$result` can be string (message key) or array (message key + params)

**Source:** [Manual:Hooks/getUserPermissionsErrors](https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors)

### File Byte-Level Protection Hook

| Hook | Interface | Purpose | Confidence |
|------|-----------|---------|------------|
| `ImgAuthBeforeStream` | `ImgAuthBeforeStreamHook` | Block file downloads via img_auth.php | HIGH |

**Why this hook:**
- Only hook called before streaming files through img_auth.php
- Parameters: `&$title`, `&$path`, `&$result`
- Return `true` to allow streaming, `false` to deny
- `$result[0]` and `$result[1]` are message indices (NOT actual messages)

**CRITICAL:** This hook is ONLY called when using img_auth.php. Standard image URLs bypass this entirely. Wiki must be configured with:
```php
$wgUploadPath = "$wgScriptPath/img_auth.php";
$wgUploadDirectory = "/path/outside/webroot";
```

**Source:** [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization), [ImgAuthBeforeStreamHook Interface](https://doc.wikimedia.org/mediawiki-core/master/php/interfaceMediaWiki_1_1Hook_1_1ImgAuthBeforeStreamHook.html)

### Upload Form Integration Hook

| Hook | Interface | Purpose | Confidence |
|------|-----------|---------|------------|
| `EditPage::showEditForm:initial` | N/A | Inject UI into edit/upload forms | MEDIUM |
| `UploadComplete` | `UploadCompleteHook` | Set permission after upload | MEDIUM |

**For Special:Upload integration:**
- Use `UploadComplete` to set default permission level on new uploads
- Access file via `$uploadBase->getLocalFile()`

**Source:** [Manual:Hooks/UploadComplete](https://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete)

---

## Dependency Injection Pattern (MW 1.35+)

### HookHandlers Registration

**extension.json:**
```json
{
  "Hooks": {
    "getUserPermissionsErrors": "permission",
    "ImgAuthBeforeStream": "imgauth",
    "UploadComplete": "upload"
  },
  "HookHandlers": {
    "permission": {
      "class": "FilePermissions\\Hooks\\PermissionHooks",
      "services": ["UserGroupManager", "PageProps"]
    },
    "imgauth": {
      "class": "FilePermissions\\Hooks\\ImgAuthHooks",
      "services": ["PageProps"]
    },
    "upload": {
      "class": "FilePermissions\\Hooks\\UploadHooks"
    }
  }
}
```

**Why separate handlers:**
- Performance: Only inject services needed by each hook
- Some services have expensive constructors
- Cleaner code organization

**Source:** [Manual:Hooks](https://www.mediawiki.org/wiki/Manual:Hooks), [Dependency Injection](https://www.mediawiki.org/wiki/Dependency_Injection)

---

## PageProps System (Permission Storage)

### Writing Page Properties

| Method | Class | Purpose | Confidence |
|--------|-------|---------|------------|
| `setPageProperty()` | `ParserOutput` | Store permission level | HIGH |
| `getPageProperty()` | `ParserOutput` | Read during parse | HIGH |

**Code pattern:**
```php
// In parser hook or form handler
$parserOutput = $parser->getOutput();
$parserOutput->setPageProperty('filepermissions-level', 'staff');
```

**Important constraints (MW 1.42+):**
- Values MUST be strings (non-string deprecated)
- NULL values deprecated; use empty string or `unsetPageProperty()`
- Property persists to `page_props` table automatically

**Source:** [Description2 Extension](https://github.com/wikimedia/mediawiki-extensions-Description2/blob/master/includes/Description2.php), [WikiSEO Extension](https://github.com/wikimedia/mediawiki-extensions-WikiSEO/blob/master/includes/WikiSEO.php)

### Reading Page Properties (Runtime)

| Service | Method | Purpose | Confidence |
|---------|--------|---------|------------|
| `PageProps` | `getProperties($titles, $propertyNames)` | Read from DB | MEDIUM |

**Code pattern:**
```php
// Inject PageProps service via DI
public function __construct(PageProps $pageProps) {
    $this->pageProps = $pageProps;
}

// In hook handler
$props = $this->pageProps->getProperties([$title], ['filepermissions-level']);
$pageId = $title->getArticleID();
$level = $props[$pageId]['filepermissions-level'] ?? 'default';
```

**Note:** Service name is `PageProps` not `PagePropsLookup`. Available via `MediaWikiServices::getInstance()->getPageProps()`.

**Source:** [Manual:page_props table](https://www.mediawiki.org/wiki/Manual:Page_props_table), [PageProps Class](https://doc.wikimedia.org/mediawiki-core/1.34.3/php/classPageProps.html)

---

## ResourceLoader (JavaScript/CSS)

### Module Definition

**extension.json:**
```json
{
  "ResourceModules": {
    "ext.FilePermissions": {
      "localBasePath": "resources",
      "remoteExtPath": "FilePermissions/resources",
      "scripts": ["ext.FilePermissions.js"],
      "styles": ["ext.FilePermissions.less"],
      "dependencies": ["oojs-ui-core", "mediawiki.api"],
      "messages": ["filepermissions-select-label", "filepermissions-level-public"]
    }
  }
}
```

### JavaScript Pattern

**Modern approach (MW 1.42+):** ES6 is enabled by default.

```javascript
// resources/ext.FilePermissions.js
( function () {
    'use strict';

    // Module automatically wrapped - no IIFE needed for scope
    // But IIFE pattern still valid and used in sibling extensions

    mw.hook('wikipage.content').add(function ($content) {
        // UI initialization
    });
}() );
```

**DO NOT:**
- Use global scope variables (they're actually local in ResourceLoader)
- Assume immediate execution (modules are loaded on demand)

**Source:** [ResourceLoader/Developing](https://www.mediawiki.org/wiki/ResourceLoader/Developing_with_ResourceLoader)

### MsUpload Integration

**MsUpload hooks into:** `EditPage::showEditForm:initial`
**MsUpload module:** `ext.MsUpload`

**Integration approach:**
```javascript
// Bridge module - loads after MsUpload
mw.loader.using(['ext.MsUpload']).then(function () {
    // Hook into MsUpload's upload complete callback
    // Add permission selector to MsUpload UI
});
```

**MsUpload requirements:** MediaWiki 1.41+

**Source:** [Extension:MsUpload](https://www.mediawiki.org/wiki/Extension:MsUpload), [MsUpload extension.json](https://github.com/wikimedia/mediawiki-extensions-MsUpload/blob/master/extension.json)

---

## Configuration Pattern

### Static Config Class

Following sibling extension pattern:

```php
// includes/Config.php
namespace FilePermissions;

class Config {
    public static function getPermissionLevels(): array {
        global $wgFilePermissionsLevels;
        return $wgFilePermissionsLevels ?? ['public', 'users', 'staff', 'admin'];
    }

    public static function getDefaultLevel(): string {
        global $wgFilePermissionsDefaultLevel;
        return $wgFilePermissionsDefaultLevel ?? 'users';
    }
}
```

**extension.json config:**
```json
{
  "config": {
    "FilePermissionsLevels": {
      "value": ["public", "users", "staff", "admin"],
      "description": "Available permission levels"
    },
    "FilePermissionsDefaultLevel": {
      "value": "users",
      "description": "Default level for new uploads"
    },
    "FilePermissionsGroupMap": {
      "value": {
        "public": ["*"],
        "users": ["user"],
        "staff": ["staff", "sysop"],
        "admin": ["sysop"]
      },
      "description": "Map of levels to allowed groups"
    }
  }
}
```

---

## What NOT to Use

| Avoid | Why | Use Instead | Confidence |
|-------|-----|-------------|------------|
| `userCan` hook | Deprecated since 1.37 | `getUserPermissionsErrors` | HIGH |
| `PermissionManager::getPermissionErrors()` | Deprecated since 1.43 | `getPermissionStatus()` | HIGH |
| Static hook methods | Old pattern, no DI | HookHandlers with services | HIGH |
| `ParserOutput::getProperty()` | Deprecated | `getPageProperty()` | HIGH |
| NULL in setPageProperty | Deprecated since 1.42 | Empty string or unset | HIGH |
| Global scope JS vars | ResourceLoader wraps modules | Explicit window attachment or module pattern | HIGH |
| `$wgHooks` array | Legacy registration | `Hooks` key in extension.json | MEDIUM |

---

## Directory Structure

```
FilePermissions/
├── extension.json
├── includes/
│   ├── Config.php                    # Static config accessor
│   ├── PermissionLevel.php           # Enum/constants for levels
│   ├── Api/
│   │   └── ApiFilePermissions.php    # API module (future)
│   ├── Hooks/
│   │   ├── PermissionHooks.php       # getUserPermissionsErrors
│   │   ├── ImgAuthHooks.php          # ImgAuthBeforeStream
│   │   └── UploadHooks.php           # UploadComplete
│   └── Special/
│       └── SpecialFilePermissions.php # Admin UI (future)
├── resources/
│   ├── ext.FilePermissions.js
│   ├── ext.FilePermissions.less
│   └── ext.FilePermissions.MsUpload.js  # MsUpload bridge
├── i18n/
│   ├── en.json
│   └── qqq.json
└── tests/
    └── phpunit/
```

---

## Version Requirements Summary

| Component | Minimum | Recommended | Notes |
|-----------|---------|-------------|-------|
| MediaWiki | 1.44.0 | 1.44.x | LTS target |
| PHP | 8.1.0 | 8.3.x | Production runs 8.3 |
| MsUpload | 1.41.0 | Latest | Optional dependency |

---

## Sources

### Official Documentation (HIGH confidence)
- [MediaWiki 1.44 Release Notes](https://www.mediawiki.org/wiki/MediaWiki_1.44)
- [Manual:Hooks/getUserPermissionsErrors](https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors)
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization)
- [Manual:page_props table](https://www.mediawiki.org/wiki/Manual:Page_props_table)
- [ResourceLoader/Developing](https://www.mediawiki.org/wiki/ResourceLoader/Developing_with_ResourceLoader)
- [Dependency Injection](https://www.mediawiki.org/wiki/Dependency_Injection)
- [MediaWiki Compatibility](https://www.mediawiki.org/wiki/Compatibility)

### Reference Implementations (MEDIUM confidence)
- [Extension:Lockdown Hooks.php](https://github.com/wikimedia/mediawiki-extensions-Lockdown/blob/master/src/Hooks.php)
- [Extension:MsUpload extension.json](https://github.com/wikimedia/mediawiki-extensions-MsUpload/blob/master/extension.json)
- [Description2 PageProperty usage](https://github.com/wikimedia/mediawiki-extensions-Description2/blob/master/includes/Description2.php)

### API Documentation (HIGH confidence)
- [ImgAuthBeforeStreamHook Interface](https://doc.wikimedia.org/mediawiki-core/master/php/interfaceMediaWiki_1_1Hook_1_1ImgAuthBeforeStreamHook.html)
- [PermissionManager Class](https://doc.wikimedia.org/mediawiki-core/master/php/classMediaWiki_1_1Permissions_1_1PermissionManager.html)

---

## Open Questions (Need Phase-Specific Research)

1. **PageProps service injection:** Exact service name for DI may need verification against MW 1.44 ServiceWiring.php
2. **MsUpload callback API:** No documented JavaScript hooks; may need to inspect MsUpload.js source for integration points
3. **img_auth.php refactor:** Implementation moved to `AuthenticatedFileEntryPoint` class; hook behavior should be verified
