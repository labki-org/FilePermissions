# Phase 5: MsUpload Integration - Research

**Researched:** 2026-01-29
**Domain:** MsUpload JavaScript bridge, plupload upload interception, MediaWiki ResourceLoader conditional loading
**Confidence:** HIGH (based on direct source code review of MsUpload v14.2)

## Summary

This phase requires injecting a permission-level dropdown into MsUpload's drag-drop upload interface and appending the selected level to each file's upload request. The primary research challenge -- that MsUpload's JavaScript API is undocumented -- was resolved through direct source code review of the MsUpload v14.2 extension cloned from the official Wikimedia GitHub mirror.

MsUpload is a thin JavaScript wrapper around the **plupload** library. It exposes a global `MsUpload` object with an `uploader` property (the plupload instance). The critical integration points are: (1) the `MsUpload` global object is set on the `window` scope and its `uploader` property is available after `mw.hook('wikiEditor.toolbarReady')` fires, (2) plupload's `BeforeUpload` event is where `multipart_params` are set per-file via `uploader.setOption()`, and (3) MsUpload builds its DOM in a specific hierarchy (`#msupload-div` placed directly after `#wikiEditor-ui-toolbar`) with a `#msupload-bottom` div for action buttons. The dropdown should be injected into or adjacent to `#msupload-div` to maintain visual coherence.

The existing `UploadHooks.php` reads the permission level from the request as `wpFilePermLevel`. Since MsUpload uploads go through the MW API (`action=upload`), not HTMLForm, the key insight is: plupload sends all `multipart_params` as POST body fields, and `RequestContext::getMain()->getRequest()->getVal('wpFilePermLevel')` will find them regardless of whether the API module formally recognizes them. This means **no server-side changes are needed** -- the existing UploadVerifyUpload and UploadComplete hooks already work for API uploads if we include `wpFilePermLevel` in the multipart_params.

**Primary recommendation:** Hook into the plupload `BeforeUpload` event via `MsUpload.uploader.bind()` to inject `wpFilePermLevel` into multipart_params. Build the dropdown by detecting MsUpload presence server-side with `ExtensionRegistry::isLoaded('MsUpload')`, then inject a dedicated JS module that waits for `MsUpload.uploader` to become available.

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| MediaWiki ResourceLoader | MW 1.44+ | Module loading, conditional dependencies | Native MW infrastructure; phase requires conditional module |
| OOUI (oojs-ui-core) | MW 1.44 bundled | DropdownInputWidget for permission selector | Consistent with Phase 4 edit controls; MW standard UI library |
| plupload | Bundled with MsUpload | Underlying upload engine MsUpload wraps | Not a dependency we add; we hook into it via MsUpload.uploader |
| mw.Api | MW 1.44 bundled | Fetching permission levels at runtime | Standard MW API client |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| mediawiki.api | MW 1.44 | CSRF token access, API calls | Loading permission levels dynamically |
| ExtensionRegistry | MW 1.25+ | Server-side detection of MsUpload | Conditional module loading in PHP hooks |
| mw.hook | MW 1.22+ | JS event system for wikiEditor.toolbarReady | Detecting when MsUpload toolbar is ready |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| OOUI dropdown | Plain HTML `<select>` matching MsUpload style | OOUI is heavier but consistent with Phase 4; plain select is lighter but inconsistent. **Recommendation: use plain HTML `<select>`** -- MsUpload's toolbar area uses plain DOM elements, not OOUI. An OOUI widget would look visually out of place. |
| Server-side conditional module load | Client-side `mw.loader.getState()` check | Server-side is cleaner and avoids loading unused JS. Use `ExtensionRegistry::isLoaded('MsUpload')` in a hook handler. |

**Installation:** No new packages needed. All dependencies are already in MediaWiki core and MsUpload.

## Architecture Patterns

### Recommended File Structure
```
modules/
  ext.FilePermissions.msupload.js    # MsUpload bridge module (NEW)
  ext.FilePermissions.msupload.css   # Dropdown styling in MsUpload area (NEW)
  ext.FilePermissions.edit.js        # Existing: File page edit controls
  ext.FilePermissions.edit.css       # Existing: File page indicator styles
includes/
  Hooks/
    DisplayHooks.php                 # MODIFY: add MsUpload conditional loading
    UploadHooks.php                  # NO CHANGES NEEDED (already works for API)
```

