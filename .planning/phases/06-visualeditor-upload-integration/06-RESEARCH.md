# Phase 6: VisualEditor Upload Integration - Research

**Researched:** 2026-01-29
**Domain:** VisualEditor upload dialog internals, mw.Upload stash flow, OOUI BookletLayout extension, MediaWiki API upload parameter handling
**Confidence:** HIGH (based on source code review of VisualEditor, MediaWiki core upload classes, and existing FilePermissions codebase)

## Summary

This phase requires injecting a permission-level dropdown into VisualEditor's upload dialog and ensuring the selected level reaches the server-side hooks (`UploadVerifyUpload`, `UploadComplete`) that already handle permission storage. The research reveals that VE's upload flow is fundamentally different from both Special:Upload (HTMLForm) and MsUpload (plupload multipart POST), requiring a distinct integration strategy.

VisualEditor uses a **two-phase stash upload** process: (1) the file is uploaded to the stash via `mw.Upload.uploadToStash()`, and (2) the file is published from the stash via `mw.Upload.finishStashUpload()`. The upload dialog is built using `mw.ForeignStructuredUpload.BookletLayout`, an OOUI `BookletLayout` subclass that manages the upload, info, and insert steps. The critical discovery is that `mw.Api.upload()` uses a **strict `fieldsAllowed` allowlist** that strips unknown parameters (including `wpFilePermLevel`) during the initial stash upload. However, `mw.Upload.finishStashUpload()` calls `mw.Api.uploadFromStash()`, which uses `postWithEditToken()` -- this path does NOT filter parameters, meaning additional data CAN be passed during the publish step. The existing `UploadVerifyUpload` and `UploadComplete` hooks fire during `performUpload()` which is the publish step -- so `RequestContext::getMain()->getRequest()->getVal('wpFilePermLevel')` will find our parameter if it is included in the publish API call.

The recommended approach is: (1) use XHR prototype patching (same technique proven in the MsUpload bridge) to intercept the `finishStashUpload` API POST and inject `wpFilePermLevel` into the request body, (2) inject an OOUI `DropdownInputWidget` into the BookletLayout's info form by monkey-patching `mw.ForeignStructuredUpload.BookletLayout.prototype.renderInfoForm`, and (3) load the bridge module conditionally via `BeforePageDisplay` when VisualEditor is installed. This avoids forking any VE or MediaWiki core code.

**Primary recommendation:** Monkey-patch `mw.ForeignStructuredUpload.BookletLayout.prototype.renderInfoForm` to add a permission dropdown field, and use XHR prototype patching to inject `wpFilePermLevel` into the `action=upload` + `filekey` API POST that publishes the file from stash.

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| MediaWiki ResourceLoader | MW 1.44+ | Module loading, conditional VE detection | Native MW infrastructure |
| OOUI (oojs-ui-core) | MW 1.44 bundled | DropdownInputWidget for permission selector | VE's upload dialog is entirely OOUI; must match |
| mw.ForeignStructuredUpload.BookletLayout | MW 1.44 core | VE's upload booklet -- the integration target | What VE uses for its upload UI |
| mw.Upload / mw.Api upload | MW 1.44 core | Underlying upload engine | Handles stash + publish flow |
| mw.Api | MW 1.44 bundled | API calls for verification | Standard MW API client |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| mediawiki.api | MW 1.44 | Post-upload PageProps verification | After upload completes |
| ExtensionRegistry | MW 1.25+ | Server-side VE detection | Conditional module loading |
| mw.hook('ve.newTarget') | VE JS hook | Detecting VE initialization | Alternative to BeforePageDisplay for VE-specific timing |
| OO.inheritClass / OO.ui | MW 1.44 | OOUI widget construction | Building the dropdown |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| XHR patching for param injection | Override BookletLayout.saveFile() | Cleaner but would need to also override createUpload() to swap the upload model. XHR patching is proven (used in Phase 5) and more surgical. |
| Monkey-patch renderInfoForm | Subclass BookletLayout | Subclassing would require also patching VE's MWMediaDialog to use our subclass. Monkey-patching the prototype is simpler and well-established in MW ecosystem. |
| OOUI DropdownInputWidget | Plain HTML select | VE's entire UI is OOUI. A plain select would be visually inconsistent. Must use OOUI to match VE's dialog styling. |
| BeforePageDisplay hook | ve.newTarget JS hook | BeforePageDisplay is simpler for server-side conditional loading. The JS-side initialization uses module timing anyway. |

**Installation:** No new packages needed. All dependencies are in MediaWiki core and VisualEditor.

## Architecture Patterns

