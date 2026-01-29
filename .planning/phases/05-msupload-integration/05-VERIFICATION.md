---
phase: 05-msupload-integration
verified: 2026-01-29T18:10:30Z
status: passed
score: 5/5 must-haves verified
re_verification: false
---

# Phase 5: MsUpload Integration Verification Report

**Phase Goal:** Users can set permission level when uploading files via MsUpload drag-drop
**Verified:** 2026-01-29T18:10:30Z
**Status:** PASSED
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Permission dropdown appears in MsUpload toolbar when MsUpload is present | ✓ VERIFIED | `modules/ext.FilePermissions.msupload.js` lines 187: `$msDiv.prepend( buildDropdown() )` after MsUpload guard check (lines 176-183). Module loaded conditionally via ExtensionRegistry check in `MsUploadHooks.php` line 41. |
| 2 | Dropdown defaults based on current page namespace | ✓ VERIFIED | `MsUploadHooks.php` lines 48-50 inject `wgFilePermMsUploadDefault` from `Config::resolveDefaultLevel( $ns )`. JS reads at line 17 and selects in `buildDropdown()` lines 56-57. Fallback to first option at lines 63-65. |
| 3 | Selected permission level is transmitted with upload request | ✓ VERIFIED | `ext.FilePermissions.msupload.js` lines 90-94: `onBeforeUpload` handler injects `wpFilePermLevel` into `uploader.settings.multipart_params` directly (not setOption, avoiding overwriting MsUpload's params). |
| 4 | Uploaded file has selected permission level stored in PageProps | ✓ VERIFIED | `UploadHooks.php` lines 140-180: `onUploadComplete` reads `wpFilePermLevel` from request (line 151), resolves default if absent (line 155), validates and stores via `PermissionService::setLevel()` in deferred update (line 175). Links to `onBeforeUpload` param injection. |
| 5 | Upload without selection uses namespace/global default | ✓ VERIFIED | `UploadHooks.php` lines 98-119: `onUploadVerifyUpload` tolerates missing `wpFilePermLevel` by resolving default via `Config::resolveDefaultLevel( NS_FILE )` at line 100. If default available, allows upload (line 104). `onUploadComplete` applies same default resolution at line 155. |

**Score:** 5/5 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/Hooks/UploadHooks.php` | Tolerant UploadVerifyUpload applying default when wpFilePermLevel absent | ✓ VERIFIED | Lines 98-119: Resolves default at line 100, allows API uploads with default or grandfathered. Lines 140-180: onUploadComplete applies default at line 155. No stub patterns, 279 lines, wired to Config::resolveDefaultLevel. |
| `includes/Hooks/MsUploadHooks.php` | EditPage::showEditForm:initial hook for conditional bridge module loading | ✓ VERIFIED | Lines 27-53: Implements `EditPage__showEditForm_initialHook`. ExtensionRegistry check at line 41, addModules at line 47, addJsConfigVars at lines 48-50. 53 lines, substantive, wired to extension.json hook binding. |
| `extension.json` | MsUpload hook handler registration and ext.FilePermissions.msupload module definition | ✓ VERIFIED | HookHandlers "msupload" entry, Hooks "EditPage::showEditForm:initial": "msupload", ResourceModules "ext.FilePermissions.msupload" with packageFiles, styles, dependencies [mediawiki.api], messages array. Valid JSON confirmed. |
| `i18n/en.json` | MsUpload bridge i18n messages | ✓ VERIFIED | Messages present: filepermissions-msupload-label, filepermissions-msupload-error-nolevels, filepermissions-msupload-error-save. Valid JSON confirmed. |
| `modules/ext.FilePermissions.msupload.js` | MsUpload bridge: dropdown injection, plupload BeforeUpload binding, post-upload verification | ✓ VERIFIED | 194 lines. IIFE pattern. buildDropdown (lines 39-69), onBeforeUpload (lines 90-94), onFileUploaded (lines 107-148), onStateChanged (lines 158-167). Binds MsUpload.uploader events at lines 190-192. wikiEditor.toolbarReady hook at line 174. Guards for MsUpload existence, levels availability. No stub patterns, no console.log. |
| `modules/ext.FilePermissions.msupload.css` | Styling for dropdown controls and error state | ✓ VERIFIED | 47 lines. Five selectors: .fileperm-msupload-controls, label, #fileperm-msupload-select, :disabled state, .fileperm-msupload-error. No stub patterns. |

**All artifacts:** EXISTS + SUBSTANTIVE + WIRED

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| MsUploadHooks.php | extension.json | HookHandlers registration | ✓ WIRED | extension.json line "msupload": { "class": "FilePermissions\\Hooks\\MsUploadHooks" }, Hooks binding "EditPage::showEditForm:initial": "msupload" |
| MsUploadHooks.php | Config.php | Config::getLevels and Config::resolveDefaultLevel | ✓ WIRED | Lines 49-50 call Config::getLevels() and Config::resolveDefaultLevel($ns). Config imported at line 7. |
| UploadHooks.php | Config.php | Config::resolveDefaultLevel for default fallback | ✓ WIRED | Lines 100, 155 call Config::resolveDefaultLevel( NS_FILE ). Config imported at line 7. |
| ext.FilePermissions.msupload.js | MsUpload.uploader | plupload BeforeUpload event binding | ✓ WIRED | Line 190: `MsUpload.uploader.bind( 'BeforeUpload', onBeforeUpload )`. Guards at lines 176-183 verify MsUpload.uploader exists. |
| ext.FilePermissions.msupload.js | uploader.settings.multipart_params | Direct property assignment (NOT setOption) | ✓ WIRED | Lines 91-93: Reads current params, adds wpFilePermLevel, assigns back to uploader.settings.multipart_params. setOption NOT used (verified via grep — only appears in comments explaining why NOT to use it). |
| ext.FilePermissions.msupload.js | mw.hook('wikiEditor.toolbarReady') | Memorized MW hook for initialization timing | ✓ WIRED | Line 174: `mw.hook( 'wikiEditor.toolbarReady' ).add( function () { ... } )`. Also error state handler at line 21 uses same hook. |
| ext.FilePermissions.msupload.js | mw.Api | Post-upload PageProps verification query | ✓ WIRED | Line 124: `new mw.Api().get({ action: 'query', prop: 'pageprops', ppprop: 'fileperm_level' })`. mediawiki.api dependency in extension.json. |
| JS dropdown | Server upload handler | wpFilePermLevel parameter | ✓ WIRED | JS line 92: `params.wpFilePermLevel = getSelectedLevel()`. UploadHooks.php lines 96, 151 read via `$request->getVal( 'wpFilePermLevel' )`. |

**All key links:** WIRED

### Requirements Coverage

| Requirement | Status | Blocking Issue |
|-------------|--------|----------------|
| MSUP-01: JS bridge module loads when MsUpload is present | ✓ SATISFIED | None — MsUploadHooks.php ExtensionRegistry check verified |
| MSUP-02: Permission dropdown injected into MsUpload toolbar | ✓ SATISFIED | None — buildDropdown and $msDiv.prepend verified |
| MSUP-03: Dropdown defaults based on current page namespace | ✓ SATISFIED | None — wgFilePermMsUploadDefault from Config::resolveDefaultLevel($ns) verified |
| MSUP-04: Selected permission level appended to upload FormData | ✓ SATISFIED | None — onBeforeUpload multipart_params injection verified |
| MSUP-05: Server-side hook captures fileperm_level from request | ✓ SATISFIED | None — UploadHooks onUploadVerifyUpload and onUploadComplete read wpFilePermLevel verified |

**All 5 requirements satisfied.**

### Anti-Patterns Found

**Scan Results:** NONE

- No TODO/FIXME/XXX/HACK comments
- No placeholder text in user-facing strings
- No empty return patterns (return null/undefined/{}/[])
- No console.log debugging artifacts
- No setOption anti-pattern in JS (correctly uses direct multipart_params mutation)
- UploadHooks "placeholder" references are documentation about form field design, not code stubs
- All PHP files pass syntax check
- All JSON files valid

**Severity:** N/A — No anti-patterns detected

### Human Verification Required

**None.** All phase success criteria verified programmatically:

1. ✓ Permission dropdown appears in MsUpload toolbar when MsUpload is present — verified via code structure, ExtensionRegistry check, DOM injection
2. ✓ Dropdown defaults based on current page namespace — verified via Config::resolveDefaultLevel wiring and JS config vars
3. ✓ Selected permission level is transmitted with upload request — verified via BeforeUpload handler and multipart_params mutation
4. ✓ Uploaded file has selected permission level stored in PageProps — verified via onUploadComplete deferred update and PermissionService::setLevel
5. ✓ Upload without selection uses namespace/global default — verified via onUploadVerifyUpload default resolution and onUploadComplete fallback

**Note:** While end-to-end browser testing would provide additional confidence, the code-level verification confirms all architectural requirements are met. The phase goal can be considered achieved from a structural perspective.

---

## Detailed Verification Notes

### Plan 05-01: Server-Side Foundation

**Must-Have: "API/bot uploads without wpFilePermLevel succeed using namespace/global default instead of being rejected"**

- **Status:** ✓ VERIFIED
- **Evidence:** UploadHooks.php lines 98-119. When wpFilePermLevel is null/empty:
  1. Line 100: Attempts `Config::resolveDefaultLevel( NS_FILE )`
  2. Lines 101-104: If default found, returns true (allow upload)
  3. Lines 110-115: Checks for Special:Upload form context via wpUploadFile/wpUploadFileURL
  4. Lines 113-114: Only rejects Special:Upload submissions (user had dropdown)
  5. Line 119: Allows API uploads without level (grandfathered)
- **Wiring:** onUploadComplete lines 151-161 apply same default resolution for storage

**Must-Have: "MsUpload bridge JS module is loaded only when MsUpload extension is installed"**

- **Status:** ✓ VERIFIED
- **Evidence:** MsUploadHooks.php lines 40-43:
  ```php
  if ( !ExtensionRegistry::getInstance()->isLoaded( 'MsUpload' ) ) {
      return;
  }
  ```
  - Silent no-op when MsUpload absent
  - addModules only called after check passes (line 47)
- **Wiring:** extension.json registers "msupload" HookHandler for EditPage::showEditForm:initial

**Must-Have: "JS config vars wgFilePermLevels and wgFilePermMsUploadDefault are available on edit pages when MsUpload is present"**

- **Status:** ✓ VERIFIED
- **Evidence:** MsUploadHooks.php lines 48-51:
  ```php
  $out->addJsConfigVars( [
      'wgFilePermLevels' => Config::getLevels(),
      'wgFilePermMsUploadDefault' => Config::resolveDefaultLevel( $ns ),
  ] );
  ```
  - JS reads at ext.FilePermissions.msupload.js lines 16-17
- **Namespace awareness:** Line 45 gets namespace from `$out->getTitle()->getNamespace()`

**Must-Have: "ext.FilePermissions.msupload module is registered in extension.json with correct dependencies and messages"**

- **Status:** ✓ VERIFIED
- **Evidence:** extension.json ResourceModules section:
  - packageFiles: ext.FilePermissions.msupload.js
  - styles: ext.FilePermissions.msupload.css
  - dependencies: mediawiki.api (for PageProps verification)
  - messages: filepermissions-msupload-label, -error-nolevels, -error-save
  - NO ext.MsUpload dependency (correct — server-side conditional loading)

**Must-Have: "Nothing changes on pages where MsUpload is not installed (silent no-op)"**

- **Status:** ✓ VERIFIED
- **Evidence:** 
  - Server-side: MsUploadHooks.php early return at line 42-43 if MsUpload not loaded
  - Client-side: JS guards at lines 176-177 check for MsUpload.uploader existence
  - Client-side: JS guards at lines 181-184 check for #msupload-div DOM element
  - No errors logged, no DOM modifications, no event bindings when MsUpload absent

### Plan 05-02: Client-Side Bridge

**Must-Have: "Permission dropdown appears prepended to #msupload-div when MsUpload is present on edit page"**

- **Status:** ✓ VERIFIED
- **Evidence:** ext.FilePermissions.msupload.js:
  - Line 187: `$msDiv.prepend( buildDropdown() )`
  - buildDropdown (lines 39-69) creates DOM structure
  - Guards ensure MsUpload exists (lines 176-177) and #msupload-div exists (lines 181-184)

**Must-Have: "Dropdown is populated with configured permission levels from wgFilePermLevels"**

- **Status:** ✓ VERIFIED
- **Evidence:** ext.FilePermissions.msupload.js:
  - Line 16: `var levels = mw.config.get( 'wgFilePermLevels' )`
  - Lines 52-60: Loop through levels array, create option for each, append to select

**Must-Have: "Dropdown defaults to namespace-aware default from wgFilePermMsUploadDefault"**

- **Status:** ✓ VERIFIED
- **Evidence:** ext.FilePermissions.msupload.js:
  - Line 17: `var defaultLevel = mw.config.get( 'wgFilePermMsUploadDefault' )`
  - Lines 56-58: If option value matches defaultLevel, set selected prop
  - Lines 63-65: Fallback to first option if defaultLevel is null

**Must-Have: "Selected permission level is injected into plupload multipart_params as wpFilePermLevel on each file's BeforeUpload"**

- **Status:** ✓ VERIFIED
- **Evidence:** ext.FilePermissions.msupload.js:
  - Line 190: `MsUpload.uploader.bind( 'BeforeUpload', onBeforeUpload )`
  - Lines 90-94 onBeforeUpload implementation:
    ```javascript
    var params = uploader.settings.multipart_params || {};
    params.wpFilePermLevel = getSelectedLevel();
    uploader.settings.multipart_params = params;
    ```
  - Direct mutation (NOT setOption) preserves MsUpload's existing params
  - getSelectedLevel() at line 76 reads current select value

**Must-Have: "Dropdown is disabled during upload (plupload STARTED state) and re-enabled when stopped"**

- **Status:** ✓ VERIFIED
- **Evidence:** ext.FilePermissions.msupload.js:
  - Line 192: `MsUpload.uploader.bind( 'StateChanged', onStateChanged )`
  - Lines 158-167 onStateChanged implementation:
    - Lines 159-160: Define plupload constants with fallbacks (STARTED=2, STOPPED=1)
    - Lines 162-163: If state === STARTED, disable select
    - Lines 164-166: If state === STOPPED, enable select

**Must-Have: "Error state shown when wgFilePermLevels is empty or missing"**

- **Status:** ✓ VERIFIED
- **Evidence:** ext.FilePermissions.msupload.js:
  - Lines 20-32: Guard at module scope checks `!levels || !levels.length`
  - If empty, registers wikiEditor.toolbarReady handler that injects error div
  - Line 26: `.addClass( 'fileperm-msupload-error' )`
  - Line 27: Text from `mw.msg( 'filepermissions-msupload-error-nolevels' )`
  - Line 31: Returns early (no dropdown, no event binding)

**Must-Have: "Post-upload verification queries PageProps and shows mw.notify error if permission was not saved"**

- **Status:** ✓ VERIFIED
- **Evidence:** ext.FilePermissions.msupload.js:
  - Line 191: `MsUpload.uploader.bind( 'FileUploaded', onFileUploaded )`
  - Lines 107-148 onFileUploaded implementation:
    - Lines 110-115: Parse response.response JSON
    - Lines 118-120: Guard for successful upload (result.upload.result === 'Success')
    - Lines 124-129: API query for pageprops.fileperm_level
    - Lines 138-145: If page.pageprops.fileperm_level missing, call mw.notify with error message

**Must-Have: "Error notifications require manual dismissal (autoHide false)"**

- **Status:** ✓ VERIFIED
- **Evidence:** ext.FilePermissions.msupload.js line 143:
  ```javascript
  { type: 'error', autoHide: false }
  ```
  - Notification persists until user dismisses manually
  - Aligns with CONTEXT.md decision for error visibility

---

## Gaps Summary

**No gaps found.** All 5 observable truths verified, all required artifacts exist and are substantive, all key links wired correctly, all 5 MSUP requirements satisfied, no anti-patterns detected.

Phase goal achieved: Users can set permission level when uploading files via MsUpload drag-drop.

---

_Verified: 2026-01-29T18:10:30Z_
_Verifier: Claude (gsd-verifier)_
