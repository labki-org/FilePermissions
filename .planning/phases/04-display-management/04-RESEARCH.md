# Phase 4: Display & Management - Research

**Researched:** 2026-01-28
**Domain:** MediaWiki File page hooks, custom API modules, OOUI widgets, ResourceLoader, audit logging
**Confidence:** HIGH

## Summary

This phase adds two capabilities to File: description pages: (1) a permission level indicator visible to authorized users, and (2) a sysop-only edit interface to change the permission level. The approach uses `ImagePageAfterImageLinks` hook to inject HTML below the image links section on File pages, `BeforePageDisplay` hook to conditionally load a ResourceLoader JavaScript module on NS_FILE pages, and a custom API module extending `ApiBase` to handle AJAX permission saves with CSRF protection.

The existing codebase already provides `PermissionService::setLevel()` for PageProps writes and `PermissionService::getLevel()` for reads. The display hook reads the current level and renders an indicator. The edit interface renders an OOUI DropdownInputWidget (populated from `Config::getLevels()`) that saves via the custom API endpoint using `mw.Api().postWithToken('csrf', ...)`. Permission changes are logged to `Special:Log` using `ManualLogEntry` for admin transparency -- this is a discretion item and the research recommends including it because it aligns with MW admin patterns (protection logs, rights logs, etc.).

**Primary recommendation:** Use `ImagePageAfterImageLinks` for display injection, a custom `ApiBase` module for AJAX saves, and `ManualLogEntry` for audit logging. The edit control should be an inline dropdown with a save button, rendered server-side via OOUI PHP widgets and activated via a ResourceLoader JavaScript module.

## Standard Stack

### Core (MediaWiki built-in -- no additional dependencies)

| Component | Type | Purpose | Why Standard |
|-----------|------|---------|--------------|
| `ImagePageAfterImageLinksHook` | Hook Interface | Inject permission indicator + edit control after image links section | Official MW hook for adding content to File pages (since MW 1.16) |
| `BeforePageDisplayHook` | Hook Interface | Conditionally load JS module on NS_FILE pages | Official MW hook for adding modules to OutputPage |
| `ApiBase` | API Base Class | Custom API endpoint for saving permission changes | Official MW API module pattern for write operations |
| OOUI `DropdownInputWidget` | PHP Widget | Server-side rendered dropdown for level selection | MW's standard UI toolkit (OOUI namespace) |
| OOUI `ButtonInputWidget` | PHP Widget | Save button for permission changes | MW's standard UI toolkit |
| ResourceLoader module | JS Delivery | Client-side save logic via `mw.Api` | MW's standard script/style delivery system |
| `mw.Api().postWithToken('csrf', ...)` | JS API Client | CSRF-protected AJAX call to save permission | MW's standard API client with automatic token handling |
| `ManualLogEntry` | Logging | Audit log for permission changes in Special:Log | MW's standard logging system used by protection, rights, etc. |
| `PermissionService` (existing) | Service | Read/write PageProps | Already built in Phase 1 |
| `Config` (existing) | Static Class | Read configured levels and groups | Already built in Phase 1 |
| `OutputPage::addJsConfigVars()` | Data Passing | Pass current level + levels list + sysop flag to JS | MW's standard PHP-to-JS data transfer |
| `OutputPage::enableOOUI()` | OOUI Setup | Enable OOUI theme/styles for server-rendered widgets | Required before using OOUI PHP widgets |

### Supporting

| Component | Purpose | When to Use |
|-----------|---------|-------------|
| i18n messages | Localized labels, log messages, indicator text | All user-facing strings |
| `$out->addModules()` | Load JS module for edit functionality | On File pages when user is sysop |
| `$out->addModuleStyles()` | Load CSS for indicator styling | On all File pages |
| `AvailableRights` / `GroupPermissions` | Register custom right for editing file permissions | In extension.json to gate edit access |
| `LogTypes` / `LogNames` / `LogHeaders` / `LogActionsHandlers` | Register custom log type | In extension.json for audit trail |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `ImagePageAfterImageLinks` | `BeforePageDisplay` + JS DOM injection | More fragile, depends on skin HTML structure; hook provides clean injection point |
| Custom `ApiBase` module | Full HTMLForm POST to Special page | Worse UX (full page reload); API + AJAX is the modern MW pattern for inline editing |
| OOUI PHP widgets | Raw HTML `<select>` + `<button>` | Works but looks inconsistent with MW UI; OOUI matches the design system |
| Inline dropdown + save | Action tab linking to edit form | More clicks, worse UX; inline is simpler for a single field |
| `ManualLogEntry` audit log | No logging | Loses admin transparency; protection/rights changes are logged in MW by convention |