### Pattern 1: Server-Side Conditional Module Loading
**What:** Only load the MsUpload bridge module when MsUpload is actually installed
**When to use:** Always -- this is the silent no-op requirement (CONTEXT.md decision)
**How it works:**

The `EditPage::showEditForm:initial` hook is where MsUpload loads itself. FilePermissions should use a similar approach -- either reuse the existing `BeforePageDisplay` hook in DisplayHooks.php or add a new hook handler. The check uses `ExtensionRegistry::isLoaded('MsUpload')`.

```php
// Source: MediaWiki ExtensionRegistry pattern
// In a hook handler (e.g., BeforePageDisplay or EditPage::showEditForm:initial)
use MediaWiki\Registration\ExtensionRegistry;

if ( ExtensionRegistry::getInstance()->isLoaded( 'MsUpload' ) ) {
    $out->addModules( [ 'ext.FilePermissions.msupload' ] );
    $out->addJsConfigVars( [
        'wgFilePermLevels' => Config::getLevels(),
        'wgFilePermDefaultLevel' => Config::resolveDefaultLevel(
            $out->getTitle()->getNamespace()
        ),
    ] );
}
```

### Pattern 2: Hooking Into MsUpload's Plupload Instance
**What:** Bind to the plupload `BeforeUpload` event to inject the permission level into every upload's POST params
**When to use:** This is the core integration mechanism
**How it works:**

MsUpload stores its plupload instance at `MsUpload.uploader` (a global). It is set during `MsUpload.createUploader()`, which runs on `mw.hook('wikiEditor.toolbarReady')`. Our bridge module must wait for MsUpload to initialize, then bind to its uploader's events.

```javascript
// Source: MsUpload.js source code analysis (line 49, 64, 420-437, 568)
// MsUpload.uploader is a plupload.Uploader instance
// MsUpload.onBeforeUpload sets multipart_params via uploader.setOption()
//
// Strategy: bind our own BeforeUpload handler AFTER MsUpload binds its own.
// plupload runs handlers in bind order, so MsUpload's handler runs first
// (setting base params), then ours adds wpFilePermLevel.
//
// CRITICAL: MsUpload.onBeforeUpload calls uploader.setOption('multipart_params', {...})
// which REPLACES the entire multipart_params object. We must therefore either:
//   (a) Run AFTER MsUpload's handler and merge our param into the existing object
//   (b) Use uploader.settings.multipart_params directly
//
// Option (a) is correct because plupload fires handlers in bind order.
// After MsUpload sets its params, our handler reads the current params and adds ours.

function onBeforeUpload( uploader /*, file */ ) {
    var params = uploader.settings.multipart_params || {};
    params.wpFilePermLevel = getSelectedLevel();
    uploader.settings.multipart_params = params;
}

// Bind after MsUpload has already bound its handlers:
MsUpload.uploader.bind( 'BeforeUpload', onBeforeUpload );
```

### Pattern 3: DOM Injection Point for Dropdown
**What:** Where to place the permission dropdown in MsUpload's UI
**When to use:** Always -- the dropdown must be visible before files are dropped (CONTEXT.md decision)

MsUpload's DOM structure (from source code analysis of MsUpload.js lines 10-46 and MsUpload.less):

```
#wikiEditor-ui-toolbar          (WikiEditor toolbar - contains upload button)
  #wikiEditor-section-main
    .group-insert
      #msupload-container       (MsUpload button container, inline in toolbar)
#msupload-div                   (MsUpload main area - AFTER toolbar)
  #msupload-status              (hidden by default)
  #msupload-dropzone            (drag-drop zone, shown if HTML5 drag supported)
  #msupload-list                (file list <ul>)
  #msupload-bottom              (action buttons - upload, clean, insert)
    #msupload-files             (upload button link)
    #msupload-clean-all         (clean all link)
    #msupload-insert-gallery    (insert gallery link)
    #msupload-insert-files      (insert files link)
    #msupload-insert-links      (insert links link)
```

**Recommended placement:** Prepend the dropdown to `#msupload-div`, before the dropzone. This makes it always visible (CONTEXT.md: "visible even before files are dropped") and positions it logically as a "setting" for the upload area.

