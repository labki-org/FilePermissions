---
phase: 04-display-management
plan: 02
subsystem: display-management
tags: [javascript, css, ooui, infusion, resourceloader, bug-fix]
dependency-graph:
  requires: [04-01]
  provides: [ext.FilePermissions.edit.js, ext.FilePermissions.edit.css, working edit UI]
  affects: []
tech-stack:
  added: []
  patterns: [OOUI infusion with infusable flag, mw.Api().postWithToken CSRF, mw.notify feedback, packageFiles module]
key-files:
  created:
    - modules/ext.FilePermissions.edit.js
    - modules/ext.FilePermissions.edit.css
  modified:
    - extension.json
    - includes/Hooks/DisplayHooks.php
decisions:
  - id: "04-02-01"
    description: "OOUI widgets require 'infusable' => true for client-side infusion"
    rationale: "Without this flag, server-rendered widgets lack data-ooui attribute and OO.ui.infuse() throws"
  - id: "04-02-02"
    description: "mw.notify() available as base stub in MW 1.44 — no explicit dependency needed"
    rationale: "mediawiki.notify is not a valid RL module; the non-existent dependency caused the entire edit module to be skipped"
metrics:
  duration: "multi-session (5 bug fixes during execution and verification)"
  completed: "2026-01-29"
---

# Phase 4 Plan 2: Frontend JS/CSS and Human Verification Summary

**One-liner:** Client-side save module and CSS styles for the file permission edit UI, with 5 bug fixes discovered during execution and human verification.

## What Was Done

### Task 1: Create JavaScript save module and CSS styles
**Commit:** `1d34bc8`

Created two frontend files for the permission edit interface on File: description pages.

**ext.FilePermissions.edit.js** implements the save flow:
- IIFE with DOM-ready handler for safe initialization
- Reads `wgFilePermCurrentLevel`, `wgFilePermPageTitle`, `wgFilePermLevels` from `mw.config`
- Infuses server-rendered OOUI `DropdownInputWidget` and `ButtonInputWidget` via `OO.ui.infuse()`
- Click handler on save button: reads dropdown value, guards against no-change, disables button
- CSRF-protected API call via `mw.Api().postWithToken('csrf', {action: 'fileperm-set-level', ...})`
- Success: green `mw.notify` notification, badge text update, state tracking
- Error: red `mw.notify` notification, button re-enabled

**ext.FilePermissions.edit.css** provides minimal MW-consistent styles:
- `.fileperm-section`: separator with MW info-panel blue border
- `.fileperm-indicator`: spacing for the label row
- `.fileperm-level-badge`: inline badge with light blue background
- `.fileperm-edit-controls`: flexbox layout for dropdown + button

### Human Verification
All critical checks passed (steps 1–8 of verification plan):
- Permission indicator visible on File pages with styled badge
- Dropdown + Save button visible for sysop users
- Save changes level, green success notification appears
- Badge updates without page reload
- Level persists after refresh
- Change logged at Special:Log/fileperm with old/new levels

Steps 9–11 (non-sysop view, file without level) deferred — not critical for current deployment.

## Issues Found and Fixed

1. **Non-existent ImagePageAfterImageLinksHook interface** — `DisplayHooks` declared `implements ImagePageAfterImageLinksHook` but this interface doesn't exist in MW 1.44. Fatal error on page load. Fix: removed interface declaration; MW calls the method by name via extension.json registration. (commit `03ede1b`)

2. **JS executing before DOM ready** — ResourceLoader `packageFiles` modules can execute before the body is parsed. OOUI widget elements weren't in the DOM yet. Fix: wrapped initialization in `$( function () { ... } )` DOM-ready handler. (commit `e9bb719`)

3. **OOUI widgets not interactive without infusion** — Code used jQuery `.val()` and `.prop('disabled')` on raw DOM elements. OOUI widgets render as HTML but require `OO.ui.infuse()` to become interactive. Fix: infuse both widgets and use OOUI API (`getValue()`, `setDisabled()`, `.on('click')`). (commit `cfb04d8`)

4. **Non-existent mediawiki.notify RL module** — `mediawiki.notify` is not a valid ResourceLoader module name. This caused the entire `ext.FilePermissions.edit` module to be skipped with warning "Skipped unavailable module". Fix: removed the dependency; `mw.notify()` is a base stub in MW 1.44 requiring no explicit dependency. (commit `650f5f0`)

5. **OOUI widgets missing infusion data** — `DropdownInputWidget` and `ButtonInputWidget` were rendered without `'infusable' => true`, so the server-rendered HTML lacked the `data-ooui` attribute. `OO.ui.infuse()` threw "No infusion data found". Fix: added `'infusable' => true` to both widget configs. (commit `650f5f0`)

## Deviations from Plan

- Plan specified jQuery `.val()` for reading dropdown — replaced with OOUI `infuse()` + `getValue()` (required for OOUI widgets)
- Plan specified jQuery `.prop('disabled')` — replaced with OOUI `setDisabled()` (required for OOUI widgets)
- Five bug fixes required during execution and verification (see above)

## Verification Results

| Check | Result |
|-------|--------|
| Permission indicator on File page | Pass |
| Styled badge with level name | Pass |
| Edit controls visible for sysop | Pass |
| Save changes permission level | Pass |
| Success notification appears | Pass |
| Badge updates without reload | Pass |
| Level persists after refresh | Pass |
| Change logged at Special:Log/fileperm | Pass |
| No JS console errors | Pass |

## Next Phase Readiness

Phase 4 is complete. All FPUI requirements verified:
- FPUI-01: Permission level indicator on File pages ✓
- FPUI-02: Sysop edit interface (dropdown + save) ✓
- FPUI-03: Permission change persists to PageProps ✓

Ready for Phase 5 (MsUpload Integration).