**Installation:** No additional packages needed. All components are MediaWiki core.

## Architecture Patterns

### Recommended File Structure

```
FilePermissions/
  includes/
    Api/
      ApiFilePermSetLevel.php     # (NEW) Custom API module for saving permission level
    Hooks/
      DisplayHooks.php            # (NEW) ImagePageAfterImageLinks + BeforePageDisplay
      EnforcementHooks.php        # (existing) Phase 2 enforcement
      RegistrationHooks.php       # (existing) Phase 1 registration
      UploadHooks.php             # (existing) Phase 3 upload form
    Config.php                    # (existing)
    PermissionService.php         # (existing)
    ServiceWiring.php             # (existing)
  modules/
    ext.FilePermissions.edit.js   # (NEW) Client-side save logic
    ext.FilePermissions.edit.css  # (NEW) Indicator + edit control styles
  i18n/
    en.json                       # (UPDATE) Add display/edit/log messages
  extension.json                  # (UPDATE) Register hooks, API module, RL module, log type, rights
```

### Pattern 1: ImagePageAfterImageLinks for Display Injection

**What:** Use the `ImagePageAfterImageLinks` hook to append permission level indicator HTML (and optionally the edit control HTML for sysops) after the image links section on File pages.

**When to use:** Every File page view where the file has a permission level.

**Why this hook:** It fires during the normal ImagePage rendering flow, after the image links section. The rendering order of a File: description page is:
1. TOC (`showTOC`)
2. Image display (`openShowImage`)
3. Wikitext content (`parent::view()`)
4. Shared description
5. File history (`imageHistory`)
6. Image links (`imageLinks`)
7. **ImagePageAfterImageLinks** hook -- our injection point
8. Metadata table

This placement puts the permission info below the "pages that use this file" list, which is a natural location for file metadata/properties.

**Example:**
```php
// Source: MW Manual:Hooks/ImagePageAfterImageLinks + existing codebase patterns
public function onImagePageAfterImageLinks( $imagePage, &$html ) {
    $title = $imagePage->getTitle();
    $level = $this->permissionService->getLevel( $title );
    if ( $level === null ) {
        return;
    }

    // Display indicator for all authorized users
    $html .= Html::rawElement( 'div', [
        'class' => 'fileperm-indicator',
        'id' => 'fileperm-level-display',
    ], Html::element( 'strong', [],
        wfMessage( 'filepermissions-level-label' )->text()
    ) . ' ' . Html::element( 'span', [
        'class' => 'fileperm-level-badge fileperm-level-' . htmlspecialchars( $level ),
    ], $level ) );
}
```

### Pattern 2: BeforePageDisplay for Conditional Module Loading

**What:** Use `BeforePageDisplay` hook to load ResourceLoader modules on NS_FILE pages and pass context data via `addJsConfigVars`.

**When to use:** On every NS_FILE page view.

**Example:**
```php
// Source: MW Manual:Hooks/BeforePageDisplay + extension examples
public function onBeforePageDisplay( $out, $skin ): void {
    $title = $out->getTitle();
    if ( !$title || $title->getNamespace() !== NS_FILE ) {
        return;
    }

    // Always load indicator styles
    $out->addModuleStyles( [ 'ext.FilePermissions.indicator' ] );

    // Load edit module only for users with edit-fileperm right
    $user = $out->getUser();
    if ( $user->isAllowed( 'edit-fileperm' ) ) {
        $out->enableOOUI();
        $out->addModules( [ 'ext.FilePermissions.edit' ] );
        $out->addJsConfigVars( [
            'wgFilePermCurrentLevel' => $this->getCurrentLevel( $title ),
            'wgFilePermLevels' => Config::getLevels(),
            'wgFilePermPageTitle' => $title->getPrefixedDBkey(),
        ] );
    }
}
```

### Pattern 3: Custom ApiBase Module with CSRF

**What:** A custom API action module registered as `action=fileperm-set-level` that accepts a page title and new permission level, validates, stores via PermissionService, and logs the change.

**When to use:** Called from JavaScript when sysop saves a permission change.