### Recommended File Structure
```
modules/
  ext.FilePermissions.visualeditor.js    # VE bridge module (NEW)
  ext.FilePermissions.visualeditor.css   # Dropdown styling in VE dialog (NEW)
  ext.FilePermissions.msupload.js        # Existing: MsUpload bridge
  ext.FilePermissions.msupload.css       # Existing: MsUpload dropdown styles
  ext.FilePermissions.edit.js            # Existing: File page edit controls
  ext.FilePermissions.edit.css           # Existing: File page indicator styles
includes/
  Hooks/
    VisualEditorHooks.php                # NEW: Conditional VE bridge loading
    MsUploadHooks.php                    # Existing: MsUpload bridge loading
    DisplayHooks.php                     # NO CHANGES NEEDED
    UploadHooks.php                      # NO CHANGES NEEDED (already handles API uploads)
```

### Pattern 1: VE Upload Flow (Two-Phase Stash)
**What:** VE uses a two-phase upload: stash first, then publish from stash
**When:** Always in VE -- this is how VE uploads work
**Critical detail:** Our hooks (`UploadVerifyUpload`, `UploadComplete`) fire during the PUBLISH step (Phase 2), not the stash step (Phase 1). This means `wpFilePermLevel` must be included in the publish API call, not the stash API call.

```
VE Upload Flow:
Phase 1 - STASH (uploadToStash):
  Browser: mw.Upload.uploadToStash()
    -> mw.Api.chunkedUploadToStash() or mw.Api.uploadToStash()
    -> mw.Api.uploadWithFormData()          <-- fieldsAllowed FILTERS params
    -> POST api.php action=upload&stash=1   <-- wpFilePermLevel would be STRIPPED
  Server: File stored in stash (no UploadVerifyUpload/UploadComplete)

Phase 2 - PUBLISH (finishStashUpload):
  Browser: mw.Upload.finishStashUpload()
    -> finish callback: mw.Api.uploadFromStash(filekey, data)
    -> mw.Api.postWithEditToken(data)       <-- NO fieldsAllowed filter
    -> POST api.php action=upload&filekey=X <-- wpFilePermLevel CAN be included
  Server: UploadFromStash.performUpload()
    -> UploadVerifyUpload hook fires        <-- reads wpFilePermLevel from request
    -> File published to repo
    -> UploadComplete hook fires            <-- stores level in PageProps
```

### Pattern 2: Monkey-Patching BookletLayout.renderInfoForm
**What:** Override the prototype method to add our dropdown field
**When:** Always -- this is how we inject the UI
**Why monkey-patch:** VE's `ve.ui.MWMediaDialog` instantiates `mw.ForeignStructuredUpload.BookletLayout` directly. We cannot control which class it uses. By patching the prototype, ALL instances get our field.

```javascript
// Source: Established MW gadget/extension pattern for VE UI extension
( function () {
    var origRenderInfoForm =
        mw.ForeignStructuredUpload.BookletLayout.prototype.renderInfoForm;

    mw.ForeignStructuredUpload.BookletLayout.prototype.renderInfoForm =
        function () {
            // Call original to build the standard form
            var form = origRenderInfoForm.call( this );

            // Add our dropdown to the form's fieldset
            this.filePermDropdown = new OO.ui.DropdownInputWidget( { ... } );
            var fieldLayout = new OO.ui.FieldLayout( this.filePermDropdown, {
                label: mw.msg( 'filepermissions-ve-label' ),
                align: 'top'
            } );

            // Insert into the form's fieldset
            form.$element.find( '.oo-ui-fieldsetLayout' )
                .append( fieldLayout.$element );

            return form;
        };
}() );
```

### Pattern 3: XHR Prototype Patching for Parameter Injection
**What:** Intercept XMLHttpRequest.send() to inject `wpFilePermLevel` into the publish API call
**When:** Always -- the `fieldsAllowed` allowlist in `mw.Api.upload()` strips unknown params during stash phase, but the publish phase (uploadFromStash -> postWithEditToken) does NOT filter
**Why XHR patching:** This is the same proven technique used in Phase 5 (MsUpload bridge). It works because `postWithEditToken` ultimately makes an XHR POST. We intercept the send, check if it is an `action=upload` with a `filekey` (meaning it is the publish step), and append `wpFilePermLevel`.

```javascript
// Source: Proven pattern from ext.FilePermissions.msupload.js
var origOpen = XMLHttpRequest.prototype.open;
XMLHttpRequest.prototype.open = function ( method, url ) {
    if ( method === 'POST' && url && url.indexOf( 'api.php' ) !== -1 ) {
        this._filePermIsApiPost = true;
    }
    return origOpen.apply( this, arguments );
};

var origSend = XMLHttpRequest.prototype.send;
XMLHttpRequest.prototype.send = function ( body ) {
    if ( this._filePermIsApiPost && body instanceof FormData ) {
        // Check for publish-from-stash: action=upload + filekey present
        var isUpload = body.get( 'action' ) === 'upload';
        var hasFilekey = !!body.get( 'filekey' );
        if ( isUpload && hasFilekey && !body.get( 'wpFilePermLevel' ) ) {
            body.append( 'wpFilePermLevel', getSelectedPermLevel() );
        }
    }
    return origSend.apply( this, arguments );
};
```

