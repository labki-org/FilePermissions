---
phase: 01-foundation-infrastructure
plan: 02
subsystem: infra
tags: [mediawiki, extension, service, dependency-injection, pageprops]

# Dependency graph
requires:
  - phase: 01-01
    provides: Config class with typed getters and isValidLevel/resolveDefaultLevel
provides:
  - PermissionService with PageProps storage (getLevel/setLevel/removeLevel)
  - Permission checking (canUserAccessLevel/canUserAccessFile)
  - Service wiring for dependency injection
affects: [02-permission-enforcement, 03-upload-integration, 04-admin-ui, 05-msupload-integration]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Service class with constructor dependency injection"
    - "PageProps for file-level metadata storage"
    - "IConnectionProvider for database access"
    - "newSelectQueryBuilder/newReplaceQueryBuilder/newDeleteQueryBuilder patterns"

key-files:
  created:
    - includes/PermissionService.php
    - includes/ServiceWiring.php
  modified: []

key-decisions:
  - "Fail-closed: canUserAccessLevel returns false when Config::isInvalidConfig() is true"
  - "Grandfathered files: files with no level and no default are treated as unrestricted"
  - "DBLoadBalancerFactory used as IConnectionProvider (standard MediaWiki pattern)"

patterns-established:
  - "Service access via MediaWikiServices::getInstance()->get('FilePermissions.PermissionService')"
  - "PageProps storage with pp_propname='fileperm_level'"

# Metrics
duration: 1min
completed: 2026-01-28
---

# Phase 01 Plan 02: PermissionService and ServiceWiring Summary

**Core permission service with PageProps storage and group-based access control, registered via MediaWiki dependency injection**

## Performance

- **Duration:** 1 min
- **Started:** 2026-01-28T22:45:13Z
- **Completed:** 2026-01-28T22:46:27Z
- **Tasks:** 2/2
- **Files modified:** 2

## Accomplishments

- Created PermissionService class with complete API for file permission management
- Implemented PageProps storage via getLevel/setLevel/removeLevel methods
- Implemented group-based access control via canUserAccessLevel/canUserAccessFile methods
- Registered service via ServiceWiring.php for dependency injection

## Task Commits

Each task was committed atomically:

1. **Task 1: Create PermissionService class** - `8d42deb` (feat)
2. **Task 2: Create ServiceWiring.php** - `b10bf5c` (feat)

## Files Created/Modified

- `includes/PermissionService.php` - Core service with storage and access control methods
- `includes/ServiceWiring.php` - Service registration for dependency injection

## Decisions Made

1. **Fail-closed on invalid config** - canUserAccessLevel returns false when Config::isInvalidConfig() is true, ensuring security even with misconfiguration
2. **Grandfathered files as unrestricted** - Files with no explicit level and no configured default are treated as unrestricted (return true), allowing backwards compatibility for existing files

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- PermissionService API complete and ready for use by enforcement hooks
- ServiceWiring enables service access via MediaWikiServices
- Ready for Phase 02 (Permission Enforcement) to implement hooks that use this service

---
*Phase: 01-foundation-infrastructure*
*Completed: 2026-01-28*
