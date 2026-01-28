---
phase: 02-core-enforcement
plan: 01
subsystem: auth
tags: [mediawiki-hooks, permission-enforcement, img_auth, svg-placeholder]

# Dependency graph
requires:
  - phase: 01-foundation
    provides: PermissionService with canUserAccessFile()
provides:
  - EnforcementHooks class implementing three MediaWiki hook interfaces
  - File: description page access control via getUserPermissionsErrors
  - Raw file/thumbnail access control via ImgAuthBeforeStream
  - Embedded image placeholder via ImageBeforeProduceHTML
  - i18n error messages for access denial
affects: [03-ui-controls, 04-special-pages]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Modern hook handler registration via extension.json HookHandlers"
    - "Dependency injection of services into hook handlers"
    - "Inline SVG data URI for placeholder images"

key-files:
  created:
    - includes/Hooks/EnforcementHooks.php
  modified:
    - extension.json
    - i18n/en.json

key-decisions:
  - "Generic error messages that do not reveal required permission level"
  - "Non-clickable placeholder to reduce discoverability of protected files"
  - "Placeholder sized to match requested dimensions (fallback 220px)"

patterns-established:
  - "Hook interface implementation with injected PermissionService"
  - "RequestContext::getMain()->getUser() for user context in hooks"

# Metrics
duration: 2min
completed: 2026-01-28
---

# Phase 02 Plan 01: Enforcement Hooks Summary

**Three MediaWiki hooks enforcing file permissions: page access, raw file/thumbnail access, and embedded image replacement with inline SVG placeholder**

## Performance

- **Duration:** 2 min
- **Started:** 2026-01-28T23:31:04Z
- **Completed:** 2026-01-28T23:32:41Z
- **Tasks:** 3
- **Files modified:** 3

## Accomplishments
- EnforcementHooks class implementing GetUserPermissionsErrorsHook, ImgAuthBeforeStreamHook, and ImageBeforeProduceHTMLHook
- File: description pages blocked for unauthorized users (generic error, no level revealed)
- Raw file and thumbnail access blocked via img_auth.php (403 response)
- Embedded images replaced with non-clickable lock icon placeholder (preserves page layout)

## Task Commits

Each task was committed atomically:

1. **Task 1: Create EnforcementHooks class** - `cb9c14c` (feat)
2. **Task 2: Register hooks in extension.json** - `e12fd74` (feat)
3. **Task 3: Add i18n error messages** - `afd9da2` (feat)

## Files Created/Modified
- `includes/Hooks/EnforcementHooks.php` - All three hook implementations with placeholder generator
- `extension.json` - HookHandlers and Hooks registration for enforcement
- `i18n/en.json` - Access denied error messages (generic, no level info)

## Decisions Made
- Error messages are generic ("You do not have permission to view/access this file") - does not reveal what permission level is required (security: reveal nothing)
- Placeholder is non-clickable (no link wrapper) - dead end to reduce discoverability of protected files
- Placeholder uses inline SVG data URI - avoids extra HTTP request, can be sized dynamically
- Fallback dimensions of 220px when width/height not specified in wikitext

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Enforcement layer complete - files are now protected at all access paths
- Ready for UI controls (Phase 3) to allow setting permission levels
- Infrastructure requirement: img_auth.php must be configured for ImgAuthBeforeStream to fire

---
*Phase: 02-core-enforcement*
*Completed: 2026-01-28*