```
#msupload-div
  NEW: .fileperm-msupload-controls  (our dropdown + label)
  #msupload-status
  #msupload-dropzone
  ...
```

### Pattern 4: Timing -- Waiting for MsUpload Initialization
**What:** Ensuring our code runs after MsUpload has created its uploader and DOM
**When to use:** Always -- race condition prevention

MsUpload initializes via `mw.hook('wikiEditor.toolbarReady').add(MsUpload.createUploader)` (line 568). The `mw.hook` system is memorized -- if a handler is added after the hook has fired, it runs immediately. This means:

```javascript
// This works even if MsUpload has already initialized:
mw.hook( 'wikiEditor.toolbarReady' ).add( function () {
    // At this point, MsUpload.createUploader has already run
    // MsUpload.uploader exists and has been initialized
    // #msupload-div exists in the DOM
    initFilePermDropdown();
} );
```

However, there is a subtlety: `mw.hook` handlers run in `.add()` order, and MsUpload registers its handler from within the `ext.MsUpload` module. Our module must ensure `ext.MsUpload` has loaded first. Since we cannot declare `ext.MsUpload` as a hard dependency (it might not exist), we must use a polling/detection approach or rely on the memorized hook behavior.

**Recommended approach:** Use `mw.loader.using('ext.MsUpload')` wrapped in a state check:

```javascript
// Only attempt if MsUpload module is registered (extension is installed)
if ( mw.loader.getState( 'ext.MsUpload' ) !== null ) {
    mw.loader.using( 'ext.MsUpload' ).then( function () {
        mw.hook( 'wikiEditor.toolbarReady' ).add( initFilePermDropdown );
    } );
}
```

**But since we only load this module when MsUpload is detected server-side**, the simpler approach works:

```javascript
// Module is only loaded when MsUpload is installed (server-side check)
// ext.MsUpload is already being loaded by MsUpload's Hooks.php
// mw.hook is memorized, so our handler will fire after MsUpload's
mw.hook( 'wikiEditor.toolbarReady' ).add( function () {
    // Check MsUpload.uploader exists (defensive)
    if ( typeof MsUpload === 'undefined' || !MsUpload.uploader ) {
        return;
    }
    initFilePermDropdown();
} );
```

### Anti-Patterns to Avoid
- **Forking MsUpload:** Never modify MsUpload's code. The entire phase is a JS bridge.
- **Using setOption('multipart_params', ...) to SET params:** MsUpload's `onBeforeUpload` calls `uploader.setOption('multipart_params', {...})` which REPLACES the entire object. If our code also calls `setOption`, it would overwrite MsUpload's params. Instead, directly modify `uploader.settings.multipart_params` after MsUpload has set it.
- **Hard-coding dependency on ext.MsUpload in extension.json:** The module must have no hard dependency. Use server-side conditional loading.
- **Polling for DOM elements:** Don't use `setInterval` to wait for `#msupload-div`. Use `mw.hook('wikiEditor.toolbarReady')` which is the guaranteed timing mechanism.
- **Adding wpFilePermLevel to the API module's allowed params:** The MW API module for `action=upload` is core code. We do not modify it. Extra multipart_params are ignored by the API module but are still accessible via `RequestContext::getMain()->getRequest()->getVal()`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Permission level dropdown | Custom dropdown from scratch | Plain HTML `<select>` element (NOT OOUI) | MsUpload's UI is plain DOM/jQuery, not OOUI. A native select matches visually. |
| Upload parameter injection | Custom XHR/fetch upload | plupload `BeforeUpload` event + `multipart_params` | MsUpload uses plupload; hook into its event system |
| MsUpload detection | Feature detection via DOM queries | `ExtensionRegistry::isLoaded('MsUpload')` (server) | Reliable, does not depend on timing or DOM state |
| Toolbar ready timing | `setInterval` or `MutationObserver` | `mw.hook('wikiEditor.toolbarReady')` | MW native hook system, memorized execution |
| CSRF token | Manual cookie/meta extraction | `mw.user.tokens.get('csrfToken')` | Standard MW approach, already used by MsUpload |
| Error notifications | Custom toast/banner | `mw.notify()` | Standard MW notification, already used in Phase 4 |
| Namespace ID detection | URL parsing | `mw.config.get('wgNamespaceNumber')` | Standard MW config var, always available |