### Pattern 4: Server-Side Conditional Module Loading
**What:** Only load the VE bridge module when VisualEditor is installed
**When:** Always -- silent no-op when VE is absent
**How:** Same pattern as MsUploadHooks.php -- use `ExtensionRegistry::isLoaded('VisualEditor')`

```php
// Source: Established pattern from MsUploadHooks.php
use MediaWiki\Registration\ExtensionRegistry;
use FilePermissions\Config;

class VisualEditorHooks implements BeforePageDisplayHook {
    public function onBeforePageDisplay( $out, $skin ): void {
        if ( !ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' ) ) {
            return;
        }
        $ns = $out->getTitle()->getNamespace();
        $out->addModules( [ 'ext.FilePermissions.visualeditor' ] );
        $out->addJsConfigVars( [
            'wgFilePermLevels' => Config::getLevels(),
            'wgFilePermVEDefault' => Config::resolveDefaultLevel( $ns ),
        ] );
    }
}
```

### Pattern 5: Module Timing via mw.loader.using
**What:** Ensure our bridge module runs AFTER VE's BookletLayout is available
**When:** Always -- we need to monkey-patch the prototype before VE instantiates its dialog
**How:** Declare `mediawiki.ForeignStructuredUpload.BookletLayout` as a dependency in our RL module registration, OR use `mw.loader.using()` at runtime.

```json
{
    "ext.FilePermissions.visualeditor": {
        "localBasePath": "modules",
        "remoteExtPath": "FilePermissions/modules",
        "packageFiles": [
            "ext.FilePermissions.visualeditor.js"
        ],
        "styles": [
            "ext.FilePermissions.visualeditor.css"
        ],
        "dependencies": [
            "mediawiki.api",
            "oojs-ui-core",
            "mediawiki.ForeignStructuredUpload.BookletLayout"
        ],
        "messages": [
            "filepermissions-ve-label",
            "filepermissions-ve-error-nolevels",
            "filepermissions-ve-error-save"
        ]
    }
}
```

Note: Unlike the MsUpload bridge, we CAN declare `mediawiki.ForeignStructuredUpload.BookletLayout` as a hard dependency because it is a MediaWiki CORE module, always available when VE is installed. VE itself depends on it.

### Anti-Patterns to Avoid
- **Forking VisualEditor or MediaWiki core:** Never modify VE's source. The entire phase is a bridge using prototype patching and XHR interception.
- **Subclassing BookletLayout and replacing VE's instance:** VE's `MWMediaDialog` hard-codes `new mw.ForeignStructuredUpload.BookletLayout(...)`. We cannot swap the class without patching VE. Monkey-patching the prototype is the correct approach.
- **Injecting wpFilePermLevel in the stash phase:** The `fieldsAllowed` allowlist in `mw.Api.uploadWithFormData()` strips unknown parameters. The parameter MUST go in the publish phase (uploadFromStash -> postWithEditToken).
- **Using plain HTML select in VE dialog:** VE's dialog is entirely OOUI. Use `OO.ui.DropdownInputWidget` for visual consistency.
- **Declaring ext.visualEditor.mwimage as a dependency:** This is a VE-internal module. Depend on `mediawiki.ForeignStructuredUpload.BookletLayout` (core module) instead.
- **Using EditPage::showEditForm:initial for VE loading:** VE does NOT use the wikitext edit form. Use `BeforePageDisplay` which fires on all pages where VE could be opened.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Permission dropdown in VE dialog | Custom OOUI dialog or panel | Monkey-patch `renderInfoForm` to add `OO.ui.DropdownInputWidget` | VE's dialog structure is fixed; extending the existing form is the only non-forking option |
| Upload parameter injection | Custom fetch/XHR upload code | XHR prototype patching on send() | VE uses `mw.Api.postWithEditToken()` internally; we intercept at the XHR level |
| VE detection | DOM queries for VE elements | `ExtensionRegistry::isLoaded('VisualEditor')` (server) | Reliable, timing-independent |
| Upload dialog timing | Polling for dialog open | `mw.loader.using()` + dependency on `mediawiki.ForeignStructuredUpload.BookletLayout` | Ensures prototype is patched before any BookletLayout instance is created |
| Post-upload verification | Server-side response modification | Client-side PageProps API query (same as MsUpload bridge) | Server cannot signal PageProps save failure in the upload response |
| Error notifications | Custom toast UI | `mw.notify()` with `{ type: 'error', autoHide: false }` | Standard MW notification, matches Phase 5 pattern |

**Key insight:** VE's upload flow is entirely built on MW core classes (`mw.Upload`, `mw.Api`, OOUI BookletLayout). The integration points are: (1) prototype patching for UI extension, (2) XHR interception for parameter injection, (3) server-side hooks that already work (UploadVerifyUpload + UploadComplete fire for UploadFromStash too).

