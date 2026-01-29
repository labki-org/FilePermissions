---
phase: 06-visualeditor-upload-integration
plan: 01
subsystem: hooks-integration
tags: [visualeditor, hooks, resourceloader, i18n, conditional-loading]
depends_on:
  requires: [01-config, 03-upload-workflow, 05-msupload-integration]
  provides: [ve-hook-handler, ve-resource-module, ve-i18n-messages]
  affects: [06-02-ve-bridge-client]
tech_stack:
  added: []
  patterns: [conditional-extension-loading, BeforePageDisplay-multi-handler]
key_files:
  created:
    - includes/Hooks/VisualEditorHooks.php
  modified:
    - extension.json
    - i18n/en.json
decisions:
  - "BeforePageDisplayHook (not EditPage hook) because VE opens on any content page"
  - "Array format for BeforePageDisplay hook to support both display and visualeditor handlers"
  - "mediawiki.ForeignStructuredUpload.BookletLayout as hard RL dependency (core module, always available)"
  - "No hard dependency on ext.visualEditor modules — conditional loading via ExtensionRegistry only"
  - "VE-specific config var wgFilePermVEDefault (distinct from wgFilePermMsUploadDefault)"
metrics:
  duration: "~2 min"
  completed: "2026-01-29"
---

# Phase 6 Plan 1: VE Server-Side Foundation Summary

**One-liner:** BeforePageDisplay hook handler with conditional VE bridge loading, ResourceLoader module registration, and i18n messages.

## What Was Done

### Task 1: Create VisualEditorHooks.php and register in extension.json
**Commit:** `6778171`

Created `VisualEditorHooks.php` following the established MsUploadHooks pattern with key differences appropriate for VisualEditor:

- **Hook interface:** `BeforePageDisplayHook` (not `EditPage__showEditForm_initialHook`) because VisualEditor can be opened on any content page, not just edit form pages
- **Extension check:** `ExtensionRegistry::getInstance()->isLoaded('VisualEditor')` for conditional loading
- **JS config vars:** `wgFilePermLevels` (permission levels array) and `wgFilePermVEDefault` (namespace-resolved default)
- **Module:** Loads `ext.FilePermissions.visualeditor` when VE is installed

Updated `extension.json` with three registrations:
1. **HookHandler** `"visualeditor"` pointing to `FilePermissions\Hooks\VisualEditorHooks`
2. **Hook binding** `"BeforePageDisplay"` changed from string `"display"` to array `["display", "visualeditor"]`
3. **ResourceModule** `"ext.FilePermissions.visualeditor"` with:
   - `packageFiles`: `ext.FilePermissions.visualeditor.js`
   - `styles`: `ext.FilePermissions.visualeditor.css`
   - `dependencies`: `mediawiki.api`, `oojs-ui-core`, `mediawiki.ForeignStructuredUpload.BookletLayout`
   - `messages`: three VE-specific i18n keys

### Task 2: Add VE bridge i18n messages
**Commit:** `37dede6`

Added three i18n messages to `i18n/en.json` following the MsUpload naming pattern:
- `filepermissions-ve-label`: "Permission level:" (dropdown label)
- `filepermissions-ve-error-nolevels`: Error for config load failure
- `filepermissions-ve-error-save`: Warning for post-upload save failure

Messages mirror MsUpload text exactly. VE-specific prefix (`ve`) allows future differentiation.

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| BeforePageDisplayHook (not EditPage hook) | VE can be opened on any content page via surface switching, not just edit form pages |
| Array format for BeforePageDisplay | Both DisplayHooks and VisualEditorHooks need to handle the same hook |
| ForeignStructuredUpload.BookletLayout as hard RL dep | Core MW module, always available when VE is installed; ensures bridge code runs after BookletLayout is defined |
| No ext.visualEditor RL dependency | Server-side ExtensionRegistry check handles conditional loading; avoids hard coupling |
| Distinct wgFilePermVEDefault config var | Keeps VE config separate from MsUpload config (wgFilePermMsUploadDefault) |

## Deviations from Plan

None -- plan executed exactly as written.

## Verification Results

| Check | Result |
|-------|--------|
| PHP lint VisualEditorHooks.php | No syntax errors |
| extension.json valid JSON | Pass |
| i18n/en.json valid JSON | Pass |
| HookHandlers contains "visualeditor" | Pass |
| BeforePageDisplay is array with both handlers | Pass |
| ResourceModule has BookletLayout dependency | Pass |
| All three filepermissions-ve-* messages present | Pass |
| No hard ext.visualEditor dependency | Pass (grep returned no matches) |

## Next Phase Readiness

Plan 06-02 (VE Bridge Client Module) can proceed. It depends on:
- `ext.FilePermissions.visualeditor` ResourceModule registered (done)
- `wgFilePermLevels` and `wgFilePermVEDefault` config vars available (done)
- `filepermissions-ve-*` i18n messages available (done)
- JS and CSS files referenced in ResourceModule still need to be created (Plan 02 scope)