**Key insight:** The entire integration is about hooking into existing systems (plupload events, MW hooks, MW config vars), not building new upload infrastructure.

## Common Pitfalls

### Pitfall 1: MsUpload's setOption Overwrites multipart_params
**What goes wrong:** Our `BeforeUpload` handler calls `uploader.setOption('multipart_params', {wpFilePermLevel: level})` which obliterates MsUpload's params (action, filename, token, comment, ignorewarnings).
**Why it happens:** `setOption()` replaces the entire value. MsUpload itself uses `setOption()` in its `onBeforeUpload` (line 430).
**How to avoid:** After MsUpload's handler runs (our handler runs second due to bind order), read the current `uploader.settings.multipart_params` object and add our field to it, or assign directly to the property.
**Warning signs:** Uploads fail with "no action specified" or "no token" errors.

### Pitfall 2: Race Condition -- Our Code Runs Before MsUpload Initializes
**What goes wrong:** Our module tries to access `MsUpload.uploader` before `MsUpload.createUploader()` has run, getting `undefined`.
**Why it happens:** ResourceLoader module execution order is not guaranteed unless dependencies are declared.
**How to avoid:** Use `mw.hook('wikiEditor.toolbarReady')` which is memorized. Even if our handler registers after MsUpload's, it will fire after MsUpload's handler has completed. Additionally, add a defensive `typeof MsUpload !== 'undefined'` check.
**Warning signs:** "Cannot read property 'bind' of null" errors in console.

### Pitfall 3: UploadVerifyUpload Rejects API Uploads Missing Permission Level
**What goes wrong:** The existing `UploadVerifyUpload` hook requires `wpFilePermLevel` and returns an error if it is empty. This blocks ALL uploads through any path that does not include this parameter (e.g., MsUpload before our bridge loads, API uploads from bots, other extensions).
**Why it happens:** Phase 3's `onUploadVerifyUpload` treats missing level as an error.
**How to avoid:** The existing hook must be reviewed -- it may need to be made tolerant of missing `wpFilePermLevel` for API uploads, OR the bridge must ensure the param is always present. **This is a critical finding: if bots or other API consumers upload files without `wpFilePermLevel`, uploads will fail.** The planner should address this -- likely by making the validation conditional (only enforce on Special:Upload form submissions, or apply namespace default when param is missing).
**Warning signs:** Bot uploads fail; MsUpload uploads without the bridge fail; any API upload fails.

### Pitfall 4: Handler Bind Order Is Not Guaranteed Across Modules
**What goes wrong:** We assume our `BeforeUpload` handler fires after MsUpload's, but if both register in the same `wikiEditor.toolbarReady` callback chain, the order depends on module load order.
**Why it happens:** `mw.hook` fires handlers in `.add()` order. If our module registers its `wikiEditor.toolbarReady` handler before MsUpload does, our handler fires first, before `MsUpload.uploader` exists.
**How to avoid:** Our handler should be registered inside the `wikiEditor.toolbarReady` callback, and should check that `MsUpload.uploader` exists before binding. Since our module is only loaded when MsUpload is installed, and MsUpload's PHP hook (`EditPage::showEditForm:initial`) adds its module first, MsUpload's handler registers first.
**Warning signs:** `MsUpload.uploader` is null when our handler runs.

### Pitfall 5: Dropdown Not Reflecting Correct Namespace Default
**What goes wrong:** The dropdown defaults to the global default instead of the page's namespace default.
**Why it happens:** Developer uses `mw.config.get('wgFilePermDefaultLevel')` which is the global default, not namespace-aware.
**How to avoid:** Server-side code must call `Config::resolveDefaultLevel()` with the current page's namespace ID and pass the resolved value to JS config vars.
**Warning signs:** Files uploaded from a Category page get the wrong default permission.

### Pitfall 6: Error State When Permission Levels Cannot Load
**What goes wrong:** If the JS config vars for permission levels are missing or empty, the dropdown renders empty with no error indication.
**Why it happens:** Server-side hook might not fire, or config is invalid.
**How to avoid:** Per CONTEXT.md decision: "If permission levels can't be loaded during initialization: show error state in dropdown area." Check that `mw.config.get('wgFilePermLevels')` is a non-empty array; if not, show an error message instead of the dropdown.
**Warning signs:** Empty dropdown appears with no options.

