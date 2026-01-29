# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-01-28)

**Core value:** Files are protected at the byte level - unauthorized users cannot view, embed, or download protected files, period.
**Current focus:** Phase 5 UAT complete, adding Phase 6 (VisualEditor Upload Integration)

## Current Position

Phase: 5 of 5 (MsUpload Integration)
Plan: 2 of 2 in current phase
Status: UAT Complete (6/6 passed)
Last activity: 2026-01-29 - Phase 5 UAT passed, race condition fix committed

Progress: [##########] 100%

## Performance Metrics

**Velocity:**
- Total plans completed: 10
- Total execution time: multi-session

**By Phase:**

| Phase | Plans | Status |
|-------|-------|--------|
| 1 | 2/2 | Complete |
| 2 | 2/2 | Complete |
| 3 | 1/1 | Complete |
| 4 | 2/2 | Complete |
| 5 | 2/2 | Complete |

**Recent Trend:**
- Phase 5 Plan 2 executed cleanly with no deviations (2 tasks, 2 min)
- All 5 phases complete, 10 plans total
- Trend: stable

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Init]: PageProps for storage (fast lookup, cached, native MW infrastructure)
- [Init]: Single permission level per file (simple mental model)
- [Init]: JS bridge for MsUpload (avoids forking, uses existing events/hooks)
- [Init]: Group-based only (reduces complexity, aligns with MW permission model)
- [01-01]: Registration callback for validation timing (before services instantiate)
- [01-01]: Fail-closed via global flag rather than exception
- [01-02]: Fail-closed in canUserAccessLevel when Config::isInvalidConfig() is true
- [01-02]: Grandfathered files (no level, no default) treated as unrestricted
- [02-01]: Generic error messages (do not reveal required permission level)
- [02-01]: Non-clickable placeholder to reduce discoverability
- [02-01]: Placeholder sized to match dimensions (fallback 220px)
- [02-02]: img_auth.php requires $wgGroupPermissions['*']['read'] = false
- [02-02]: Parser cache disabled for pages embedding protected images
- [02-02]: Base64-encoded SVG for data URI reliability
- [03-01]: UploadVerifyUploadHook for server-side validation (UploadForm bypasses HTMLForm validation)
- [03-01]: DeferredUpdates for storage timing (page not committed when UploadComplete fires)
- [03-01]: array_unique in Config::getLevels() to handle MW array config merging
- [04-01]: OOUI server-side rendering for edit controls (not Codex/Vue)
- [04-01]: ManualLogEntry audit logging for permission changes
- [04-01]: Custom edit-fileperm right (not group membership check)
- [04-02]: OOUI widgets require 'infusable' => true for client-side infusion
- [04-02]: mw.notify() available as base stub in MW 1.44 — no explicit RL dependency needed
- [05-01]: Detect Special:Upload form context via wpUploadFile/wpUploadFileURL request params
- [05-01]: API uploads without wpFilePermLevel allowed with resolved default or grandfathered
- [05-01]: ExtensionRegistry::isLoaded for conditional MsUpload bridge loading (silent no-op when absent)
- [05-01]: No hard ext.MsUpload dependency in ResourceLoader module registration
- [05-02]: Plain HTML select (not OOUI) for MsUpload bridge dropdown — matches MsUpload's DOM style
- [05-02]: Direct uploader.settings.multipart_params mutation — setOption would overwrite MsUpload's params
- [05-02]: Post-upload PageProps API query for verification — server cannot signal save failure in upload response
- [05-02]: autoHide: false on error notifications — errors require manual dismissal

### Research Flags

- Phase 5 (MsUpload Integration): RESEARCH COMPLETE - MsUpload source reviewed (see 05-RESEARCH.md)

### Pending Todos

None.

### Blockers/Concerns

- Deployment requires $wgGroupPermissions['*']['read'] = false (private wiki) for img_auth.php enforcement
- Deployment requires web server blocking direct /images/ access
- Parser cache disabled for pages with protected embedded images (performance tradeoff)

## Session Continuity

Last session: 2026-01-29
Stopped at: Phase 5 UAT complete. Next: add Phase 6 (VisualEditor Upload Integration)
Resume file: None
