# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-01-28)

**Core value:** Files are protected at the byte level - unauthorized users cannot view, embed, or download protected files, period.
**Current focus:** Phase 4 - Display & Management

## Current Position

Phase: 4 of 5 (Display & Management)
Plan: 0 of ? in current phase
Status: Ready to plan
Last activity: 2026-01-29 - Phase 3 verified and complete

Progress: [######....] 60%

## Performance Metrics

**Velocity:**
- Total plans completed: 5
- Total execution time: multi-session

**By Phase:**

| Phase | Plans | Status |
|-------|-------|--------|
| 1 | 2/2 | Complete |
| 2 | 2/2 | Complete |
| 3 | 1/1 | Complete |

**Recent Trend:**
- Phase 3 required 4 commits (1 feat + 3 fixes) during human verification
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

### Research Flags

- Phase 5 (MsUpload Integration): NEEDS RESEARCH - MsUpload JavaScript API is undocumented

### Pending Todos

None yet.

### Blockers/Concerns

- Deployment requires $wgGroupPermissions['*']['read'] = false (private wiki) for img_auth.php enforcement
- Deployment requires web server blocking direct /images/ access
- Parser cache disabled for pages with protected embedded images (performance tradeoff)

## Session Continuity

Last session: 2026-01-29
Stopped at: Phase 3 verified and complete
Resume file: None