## Code Examples

### Example 1: Server-Side Conditional Module Loading (PHP)
```php
// Source: Direct source code analysis of MsUpload Hooks.php + MW ExtensionRegistry docs
// In DisplayHooks.php or a new MsUploadHooks.php handler

use MediaWiki\Registration\ExtensionRegistry;
use FilePermissions\Config;

// In onBeforePageDisplay or a new EditPage::showEditForm:initial handler:
public function onBeforePageDisplay( $out, $skin ): void {
    // ... existing File page logic ...

    // MsUpload bridge: only load when MsUpload is installed and page is editable
    if ( ExtensionRegistry::getInstance()->isLoaded( 'MsUpload' ) ) {
        $ns = $out->getTitle()->getNamespace();
        $out->addModules( [ 'ext.FilePermissions.msupload' ] );
        $out->addJsConfigVars( [
            'wgFilePermLevels' => Config::getLevels(),
            'wgFilePermMsUploadDefault' => Config::resolveDefaultLevel( $ns ),
        ] );
    }
}
```

### Example 2: Bridge Module JavaScript (Core Pattern)
```javascript
// Source: MsUpload.js source analysis + plupload docs
// ext.FilePermissions.msupload.js

( function () {
    'use strict';

    var levels = mw.config.get( 'wgFilePermLevels' );
    var defaultLevel = mw.config.get( 'wgFilePermMsUploadDefault' );

    // Guard: levels must be available
    if ( !levels || !levels.length ) {
        // Show error state when dropdown is injected
        mw.hook( 'wikiEditor.toolbarReady' ).add( function () {
            var $div = $( '#msupload-div' );
            if ( $div.length ) {
                $div.prepend(
                    $( '<div>' )
                        .addClass( 'fileperm-msupload-error' )
                        .text( mw.msg( 'filepermissions-msupload-error-nolevels' ) )
                );
            }
        } );
        return;
    }

    function buildDropdown() {
        var $container = $( '<div>' )
            .addClass( 'fileperm-msupload-controls' )
            .attr( 'id', 'fileperm-msupload-controls' );

        var $label = $( '<label>' )
            .attr( 'for', 'fileperm-msupload-select' )
            .text( mw.msg( 'filepermissions-msupload-label' ) );

        var $select = $( '<select>' )
            .attr( { id: 'fileperm-msupload-select', name: 'fileperm-msupload-select' } );

        for ( var i = 0; i < levels.length; i++ ) {
            var $option = $( '<option>' )
                .val( levels[ i ] )
                .text( levels[ i ] );
            if ( levels[ i ] === defaultLevel ) {
                $option.prop( 'selected', true );
            }
            $select.append( $option );
        }

        // If no default matched, select the first option
        if ( defaultLevel === null && levels.length > 0 ) {
            $select.children().first().prop( 'selected', true );
        }

        $container.append( $label, $select );
        return $container;
    }

    function getSelectedLevel() {
        return $( '#fileperm-msupload-select' ).val();
    }

    function onBeforeUpload( uploader /*, file */ ) {
        // MsUpload.onBeforeUpload has already run (it bound first)
        // and called setOption('multipart_params', {...})
        // We add our field to the existing params object
        var params = uploader.settings.multipart_params || {};
        params.wpFilePermLevel = getSelectedLevel();
        uploader.settings.multipart_params = params;
    }

    mw.hook( 'wikiEditor.toolbarReady' ).add( function () {
        // Defensive: ensure MsUpload initialized
        if ( typeof MsUpload === 'undefined' || !MsUpload.uploader ) {
            return;
        }

        // Inject dropdown into the MsUpload area
        var $msDiv = $( '#msupload-div' );
        if ( !$msDiv.length ) {
            return;
        }

        $msDiv.prepend( buildDropdown() );

        // Bind to plupload BeforeUpload to inject permission level
        MsUpload.uploader.bind( 'BeforeUpload', onBeforeUpload );
    } );
}() );
```