## Common Pitfalls

### Pitfall 1: fieldsAllowed Strips wpFilePermLevel During Stash Upload
**What goes wrong:** Attempting to add `wpFilePermLevel` to the initial upload (stash phase) via `mw.Upload` parameters. The parameter is silently stripped by `mw.Api.uploadWithFormData()`.
**Why it happens:** MediaWiki core has a strict `fieldsAllowed` allowlist in `resources/src/mediawiki.api/upload.js` that only permits: `stash, filekey, filename, comment, tags, text, watchlist, watchlistexpiry, ignorewarnings, chunk, offset, filesize, async`. All other keys are deleted before the POST.
**How to avoid:** Inject `wpFilePermLevel` only during the PUBLISH phase (uploadFromStash -> postWithEditToken), which does NOT use `uploadWithFormData` and therefore does NOT filter parameters.
**Warning signs:** `wpFilePermLevel` is null/empty in `UploadVerifyUpload` hook despite being set client-side. No JavaScript error -- the parameter is silently stripped.

### Pitfall 2: UploadVerifyUpload Fires During Publish, Not Stash
**What goes wrong:** Developer assumes `UploadVerifyUpload` fires when the file is stashed, and writes code to read `wpFilePermLevel` from the stash request.
**Why it happens:** The two-phase flow is not obvious. `UploadVerifyUpload` fires inside `UploadBase::performUpload()`, which is the PUBLISH step.
**How to avoid:** Understand the hook fire order: `UploadStashFile` (stash phase) -> `UploadVerifyUpload` (publish phase) -> `UploadComplete` (publish phase). Our parameter must be in the publish request.
**Warning signs:** Hook fires but request params differ from what was sent in the stash upload.

### Pitfall 3: Monkey-Patch Executes After BookletLayout Is Already Instantiated
**What goes wrong:** Our module loads too late, after VE has already created a `BookletLayout` instance with the original `renderInfoForm`. Our patched version is never called.
**Why it happens:** ResourceLoader module execution order is not guaranteed unless dependencies are declared. If our module loads after `ext.visualEditor.mwimage` has already instantiated the dialog, the prototype patch misses the existing instance.
**How to avoid:** Declare `mediawiki.ForeignStructuredUpload.BookletLayout` as a dependency in our module. This ensures our module loads after the BookletLayout class is defined but potentially before VE instantiates it. VE lazily initializes the dialog -- it is not created until the user opens the media dialog, which gives our prototype patch time to apply.
**Warning signs:** Dropdown does not appear in the upload dialog, but no JS errors.

### Pitfall 4: BeforePageDisplay Fires on All Pages, Loading Module Unnecessarily
**What goes wrong:** The VE bridge module is loaded on every page view, wasting bandwidth.
**Why it happens:** `BeforePageDisplay` fires on every page. Without filtering, the module loads everywhere.
**How to avoid:** The module registration in extension.json does not cause the module to load automatically -- it must be explicitly added via `$out->addModules()`. The hook handler should check that the page is editable and VE is relevant. However, since VE can be opened on any content page via the edit tab, it is acceptable to load on all content pages where VE is available. The module is small (only JS bridge code).
**Warning signs:** Module appears in ResourceLoader output on Special pages or non-editable pages.

### Pitfall 5: FormData.get() Not Available in Older Browsers
**What goes wrong:** `body.get('action')` throws in browsers that do not support `FormData.get()`.
**Why it happens:** `FormData.get()` was not available in IE11. However, MW 1.44 has dropped IE11 support.
**How to avoid:** MW 1.44+ only supports modern browsers. `FormData.get()` is safe to use. This was already validated in Phase 5.
**Warning signs:** Not a concern for MW 1.44+, but would matter if backporting.

### Pitfall 6: VE Dialog Reuse -- Dropdown State Persists Between Opens
**What goes wrong:** User opens VE media dialog, selects a permission level, closes dialog without uploading. Next time they open it, the old selection persists.
**Why it happens:** VE's MWMediaDialog may reuse the same instance. The BookletLayout `clear()` method resets built-in fields but not our custom dropdown.
**How to avoid:** Also monkey-patch `clear()` to reset the dropdown to the default value. OR read the selected value fresh from the dropdown at upload time (which our XHR interception does -- it reads `getSelectedPermLevel()` at send time, not at dialog open time).
**Warning signs:** Files uploaded with wrong permission level after dialog reuse.

### Pitfall 7: Foreign Upload Target Breaks Our Flow
**What goes wrong:** If `$wgForeignUploadTargets` includes a foreign wiki, the upload goes to a different wiki where our `UploadVerifyUpload`/`UploadComplete` hooks do not exist.
**Why it happens:** `mw.ForeignStructuredUpload` can target foreign wikis via `mw.ForeignApi`.
**How to avoid:** Our extension is designed for local uploads. When the target is not `'local'`, skip permission dropdown injection. Check `this.upload.target === 'local'` in the monkey-patched `renderInfoForm`.
**Warning signs:** Permission level not stored after upload; no error (hooks do not exist on foreign wiki).