**Example:**
```php
// Source: MW API:Extensions docs, ApiBase, needsToken/mustBePosted/isWriteMode pattern
namespace FilePermissions\Api;

use ApiBase;
use FilePermissions\Config;
use FilePermissions\PermissionService;
use ManualLogEntry;
use MediaWiki\Title\Title;

class ApiFilePermSetLevel extends ApiBase {
    private PermissionService $permissionService;

    // Constructor receives ApiMain + module name + services

    public function execute() {
        $params = $this->extractRequestParams();
        $title = Title::newFromText( $params['title'], NS_FILE );

        // Validate title exists, level is valid
        // Call $this->permissionService->setLevel( $title, $params['level'] )
        // Create ManualLogEntry for audit
        // Return success result
    }

    public function needsToken() {
        return 'csrf';
    }

    public function mustBePosted() {
        return true;
    }

    public function isWriteMode() {
        return true;
    }

    public function getAllowedParams() {
        return [
            'title' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => true,
            ],
            'level' => [
                ApiBase::PARAM_TYPE => Config::getLevels(),
                ApiBase::PARAM_REQUIRED => true,
            ],
        ];
    }
}
```

### Pattern 4: ResourceLoader Module with mw.Api

**What:** A JavaScript module that handles the save interaction -- reads the dropdown value, calls the API with CSRF token, shows success/error feedback.

**When to use:** Loaded on File pages for sysop users only.

**Example registration in extension.json:**
```json
{
    "ResourceModules": {
        "ext.FilePermissions.edit": {
            "localBasePath": "modules",
            "remoteExtPath": "FilePermissions/modules",
            "packageFiles": [
                "ext.FilePermissions.edit.js"
            ],
            "styles": [
                "ext.FilePermissions.edit.css"
            ],
            "dependencies": [
                "mediawiki.api",
                "mediawiki.notify",
                "oojs-ui-core"
            ],
            "messages": [
                "filepermissions-edit-success",
                "filepermissions-edit-error"
            ]
        }
    }
}
```

**Example JavaScript:**
```javascript
// Source: mw.Api docs, postWithToken pattern
( function () {
    'use strict';

    var currentLevel = mw.config.get( 'wgFilePermCurrentLevel' );
    var pageTitle = mw.config.get( 'wgFilePermPageTitle' );

    // Find the save button and dropdown rendered by PHP
    var $dropdown = $( '#fileperm-edit-dropdown select' );
    var $saveBtn = $( '#fileperm-edit-save' );

    $saveBtn.on( 'click', function () {
        var newLevel = $dropdown.val();
        if ( newLevel === currentLevel ) {
            return;
        }

        var api = new mw.Api();
        api.postWithToken( 'csrf', {
            action: 'fileperm-set-level',
            title: pageTitle,
            level: newLevel
        } ).then( function () {
            mw.notify( mw.msg( 'filepermissions-edit-success' ), { type: 'success' } );
            // Update displayed badge
            $( '.fileperm-level-badge' ).text( newLevel )
                .attr( 'class', 'fileperm-level-badge fileperm-level-' + newLevel );
            currentLevel = newLevel;
        }, function ( code, data ) {
            mw.notify( mw.msg( 'filepermissions-edit-error' ), { type: 'error' } );
        } );
    } );
}() );
```

### Pattern 5: ManualLogEntry for Audit Trail

**What:** Log permission changes to Special:Log so admins can track who changed what and when.

**When to use:** Every time a permission level is changed via the API.

**Example:**
```php
// Source: MW Manual:Logging to Special:Log, ManualLogEntry docs
$logEntry = new ManualLogEntry( 'fileperm', 'change' );
$logEntry->setPerformer( $this->getUser() );
$logEntry->setTarget( $title );
$logEntry->setParameters( [
    '4::oldlevel' => $oldLevel ?? '(none)',
    '5::newlevel' => $newLevel,
] );
$logid = $logEntry->insert();
$logEntry->publish( $logid );
```

### Pattern 6: Custom User Right for Edit Access

**What:** Register a custom `edit-fileperm` right and assign it to sysop group, so the edit control is only shown to authorized users.

**Why not just check group membership:** Using a user right is more flexible -- site admins can grant it to other groups via LocalSettings.php without code changes.

**Example extension.json:**
```json
{
    "AvailableRights": [ "edit-fileperm" ],
    "GroupPermissions": {
        "sysop": {
            "edit-fileperm": true
        }
    }
}
```

**Check in PHP:**
```php
$user->isAllowed( 'edit-fileperm' )
```

### Anti-Patterns to Avoid

