---
phase: 05-msupload-integration
plan: 01
subsystem: upload
tags: [msupload, plupload, extensionregistry, upload-hooks, resourceloader, i18n]

# Dependency graph
requires:
  - phase: 03-upload-integration
    provides: UploadVerifyUpload and UploadComplete hooks in UploadHooks.php
  - phase: 01-foundation
    provides: Config::resolveDefaultLevel for namespace-aware defaults
provides:
  - Tolerant UploadVerifyUpload that accepts API uploads without wpFilePermLevel
  - MsUploadHooks handler for conditional bridge module loading
  - ext.FilePermissions.msupload ResourceLoader module registration
  - Three MsUpload bridge i18n messages
affects: [05-02-PLAN.md (client-side JS/CSS that uses registered module and i18n)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "ExtensionRegistry::isLoaded for conditional cross-extension integration"
    - "EditPage::showEditForm:initial hook for edit-page module loading"
    - "Context-aware upload validation (form vs API detection via wpUploadFile)"

key-files:
  created:
    - includes/Hooks/MsUploadHooks.php
  modified:
    - includes/Hooks/UploadHooks.php
    - extension.json
    - i18n/en.json

key-decisions:
  - "Detect Special:Upload form context via wpUploadFile/wpUploadFileURL request params"
  - "API uploads without wpFilePermLevel allowed with resolved default or grandfathered (no level)"
  - "MsUploadHooks uses static Config methods only (no constructor-injected services)"
  - "ext.FilePermissions.msupload has no hard dependency on ext.MsUpload"

patterns-established:
  - "Context-aware validation: form submissions enforce mandatory fields, API uploads apply defaults"
  - "Conditional extension integration: server-side ExtensionRegistry check + conditional addModules"

# Metrics
duration: 4min
completed: 2026-01-29
---

# Phase 5 Plan 1: Server-Side MsUpload Foundation Summary

**Tolerant UploadVerifyUpload for API uploads, MsUploadHooks with ExtensionRegistry-gated module loading, and ext.FilePermissions.msupload ResourceLoader registration**

## Performance

- **Duration:** 4 min
- **Started:** 2026-01-29T17:57:39Z
- **Completed:** 2026-01-29T18:02:06Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments
- Fixed critical UploadVerifyUpload bug that blocked all API uploads (bots, MsUpload, other extensions) missing wpFilePermLevel parameter
- Created MsUploadHooks.php with EditPage::showEditForm:initial handler that conditionally loads bridge module only when MsUpload is installed
- Registered ext.FilePermissions.msupload ResourceLoader module with packageFiles, styles, mediawiki.api dependency, and i18n messages
- Added three i18n messages for MsUpload bridge dropdown label and error states

## Task Commits

Each task was committed atomically:

1. **Task 1: Fix UploadVerifyUpload to apply default when wpFilePermLevel is absent** - `a300f25` (fix)
2. **Task 2: Create MsUploadHooks handler, register module, add i18n messages** - `a170872` (feat)

## Files Created/Modified
- `includes/Hooks/UploadHooks.php` - Tolerant onUploadVerifyUpload (applies default for API uploads) and onUploadComplete (resolves default when level absent)
- `includes/Hooks/MsUploadHooks.php` - New hook handler: checks ExtensionRegistry for MsUpload, loads bridge module and JS config vars on edit pages
- `extension.json` - Added msupload HookHandler, EditPage::showEditForm:initial hook binding, ext.FilePermissions.msupload ResourceModule
- `i18n/en.json` - Added filepermissions-msupload-label, filepermissions-msupload-error-nolevels, filepermissions-msupload-error-save

## Decisions Made
- **Form vs API detection:** Used wpUploadFile/wpUploadFileURL request parameters to distinguish Special:Upload form submissions from API uploads. Form fields are specific to HTMLForm and absent in API requests.
- **Grandfathered API uploads:** When no default is configured and upload comes via API, the upload is allowed without a permission level. This matches decision [01-02] where grandfathered files are treated as unrestricted.
- **No constructor services for MsUploadHooks:** The handler only needs static Config methods (getLevels, resolveDefaultLevel), so no DI services are injected. This keeps the handler lightweight.
- **No hard ext.MsUpload dependency:** The ResourceLoader module deliberately omits ext.MsUpload from dependencies. Loading is controlled server-side by MsUploadHooks via ExtensionRegistry check.

## Deviations from Plan
None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Server-side foundation complete for MsUpload integration
- ext.FilePermissions.msupload module registered but JS/CSS files not yet created (05-02-PLAN.md)
- JS config vars wgFilePermLevels and wgFilePermMsUploadDefault will be available on edit pages when MsUpload is present
- UploadVerifyUpload and UploadComplete now handle API uploads correctly, ready for plupload parameter injection

---
*Phase: 05-msupload-integration*
*Completed: 2026-01-29*
