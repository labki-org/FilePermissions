# Roadmap: FilePermissions

## Overview

FilePermissions delivers group-based, byte-level access control for MediaWiki uploaded files. The roadmap follows an infrastructure-first, enforcement-before-UI strategy: web server configuration and permission storage come first, then hook-based enforcement, then user-facing upload and management interfaces. This ordering ensures no security windows where UI exists but enforcement is incomplete.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [ ] **Phase 1: Foundation & Infrastructure** - Permission model, storage, and configuration
- [ ] **Phase 2: Core Enforcement** - Hook-based access control for all content paths
- [ ] **Phase 3: Upload Integration** - Permission selection during Special:Upload
- [ ] **Phase 4: Display & Management** - Permission visibility and admin editing on File pages
- [ ] **Phase 5: MsUpload Integration** - JavaScript bridge for drag-drop upload permissions

## Phase Details

### Phase 1: Foundation & Infrastructure
**Goal**: Establish the permission model, configuration system, and storage layer that all other phases depend on
**Depends on**: Nothing (first phase)
**Requirements**: PERM-01, PERM-02, PERM-03, PERM-04, PERM-05, PERM-06, PERM-07, PERM-08, CONF-01, CONF-02
**Success Criteria** (what must be TRUE):
  1. Permission levels can be configured via LocalSettings.php ($wgFilePermLevels)
  2. Group-to-level grants can be configured via LocalSettings.php ($wgFilePermGroupGrants)
  3. Extension validates configuration on load and fails closed on invalid config
  4. A file's permission level can be stored and retrieved from PageProps
  5. Default permission levels resolve correctly (global default, namespace override, fallback)
**Plans**: 2 plans

Plans:
- [x] 01-01-PLAN.md - Extension skeleton & configuration system
- [x] 01-02-PLAN.md - Permission service & PageProps storage

### Phase 2: Core Enforcement
**Goal**: Unauthorized users cannot access protected files through any content path
**Depends on**: Phase 1
**Requirements**: ENFC-01, ENFC-02, ENFC-03, ENFC-04, ENFC-05
**Success Criteria** (what must be TRUE):
  1. Unauthorized user receives permission error when viewing File: description page
  2. Unauthorized user receives 403 when requesting raw file via img_auth.php
  3. Unauthorized user receives 403 when requesting thumbnail via img_auth.php
  4. Embedded images fail to render for unauthorized users (broken image or placeholder)
  5. Permission check correctly falls back to default level for files without explicit permission
**Plans**: TBD

Plans:
- [ ] 02-01: TBD
- [ ] 02-02: TBD

### Phase 3: Upload Integration
**Goal**: Users can set permission level when uploading files via Special:Upload
**Depends on**: Phase 2
**Requirements**: UPLD-01, UPLD-02, UPLD-03, UPLD-04
**Success Criteria** (what must be TRUE):
  1. Permission dropdown appears on Special:Upload form
  2. Dropdown options match configured $wgFilePermLevels
  3. Default selection reflects namespace context or global default
  4. Uploaded file has selected permission level stored in PageProps
**Plans**: TBD

Plans:
- [ ] 03-01: TBD

### Phase 4: Display & Management
**Goal**: Users can see file permissions and admins can change them on File pages
**Depends on**: Phase 3
**Requirements**: FPUI-01, FPUI-02, FPUI-03
**Success Criteria** (what must be TRUE):
  1. Permission level badge/indicator visible on File: description pages
  2. Sysop users see permission edit interface (dropdown + save) on File pages
  3. Sysop can change permission level and change persists to PageProps
**Plans**: TBD

Plans:
- [ ] 04-01: TBD

### Phase 5: MsUpload Integration
**Goal**: Users can set permission level when uploading files via MsUpload drag-drop
**Depends on**: Phase 4
**Requirements**: MSUP-01, MSUP-02, MSUP-03, MSUP-04, MSUP-05
**Research Flag**: NEEDS RESEARCH - MsUpload JavaScript API is undocumented; planning should include source code review to identify integration points
**Success Criteria** (what must be TRUE):
  1. Permission dropdown appears in MsUpload toolbar when MsUpload is present
  2. Dropdown defaults based on current page namespace
  3. Selected permission level is transmitted with upload request
  4. Uploaded file has selected permission level stored in PageProps
  5. Upload without selection uses namespace/global default
**Plans**: TBD

Plans:
- [ ] 05-01: TBD
- [ ] 05-02: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3 -> 4 -> 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation & Infrastructure | 2/2 | Complete | 2026-01-28 |
| 2. Core Enforcement | 0/? | Not started | - |
| 3. Upload Integration | 0/? | Not started | - |
| 4. Display & Management | 0/? | Not started | - |
| 5. MsUpload Integration | 0/? | Not started | - |

---
*Roadmap created: 2026-01-28*
*Depth: standard (5 phases)*
*Coverage: 27/27 v1 requirements mapped*
