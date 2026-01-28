# Phase 2: Core Enforcement - Research

**Researched:** 2026-01-28
**Domain:** MediaWiki Permission Hooks, img_auth.php, Embedded Image Handling
**Confidence:** HIGH

## Summary

Phase 2 implements the enforcement layer that prevents unauthorized users from accessing protected files through any content path. The research confirms MediaWiki provides three primary hook points for enforcement: `getUserPermissionsErrors` for File: description page access, `ImgAuthBeforeStream` for raw file and thumbnail access via img_auth.php, and `ImageBeforeProduceHTML` for embedded image rendering.

The standard approach uses MediaWiki's modern hook handler pattern: implement hook interfaces in a handler class registered via extension.json's HookHandlers with dependency injection. The PermissionService built in Phase 1 provides the `canUserAccessFile()` method that all hooks will use for authorization checks.

**Primary recommendation:** Create a single `EnforcementHooks` class implementing all three hook interfaces, injecting the PermissionService. Use MediaWiki's standard error message system for denials, and generate inline SVG placeholders for embedded images.

## Standard Stack

The established components for this domain:

### Core

| Component | Version | Purpose | Why Standard |
|-----------|---------|---------|--------------|
| GetUserPermissionsErrorsHook | MW 1.35+ | Block File: page access | Standard interface since MW 1.35 |
| ImgAuthBeforeStreamHook | MW 1.35+ | Block raw file/thumbnail access | Only hook point for img_auth.php |
| ImageBeforeProduceHTMLHook | MW 1.35+ | Replace embedded images | Allows HTML replacement before output |
| RequestContext | MW Core | Get current user | Standard user context access |

### Supporting

| Component | Purpose | When to Use |
|-----------|---------|-------------|
| wfMessage() | Generate localized error messages | All user-facing error text |
| Title::newFromText() | Parse file titles from paths | Thumbnail/archive path resolution |
| RepoGroup::findFile() | Look up File objects | Getting file metadata for placeholders |
| $wgParserCacheFilterConfig | Disable parser cache per namespace | Ensure permission changes take effect |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| ImageBeforeProduceHTML | BeforeParserFetchFileAndTitle | ImageBeforeProduceHTML gives access to final HTML; BeforeParserFetchFileAndTitle only marks file as "broken" |
| Inline SVG placeholder | External placeholder image file | Inline SVG avoids additional HTTP request and can be sized dynamically |
| Single hooks class | Separate class per hook | Single class shares PermissionService instance, simpler wiring |

## Architecture Patterns

### Recommended Project Structure

```
FilePermissions/
├── extension.json                     # Hook registrations
├── includes/
│   ├── Config.php                    # (Phase 1)
│   ├── PermissionService.php         # (Phase 1) - provides canUserAccessFile()
│   ├── ServiceWiring.php             # Service registration
│   └── Hooks/
│       ├── RegistrationHooks.php     # (Phase 1)
│       └── EnforcementHooks.php      # NEW: All enforcement hooks
└── i18n/
    └── en.json                       # Error messages
```

### Pattern 1: Modern Hook Handler Registration

**What:** Register hook handlers via extension.json HookHandlers with dependency injection.

**When to use:** All hook implementations in MW 1.35+.

**Example:**
```json
// extension.json
{
    "HookHandlers": {
        "enforcement": {
            "class": "FilePermissions\\Hooks\\EnforcementHooks",
            "services": [
                "FilePermissions.PermissionService"
            ]
        }
    },
    "Hooks": {
        "getUserPermissionsErrors": "enforcement",
        "ImgAuthBeforeStream": "enforcement",
        "ImageBeforeProduceHTML": "enforcement"
    }
}
```