- **Injecting raw HTML via `BeforePageDisplay` + DOM manipulation:** Fragile, depends on skin structure. Use `ImagePageAfterImageLinks` which provides a clean `&$html` string to append to.
- **Full page reload for saving:** Use AJAX via `mw.Api` for inline save. Full-page POST to a SpecialPage is unnecessary overhead for changing a single value.
- **Checking group membership directly:** Use `$user->isAllowed('edit-fileperm')` instead of `in_array('sysop', $groups)`. Rights-based checks are the MW convention and allow admins to reassign rights flexibly.
- **Skipping CSRF token:** Any write API must use `needsToken() => 'csrf'` and `mustBePosted() => true`. The `mw.Api().postWithToken()` client handles token fetching and retry automatically.
- **Using OOUI in JS when PHP suffices:** Render the dropdown and button server-side using OOUI PHP classes. The JS module only handles the click-to-save interaction. This avoids JS widget construction latency.
- **Inline JavaScript in hook output:** Always use ResourceLoader modules. Never output `<script>` tags in hook HTML.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| API endpoint | Custom PHP script or raw POST handler | `ApiBase` subclass registered via `APIModules` | CSRF protection, parameter validation, error handling, rate limiting all built-in |
| AJAX with tokens | Manual token fetch + `$.ajax` | `mw.Api().postWithToken('csrf', ...)` | Automatic token caching, retry on expiry, error normalization |
| Dropdown UI | Raw `<select>` HTML | OOUI `DropdownInputWidget` via PHP | Consistent MW styling, accessibility, RTL support |
| Audit logging | Custom database table | `ManualLogEntry` + `Special:Log` | Integrates with RC feed, log filtering, admin tools, i18n |
| User right check | `in_array('sysop', $groups)` | `$user->isAllowed('edit-fileperm')` | Flexible right assignment, proper permission model |
| Conditional module loading | Inline `<script>` with `if` checks | `BeforePageDisplay` + `$out->addModules()` | ResourceLoader bundles, caching, dependency management |
| Pass data to JS | Global JS variables or `<script>` data | `$out->addJsConfigVars()` + `mw.config.get()` | Clean, cached, respects MW page output pipeline |

**Key insight:** MediaWiki provides a complete pipeline for inline editing: OOUI for rendering, `ApiBase` for the backend, `mw.Api` for the client, `ManualLogEntry` for auditing, and `BeforePageDisplay` for conditional loading. Using this pipeline produces code that is consistent, secure, and maintainable.

## Common Pitfalls

### Pitfall 1: OOUI Not Enabled Before Widget Rendering

**What goes wrong:** OOUI PHP widgets render broken HTML -- missing styles, incorrect theme.
**Why it happens:** OOUI PHP widgets require `$out->enableOOUI()` to set up the correct theme and directionality before any widget HTML is generated.
**How to avoid:** Call `$out->enableOOUI()` in the `BeforePageDisplay` hook before any OOUI HTML is added. In `ImagePageAfterImageLinks`, you may need to access OutputPage via `$imagePage->getContext()->getOutput()->enableOOUI()`.
**Warning signs:** Dropdown renders as unstyled `<select>`, buttons look plain or broken.

### Pitfall 2: API Module Not Registered in extension.json

**What goes wrong:** JavaScript calls `action=fileperm-set-level` but gets "unrecognized value for parameter action" error.
**Why it happens:** The `APIModules` key in `extension.json` is missing or has the wrong class path.
**How to avoid:** Register in `extension.json`:
```json
"APIModules": {
    "fileperm-set-level": {
        "class": "FilePermissions\\Api\\ApiFilePermSetLevel",
        "services": [ "FilePermissions.PermissionService" ]
    }
}
```
**Warning signs:** JS save button always fails with API error. Network tab shows 200 response but error JSON.

### Pitfall 3: Missing needsToken/mustBePosted on Write API

**What goes wrong:** API calls succeed without token, creating a CSRF vulnerability. Or worse: API rejects all requests with "This module requires a token" but the client doesn't send one.
**Why it happens:** `needsToken()` must return `'csrf'` (not `true` -- MW changed this). `mustBePosted()` must also return `true` when tokens are used.
**How to avoid:** Implement all three methods: `needsToken() => 'csrf'`, `mustBePosted() => true`, `isWriteMode() => true`. Use `mw.Api().postWithToken('csrf', ...)` on the client side.
**Warning signs:** "Token required" errors in JS console, or saves work from browser but fail from automated scripts (indicating missing CSRF).

### Pitfall 4: addJsConfigVars Caching Conflicts

**What goes wrong:** Permission level shows stale data after a save on the same page.
**Why it happens:** `addJsConfigVars` data is embedded in the page HTML at render time. After an AJAX save, the config vars still contain the old level.
**How to avoid:** After a successful save, update the JavaScript variable directly (`currentLevel = newLevel`) rather than re-reading from `mw.config`. The JS module must maintain its own state after the initial load.
**Warning signs:** Save succeeds but badge shows old level until page refresh.

### Pitfall 5: ImagePageAfterImageLinks Not Firing for Non-Existent Files

