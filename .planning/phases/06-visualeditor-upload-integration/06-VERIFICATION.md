---
phase: 06-visualeditor-upload-integration
verified: 2026-01-29T19:30:00Z
status: passed
score: 5/5 must-haves verified
---

# Phase 6: VisualEditor Upload Integration Verification Report

**Phase Goal:** Users can set permission level when uploading files via VisualEditor
**Verified:** 2026-01-29T19:30:00Z
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Permission dropdown appears in VisualEditor's upload dialog | ✓ VERIFIED | `renderInfoForm` monkey-patch creates `OO.ui.DropdownInputWidget` with levels, appends to info form fieldset (lines 77-111 in visualeditor.js) |
| 2 | Dropdown defaults based on current page namespace | ✓ VERIFIED | `wgFilePermVEDefault` set via `Config::resolveDefaultLevel($ns)` in VisualEditorHooks.php (line 49), dropdown value set to `defaultLevel` (line 92 in visualeditor.js) |
| 3 | Selected permission level is transmitted with upload request | ✓ VERIFIED | XHR `send()` patch intercepts publish-from-stash (action=upload + filekey), appends `wpFilePermLevel` from active dropdown (lines 148-164 in visualeditor.js) |
| 4 | Uploaded file has selected permission level stored in PageProps | ✓ VERIFIED | UploadHooks.php `onUploadComplete` reads `wpFilePermLevel` (line 151), stores via `PermissionService::setLevel()` (line 167) which writes to `page_props` table with `pp_propname='fileperm_level'` |
| 5 | Upload without selection uses namespace/global default | ✓ VERIFIED | `onUploadComplete` resolves default when `wpFilePermLevel` is null/empty via `Config::resolveDefaultLevel(NS_FILE)` (line 155 in UploadHooks.php) |

