# Phase 5: MsUpload Integration - Context

**Gathered:** 2026-01-29
**Status:** Ready for planning

<domain>
## Phase Boundary

Add a permission-level dropdown to MsUpload's drag-drop upload interface so files uploaded via MsUpload receive the same permission treatment as files uploaded via Special:Upload. This is a JavaScript bridge into MsUpload — no changes to MsUpload itself.

</domain>

<decisions>
## Implementation Decisions

### Dropdown placement & appearance
- One global dropdown for the entire batch (not per-file)
- Dropdown always visible in the MsUpload toolbar area, even before files are dropped
- Label present next to the dropdown for clarity

### Upload behavior & defaults
- Default permission comes from the current page's namespace (namespace-aware), not just the global default
- Dropdown is editable anytime before upload starts
- Permission is read once at upload time and applies to the whole batch — no mid-batch changes (MsUpload's workflow doesn't support changing once upload is triggered)

### Missing MsUpload fallback
- Silent no-op if MsUpload is not installed — no errors, no UI, nothing
- Target the latest MsUpload release; document minimum supported version

### Feedback & error states
- No extra success feedback — permissions applied silently on successful upload
- If permission save fails but file upload succeeds: show error notification to user
- If permission levels can't be loaded during initialization: show error state in dropdown area
- Error notifications require manual dismissal (persist until user clicks to dismiss)

### Claude's Discretion
- Exact dropdown position within MsUpload's toolbar area (based on layout analysis)
- Visual style choice: OOUI widget vs matching MsUpload's native style (whichever fits better)
- Whether to include a label and its wording
- JS module loading strategy (conditional registration vs runtime detection)
- Whether to hide the dropdown when user lacks upload rights (match MsUpload's behavior)

</decisions>

<specifics>
## Specific Ideas

- MsUpload workflow: user drops files → sets permission → triggers upload. The permission dropdown should be part of the pre-upload configuration, not a post-upload step.
- Existing JS bridge decision from project init: avoids forking MsUpload, uses existing events/hooks.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 05-msupload-integration*
*Context gathered: 2026-01-29*