**What goes wrong:** The permission indicator doesn't appear for files that exist in PageProps but not in the file system (deleted files, broken uploads).
**Why it happens:** `ImagePageAfterImageLinks` only fires when `ImagePage::imageLinks()` runs, which requires the page to exist. If the file page doesn't exist, this section is skipped entirely.
**How to avoid:** This is acceptable behavior -- if the file page doesn't exist, there's no permission to display. Verify that the hook fires for valid file pages with or without an actual uploaded file (the File: page can exist as wikitext even without an uploaded file).
**Warning signs:** Indicator missing on some File pages.

### Pitfall 6: Log Parameter Numbering

**What goes wrong:** Log entry displays raw parameter names instead of formatted values.
**Why it happens:** `ManualLogEntry::setParameters()` uses a special numbering convention. Parameters `$1`, `$2`, `$3` are reserved (username, userpage, target). Custom parameters must start at `$4`. Keys must be formatted as `'4::paramname'`.
**How to avoid:** Use the `'4::oldlevel'` and `'5::newlevel'` key format. Define a corresponding log message in i18n: `"logentry-fileperm-change": "$1 {{GENDER:$2|changed}} the permission level of $3 from $4 to $5"`.
**Warning signs:** Log entries in Special:Log show `{paramname}` instead of actual values.

### Pitfall 7: OOUI Maintenance Mode

**What goes wrong:** Choosing between OOUI and Codex for new UI elements.
**Why it happens:** OOUI has been placed in maintenance mode. The Codex design system has replaced OOUI as the default UI library for new development. However, OOUI is not being removed and is still widely used.
**How to avoid:** Use OOUI for this extension. The extension targets MW 1.44 where OOUI is fully supported. Codex requires Vue.js and a different architecture that would be overkill for a single dropdown. OOUI PHP server-side rendering is the pragmatic choice.
**Warning signs:** None for OOUI usage. Only relevant if planning for MW 2.x+ migration (far future).

## Code Examples

### Complete ImagePageAfterImageLinks Handler

```php
// Source: MW Manual:Hooks/ImagePageAfterImageLinks, OOUI PHP docs
use MediaWiki\Hook\ImagePageAfterImageLinksHook;

public function onImagePageAfterImageLinks( $imagePage, &$html ) {
    $title = $imagePage->getTitle();
    $level = $this->permissionService->getLevel( $title );

    if ( $level === null ) {
        return;
    }

    $out = $imagePage->getContext()->getOutput();
    $user = $imagePage->getContext()->getUser();

    // Always show the indicator
    $indicatorHtml = Html::rawElement( 'div', [
        'class' => 'fileperm-section',
        'id' => 'fileperm-section',
    ], $this->buildIndicatorHtml( $level )
        . ( $user->isAllowed( 'edit-fileperm' ) ? $this->buildEditHtml( $level, $out ) : '' )
    );

    $html .= $indicatorHtml;
}

private function buildIndicatorHtml( string $level ): string {
    return Html::rawElement( 'div', [
        'class' => 'fileperm-indicator',
    ], Html::element( 'strong', [],
        wfMessage( 'filepermissions-level-label' )->text()
    ) . ' ' . Html::element( 'span', [
        'class' => 'fileperm-level-badge',
        'id' => 'fileperm-level-badge',
    ], $level ) );
}

private function buildEditHtml( string $currentLevel, OutputPage $out ): string {
    $out->enableOOUI();

    $options = [];
    foreach ( Config::getLevels() as $lvl ) {
        $options[] = [
            'data' => $lvl,
            'label' => $lvl,
        ];
    }

    $dropdown = new \OOUI\DropdownInputWidget( [
        'name' => 'fileperm-level',
        'options' => $options,
        'value' => $currentLevel,
        'id' => 'fileperm-edit-dropdown',
    ] );

    $button = new \OOUI\ButtonInputWidget( [
        'label' => wfMessage( 'filepermissions-edit-save' )->text(),
        'flags' => [ 'primary', 'progressive' ],
        'id' => 'fileperm-edit-save',
        'type' => 'button',
    ] );

    return Html::rawElement( 'div', [
        'class' => 'fileperm-edit-controls',
        'id' => 'fileperm-edit-controls',
    ], $dropdown . ' ' . $button );
}
```

### Complete ApiBase Module