**Score:** 5/5 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/Hooks/VisualEditorHooks.php` | BeforePageDisplay handler for conditional VE bridge loading | ✓ VERIFIED | EXISTS (52 lines), SUBSTANTIVE (implements BeforePageDisplayHook, ExtensionRegistry check, addModules, addJsConfigVars), WIRED (registered in extension.json HookHandlers, bound to BeforePageDisplay hook) |
| `extension.json` (HookHandler) | visualeditor HookHandler registration | ✓ VERIFIED | EXISTS, SUBSTANTIVE (`"visualeditor": {"class": "FilePermissions\\Hooks\\VisualEditorHooks"}`), WIRED (referenced in Hooks.BeforePageDisplay array) |
| `extension.json` (Hook) | BeforePageDisplay array with both handlers | ✓ VERIFIED | EXISTS, SUBSTANTIVE (`"BeforePageDisplay": ["display", "visualeditor"]`), WIRED (both handlers registered in HookHandlers section) |
| `extension.json` (ResourceModule) | ext.FilePermissions.visualeditor module definition | ✓ VERIFIED | EXISTS, SUBSTANTIVE (packageFiles, styles, dependencies, messages arrays populated), WIRED (referenced by VisualEditorHooks.php addModules call) |
| `i18n/en.json` | VE bridge i18n messages | ✓ VERIFIED | EXISTS, SUBSTANTIVE (3 messages: filepermissions-ve-label, filepermissions-ve-error-nolevels, filepermissions-ve-error-save), WIRED (referenced in extension.json messages array and consumed by visualeditor.js via mw.msg) |
| `modules/ext.FilePermissions.visualeditor.js` | VE bridge: BookletLayout monkey-patch, XHR interception, post-upload verification | ✓ VERIFIED | EXISTS (180 lines), SUBSTANTIVE (complete implementation with 5 monkey-patches, config guards, XHR protocol patching, PageProps verification), WIRED (referenced in extension.json packageFiles, loaded by VisualEditorHooks) |
| `modules/ext.FilePermissions.visualeditor.css` | Dropdown styling within VE upload dialog | ✓ VERIFIED | EXISTS (23 lines), SUBSTANTIVE (3 rulesets for .fileperm-ve-dropdown), WIRED (referenced in extension.json styles array) |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| extension.json | VisualEditorHooks.php | HookHandlers.visualeditor class reference | ✓ WIRED | `"visualeditor": {"class": "FilePermissions\\Hooks\\VisualEditorHooks"}` found in extension.json |
| extension.json | BeforePageDisplay hook | Hook binding array includes visualeditor handler | ✓ WIRED | `"BeforePageDisplay": ["display", "visualeditor"]` found in extension.json Hooks section |
| extension.json | visualeditor.js | ResourceModules packageFiles | ✓ WIRED | `"ext.FilePermissions.visualeditor"` module references `"ext.FilePermissions.visualeditor.js"` in packageFiles array |
| visualeditor.js | BookletLayout.renderInfoForm | Monkey-patch to inject OOUI dropdown | ✓ WIRED | `origRenderInfoForm` stores original, replacement function creates dropdown and appends to form (lines 77-111) |
| visualeditor.js | BookletLayout.clear | Monkey-patch to reset dropdown on dialog reuse | ✓ WIRED | `origClear` stores original, replacement resets dropdown value to default (lines 116-122) |
| visualeditor.js | XMLHttpRequest.prototype.send | XHR interception to append wpFilePermLevel | ✓ WIRED | `origSend` stores original, replacement checks for publish-from-stash (isUpload && hasFilekey) and appends wpFilePermLevel (lines 148-164) |
| visualeditor.js | BookletLayout.saveFile | Monkey-patch for post-upload verification | ✓ WIRED | `origSaveFile` stores original, replacement chains promise with verifyPermission call (lines 170-179) |
| XHR wpFilePermLevel | UploadHooks.onUploadComplete | API POST parameter → PageProps storage | ✓ WIRED | UploadHooks reads wpFilePermLevel from RequestContext (line 151), stores via PermissionService.setLevel() which writes to page_props table (PermissionService.php lines 92-104) |

### Requirements Coverage

Phase 6 is a project extension beyond the initial 27 v1 requirements. No requirements from REQUIREMENTS.md were explicitly mapped to Phase 6 in the ROADMAP. However, Phase 6 fulfills the implicit requirement for complete upload integration coverage across all three MediaWiki upload paths.

**Upload Path Coverage:**
- ✓ Special:Upload form (Phase 3)
- ✓ MsUpload toolbar (Phase 5)
- ✓ VisualEditor dialog (Phase 6)

### Anti-Patterns Found

None. All files checked for common stub patterns (TODO, FIXME, placeholder comments, empty returns, console.log-only implementations). Zero matches found.

### Human Verification Required

**Note:** The following items require manual testing with a live MediaWiki instance with VisualEditor installed.

#### 1. VE Upload Dialog Dropdown Visibility

**Test:** 
1. Navigate to any content page on the wiki
2. Open VisualEditor (Edit button)
3. Click "Insert" → "Media" or use Ctrl+M
4. Switch to "Upload" tab
5. Fill in file selection and title fields
6. Observe the info form panel

**Expected:** Permission level dropdown appears below the title/description fields with label "Permission level:" and options matching configured levels from LocalSettings.php

**Why human:** Visual rendering verification requires actual browser inspection of OOUI widgets in VE dialog context

#### 2. Namespace Default Resolution

**Test:**
1. Edit page in Main namespace (namespace 0)
2. Open VE upload dialog
3. Note dropdown default value
4. Cancel and navigate to a File: page (namespace 6)
5. Open VE upload dialog
6. Compare dropdown default value

**Expected:** 
- If `$wgFilePermNamespaceDefaults` configures different defaults for NS_MAIN vs NS_FILE, dropdown should reflect the page's namespace
- If not configured, global `$wgFilePermDefaultLevel` should be used

**Why human:** Namespace-based configuration requires testing across multiple namespace contexts

#### 3. Upload Completes and Permission Stored

**Test:**
1. Open VE upload dialog
2. Select a test image file
3. Choose permission level "staff" from dropdown
4. Complete upload with title and description
5. After upload completes, navigate to the uploaded File: page
6. Check "Permission level" field in the info box

**Expected:** File page displays "Permission level: staff" in the right sidebar info box (added by DisplayHooks)

**Why human:** End-to-end integration test requires file upload, page refresh, and visual verification of display

#### 4. Foreign Upload Target Guard

**Test:**
1. Configure MediaWiki to use Commons as foreign upload target (`$wgForeignFileRepos`)
2. Open VE upload dialog
3. Switch upload target to Wikimedia Commons (if dropdown appears)
4. Observe info form panel

**Expected:** Permission level dropdown does NOT appear when target is not 'local' (foreign uploads like Commons don't support custom PageProps)

**Why human:** Foreign upload configuration requires specific MediaWiki setup and target selection

#### 5. Dialog Reuse Resets Dropdown

**Test:**
1. Open VE upload dialog
2. Change permission dropdown from default to different level (e.g., "staff")
3. Click "Cancel" to close dialog WITHOUT uploading
4. Open VE upload dialog again

**Expected:** Dropdown resets to namespace default value (not the previously selected "staff" value)

**Why human:** State persistence across dialog lifecycle requires interaction testing

#### 6. Post-Upload Verification Error

**Test:**
1. Temporarily break UploadHooks (comment out the `setLevel` call in `onUploadComplete`)
2. Upload a file via VE with permission level selected
3. Wait for upload to complete

**Expected:** Persistent error notification appears: "Warning: File uploaded but permission level may not have been saved for '[filename]'. Check the file page."

**Why human:** Requires intentional failure condition and notification visibility verification

**Note:** After testing, restore UploadHooks to working state.

---

## Verification Summary

All automated checks passed. Phase 6 goal **fully achieved** from a code structure perspective:

- All 5 observable truths verified via source code inspection
- All 7 required artifacts exist, are substantive, and are wired correctly
- All 8 key links verified as connected
- Zero anti-patterns (stubs, TODOs, placeholders) found
- Zero syntax errors in PHP, JavaScript, or CSS files
- All monkey-patching patterns present (renderInfoForm, clear, saveFile, XHR open/send)
- Foreign upload guard implemented
- Publish-from-stash detection implemented (filekey guard)
- PageProps verification implemented
- Extension.json valid JSON with all registrations complete

**Human verification recommended** to confirm visual rendering, namespace default resolution, upload completion, and error handling in live browser environment. However, all code-level requirements for the phase goal are satisfied.

---

_Verified: 2026-01-29T19:30:00Z_
_Verifier: Claude (gsd-verifier)_