**Source:** [MediaWiki Hooks Documentation](https://github.com/wikimedia/mediawiki/blob/master/docs/Hooks.md)

### Pattern 2: Hook Handler Class with Interface Implementation

**What:** Single class implementing multiple hook interfaces with injected dependencies.

**When to use:** When multiple hooks share the same service dependencies.

**Example:**
```php
namespace FilePermissions\Hooks;

use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Hook\ImgAuthBeforeStreamHook;
use MediaWiki\Hook\ImageBeforeProduceHTMLHook;
use FilePermissions\PermissionService;

class EnforcementHooks implements
    GetUserPermissionsErrorsHook,
    ImgAuthBeforeStreamHook,
    ImageBeforeProduceHTMLHook
{
    private PermissionService $permissionService;

    public function __construct( PermissionService $permissionService ) {
        $this->permissionService = $permissionService;
    }

    // Hook implementations follow...
}
```

**Source:** [MediaWiki Hooks.md](https://github.com/wikimedia/mediawiki/blob/master/docs/Hooks.md)

### Pattern 3: getUserPermissionsErrors for File: Page Denial

**What:** Block access to File: description pages for unauthorized users.

**When to use:** ENFC-01 requirement.

**Example:**
```php
public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
    // Only apply to File: namespace
    if ( $title->getNamespace() !== NS_FILE ) {
        return true;
    }

    // Only check 'read' action
    if ( $action !== 'read' ) {
        return true;
    }

    // Check permission via service
    if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
        // Generic error - does not reveal required level
        $result = [ 'filepermissions-access-denied' ];
        return false;
    }

    return true;
}
```

**Source:** [Manual:Hooks/getUserPermissionsErrors](https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors), [Lockdown Extension](https://github.com/wikimedia/mediawiki-extensions-Lockdown/blob/master/src/Hooks.php)

### Pattern 4: ImgAuthBeforeStream for Raw File/Thumbnail Denial

**What:** Block access to raw files and thumbnails via img_auth.php.

**When to use:** ENFC-02, ENFC-03 requirements.

**Example:**
```php
public function onImgAuthBeforeStream( &$title, &$path, &$name, &$result ) {
    // Title is already resolved from path by MediaWiki
    // For thumbnails, $title points to the parent file

    $user = RequestContext::getMain()->getUser();

    if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
        // Return 403 with MediaWiki standard messages
        // $result[0] = header message index
        // $result[1] = body message index (used if $wgImgAuthDetails = true)
        $result = [
            'img-auth-accessdenied',    // Header: "Access Denied"
            'filepermissions-img-denied' // Body (optional detail)
        ];
        return false;
    }

    return true;
}
```

**Source:** [Manual:Hooks/ImgAuthBeforeStream](https://www.mediawiki.org/wiki/Manual:Hooks/ImgAuthBeforeStream), [img_auth.php source](https://github.com/matthiasmullie/mediawiki-core/blob/master/img_auth.php)

### Pattern 5: ImageBeforeProduceHTML for Embedded Image Placeholder

**What:** Replace embedded images with a placeholder SVG for unauthorized users.

**When to use:** ENFC-04 requirement.

**Example:**
```php
public function onImageBeforeProduceHTML(
    $unused,
    &$title,
    &$file,
    &$frameParams,
    &$handlerParams,
    &$time,
    &$res,
    $parser,
    &$query,
    &$widthOption
) {
    $user = RequestContext::getMain()->getUser();

    if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
        // Generate placeholder HTML
        $width = $handlerParams['width'] ?? 220;
        $height = $handlerParams['height'] ?? $width;

        // Inline SVG placeholder - icon only, no text
        $res = $this->generatePlaceholderHtml( $width, $height );

        // Return false to skip default rendering
        return false;
    }

    return true;
}

private function generatePlaceholderHtml( int $width, int $height ): string {
    // Lock icon SVG - minimal, grayscale
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#999">'
        . '<path d="M12 17a2 2 0 002-2v-2a2 2 0 00-4 0v2a2 2 0 002 2zm6-7V8a6 6 0 10-12 0v2a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2v-6a2 2 0 00-2-2z"/>'
        . '</svg>';

    // URL-encode the SVG for data URI
    $dataUri = 'data:image/svg+xml,' . rawurlencode( $svg );

    // Non-clickable placeholder - no link wrapper
    return '<span class="fileperm-placeholder" style="display:inline-block;width:'
        . $width . 'px;height:' . $height . 'px;background:url(\''
        . $dataUri . '\') center/50% no-repeat #f5f5f5;"></span>';
}
```

**Source:** [ImageBeforeProduceHTMLHook Interface](https://github.com/wikimedia/mediawiki/blob/master/includes/Hook/ImageBeforeProduceHTMLHook.php)

### Pattern 6: Disable Parser Cache for File: Namespace

**What:** Ensure permission changes take effect immediately without waiting for cache expiration.

**When to use:** Required for immediate permission enforcement on File: pages.

**Example:**
```php
// LocalSettings.php or extension setup
$wgParserCacheFilterConfig = [
    NS_FILE => [
        'minCpuTime' => PHP_INT_MAX,  // Effectively disables caching
    ],
];
```

**Alternative in extension.json (if supported):**
```json
{
    "config": {
        "ParserCacheFilterConfig": {
            "value": {
                "6": { "minCpuTime": 2147483647 }
            },
            "merge_strategy": "array_merge_recursive"
        }
    }
}
```

**Source:** [Manual:$wgParserCacheFilterConfig](https://www.mediawiki.org/wiki/Manual:$wgParserCacheFilterConfig)

### Anti-Patterns to Avoid

- **Checking user directly in hook without RequestContext:** Use `RequestContext::getMain()->getUser()` to get the current user in hooks that don't receive it as a parameter (like ImgAuthBeforeStream).

- **Revealing permission level in error messages:** Per user decision, error messages must be generic and not reveal what level is required.

- **Making placeholder clickable:** Per user decision, placeholder should NOT link to the File: page to reduce discoverability.

- **Using deprecated User::getGroups():** Use UserGroupManager via the PermissionService instead.

- **Checking thumbnail permissions separately:** Thumbnails inherit parent file permissions; the Title passed to ImgAuthBeforeStream is already resolved to the parent file.

## Don't Hand-Roll

Problems that look simple but have existing solutions:

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Title from img_auth path | Custom path parsing | Hook's $title parameter | MediaWiki already resolves path to Title |
| Error message localization | Hardcoded strings | wfMessage() + i18n/en.json | Standard i18n, translatable |
| User context in hooks | Global $wgUser | RequestContext::getMain()->getUser() | $wgUser deprecated; RequestContext is proper |
| Permission check logic | Inline checks in each hook | PermissionService::canUserAccessFile() | Centralized, testable, consistent |
| Placeholder image | External .png file | Inline SVG data URI | No extra HTTP request, dynamic sizing |

**Key insight:** MediaWiki's hook system already does the heavy lifting of resolving paths to Title objects. The ImgAuthBeforeStream hook receives a Title that's already resolved from thumbnail/archive paths to the parent file.

## Common Pitfalls

### Pitfall 1: Archived File Version Leakage

**What goes wrong:** Old file versions in /archive/ directory accessible even when current version is protected.

**Why it happens:** Some implementations check only current file, not archived versions.

**How to avoid:** The ImgAuthBeforeStream hook receives the resolved Title for both current and archived versions. The permission check applies to the file as a whole, not per-version.

**Warning signs:** Users can access /img_auth.php/archive/... URLs for protected files.

**Source:** [Manual_talk:Image_authorization](https://www.mediawiki.org/wiki/Manual_talk:Image_authorization)

### Pitfall 2: Thumbnail Path Resolution

**What goes wrong:** Permission check fails because thumbnail path isn't recognized.

**Why it happens:** Thumbnail URLs have a different structure (/thumb/...) than original files.

**How to avoid:** MediaWiki's img_auth.php already resolves thumbnail paths to the parent file's Title before calling ImgAuthBeforeStream. Trust the $title parameter.

**Warning signs:** 404 errors or permission bypass on thumbnail URLs.

### Pitfall 3: Parser Cache Serving Stale Permissions

**What goes wrong:** User loses access but embedded images still render because page is cached.

**Why it happens:** Parser cache stores rendered HTML including embedded images.

**How to avoid:** Disable parser cache for NS_FILE using $wgParserCacheFilterConfig with PHP_INT_MAX.

**Warning signs:** Permission changes don't take effect until cache expires or is purged.

**Source:** [Manual:$wgParserCacheFilterConfig](https://www.mediawiki.org/wiki/Manual:$wgParserCacheFilterConfig)

### Pitfall 4: getUserPermissionsErrors Action Scope

**What goes wrong:** Hook blocks all actions (edit, upload) not just read.

**Why it happens:** Forgetting to check the $action parameter.

**How to avoid:** Always check `if ( $action !== 'read' ) { return true; }` early in the hook.

**Warning signs:** Users can't edit File: pages they should have access to.

### Pitfall 5: Error Message Information Leakage

**What goes wrong:** Error message reveals permission structure (e.g., "You need 'confidential' access").

**Why it happens:** Including permission level in error message parameters.

**How to avoid:** Use a single generic message like 'filepermissions-access-denied' without parameters revealing level.

**Warning signs:** Error pages show what level is needed.

### Pitfall 6: img_auth.php Not Configured

**What goes wrong:** ImgAuthBeforeStream hook never fires; files served directly by web server.

**Why it happens:** $wgUploadPath not pointing to img_auth.php, or images directory is web-accessible.

**How to avoid:** This is a deployment configuration issue, not extension code. Document requirement that img_auth.php must be configured.

**Warning signs:** Files accessible via direct URL to /images/ directory.

**Source:** [Manual:Image_authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization)

## Code Examples

Verified patterns from official sources:

### Complete EnforcementHooks Class Structure

```php
<?php
// Source: MediaWiki hook interface patterns
namespace FilePermissions\Hooks;

use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Hook\ImgAuthBeforeStreamHook;
use MediaWiki\Hook\ImageBeforeProduceHTMLHook;
use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;
use FilePermissions\PermissionService;

class EnforcementHooks implements
    GetUserPermissionsErrorsHook,
    ImgAuthBeforeStreamHook,
    ImageBeforeProduceHTMLHook
{
    private PermissionService $permissionService;

    public function __construct( PermissionService $permissionService ) {
        $this->permissionService = $permissionService;
    }

    /**
     * Block File: page access for unauthorized users.
     * ENFC-01: getUserPermissionsErrors hook denies File: page access
     */
    public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
        if ( $title->getNamespace() !== NS_FILE ) {
            return true;
        }

        if ( $action !== 'read' ) {
            return true;
        }

        if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
            $result = [ 'filepermissions-access-denied' ];
            return false;
        }

        return true;
    }

    /**
     * Block raw file and thumbnail access via img_auth.php.
     * ENFC-02: ImgAuthBeforeStream hook denies raw file downloads
     * ENFC-03: Thumbnail access denied via ImgAuthBeforeStream
     */
    public function onImgAuthBeforeStream( &$title, &$path, &$name, &$result ) {
        $user = RequestContext::getMain()->getUser();

        if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
            $result = [
                'img-auth-accessdenied',
                'filepermissions-img-denied'
            ];
            return false;
        }

        return true;
    }

    /**
     * Replace embedded images with placeholder for unauthorized users.
     * ENFC-04: Embedded images fail to render for unauthorized users
     */
    public function onImageBeforeProduceHTML(
        $unused,
        &$title,
        &$file,
        &$frameParams,
        &$handlerParams,
        &$time,
        &$res,
        $parser,
        &$query,
        &$widthOption
    ) {
        $user = RequestContext::getMain()->getUser();

        if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
            $width = $handlerParams['width'] ?? 220;
            $height = $handlerParams['height'] ?? $width;
            $res = $this->generatePlaceholderHtml( $width, $height );
            return false;
        }

        return true;
    }

    /**
     * Generate placeholder HTML with lock icon SVG.
     */
    private function generatePlaceholderHtml( int $width, int $height ): string {
        // Simple lock icon - minimal grayscale
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23999">'
            . '<path d="M12 17a2 2 0 002-2v-2a2 2 0 00-4 0v2a2 2 0 002 2zm6-7V8a6 6 0 10-12 0v2'
            . 'a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2v-6a2 2 0 00-2-2z"/></svg>';

        return '<span class="fileperm-placeholder" style="display:inline-block;'
            . 'width:' . htmlspecialchars( (string)$width ) . 'px;'
            . 'height:' . htmlspecialchars( (string)$height ) . 'px;'
            . 'background:url(\'data:image/svg+xml,' . $svg . '\') center/50% no-repeat #f5f5f5;'
            . 'border:1px solid #ddd;"></span>';
    }
}
```

### i18n Messages (i18n/en.json)

```json
{
    "@metadata": {
        "authors": ["FilePermissions Contributors"]
    },
    "filepermissions-desc": "Group-based file permission system",
    "filepermissions-access-denied": "You do not have permission to view this file.",
    "filepermissions-img-denied": "You do not have permission to access this file."
}
```

### extension.json Hook Registration

```json
{
    "HookHandlers": {
        "enforcement": {
            "class": "FilePermissions\\Hooks\\EnforcementHooks",
            "services": [
                "FilePermissions.PermissionService"
            ]
        }
    },
    "Hooks": {
        "getUserPermissionsErrors": "enforcement",
        "ImgAuthBeforeStream": "enforcement",
        "ImageBeforeProduceHTML": "enforcement"
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| $wgHooks array | extension.json Hooks + HookHandlers | MW 1.35 | Better DI, services injected |
| Separate hook files | Single handler implementing interfaces | MW 1.35 | Cleaner architecture |
| wfRunHooks() | HookRunner | MW 1.35 | Deprecated function |
| $wgUser global | RequestContext::getMain()->getUser() | MW 1.27+ | Global deprecated |
| Parser::disableCache() | $wgParserCacheFilterConfig | MW 1.42 | Per-namespace control |

**Deprecated/outdated:**
- `$wgHooks` array registration - use extension.json HookHandlers
- `wfRunHooks()` - use HookRunner
- `$wgUser` - use RequestContext
- `Parser::disableCache()` - removed in MW 1.35

## Open Questions

Things that couldn't be fully resolved:

1. **Parser cache config via extension.json**
   - What we know: $wgParserCacheFilterConfig works in LocalSettings.php
   - What's unclear: Whether extension.json can set this with proper merge strategy
   - Recommendation: Document as deployment requirement; optionally set via registration callback

2. **Exact ImageBeforeProduceHTML parameter availability**
   - What we know: Hook provides $handlerParams with width/height
   - What's unclear: Whether width/height always present or need fallback
   - Recommendation: Use fallback defaults (220px) when not specified

3. **Placeholder sizing for non-specified dimensions**
   - What we know: User wants placeholder to match requested dimensions
   - What's unclear: What to do when no dimensions specified in wikitext
   - Recommendation: Use MediaWiki's default thumbnail size (typically 220px)

## Sources

### Primary (HIGH confidence)

- [MediaWiki Hooks.md](https://github.com/wikimedia/mediawiki/blob/master/docs/Hooks.md) - Modern hook handler pattern
- [Manual:Hooks/getUserPermissionsErrors](https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors) - Permission errors hook
- [Manual:Hooks/ImgAuthBeforeStream](https://www.mediawiki.org/wiki/Manual:Hooks/ImgAuthBeforeStream) - img_auth hook
- [img_auth.php source](https://github.com/matthiasmullie/mediawiki-core/blob/master/img_auth.php) - wfForbidden pattern
- [Manual:Image_authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization) - img_auth.php configuration
- [GetUserPermissionsErrorsHook interface](https://github.com/wikimedia/mediawiki/blob/master/includes/Permissions/Hook/GetUserPermissionsErrorsHook.php) - Hook signature
- [ImageBeforeProduceHTMLHook interface](https://github.com/wikimedia/mediawiki/blob/master/includes/Hook/ImageBeforeProduceHTMLHook.php) - Hook signature
- [Lockdown Extension](https://github.com/wikimedia/mediawiki-extensions-Lockdown/blob/master/src/Hooks.php) - Reference implementation

### Secondary (MEDIUM confidence)

- [Manual:$wgParserCacheFilterConfig](https://www.mediawiki.org/wiki/Manual:$wgParserCacheFilterConfig) - Parser cache configuration
- [SVG Data URIs](https://css-tricks.com/lodge/svg/09-svg-data-uris/) - Inline SVG encoding

### Tertiary (LOW confidence)

- [Manual_talk:Image_authorization](https://www.mediawiki.org/wiki/Manual_talk:Image_authorization) - Archive file leakage discussion (community report, not official)

## Metadata

**Confidence breakdown:**
- Hook interfaces: HIGH - Verified via GitHub source code
- Hook registration pattern: HIGH - Official documentation
- Placeholder implementation: MEDIUM - Pattern based on standard practices, not MW-specific
- Parser cache config: MEDIUM - Documented but extension.json integration unclear

**Research date:** 2026-01-28
**Valid until:** 2026-02-28 (stable domain, 30 days)