```php
// Source: MW API:Extensions docs, ApiBase pattern
namespace FilePermissions\Api;

use ApiBase;
use FilePermissions\Config;
use FilePermissions\PermissionService;
use ManualLogEntry;
use MediaWiki\Title\Title;

class ApiFilePermSetLevel extends ApiBase {
    private PermissionService $permissionService;

    public function __construct( $mainModule, $moduleName, PermissionService $permissionService ) {
        parent::__construct( $mainModule, $moduleName );
        $this->permissionService = $permissionService;
    }

    public function execute() {
        $this->checkUserRightsAny( 'edit-fileperm' );

        $params = $this->extractRequestParams();
        $title = Title::newFromText( $params['title'], NS_FILE );

        if ( !$title || !$title->exists() ) {
            $this->dieWithError( 'filepermissions-api-nosuchpage' );
        }

        $newLevel = $params['level'];
        $oldLevel = $this->permissionService->getLevel( $title );

        $this->permissionService->setLevel( $title, $newLevel );

        // Audit log
        $logEntry = new ManualLogEntry( 'fileperm', 'change' );
        $logEntry->setPerformer( $this->getUser() );
        $logEntry->setTarget( $title );
        $logEntry->setParameters( [
            '4::oldlevel' => $oldLevel ?? '(none)',
            '5::newlevel' => $newLevel,
        ] );
        $logid = $logEntry->insert();
        $logEntry->publish( $logid );

        $this->getResult()->addValue( null, $this->getModuleName(), [
            'result' => 'success',
            'level' => $newLevel,
        ] );
    }

    public function needsToken() {
        return 'csrf';
    }

    public function mustBePosted() {
        return true;
    }

    public function isWriteMode() {
        return true;
    }

    public function getAllowedParams() {
        return [
            'title' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => true,
            ],
            'level' => [
                ApiBase::PARAM_TYPE => Config::getLevels(),
                ApiBase::PARAM_REQUIRED => true,
            ],
        ];
    }
}
```

### Complete ResourceLoader JavaScript

```javascript
// Source: mw.Api docs, mw.config, mw.notify
( function () {
    'use strict';

    var currentLevel = mw.config.get( 'wgFilePermCurrentLevel' );
    var pageTitle = mw.config.get( 'wgFilePermPageTitle' );
    var $saveBtn = $( '#fileperm-edit-save' );

    if ( !$saveBtn.length ) {
        return;
    }

    $saveBtn.on( 'click', function () {
        // OOUI DropdownInputWidget renders a <select> inside its container
        var newLevel = $( '#fileperm-edit-dropdown select, #fileperm-edit-dropdown input' ).val();

        if ( !newLevel || newLevel === currentLevel ) {
            return;
        }

        $saveBtn.prop( 'disabled', true );

        var api = new mw.Api();
        api.postWithToken( 'csrf', {
            action: 'fileperm-set-level',
            title: pageTitle,
            level: newLevel
        } ).then( function () {
            mw.notify( mw.msg( 'filepermissions-edit-success' ), { type: 'success' } );
            $( '#fileperm-level-badge' ).text( newLevel );
            currentLevel = newLevel;
            $saveBtn.prop( 'disabled', false );
        }, function ( code, data ) {
            mw.notify( mw.msg( 'filepermissions-edit-error' ), { type: 'error' } );
            $saveBtn.prop( 'disabled', false );
        } );
    } );
}() );
```

### extension.json Updates

```json
{
    "HookHandlers": {
        "display": {
            "class": "FilePermissions\\Hooks\\DisplayHooks",
            "services": [ "FilePermissions.PermissionService" ]
        }
    },
    "Hooks": {
        "ImagePageAfterImageLinks": "display",
        "BeforePageDisplay": "display"
    },
    "APIModules": {
        "fileperm-set-level": {
            "class": "FilePermissions\\Api\\ApiFilePermSetLevel",
            "services": [ "FilePermissions.PermissionService" ]
        }
    },
    "AvailableRights": [ "edit-fileperm" ],
    "GroupPermissions": {
        "sysop": {
            "edit-fileperm": true
        }
    },
    "LogTypes": [ "fileperm" ],
    "LogNames": {
        "fileperm": "filepermissions-log-name"
    },
    "LogHeaders": {
        "fileperm": "filepermissions-log-header"
    },
    "LogActionsHandlers": {
        "fileperm/*": "LogFormatter"
    },
    "ResourceModules": {
        "ext.FilePermissions.edit": {
            "localBasePath": "modules",
            "remoteExtPath": "FilePermissions/modules",
            "packageFiles": [
                "ext.FilePermissions.edit.js"
            ],
            "styles": [
                "ext.FilePermissions.edit.css"
            ],
            "dependencies": [
                "mediawiki.api",
                "mediawiki.notify",
                "oojs-ui-core"
            ],
            "messages": [
                "filepermissions-edit-success",
                "filepermissions-edit-error"
            ]
        }
    }
}
```

### i18n Messages

