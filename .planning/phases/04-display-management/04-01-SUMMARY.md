---
phase: 04-display-management
plan: 01
subsystem: display-management
tags: [hooks, api, ooui, resourceloader, logging, i18n, permissions]
dependency-graph:
  requires: [01-01, 01-02, 02-01]
  provides: [DisplayHooks, ApiFilePermSetLevel, edit-fileperm right, fileperm log type, RL modules shell]
  affects: [04-02]
tech-stack:
  added: []
  patterns: [ImagePageAfterImageLinks hook, BeforePageDisplay hook, ApiBase custom module, OOUI server-side widgets, ManualLogEntry audit log, conditional RL module loading]
key-files:
  created:
    - includes/Hooks/DisplayHooks.php
    - includes/Api/ApiFilePermSetLevel.php
  modified:
    - extension.json
    - i18n/en.json
decisions:
  - id: "04-01-01"
    description: "OOUI server-side rendering for edit controls (not Codex/Vue)"
    rationale: "MW 1.44 fully supports OOUI; Codex requires Vue.js which is overkill for a single dropdown"
  - id: "04-01-02"
    description: "ManualLogEntry audit logging for permission changes"
    rationale: "Trivial cost (5 lines), high admin value, aligns with MW convention (protection/rights logs)"
  - id: "04-01-03"
    description: "Custom edit-fileperm right (not group membership check)"
    rationale: "Rights-based check is MW convention, allows admins to reassign to other groups"
metrics:
  duration: "116s"
  completed: "2026-01-29"
---

# Phase 4 Plan 1: Backend Hooks, API, and Registration Summary

**One-liner:** DisplayHooks for File page indicator + sysop edit controls, ApiFilePermSetLevel for CSRF-protected permission saves with audit logging, full extension.json registration.

## What Was Done

### Task 1: Create DisplayHooks and ApiFilePermSetLevel
**Commit:** `c067be7`

Created two new PHP files implementing the backend infrastructure for permission display and editing on File: description pages.

**DisplayHooks.php** implements two hook interfaces:
- `ImagePageAfterImageLinksHook`: Injects a `div.fileperm-section` containing a permission level indicator (`div.fileperm-indicator` with bold label and `span.fileperm-level-badge`) visible to all authorized users. For users with `edit-fileperm` right, also renders OOUI `DropdownInputWidget` and `ButtonInputWidget` wrapped in `div.fileperm-edit-controls`.
- `BeforePageDisplayHook`: On NS_FILE pages, always loads `ext.FilePermissions.indicator` styles. For users with `edit-fileperm` right, loads `ext.FilePermissions.edit` JS module and passes `wgFilePermCurrentLevel`, `wgFilePermLevels`, and `wgFilePermPageTitle` via `addJsConfigVars()`.

**ApiFilePermSetLevel.php** implements a custom API module:
- Extends `ApiBase` with constructor-injected `PermissionService`
- Gates on `edit-fileperm` right via `checkUserRightsAny()`
- Validates title exists in NS_FILE
- Stores new level via `PermissionService::setLevel()`
- Creates `ManualLogEntry('fileperm', 'change')` with old/new level parameters
- CSRF-protected: `needsToken() => 'csrf'`, `mustBePosted() => true`, `isWriteMode() => true`

### Task 2: Register hooks, API, rights, log type, and RL modules
**Commit:** `aec6ba9`

Updated extension.json with all new registrations alongside existing entries:
- `HookHandlers.display` pointing to `DisplayHooks` with `PermissionService` DI
- `Hooks.ImagePageAfterImageLinks` and `Hooks.BeforePageDisplay` mapping to display handler
- `APIModules.fileperm-set-level` pointing to `ApiFilePermSetLevel` with DI
- `AvailableRights: ["edit-fileperm"]` and `GroupPermissions.sysop.edit-fileperm: true`
- `LogTypes`, `LogNames`, `LogHeaders`, `LogActionsHandlers` for `fileperm` log type
- `ResourceModules`: `ext.FilePermissions.indicator` (styles-only) and `ext.FilePermissions.edit` (packageFiles JS with mediawiki.api, mediawiki.notify, oojs-ui-core dependencies)

Updated i18n/en.json with 9 new messages:
- `filepermissions-level-label`, `filepermissions-edit-save` (display/edit UI)
- `filepermissions-edit-success`, `filepermissions-edit-error` (JS notifications)
- `filepermissions-log-name`, `filepermissions-log-header`, `logentry-fileperm-change` (audit log)
- `filepermissions-api-nosuchpage` (API error)
- `right-edit-fileperm` (right description)

## Deviations from Plan

None - plan executed exactly as written.

## Decisions Made

1. **OOUI server-side rendering** (04-01-01): Used OOUI PHP widgets (`DropdownInputWidget`, `ButtonInputWidget`) for the edit controls rather than Codex/Vue. MW 1.44 fully supports OOUI and Codex would require Vue.js infrastructure that is overkill for a single dropdown.

2. **ManualLogEntry audit logging** (04-01-02): Included audit logging for all permission changes to `Special:Log/fileperm`. Trivial implementation cost with high admin value, follows MW convention where protection and rights changes are logged.

3. **Custom edit-fileperm right** (04-01-03): Used a dedicated `edit-fileperm` user right checked via `$user->isAllowed()` rather than direct group membership checks. This is the MW convention and allows site admins to grant the right to additional groups via LocalSettings.php.

## Verification Results

| Check | Result |
|-------|--------|
| `php -l includes/Hooks/DisplayHooks.php` | Pass - no syntax errors |
| `php -l includes/Api/ApiFilePermSetLevel.php` | Pass - no syntax errors |
| `python3 -m json.tool extension.json` | Pass - valid JSON |
| `python3 -m json.tool i18n/en.json` | Pass - valid JSON |
| Existing extension.json entries preserved | Pass - enforcement, upload hooks, config all present |
| New registrations present | Pass - display handler, hooks, API, rights, log type, RL modules |

## Next Phase Readiness

**Plan 04-02 depends on:**
- `ext.FilePermissions.edit.js` and `ext.FilePermissions.edit.css` files (not yet created -- these are RL module shells registered in extension.json, actual file content is Plan 02's scope)
- The PHP-rendered HTML structure: `div.fileperm-section > div.fileperm-indicator + div.fileperm-edit-controls`
- JS config vars: `wgFilePermCurrentLevel`, `wgFilePermLevels`, `wgFilePermPageTitle`
- API endpoint: `action=fileperm-set-level` with title + level params

**Blockers:** None. All backend infrastructure is in place for Plan 02 to create the frontend JS/CSS.
