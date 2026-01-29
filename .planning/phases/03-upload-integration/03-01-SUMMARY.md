---
phase: 03-upload-integration
plan: 01
subsystem: upload
tags: [upload-form, permission-storage, deferred-updates, human-verification]

# Dependency graph
requires:
  - phase: 02-core-enforcement
    plan: 02
    provides: Enforcement hooks verified across all access paths
provides:
  - Permission level dropdown on Special:Upload form
  - Server-side validation blocking upload without level selection
  - Deferred PageProps storage of selected permission level
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "DeferredUpdates::addCallableUpdate for post-transaction storage"

key-files:
  created:
    - includes/Hooks/UploadHooks.php
  modified:
    - extension.json
    - i18n/en.json
    - includes/Config.php

key-decisions:
  - "UploadVerifyUploadHook for server-side validation (UploadForm bypasses HTMLForm validation)"
  - "DeferredUpdates for storage timing (page not committed when UploadComplete fires)"
  - "Fresh Title in deferred callback to avoid cached articleID=0"
  - "array_unique in Config::getLevels() to handle MW array config merging"

patterns-established:
  - "DeferredUpdates pattern for hooks that fire before DB transaction commits"

# Metrics
duration: multi-session (4 bug fixes during verification)
completed: 2026-01-29
---

# Phase 03 Plan 01: Upload Integration Summary

**Permission level dropdown on Special:Upload with server-side validation and deferred PageProps storage**

## Performance

- **Commits:** 4 (feat + 3 fixes)
- **Completed:** 2026-01-29
- **Tasks:** 1 auto + 1 human verification checkpoint

## Accomplishments
- Permission level dropdown appears on Special:Upload form (UPLD-01)
- Dropdown lists all configured levels with granted group names (UPLD-02)
- Empty placeholder default; re-upload pre-selects existing level (UPLD-03)
- Selected level stored in PageProps via deferred update (UPLD-04)
- Server-side validation blocks upload without level selection

## Issues Found and Fixed During Verification

1. **UploadCompleteHook signature mismatch** — `onUploadComplete(&$upload)` used pass-by-reference, but MW 1.44 interface requires `onUploadComplete($uploadBase)`. Fatal error on page load. Fix: remove `&`, rename parameter. (commit dce276e)

2. **Duplicate levels in dropdown** — MW merges extension.json array defaults with LocalSettings values via `array_merge`, doubling entries. Fix: `array_values(array_unique(...))` in `Config::getLevels()`. (commit 5f830d6)

3. **Validation not blocking empty selection** — `UploadForm::trySubmit()` returns false, completely bypassing HTMLForm's `validate()` method. `validation-callback` on descriptors is never invoked. Fix: implement `UploadVerifyUploadHook` for server-side validation. (commit 5f830d6)

4. **PageProps storage failing — articleID=0** — `LocalFile::upload()` defers page creation via `AutoCommitUpdate`, so the file page does not exist when `UploadComplete` fires. Even `IDBAccessObject::READ_LATEST` returns 0. Fix: `DeferredUpdates::addCallableUpdate()` with fresh Title to store after transaction commits. (commit 2ecee24)

## Human Verification Results

| Step | Check | Result |
|------|-------|--------|
| 1 | Permission dropdown on Special:Upload | Pass |
| 2 | Levels show with group names, no duplicates | Pass |
| 3 | Placeholder default "-- Choose --" | Pass |
| 4 | Upload blocked without level selection | Pass |
| 5 | Permission level stored in PageProps | Pass |
| 6 | Re-upload pre-selects existing level | Pass |
| 7 | Enforcement works for uploaded file | Pass |

## Deviations from Plan

- Added `UploadVerifyUploadHook` (not in original plan) — required because UploadForm bypasses HTMLForm validation
- Used `DeferredUpdates` (not in original plan) — required because page creation is deferred in `LocalFile::upload()`
- Modified `Config::getLevels()` (not in original plan) — required to handle MW array config merging

## Next Phase Readiness
- All UPLD requirements verified and working
- Upload → enforcement pipeline confirmed end-to-end
- Ready for Phase 4 (Display & Management)

---
*Phase: 03-upload-integration*
*Completed: 2026-01-29*