## Code Examples

### Example 1: Server-Side Conditional Module Loading (PHP)
```php
// Source: Pattern from MsUploadHooks.php + ExtensionRegistry docs
// includes/Hooks/VisualEditorHooks.php

<?php
declare( strict_types=1 );

namespace FilePermissions\Hooks;

use FilePermissions\Config;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;

class VisualEditorHooks implements BeforePageDisplayHook {

    public function onBeforePageDisplay( $out, $skin ): void {
        if ( !ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' ) ) {
            return;
        }

        $ns = $out->getTitle()->getNamespace();

        $out->addModules( [ 'ext.FilePermissions.visualeditor' ] );
        $out->addJsConfigVars( [
            'wgFilePermLevels' => Config::getLevels(),
            'wgFilePermVEDefault' => Config::resolveDefaultLevel( $ns ),
        ] );
    }
}
```

### Example 2: Bridge Module JavaScript (Core Pattern)
```javascript
// Source: BookletLayout JSDoc extension pattern + Phase 5 XHR patching
// ext.FilePermissions.visualeditor.js

( function () {
    'use strict';

    var levels = mw.config.get( 'wgFilePermLevels' );
    var defaultLevel = mw.config.get( 'wgFilePermVEDefault' );

    // Guard: levels must be available
    if ( !levels || !levels.length ) {
        return;
    }

    // --- PART 1: Monkey-patch BookletLayout to add dropdown ---

    var origRenderInfoForm =
        mw.ForeignStructuredUpload.BookletLayout.prototype.renderInfoForm;

    mw.ForeignStructuredUpload.BookletLayout.prototype.renderInfoForm =
        function () {
            var form = origRenderInfoForm.call( this );

            // Only add dropdown for local uploads
            if ( this.upload && this.upload.target !== 'local' ) {
                return form;
            }

            // Build OOUI dropdown
            this.filePermDropdown = new OO.ui.DropdownInputWidget( {
                options: levels.map( function ( lvl ) {
                    return { data: lvl, label: lvl };
                } ),
                value: defaultLevel || levels[ 0 ]
            } );

            var fieldLayout = new OO.ui.FieldLayout(
                this.filePermDropdown,
                {
                    label: mw.msg( 'filepermissions-ve-label' ),
                    align: 'top'
                }
            );

            // Insert into the form's fieldset
            form.$element.find( '.oo-ui-fieldsetLayout' )
                .append( fieldLayout.$element );

            return form;
        };

    // Also patch clear() to reset dropdown
    var origClear =
        mw.ForeignStructuredUpload.BookletLayout.prototype.clear;

    mw.ForeignStructuredUpload.BookletLayout.prototype.clear = function () {
        origClear.call( this );
        if ( this.filePermDropdown ) {
            this.filePermDropdown.setValue( defaultLevel || levels[ 0 ] );
        }
    };

    // --- PART 2: XHR patching to inject wpFilePermLevel ---

    /**
     * Get selected permission level from the BookletLayout dropdown.
     * Falls back to default if dropdown is not found.
     */
    function getSelectedPermLevel() {
        // The dropdown is an OOUI widget; find it via the DOM
        var $dropdown = $( '.fileperm-ve-dropdown' );
        if ( $dropdown.length ) {
            var widget = OO.ui.infuse( $dropdown );
            return widget.getValue();
        }
        return defaultLevel || levels[ 0 ];
    }

    // Patch XMLHttpRequest to inject wpFilePermLevel on publish
    var origOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function ( method, url ) {
        if ( method === 'POST' && url && url.indexOf( 'api.php' ) !== -1 ) {
            this._filePermIsApiPost = true;
        }
        return origOpen.apply( this, arguments );
    };

    var origSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function ( body ) {
        if ( this._filePermIsApiPost && body instanceof FormData ) {
            var isUpload = ( typeof body.get === 'function' ) &&
                body.get( 'action' ) === 'upload';
            var hasFilekey = ( typeof body.get === 'function' ) &&
                !!body.get( 'filekey' );

            // Only inject on publish-from-stash (action=upload + filekey)
            if ( isUpload && hasFilekey && !body.get( 'wpFilePermLevel' ) ) {
                body.append( 'wpFilePermLevel', getSelectedPermLevel() );
            }
        }
        return origSend.apply( this, arguments );
    };
}() );
```

