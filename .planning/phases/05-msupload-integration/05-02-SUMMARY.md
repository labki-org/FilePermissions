---
phase: 05-msupload-integration
plan: 02
subsystem: upload
tags: [msupload, plupload, jquery, dropdown, multipart-params, pageprops, css]

# Dependency graph
requires:
  - phase: 05-msupload-integration
    provides: MsUploadHooks server-side conditional loading, ext.FilePermissions.msupload module registration, i18n messages
  - phase: 03-upload-integration
    provides: UploadVerifyUpload and UploadComplete hooks that read wpFilePermLevel from request
provides:
  - MsUpload bridge dropdown UI injected into #msupload-div
  - plupload BeforeUpload handler injecting wpFilePermLevel into multipart_params
  - Post-upload PageProps verification with persistent error notification
  - Dropdown disable/enable during upload via StateChanged
  - Error state UI when permission levels unavailable
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Plain HTML select for MsUpload integration (not OOUI — matches MsUpload DOM style)"
    - "Direct uploader.settings.multipart_params mutation (not setOption — avoids overwriting MsUpload params)"
    - "Post-upload verification via PageProps API query"
    - "plupload StateChanged for UI state management"

key-files:
  created:
    - modules/ext.FilePermissions.msupload.js
    - modules/ext.FilePermissions.msupload.css
  modified: []

key-decisions:
  - "Plain HTML select instead of OOUI DropdownInputWidget — MsUpload uses plain DOM, OOUI would look out of place"
  - "Direct multipart_params mutation via uploader.settings — setOption would overwrite MsUpload's params"
  - "Post-upload PageProps API query for verification — server has no mechanism to signal permission save failure in upload response"
  - "autoHide: false on error notifications — errors require manual dismissal per CONTEXT.md decision"

patterns-established:
  - "plupload event binding order: bind after MsUpload to run handlers after its setOption call"
  - "Memorized mw.hook for cross-extension timing: register handler on wikiEditor.toolbarReady without dependency declaration"

# Metrics
duration: 2min
completed: 2026-01-29
---

# Phase 5 Plan 2: MsUpload Bridge Client-Side Module Summary

**Permission dropdown with plupload BeforeUpload param injection, FileUploaded PageProps verification, and StateChanged disable/enable for MsUpload drag-drop uploads**

## Performance

- **Duration:** 2 min
- **Started:** 2026-01-29T18:05:36Z
- **Completed:** 2026-01-29T18:07:29Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- Created JavaScript bridge module with dropdown injection, plupload event binding, and post-upload verification
- Dropdown populated from wgFilePermLevels config with namespace-aware default from wgFilePermMsUploadDefault
- BeforeUpload handler adds wpFilePermLevel to uploader.settings.multipart_params without overwriting MsUpload's params
- FileUploaded handler queries PageProps API and shows persistent mw.notify error if permission was not saved
- StateChanged handler disables dropdown during upload and re-enables when stopped
- Error state rendered when permission levels are empty or unavailable
- CSS styles match MsUpload's plain DOM aesthetic with disabled and error states

## Task Commits

Each task was committed atomically:

1. **Task 1: Create ext.FilePermissions.msupload.js bridge module** - `157d674` (feat)
2. **Task 2: Create ext.FilePermissions.msupload.css styling** - `f2634df` (feat)

## Files Created/Modified
- `modules/ext.FilePermissions.msupload.js` - MsUpload bridge: dropdown injection, plupload BeforeUpload/FileUploaded/StateChanged binding, post-upload PageProps verification
- `modules/ext.FilePermissions.msupload.css` - Dropdown controls, label, select, disabled state, and error state styling

## Decisions Made
- **Plain HTML select over OOUI:** MsUpload's toolbar area uses plain DOM elements. An OOUI DropdownInputWidget would look visually out of place and adds unnecessary weight. Consistent with research recommendation.
- **Direct multipart_params mutation:** Using `uploader.settings.multipart_params` directly instead of `uploader.setOption()` because MsUpload's onBeforeUpload calls setOption which replaces the entire params object. Direct mutation preserves MsUpload's params (action, filename, token, comment).
- **Post-upload verification via PageProps query:** The server-side UploadComplete hook has no mechanism to signal permission save failure in the upload API response. A lightweight GET query to check pageprops.fileperm_level after each upload provides the required feedback.
- **Persistent error notifications:** `autoHide: false` on mw.notify for permission save failures, per CONTEXT.md decision that error notifications require manual dismissal.
- **plupload constant fallbacks:** `plupload.STARTED` and `plupload.STOPPED` used when available, with numeric fallbacks (2 and 1) for robustness.

## Deviations from Plan
None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- MsUpload integration is complete (both server-side foundation from Plan 01 and client-side bridge from Plan 02)
- Full upload flow: dropdown visible in MsUpload area -> user selects level -> plupload BeforeUpload injects into multipart_params -> server UploadVerifyUpload validates -> UploadComplete stores in PageProps -> client verifies via API query
- No further phases planned in ROADMAP.md

---
*Phase: 05-msupload-integration*
*Completed: 2026-01-29*
