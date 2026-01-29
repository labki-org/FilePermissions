# Phase 3: Upload Integration - Context

**Gathered:** 2026-01-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Users can set a permission level when uploading files via Special:Upload. A permission dropdown appears on the upload form, options reflect configured `$wgFilePermLevels`, and the selected level is stored in PageProps on upload. This phase does NOT cover File page display, permission editing, or MsUpload integration.

</domain>

<decisions>
## Implementation Decisions

### Dropdown placement & labeling
- Label: "Permission level"
- Always visible on the form (not collapsible/advanced)
- Brief help text below dropdown explaining "Controls which groups can view this file" or similar
- Claude's discretion: exact position within the Special:Upload form (based on MW form conventions)

### Default selection logic
- Special:Upload: dropdown starts empty with "-- Choose --" placeholder
- Selection is required — form blocks submission until a level is chosen
- Re-uploads: pre-select the file's current permission level
- If a file's existing level was removed from config, fall back to empty (require re-selection)
- MsUpload namespace-aware defaults are out of scope (Phase 5)

### Permission visibility
- Dropdown options display: level name + granted groups (e.g., "Confidential (sysop, trusted-staff)")
- All configured levels appear regardless of the uploading user's own group membership
- "Public" / unrestricted is not a built-in special option — it's a regular configured level in `$wgFilePermLevels` like any other (admin grants it to `*` anonymous group if desired)
- If admin doesn't configure a public level, all files must have a restriction level

### Upload-without-selection behavior
- Empty placeholder always blocks upload — user must commit to a real level
- If upload fails (file too large, duplicate, etc.), form resets to empty — user must re-select
- Claude's discretion: error message style and validation approach (MW-conventional)

### Claude's Discretion
- Exact form field position within Special:Upload
- Help text wording
- Error message text and validation pattern (inline vs banner)
- Dropdown option ordering (e.g., most restrictive first vs alphabetical)

</decisions>

<specifics>
## Specific Ideas

- Dropdown always visible — permissions are a conscious, required choice, not an afterthought
- Re-uploads inherit the existing level so users don't accidentally change permissions when updating a file
- Form resets on upload failure to force re-confirmation of permission choice

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 03-upload-integration*
*Context gathered: 2026-01-28*
