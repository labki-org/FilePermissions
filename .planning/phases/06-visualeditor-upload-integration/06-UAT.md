---
status: complete
phase: 06-visualeditor-upload-integration
source: [06-01-SUMMARY.md, 06-02-SUMMARY.md]
started: 2026-01-29T12:00:00Z
updated: 2026-01-29T12:15:00Z
---

## Current Test

[testing complete]

## Tests

### 1. VE bridge module loads conditionally
expected: On a page where VisualEditor is installed, ext.FilePermissions.visualeditor module is loaded via ResourceLoader. If VE is not installed, it does not load.
result: pass

### 2. Permission dropdown appears in VE upload dialog
expected: Open VisualEditor on any page → Insert → Media → Upload tab → select a file → proceed to the info form. An OOUI "Permission level:" dropdown appears in the info form, listing the configured permission levels (e.g., public, internal, confidential).
result: pass

### 3. Dropdown defaults to namespace-appropriate level
expected: The permission dropdown pre-selects the default level configured for the current page's namespace (via $wgFilePermNamespaceDefaults), or the global default ($wgFilePermDefaultLevel), or the first configured level if no default is set.
result: pass

### 4. Permission level stored after VE upload
expected: Upload a file through VisualEditor with a specific permission level selected (e.g., "confidential"). After upload completes, visit the File: page. The permission level indicator should show the level you selected ("confidential").
result: issue
reported: "The file permission does not show up on the uploaded File: page. My guess is the file gets uploaded to wiki when clicking the 'upload' button which happens on the first form, not after the info form"
severity: major

### 5. Upload without explicit selection uses default
expected: If a namespace or global default is configured, upload a file through VE without changing the dropdown from its default. The file should have the default permission level stored. Verify on the File: page.
result: issue
reported: "Same issue as test 4. The permission level doesn't show up on the File: page once uploaded so I cannot test this"
severity: major

### 6. Dropdown resets on dialog reuse
expected: Upload one file via VE with "confidential" selected. Close the dialog. Open Insert → Media → Upload again. The dropdown should reset to the default level (not remain on "confidential" from the previous upload).
result: pass

### 7. Foreign upload target skips dropdown
expected: If your wiki is configured with a foreign upload target (e.g., Commons), open VE → Insert → Media → Upload. If the upload target is foreign (not local), the permission dropdown should NOT appear, since permissions only apply to local files.
result: skipped
reason: User unfamiliar with foreign upload target configuration

### 8. Error notification on permission save failure
expected: If the permission level fails to save to PageProps (e.g., due to a server error), a persistent error notification appears with warning message. The notification does NOT auto-dismiss.
result: skipped
reason: Skipped for now

## Summary

total: 8
passed: 4
issues: 2
pending: 0
skipped: 2

## Gaps

- truth: "Uploaded file has selected permission level stored in PageProps after VE upload"
  status: failed
  reason: "User reported: The file permission does not show up on the uploaded File: page. My guess is the file gets uploaded to wiki when clicking the 'upload' button which happens on the first form, not after the info form"
  severity: major
  test: 4
  root_cause: ""
  artifacts: []
  missing: []
  debug_session: ""

- truth: "Upload without explicit selection uses namespace/global default"
  status: failed
  reason: "User reported: Same issue as test 4. The permission level doesn't show up on the File: page once uploaded so I cannot test this"
  severity: major
  test: 5
  root_cause: ""
  artifacts: []
  missing: []
  debug_session: ""