### Example 3: ResourceModule Registration in extension.json
```json
{
    "ext.FilePermissions.visualeditor": {
        "localBasePath": "modules",
        "remoteExtPath": "FilePermissions/modules",
        "packageFiles": [
            "ext.FilePermissions.visualeditor.js"
        ],
        "styles": [
            "ext.FilePermissions.visualeditor.css"
        ],
        "dependencies": [
            "mediawiki.api",
            "oojs-ui-core",
            "mediawiki.ForeignStructuredUpload.BookletLayout"
        ],
        "messages": [
            "filepermissions-ve-label",
            "filepermissions-ve-error-nolevels",
            "filepermissions-ve-error-save"
        ]
    }
}
```

Note: `mediawiki.ForeignStructuredUpload.BookletLayout` IS listed as a dependency because it is a MediaWiki core module, always available. This ensures our prototype patch applies before VE instantiates any BookletLayout.

### Example 4: Post-Upload Verification (Same Pattern as Phase 5)
```javascript
// Source: ext.FilePermissions.msupload.js verified pattern
function verifyPermission( filename ) {
    new mw.Api().get( {
        action: 'query',
        titles: 'File:' + filename,
        prop: 'pageprops',
        ppprop: 'fileperm_level'
    } ).then( function ( data ) {
        if ( !data.query || !data.query.pages ) {
            return;
        }
        var pages = data.query.pages;
        for ( var pageId in pages ) {
            var page = pages[ pageId ];
            if ( !page.pageprops || !page.pageprops.fileperm_level ) {
                mw.notify(
                    mw.msg( 'filepermissions-ve-error-save', filename ),
                    { type: 'error', autoHide: false }
                );
            }
        }
    } );
}
```

### Example 5: Hook Handler Registration in extension.json
```json
{
    "HookHandlers": {
        "visualeditor": {
            "class": "FilePermissions\\Hooks\\VisualEditorHooks"
        }
    },
    "Hooks": {
        "BeforePageDisplay": [ "display", "visualeditor" ]
    }
}
```

Note: `BeforePageDisplay` already has the `display` handler. Both handlers can be registered for the same hook -- MW invokes all registered handlers.

## VE Upload Dialog Source Code Analysis

### Key Findings from Source Review

**Class hierarchy:**
```
mw.Upload                              (base upload model)
  -> mw.ForeignUpload                  (adds foreign wiki API support)
    -> mw.ForeignStructuredUpload      (adds structured metadata: descriptions, categories, date)

mw.Upload.BookletLayout                (base OOUI booklet for upload flow)
  -> mw.ForeignStructuredUpload.BookletLayout  (adds category/date/license fields)

ve.ui.MWMediaDialog                    (VE's media insertion dialog)
  -> uses mw.ForeignStructuredUpload.BookletLayout as upload panel
```

**Upload flow in ve.ui.MWMediaDialog:**
| Step | VE Action | BookletLayout Method | mw.Upload Method | mw.Api Method | Server Hook |
|------|-----------|---------------------|-----------------|---------------|-------------|
| 1 | User selects file | `uploadFile()` | `uploadToStash()` | `chunkedUploadToStash()` / `uploadToStash()` -> `uploadWithFormData()` | `UploadStashFile` (stash only) |
| 2 | File uploaded to stash | BookletLayout shows info form | (stash complete) | (stash complete) | - |
| 3 | User fills metadata, clicks Save | `saveFile()` | `finishStashUpload()` | `uploadFromStash()` -> `postWithEditToken()` | `UploadVerifyUpload`, then `UploadComplete` |
| 4 | File published | BookletLayout shows insert panel | (complete) | (complete) | - |

**Critical parameter flow detail:**
- Step 1 (stash): `uploadWithFormData()` filters via `fieldsAllowed` -- custom params stripped
- Step 3 (publish): `uploadFromStash()` -> `postWithEditToken()` -- NO filtering, custom params pass through

### fieldsAllowed Allowlist (from mediawiki.api/upload.js)
```javascript
const fieldsAllowed = {
    stash: true,
    filekey: true,
    filename: true,
    comment: true,
    text: true,
    watchlist: true,
    ignorewarnings: true,
    chunk: true,
    offset: true,
    filesize: true,
    async: true
};
```
`wpFilePermLevel` is NOT in this list and will be stripped during stash upload.

### VE MWMediaDialog Action Handlers
```javascript
// From ve.ui.MWMediaDialog.js source
case 'upload':
    return new OO.ui.Process( this.mediaUploadBooklet.uploadFile() );
case 'save':
    return new OO.ui.Process( this.mediaUploadBooklet.saveFile() );
```
The dialog delegates entirely to BookletLayout. No parameters can be injected at the dialog level.

### BookletLayout.saveFile() Flow
```javascript
// From mw.Upload.BookletLayout source
saveFile: function () {
    this.upload.setFilename( this.getFilename() );
    this.upload.setText( this.getText() );
    // Waits for stash, then:
    this.upload.finishStashUpload().then( ... );
}
```
`finishStashUpload()` calls `mw.Api.uploadFromStash(filekey, data)` where `data` includes `filename`, `text`, `comment`. Our XHR patch intercepts this POST to add `wpFilePermLevel`.

