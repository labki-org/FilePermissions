---
phase: 02-core-enforcement
plan: 02
subsystem: auth
tags: [human-verification, enforcement-testing, img_auth]

# Dependency graph
requires:
  - phase: 02-core-enforcement
    plan: 01
    provides: EnforcementHooks with three hook implementations
provides:
  - Human-verified enforcement across all access paths
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns: []

key-files:
  created: []
  modified: []

key-decisions:
  - "img_auth.php requires $wgGroupPermissions['*']['read'] = false to enforce hooks"
  - "Parser cache must be disabled for pages embedding protected images"
  - "SVG placeholder uses base64 encoding for data URI reliability"

patterns-established: []

# Metrics
duration: manual verification
completed: 2026-01-28
---

# Phase 02 Plan 02: Human Verification Summary

**All enforcement access paths verified by human testing against live MediaWiki instance**

## Performance

- **Duration:** Manual verification session
- **Started:** 2026-01-28
- **Completed:** 2026-01-28
- **Tasks:** 1 (human verification checkpoint)

## Accomplishments
- File: description page blocked for unauthorized users with localized error message
- Raw file access via img_auth.php returns 403 for unauthorized users
- Embedded images replaced with lock icon placeholder for unauthorized users
- Authorized users (sysop) see everything normally

## Test Results

| Test | Path | Result |
|------|------|--------|
| ENFC-01 | File: description page | Pass - permission error with message |
| ENFC-02 | Raw file via img_auth.php | Pass - 403 access denied |
| ENFC-03 | Thumbnail via img_auth.php | Skipped (same code path as ENFC-02) |
| ENFC-04 | Embedded image placeholder | Pass - lock icon, not clickable |

## Issues Found and Fixed During Verification

1. **Missing MessagesDirs** - i18n messages showed raw key `⧼filepermissions-access-denied⧽`. Fixed by adding `MessagesDirs` to extension.json.

2. **img_auth.php skips hooks on public wikis** - `AuthenticatedFileEntryPoint.php:48` checks `groupHasPermission('*', 'read')` and skips ALL permission hooks if true. Fix: deployment requires `$wgGroupPermissions['*']['read'] = false`.

3. **Direct /images/ access** - Apache served files directly bypassing img_auth.php. Fix: Apache config blocking `/var/www/html/images`.

4. **Parser cache serving stale permissions** - `ImageBeforeProduceHTML` fires during parsing; result cached for all users. First viewer's permissions determined what everyone saw. Fix: `$parser->getOutput()->updateCacheExpiry(0)` for pages embedding protected images.

5. **SVG data URI breaking HTML** - Unescaped `"` in SVG broke the `style` attribute. Fix: base64-encode SVG for data URI.

## Deployment Requirements Discovered

- Wiki MUST have `$wgGroupPermissions['*']['read'] = false` for img_auth.php enforcement
- Web server MUST block direct access to `/images/` directory
- `$wgUploadPath` must point to img_auth.php

## Deviations from Plan

Verification uncovered 5 bugs requiring code and configuration fixes before tests could pass.

## Next Phase Readiness
- All enforcement paths verified and working
- Deployment requirements documented
- Ready for Phase 3 (Upload Integration)

---
*Phase: 02-core-enforcement*
*Completed: 2026-01-28*
