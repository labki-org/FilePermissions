---
phase: 06-visualeditor-upload-integration
plan: 02
subsystem: client-bridge
tags: [visualeditor, javascript, css, ooui, xhr-interception, monkey-patching]
depends_on:
  requires: [06-01-ve-server-side-foundation, 05-02-msupload-bridge-client]
  provides: [ve-upload-bridge, ve-permission-dropdown, ve-xhr-interception]
  affects: []
tech_stack:
  added: []
  patterns: [ooui-dropdown-injection, bookletlayout-monkey-patching, xhr-prototype-patching, publish-from-stash-interception]
key_files:
  created:
    - modules/ext.FilePermissions.visualeditor.js
    - modules/ext.FilePermissions.visualeditor.css
  modified: []
decisions:
  - "Module-level activeDropdown variable for XHR interceptor (not DOM query)"
  - "saveFile monkey-patch for post-upload verification (not XHR response listener)"
  - "OOUI DropdownInputWidget with FieldLayout for VE dialog consistency"
  - "hasFilekey guard to distinguish publish-from-stash from stash upload"
metrics:
  duration: "~2 min"
  completed: "2026-01-29"
---

# Phase 6 Plan 2: VE Bridge Client Module Summary

**One-liner:** OOUI permission dropdown injected into VE upload dialog via BookletLayout monkey-patching, with XHR publish-from-stash interception and PageProps verification.

## What Was Done

### Task 1: Create ext.FilePermissions.visualeditor.js bridge module
**Commit:** `b67c222`

Created the VisualEditor bridge JavaScript module (180 lines) with four major components:

**Part 0 -- Guard and config:**
- Reads `wgFilePermLevels` and `wgFilePermVEDefault` from `mw.config`
- Early return if no levels configured (silent no-op)
- Module-level `activeDropdown` variable for XHR interceptor access

**Part 1 -- BookletLayout monkey-patching:**
- `renderInfoForm` patch: Calls original, then creates `OO.ui.DropdownInputWidget` with permission levels. Wraps in `OO.ui.FieldLayout` with label from `filepermissions-ve-label` message. Appends to existing fieldset in info form panel. Sets module-level `activeDropdown` reference.
- Foreign upload guard: If `this.upload.target !== 'local'`, returns form unchanged (no dropdown for Commons uploads).
- `clear` patch: Resets dropdown value to `defaultLevel || levels[0]` when dialog is reused.

**Part 2 -- XHR prototype patching:**
- `XMLHttpRequest.prototype.open` patch: Tags API POST requests with `_filePermIsApiPost` flag.
- `XMLHttpRequest.prototype.send` patch: On FormData with `action=upload` AND `filekey` present (publish-from-stash), appends `wpFilePermLevel` if not already present. The `hasFilekey` guard is critical -- it prevents injection during the stash phase where `fieldsAllowed` would strip the parameter.
- Coexists with MsUpload bridge XHR patches via standard monkey-patching chain.

**Part 3 -- Post-upload verification:**
- `saveFile` monkey-patch: Calls original, then on success queries PageProps API for `fileperm_level`. Shows persistent `mw.notify` error if permission not found.
- 1-second delay before verification to allow DeferredUpdates to store permission.

### Task 2: Create ext.FilePermissions.visualeditor.css styling
**Commit:** `b7e593f`

Created minimal CSS (23 lines) for the OOUI dropdown within VE's dialog:
- `.fileperm-ve-dropdown`: `margin-top: 0.5em` spacing, `max-width: 300px` width constraint
- Inner `.oo-ui-dropdownInputWidget`: full width within container
- Disabled state: `opacity: 0.6` for visual upload feedback

Intentionally minimal -- OOUI widgets inherit extensive built-in styling from VE's theme. Over-styling would clash.

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| Module-level activeDropdown variable | XHR interceptor cannot query DOM (dropdown may not be visible during publish); module variable is reliable |
| saveFile monkey-patch for verification | Cleaner than XHR response listener; saveFile returns a promise chain, making post-upload action natural |
| OOUI DropdownInputWidget with FieldLayout | VE dialog is entirely OOUI; plain HTML select would break visual consistency |
| hasFilekey guard for publish-from-stash | Distinguishes VE's two-phase upload (stash then publish); wpFilePermLevel must only be in publish phase |

## Deviations from Plan

None -- plan executed exactly as written.

## Verification Results

| Check | Result |
|-------|--------|
| JS syntax check (node --check) | Pass -- no errors |
| renderInfoForm monkey-patch present | Pass (origRenderInfoForm) |
| clear monkey-patch present | Pass (origClear) |
| XHR open/send interception | Pass (origOpen, origSend) |
| publish-from-stash detection (filekey) | Pass (isUpload && hasFilekey) |
| wpFilePermLevel injection | Pass (body.append) |
| PageProps verification (fileperm_level) | Pass (API query + mw.notify) |
| OOUI DropdownInputWidget used | Pass (not plain HTML select) |
| Foreign upload target guard | Pass (this.upload.target !== 'local') |
| CSS .fileperm-ve-dropdown styling | Pass |
| Persistent error notification | Pass (autoHide: false) |

## Phase Completion

This was the final plan (02 of 02) in Phase 6 and the final phase of the project.

**All upload integration paths are now covered:**
- Special:Upload form (Phase 3 -- server-side hooks)
- MsUpload toolbar (Phase 5 -- plupload XHR bridge)
- VisualEditor dialog (Phase 6 -- BookletLayout monkey-patch + publish-from-stash XHR bridge)

**Project is complete.** All 12 plans across 6 phases have been executed.