## Critical Integration Architecture

### End-to-End Parameter Flow

```
Browser (VE + our bridge):
  1. User opens VE media dialog, selects Upload tab
  2. BookletLayout.renderInfoForm() runs (MONKEY-PATCHED)
     -> Standard fields created (filename, description, categories, date)
     -> Our code appends permission dropdown field
  3. User selects file, fills metadata, selects permission level
  4. User clicks Upload -> BookletLayout.uploadFile()
     -> File stashed (wpFilePermLevel NOT included - fieldsAllowed blocks it)
  5. User clicks Save -> BookletLayout.saveFile()
     -> Calls finishStashUpload()
     -> API POST: action=upload, filekey=X, filename=Y, text=Z
     -> XHR INTERCEPTED: wpFilePermLevel appended to FormData
     -> Final POST includes: action=upload, filekey=X, filename=Y,
        text=Z, wpFilePermLevel=selected_value

Server (MediaWiki API + our hooks):
  6. ApiUpload processes action=upload with filekey (UploadFromStash)
  7. UploadVerifyUpload hook fires:
     -> UploadHooks reads RequestContext::getMain()->getRequest()
        ->getVal('wpFilePermLevel')
     -> Validates level (or applies namespace default if missing)
  8. File published to repo
  9. UploadComplete hook fires:
     -> UploadHooks reads wpFilePermLevel from request
     -> DeferredUpdates stores level in PageProps
```

### Server-Side: No Changes Needed

The existing `UploadHooks.php` already handles the VE upload scenario correctly:
- `UploadVerifyUpload`: Reads `wpFilePermLevel` from request. If missing, applies namespace/global default. If still missing and not from Special:Upload form, allows (grandfathered).
- `UploadComplete`: Reads `wpFilePermLevel` from request or resolves default. Stores in PageProps via DeferredUpdates.

Both hooks fire during `UploadBase::performUpload()` which is called by `UploadFromStash` (the class used for stash-to-publish flow). The request context contains all POST parameters from the API call, including any extra params not in the API module's formal parameter list.

### XHR Interception Scope

Both the MsUpload bridge and VE bridge patch `XMLHttpRequest.prototype`. If both bridges are loaded on the same page (e.g., an edit page with both MsUpload and VE available), the patches must coexist:
- MsUpload bridge intercepts: `action=upload` WITHOUT `filekey` (direct upload)
- VE bridge intercepts: `action=upload` WITH `filekey` (publish from stash)

Both bridges use the same pattern: check `body.get('wpFilePermLevel')` before appending, so double-injection is prevented. However, they should ideally share the XHR patching code to avoid double-patching the prototype. A shared utility module or sequential check (first bridge's patch is preserved by second bridge's wrapping) handles this naturally since each bridge wraps the previous version.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Direct upload (no stash) | Two-phase stash + publish | MW 1.20+ | VE always uses stash. Upload params must go in publish phase. |
| Custom upload XHR | mw.Upload model + mw.Api helpers | MW 1.28+ | Upload flow is abstracted. Cannot easily add params at model level. |
| OOUI standard UI lib | OOUI in maintenance mode, Codex replacing | 2024+ | VE still uses OOUI. Our widgets must be OOUI, not Codex. |
| IE11 support | Modern browsers only | MW 1.42+ | FormData.get() is safe. No need for IE11 fallbacks. |
| `$wgForeignUploadTargets = []` meant local | `['local']` is explicit default | MW 1.28 | Local upload is the default and most common config |

**Deprecated/outdated:**
- IE11 FormData workarounds: Not needed for MW 1.44+
- Flash/Silverlight upload runtimes: Long dead, irrelevant

## Open Questions

1. **XHR patching coexistence between MsUpload and VE bridges**
   - What we know: Both bridges patch `XMLHttpRequest.prototype.open` and `.send`. Each wraps the previous version using `var origOpen = XMLHttpRequest.prototype.open`.
   - What's unclear: If both load on the same page, will the double-wrapping cause issues? Each wrapper stores the "previous" function and calls through. This is standard monkey-patching and should work (nested wrappers). But it has not been tested.
   - Recommendation: Consider extracting the XHR patching into a shared utility module (`ext.FilePermissions.xhrpatch`) that both bridges depend on. This eliminates double-patching. Alternatively, accept double-wrapping and test it during verification.

2. **Dropdown value retrieval in XHR interceptor**
   - What we know: The XHR send interceptor needs to read the current dropdown value. The dropdown is an OOUI widget added to the BookletLayout.
   - What's unclear: At XHR send time, is the BookletLayout's info form still in the DOM? VE may have navigated to a different panel.
   - Recommendation: Store the dropdown reference on the BookletLayout instance (`this.filePermDropdown`) during `renderInfoForm`. Read the value directly from the widget reference, not from DOM queries. The widget value persists regardless of DOM visibility.

