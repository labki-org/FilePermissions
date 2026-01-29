# Phase 4: Display & Management - Context

**Gathered:** 2026-01-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Users can see file permissions and admins can change them on File: description pages. This phase adds a permission indicator visible to authorized users and a sysop-only edit interface for changing permission levels. The permission model, enforcement, and upload integration already exist from prior phases.

</domain>

<decisions>
## Implementation Decisions

### Permission indicator
- Display the current permission level on File: description pages
- Visible to users who have access to the file (unauthorized users hit enforcement from Phase 2 before seeing the page)

### Edit interface
- Sysop-only control to change a file's permission level directly on the File: page
- Dropdown populated from configured $wgFilePermLevels
- Save persists the new level to PageProps (same storage as upload integration)

### Claude's Discretion
- **Edit control style:** Inline dropdown vs edit-button-to-form vs other approach — choose what fits MediaWiki conventions best
- **Confirmation step:** Whether changing permission requires a confirm dialog or saves directly — choose based on MW UX patterns
- **Placement on File page:** Where the permission indicator and edit control appear — file info table row, separate section, or other placement that integrates cleanly
- **Audit logging:** Whether permission changes get logged to Special:Log — decide based on what's practical and aligns with MW admin patterns
- **Badge/indicator design:** How the permission level is visually presented — text label, badge, icon, etc.

</decisions>

<specifics>
## Specific Ideas

No specific requirements — open to standard approaches. User delegated all implementation details to Claude's discretion for this phase.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 04-display-management*
*Context gathered: 2026-01-28*