```json
{
    "filepermissions-level-label": "Permission level:",
    "filepermissions-edit-save": "Save",
    "filepermissions-edit-success": "Permission level updated.",
    "filepermissions-edit-error": "Failed to update permission level.",
    "filepermissions-log-name": "File permission log",
    "filepermissions-log-header": "This log tracks changes to file permission levels.",
    "logentry-fileperm-change": "$1 {{GENDER:$2|changed}} the permission level of $3 from $4 to $5",
    "filepermissions-api-nosuchpage": "The specified file page does not exist.",
    "right-edit-fileperm": "Change file permission levels"
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Raw HTML in hooks | OOUI PHP widgets | MW 1.22+ (OOUI intro) | Consistent UI, accessibility, RTL |
| `$wgHooks` array | `HookHandlers` in extension.json | MW 1.35+ | Dependency injection, typed interfaces |
| jQuery `$.ajax` with manual tokens | `mw.Api().postWithToken()` | MW 1.20+ | Automatic CSRF, retry, error handling |
| `scripts` in ResourceModules | `packageFiles` in ResourceModules | MW 1.33+ | Better bundling, `require()` support |
| `$wgLogTypes[]` in PHP | `LogTypes` in extension.json | MW 1.25+ | Declarative registration |
| OOUI as primary UI library | Codex replaces OOUI for new Wikimedia projects | ~2024 | OOUI still fully supported, Codex needs Vue.js -- overkill for this extension |
| `$user->isAllowed()` directly | `Authority` interface | MW 1.36+ | Same method on User, more testable via Authority |

**Deprecated/outdated:**
- OOUI is in maintenance mode (not deprecated, still works, no new features). Use it for MW 1.44 extensions without Vue.js.
- `$wgHooks['HookName']` array registration: Legacy. Use `extension.json` `Hooks` + `HookHandlers`.
- `needsToken()` returning `true`: Must return string `'csrf'` since MW 1.27+.

## Discretion Recommendations

The CONTEXT.md delegated several choices. Based on research, here are the recommendations:

### Edit Control Style: Inline dropdown + save button
**Recommendation:** Render the dropdown and save button directly below the permission indicator, always visible to sysops. No edit-button-to-reveal or modal dialog needed.
**Rationale:** The permission level is a single field. A click-to-reveal pattern adds unnecessary interaction cost. Inline display matches how MediaWiki shows editable properties (e.g., page categories, protection levels). The save button provides a clear action point.

### Confirmation Step: No confirmation dialog
**Recommendation:** Save directly on button click. No confirm dialog.
**Rationale:** MW convention for admin actions (page protection, user rights) does not use confirmation dialogs. The audit log provides accountability. Undo is trivial (select the old level, save again). A confirmation dialog would be atypical for MW admin UX.

### Placement on File Page: After image links section
**Recommendation:** Use `ImagePageAfterImageLinks` to place the indicator and edit controls after the "pages that use this file" section.
**Rationale:** This is the only clean hook point on File pages that allows HTML injection. It appears before the metadata table, which is a natural place for file properties. The section can be wrapped in a heading for TOC integration if desired.

### Audit Logging: Yes, log to Special:Log
**Recommendation:** Log all permission changes using `ManualLogEntry('fileperm', 'change')`. Register a custom `fileperm` log type.
**Rationale:** This aligns with MW admin patterns. Protection changes log to `Special:Log/protect`, rights changes log to `Special:Log/rights`. File permission changes should log to `Special:Log/fileperm`. The cost is minimal (one DB insert per change) and the admin value is high. Note: REQUIREMENTS.md lists audit logging as v2/deferred, but the implementation cost is trivially low (5 lines of code) and it directly supports the admin use case this phase enables.

### Badge/Indicator Design: Text label
**Recommendation:** Display as a bold label followed by the level name in a styled `<span>`. Example: **Permission level:** `confidential`. Use CSS classes for optional per-level coloring.
**Rationale:** Simple text is readable, translatable, and works in all skins. A visual badge can be achieved with CSS classes (`fileperm-level-confidential`) without complex SVG or icon systems. The OOUI icon library is overkill for a text label.

## Open Questions

1. **ImagePageAfterImageLinks hook interface namespace**
   - What we know: The hook is `ImagePageAfterImageLinks`, called in `ImagePage.php`. The typed interface should be `MediaWiki\Page\Hook\ImagePageAfterImageLinksHook` based on the file location convention.
   - What's unclear: The exact namespace may vary. In older MW, it was `MediaWiki\Hook\ImagePageAfterImageLinksHook`. MW 1.44 may have reorganized.
   - Recommendation: LOW confidence on exact import path. During implementation, check the MW 1.44 autoload map. If the typed interface is not available, use the handler name string in extension.json (which doesn't require importing the interface in some registration patterns).

2. **OOUI widget rendering inside hook HTML string**
   - What we know: `ImagePageAfterImageLinks` provides `&$html` (a string reference). OOUI PHP widgets stringify to HTML. `enableOOUI()` must be called on OutputPage before widgets render.
   - What's unclear: Whether `enableOOUI()` can be called inside `ImagePageAfterImageLinks` (which has access to the ImagePage context but not directly to OutputPage), or if it must be done in `BeforePageDisplay` which fires earlier.
   - Recommendation: Call `enableOOUI()` in `BeforePageDisplay` (which fires before the page body renders) to ensure styles are loaded. In `ImagePageAfterImageLinks`, just stringify the OOUI widgets into `$html`.

3. **ApiBase constructor DI with services**
   - What we know: `APIModules` in extension.json supports `"services"` key for DI, similar to `HookHandlers`. The class `ApiBase` changed namespace to `MediaWiki\Api\ApiBase` in MW 1.43.
   - What's unclear: The exact constructor signature for DI in MW 1.44 API modules. Older MW expected `__construct( $mainModule, $moduleName )`. With services injection, additional params are appended.
   - Recommendation: MEDIUM confidence. The services injection pattern is documented but the exact constructor signature needs verification against MW 1.44 source during implementation.

## Sources

### Primary (HIGH confidence)
- [Manual:Hooks/ImagePageAfterImageLinks](https://www.mediawiki.org/wiki/Manual:Hooks/ImagePageAfterImageLinks) - Hook for adding content to File pages after image links
- [Manual:Hooks/BeforePageDisplay](https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay) - Hook for adding modules to OutputPage; interface at `MediaWiki\Output\Hook\BeforePageDisplayHook`
- [API:Extensions](https://www.mediawiki.org/wiki/API:Extensions) - Creating custom API modules with ApiBase
- [OOUI/Using OOUI in MediaWiki](https://www.mediawiki.org/wiki/OOUI/Using_OOUI_in_MediaWiki) - enableOOUI(), PHP widget creation, DropdownInputWidget
- [OOUI/Widgets/Inputs](https://www.mediawiki.org/wiki/OOUI/Widgets/Inputs) - DropdownInputWidget options format
- [Manual:Logging to Special:Log](https://www.mediawiki.org/wiki/Manual:Logging_to_Special:Log) - ManualLogEntry, log type registration
- [ResourceLoader/Developing with ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader/Developing_with_ResourceLoader) - Module registration, packageFiles, dependencies
- [mw.Api class reference](https://doc.wikimedia.org/mediawiki-core/master/js/mw.Api.html) - postWithToken, CSRF handling
- [Manual:User rights](https://www.mediawiki.org/wiki/Manual:User_rights) - AvailableRights, GroupPermissions, isAllowed()
- Existing codebase: `PermissionService.php`, `Config.php`, `EnforcementHooks.php`, `UploadHooks.php`, `extension.json`

### Secondary (MEDIUM confidence)
- [Manual:Extension.json/Schema](https://www.mediawiki.org/wiki/Manual:Extension.json/Schema) - APIModules, LogTypes, LogNames, ResourceModules keys
- [OOUI/PHP examples](https://www.mediawiki.org/wiki/OOUI/PHP_examples) - PHP widget usage patterns
- [Category:MediaWiki hooks included in ImagePage.php](https://www.mediawiki.org/wiki/Category:MediaWiki_hooks_included_in_ImagePage.php) - List of 5 hooks in ImagePage
- [ResourceLoader/Package files](https://www.mediawiki.org/wiki/ResourceLoader/Package_files) - packageFiles vs scripts comparison
- ImagePage.php source analysis (rendering order: TOC, openShowImage, content, history, links, hook, metadata)

### Tertiary (LOW confidence)
- Exact PHP interface namespaces for MW 1.44 hooks (`ImagePageAfterImageLinksHook`, `BeforePageDisplayHook`) -- may have shifted in namespace reorganization
- `ApiBase` constructor signature with DI services in MW 1.44 -- needs verification against actual source
- OOUI widget rendering inside `ImagePageAfterImageLinks` `&$html` string with `enableOOUI()` called in separate hook -- logical but untested

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - All components are well-documented core MW features (hooks, ApiBase, OOUI, ResourceLoader, ManualLogEntry)
- Architecture: HIGH - Follows established patterns from prior phases (hook handler DI, extension.json registration) and documented MW extension patterns
- Pitfalls: HIGH for #1-6 (verified against official docs), MEDIUM for #7 (OOUI maintenance mode is documented but impact on MW 1.44 is minimal)
- Code examples: HIGH for structure/pattern, LOW for exact import paths in MW 1.44

**Research date:** 2026-01-28
**Valid until:** 2026-02-28 (30 days -- stable MW core APIs and hooks)