3. **VE dialog initialization timing**
   - What we know: VE lazily creates its MWMediaDialog -- it is NOT instantiated at page load. Our prototype patch runs at module load time (before VE opens).
   - What's unclear: Does VE ever create a BookletLayout before the user opens the media dialog? If VE pre-creates the dialog during `ve.newTarget`, our patch might miss it.
   - Recommendation: The VE source confirms the BookletLayout is lazily initialized (`if (!this.mediaUploadBookletInit) { this.mediaUploadBookletInit = true; this.mediaUploadBooklet.initialize(); }`). Our prototype patch on `renderInfoForm` applies to the class, so any future instance will use it. LOW risk.

4. **BeforePageDisplay hook multiplexing**
   - What we know: `BeforePageDisplay` already has the `display` handler registered. Adding `visualeditor` as a second handler for the same hook is supported by MW.
   - What's unclear: Whether the extension.json `Hooks` format supports arrays of handler names for the same hook.
   - Recommendation: MW extension.json supports `"BeforePageDisplay": ["display", "visualeditor"]` syntax. Alternatively, the VE bridge loading can be added to the existing `DisplayHooks.onBeforePageDisplay()` method. Both approaches work; separate handlers are cleaner.

## Sources

### Primary (HIGH confidence)
- VE source: `ve.ui.MWMediaDialog.js` - reviewed via raw GitHub (upload flow, BookletLayout instantiation, action handlers)
- VE source: `extension.json` - reviewed via raw GitHub (module dependencies, especially `mediawiki.ForeignStructuredUpload.BookletLayout`)
- MW core source: `mediawiki.api/upload.js` - reviewed via JSDoc source + WebFetch analysis (fieldsAllowed allowlist, uploadWithFormData, finishUploadToStash, uploadFromStash)
- MW core source: `mw.Upload.BookletLayout/BookletLayout.js` - reviewed via raw GitHub (uploadFile, saveFile, renderInfoForm, createUpload methods)
- MW core source: `mw.ForeignStructuredUpload.BookletLayout/BookletLayout.js` - reviewed via raw GitHub (renderInfoForm override, saveFile chain, createUpload)
- MW core source: `mw.ForeignStructuredUpload.BookletLayout/ForeignStructuredUpload.js` - reviewed via GitHub (class hierarchy, constructor, getText/getComment)
- FilePermissions codebase: `UploadHooks.php`, `MsUploadHooks.php`, `ext.FilePermissions.msupload.js`, `extension.json` - direct file read (existing patterns)

### Secondary (MEDIUM confidence)
- [MediaWiki Upload dialog docs](https://www.mediawiki.org/wiki/Upload_dialog) - upload dialog architecture and $wgUploadDialog config
- [mw.Upload.BookletLayout JSDoc](https://doc.wikimedia.org/mediawiki-core/master/js/mw.Upload.BookletLayout.html) - extension guidance ("override renderInfoForm")
- [mw.Upload JSDoc](https://doc.wikimedia.org/mediawiki-core/master/js/mw.Upload.html) - upload model API, stash flow
- [VisualEditor/Hooks](https://www.mediawiki.org/wiki/VisualEditor/Hooks) - ve.newTarget hook documentation
- [Manual:$wgForeignUploadTargets](https://www.mediawiki.org/wiki/Manual:$wgForeignUploadTargets) - local vs foreign upload target config
- [Manual:Hooks/UploadVerifyUpload](https://www.mediawiki.org/wiki/Manual:Hooks/UploadVerifyUpload) - hook fire timing (during performUpload, NOT stash)
- [Manual:Hooks/UploadComplete](https://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete) - hook fire timing (after performUpload)
- [mediawiki.api/upload.js source](https://doc.wikimedia.org/mediawiki-core/master/js/mediawiki.api_upload.js.html) - fieldsAllowed allowlist confirmation

### Tertiary (LOW confidence)
- WebSearch results on VE upload extension patterns - no direct examples found of other extensions extending VE's upload dialog. Pattern is based on documented BookletLayout extension approach + Phase 5 XHR patching precedent.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - VE uses MW core upload classes, all verified via source
- Architecture: HIGH - Two-phase stash flow confirmed from source. XHR interception proven in Phase 5. Prototype patching is documented MW extension pattern.
- Pitfalls: HIGH - fieldsAllowed allowlist is from source code. Hook fire timing confirmed from MW docs. Dialog reuse risk identified from VE source.
- Parameter flow: HIGH - End-to-end flow verified from VE dialog -> BookletLayout -> mw.Upload -> mw.Api -> server hooks

**Research date:** 2026-01-29
**Valid until:** 2026-03-01 (VE's upload dialog architecture is stable; BookletLayout API is documented and unlikely to change)