### Example 3: ResourceModule Registration in extension.json
```json
{
    "ext.FilePermissions.msupload": {
        "localBasePath": "modules",
        "remoteExtPath": "FilePermissions/modules",
        "packageFiles": [
            "ext.FilePermissions.msupload.js"
        ],
        "styles": [
            "ext.FilePermissions.msupload.css"
        ],
        "dependencies": [
            "mediawiki.api"
        ],
        "messages": [
            "filepermissions-msupload-label",
            "filepermissions-msupload-error-nolevels",
            "filepermissions-msupload-error-save"
        ]
    }
}
```

Note: `ext.MsUpload` is deliberately NOT listed as a dependency. The module is only loaded server-side when MsUpload is installed, and it accesses the `MsUpload` global at runtime after `wikiEditor.toolbarReady` fires.

### Example 4: Error Notification on Permission Save Failure
```javascript
// Source: Pattern from existing ext.FilePermissions.edit.js
// After upload completes, MsUpload's onFileUploaded fires.
// We can bind to plupload's FileUploaded to check if permission was saved.
//
// However, the permission is saved server-side by UploadComplete hook,
// not by a separate API call. So there is no client-side failure to detect
// unless the UploadComplete hook itself fails.
//
// The CONTEXT.md decision says: "If permission save fails but file upload
// succeeds: show error notification to user."
//
// This requires the server to signal the failure. Options:
// 1. The UploadComplete hook currently silently ignores failures (returns true).
//    It could be modified to add a warning to the API response.
// 2. A post-upload verification API call from the client.
//
// Recommendation: After each file upload completes, make a lightweight API
// call to verify the permission was saved. If not, show mw.notify error.
```

### Example 5: MsUpload's BeforeUpload Handler (Reference)
```javascript
// Source: MsUpload.js lines 420-438 (verbatim from source review)
// This is what MsUpload does -- our handler runs AFTER this:
onBeforeUpload: function ( uploader, file ) {
    let editComment = MsUpload.editComment || mw.message( 'msu-comment' ).plain();
    file.li.title.text( file.name ).show();
    $( '#' + file.id + ' .file-name-input' ).hide();
    $( '#' + file.id + ' .file-extension' ).hide();
    if ( file.cat && mw.config.get( 'wgCanonicalNamespace' ) === 'Category' ) {
        editComment += '\n\n[[' + mw.config.get( 'wgPageName' ) + ']]';
    }
    uploader.setOption( 'multipart_params', {
        format: 'json',
        action: 'upload',
        filename: file.name,
        ignorewarnings: true,
        comment: editComment,
        token: mw.user.tokens.get( 'csrfToken' )
    } ); // This REPLACES multipart_params entirely
    $( '#' + file.id + ' .file-progress-state' ).text( '0%' );
},
```

## MsUpload Source Code Analysis

### Key Findings from MsUpload v14.2 Source Review

**File:** `resources/MsUpload.js` (567 lines)
**Architecture:** Single global `MsUpload` object with methods, wrapping plupload

| Finding | Location | Confidence |
|---------|----------|------------|
| `MsUpload` is a global const object | Line 2: `const MsUpload = {` | HIGH |
| `MsUpload.uploader` holds the plupload instance | Line 49: `MsUpload.uploader = new plupload.Uploader({...})` | HIGH |
| Uploader URL is MW API | Line 55: `url: mw.config.get('wgScriptPath') + '/api.php'` | HIGH |
| BeforeUpload sets multipart_params via setOption (REPLACES) | Lines 430-437: `uploader.setOption('multipart_params', {...})` | HIGH |
| Params include: format, action, filename, ignorewarnings, comment, token | Lines 431-436 | HIGH |
| Entry point is wikiEditor.toolbarReady hook | Line 568: `mw.hook('wikiEditor.toolbarReady').add(MsUpload.createUploader)` | HIGH |
| DOM: #msupload-div placed after #wikiEditor-ui-toolbar | Line 45: `$('#wikiEditor-ui-toolbar').after($uploadDiv)` | HIGH |
| DOM: #msupload-bottom contains action buttons | Lines 43-44 | HIGH |
| DOM: #msupload-dropzone shows drag-drop zone | Line 358 | HIGH |
| No custom events/hooks exposed by MsUpload | Full source review | HIGH |
| MsUpload does NOT expose any plugin/extension API | Full source review | HIGH |
| plupload events available: PostInit, FilesAdded, QueueChanged, StateChanged, FilesRemoved, BeforeUpload, UploadProgress, Error, FileUploaded, CheckFiles, UploadComplete | Lines 59-69 | HIGH |
| Upload start triggered by #msupload-files click handler | Lines 72-86: `MsUpload.uploader.start()` | HIGH |

