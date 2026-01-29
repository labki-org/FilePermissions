---
status: complete
phase: 05-msupload-integration
source: [05-01-SUMMARY.md, 05-02-SUMMARY.md]
started: 2026-01-29T18:15:00Z
updated: 2026-01-29T19:30:00Z
---

## Tests

### 1. Permission dropdown appears in MsUpload area
expected: On an edit page where MsUpload is active, a "Permission level:" dropdown appears above the MsUpload drop zone. Options match configured $wgFilePermLevels.
result: pass

### 2. Dropdown defaults to namespace-appropriate level
expected: The dropdown pre-selects the default level for the current page's namespace (based on $wgFilePermNamespaceDefaults or $wgFilePermDefaultLevel).
result: pass

### 3. Drag-drop upload stores selected permission level
expected: After drag-dropping a file with a specific permission level selected, the uploaded file's File: page shows the selected permission level badge.
result: pass

### 4. Dropdown disabled during upload
expected: While files are uploading (progress bar active), the permission dropdown is grayed out / non-interactive. After upload completes, it becomes selectable again.
result: pass

### 5. Special:Upload still works with mandatory selection
expected: Going to Special:Upload, the permission dropdown still appears and requires explicit selection before upload succeeds.
result: pass

### 6. No bridge UI when MsUpload is absent
expected: On an edit page where MsUpload is NOT installed/active, no permission dropdown appears in the editing area. No JS errors in console.
result: pass

## Summary

total: 6
passed: 6
issues: 0
pending: 0
skipped: 0

## Gaps

[none]