### Plupload Event Flow
```
1. PostInit          -- plupload ready (MsUpload shows dropzone)
2. FilesAdded        -- user drops/selects files (MsUpload builds file list UI)
3. QueueChanged      -- queue updated
4. CheckFiles        -- MsUpload checks file count, shows/hides buttons
5. [User clicks Upload button]
6. StateChanged      -- upload started
7. BeforeUpload      -- PER FILE: MsUpload sets multipart_params (action, filename, token, etc.)
                        >>> OUR HOOK: add wpFilePermLevel to multipart_params <<<
8. UploadProgress    -- PER FILE: percentage updates
9. FileUploaded      -- PER FILE: success/error handling
10. UploadComplete   -- all files done (plupload event, NOT MW hook)
```

### MsUpload PHP Hook
**File:** `includes/Hooks.php`
**Hook:** `EditPage::showEditForm:initial`
**What it does:** Adds JS config vars (`msuConfig`), loads `ext.MsUpload` module, loads plupload script. Only runs on editable wikitext pages (not special pages, not non-wikitext content models).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| plupload Flash/Silverlight runtimes | HTML5 runtime only (practically) | plupload 2.x | MsUpload still configures `runtimes: 'html5,html4'` but html5 is used by all modern browsers |
| `mw.hook` not memorized | `mw.hook` memorized (like Deferred) | MW 1.22+ | Safe to register handlers after hook has fired |
| MsUpload used custom upload path | MsUpload uses MW API `action=upload` | MsUpload recent versions | Our parameter injection via multipart_params works |

**Deprecated/outdated:**
- plupload Flash/Silverlight runtimes: Still configured but never used in modern browsers. Not relevant to our integration.

## Critical Integration Architecture

### How Parameter Flow Works (End-to-End)

```
Browser (MsUpload + our bridge):
  1. User selects permission level in dropdown
  2. User drops files, clicks Upload
  3. Per file: plupload fires BeforeUpload
     a. MsUpload.onBeforeUpload sets: {action:'upload', filename, token, comment, ...}
     b. Our onBeforeUpload adds: wpFilePermLevel to same params object
  4. plupload sends multipart/form-data POST to /api.php
     POST body includes: action=upload, filename=..., token=..., wpFilePermLevel=...

Server (MediaWiki API + our hooks):
  5. api.php processes action=upload (ignores wpFilePermLevel -- not a known param)
  6. UploadVerifyUpload hook fires:
     - UploadHooks reads RequestContext::getMain()->getRequest()->getVal('wpFilePermLevel')
     - Validates level is non-empty and valid
  7. File is stored
  8. UploadComplete hook fires:
     - UploadHooks reads wpFilePermLevel from request
     - DeferredUpdates stores level in PageProps
```

### Server-Side Validation Concern

**CRITICAL:** The current `UploadVerifyUpload` implementation in `UploadHooks.php` (lines 87-108) returns an error if `wpFilePermLevel` is null or empty:

```php
$level = RequestContext::getMain()->getRequest()->getVal( 'wpFilePermLevel' );
if ( $level === null || $level === '' ) {
    $error = [ 'filepermissions-upload-required' ];
    return false;
}
```

This blocks ANY upload that does not include `wpFilePermLevel`, including:
- API uploads from bots
- Uploads from other extensions
- MsUpload uploads before our bridge module loads

**Recommended fix:** Make validation context-aware. Either:
1. Check if the upload came from Special:Upload (where mandatory selection makes sense) vs API (where a default should apply)
2. Apply the namespace default when the parameter is missing from API uploads
3. Only reject when the upload is from the Special:Upload form (check for a different indicator like `wpUploadFile` being present)

## Open Questions

1. **UploadVerifyUpload blocking API uploads without wpFilePermLevel**
   - What we know: The current hook rejects uploads missing this parameter. MsUpload uses API uploads. Bots use API uploads.
   - What's unclear: Whether the project intends to require permission level for ALL upload paths or only Special:Upload
   - Recommendation: Make the hook apply namespace/global default when `wpFilePermLevel` is absent in API uploads, rather than rejecting. This aligns with CONTEXT.md decision "Upload without selection uses namespace/global default."

2. **Post-upload permission verification on client**
   - What we know: CONTEXT.md says "If permission save fails but file upload succeeds: show error notification"
   - What's unclear: The UploadComplete hook runs server-side and currently silently ignores failures (returns true). There is no mechanism to signal a permission save failure back to the MsUpload client.
   - Recommendation: After each file upload, the bridge JS could make a lightweight API query (`action=query` with `prop=pageprops`) to verify the permission was stored. If not, show `mw.notify()` error. This is a small overhead (one extra API call per file) but provides the required feedback.

3. **Dropdown behavior during upload**
   - What we know: CONTEXT.md says "Dropdown is editable anytime before upload starts" and "Permission is read once at upload time"
   - What's unclear: Should the dropdown be disabled once upload starts? MsUpload does not expose a clean "upload started" event to external code.
   - Recommendation: Bind to plupload's `StateChanged` event. When `uploader.state === plupload.STARTED`, disable the select. When `uploader.state === plupload.STOPPED`, re-enable.

4. **Which PHP hook to use for loading the bridge module**
   - What we know: MsUpload uses `EditPage::showEditForm:initial`. FilePermissions currently uses `BeforePageDisplay`.
   - What's unclear: Whether `BeforePageDisplay` fires on edit pages and can detect MsUpload, or whether we need `EditPage::showEditForm:initial`.
   - Recommendation: Use `EditPage::showEditForm:initial` to match MsUpload's pattern. This hook fires on edit pages where MsUpload is active. `BeforePageDisplay` fires on ALL pages, making the check less targeted. A new hook handler class (or method in DisplayHooks) registered for this hook is cleaner.

## Sources

### Primary (HIGH confidence)
- MsUpload v14.2 source code: `resources/MsUpload.js` (567 lines, full review) -- cloned from https://github.com/wikimedia/mediawiki-extensions-MsUpload
- MsUpload v14.2 source code: `includes/Hooks.php` (59 lines, full review)
- MsUpload v14.2 source code: `extension.json` (configuration and module registration)
- MsUpload v14.2 source code: `resources/MsUpload.less` (CSS structure analysis)
- FilePermissions source code: `includes/Hooks/UploadHooks.php` (server-side upload handling)
- FilePermissions source code: `modules/ext.FilePermissions.edit.js` (existing JS patterns)
- FilePermissions source code: `extension.json` (module registration patterns)

### Secondary (MEDIUM confidence)
- [Plupload Uploader API docs](https://www.plupload.com/docs/v2/Uploader) -- multipart_params, setOption, event system
- [MediaWiki ExtensionRegistry::isLoaded](https://www.mediawiki.org/wiki/Manual:Extension_registration) -- conditional extension detection
- [MediaWiki ResourceLoader module loading](https://www.mediawiki.org/wiki/ResourceLoader/Developing_with_ResourceLoader) -- conditional dependency patterns
- [MediaWiki API:Upload](https://www.mediawiki.org/wiki/API:Upload) -- upload API parameter handling
- [MediaWiki UploadComplete hook](https://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete) -- hook behavior during API uploads
- [MediaWiki mw.hook](https://www.mediawiki.org/wiki/Manual:JavaScript_hooks) -- memorized hook behavior

### Tertiary (LOW confidence)
- WebSearch results on plupload BeforeUpload + multipart_params dynamic setting -- verified pattern against MsUpload source code (upgraded to HIGH)
- [Ben Nadel blog on plupload per-file params](https://www.bennadel.com/blog/2506-storing-per-file-multipart-params-in-the-plupload-queue.htm) -- corroborates pattern

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Based on direct MsUpload source code review, no speculation
- Architecture: HIGH - Integration points identified from actual code (line numbers cited)
- Pitfalls: HIGH - Identified from source analysis (setOption replacement, race conditions) and existing code review (UploadVerifyUpload blocking)
- Parameter flow: HIGH - Verified MsUpload sends to /api.php, existing hooks read from RequestContext

**Research date:** 2026-01-29
**Valid until:** 2026-03-01 (MsUpload changes infrequently; last substantive commit was a build dependency update)
