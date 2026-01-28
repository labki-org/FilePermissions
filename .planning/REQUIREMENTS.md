# Requirements: FilePermissions

**Defined:** 2025-01-28
**Core Value:** Files are protected at the byte level — unauthorized users cannot view, embed, or download protected files

## v1 Requirements

Requirements for initial release. Each maps to roadmap phases.

### Permission Model

- [ ] **PERM-01**: Extension stores one permission level per file in PageProps (`mwfileperm_level`)
- [ ] **PERM-02**: Permission levels configurable via `$wgFilePermLevels` array
- [ ] **PERM-03**: Group-to-level mapping via `$wgFilePermGroupGrants` configuration
- [ ] **PERM-04**: Wildcard `'*'` in grants means access to all permission levels
- [ ] **PERM-05**: User's effective permissions = union of all their group grants
- [ ] **PERM-06**: Global default level via `$wgFilePermDefaultLevel`
- [ ] **PERM-07**: Namespace-based defaults via `$wgFilePermByNamespaceDefault`
- [ ] **PERM-08**: Invalid/missing permissions treated as global default

### Enforcement

- [ ] **ENFC-01**: `getUserPermissionsErrors` hook denies File: page access to unauthorized users
- [ ] **ENFC-02**: `ImgAuthBeforeStream` hook denies raw file downloads to unauthorized users
- [ ] **ENFC-03**: Thumbnail access denied to unauthorized users (via ImgAuthBeforeStream)
- [ ] **ENFC-04**: Embedded images fail to render for unauthorized users
- [ ] **ENFC-05**: Permission check fetches level from PageProps with default fallback

### Upload Integration — Special:Upload

- [ ] **UPLD-01**: Permission dropdown added to Special:Upload form
- [ ] **UPLD-02**: Dropdown options populated from `$wgFilePermLevels`
- [ ] **UPLD-03**: Default selection based on namespace context or global default
- [ ] **UPLD-04**: `UploadComplete` hook stores selected permission level in PageProps

### Upload Integration — MsUpload

- [ ] **MSUP-01**: JS bridge module loads when MsUpload is present
- [ ] **MSUP-02**: Permission dropdown injected into MsUpload toolbar
- [ ] **MSUP-03**: Dropdown defaults based on current page namespace
- [ ] **MSUP-04**: Selected permission level appended to upload FormData
- [ ] **MSUP-05**: Server-side hook captures `fileperm_level` from request

### File Page UI

- [ ] **FPUI-01**: Permission level displayed on File: description pages (badge/indicator)
- [ ] **FPUI-02**: Privileged users (sysop) can edit permission level directly on File: page
- [ ] **FPUI-03**: Permission edit interface (dropdown + save) accessible via action tab or inline

### Configuration

- [ ] **CONF-01**: Static `Config.php` class provides typed access to all config variables
- [ ] **CONF-02**: Configuration validation on extension load (fail closed on invalid config)

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Administrative Tools

- **ADMN-01**: Special:FilePermissions page for bulk permission changes
- **ADMN-02**: API endpoint for programmatic permission management
- **ADMN-03**: Audit log for permission changes

### Search Integration

- **SRCH-01**: Filter search results by permission level
- **SRCH-02**: Hide protected file names from unauthorized users in search

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Per-user ACLs | Group-based only — reduces complexity, aligns with MW permission model |
| Complex role hierarchies | Flat permission levels — simple mental model |
| File inheritance trees | No nested policies — each file stands alone |
| MediaWiki core modifications | Extension hooks only |
| MsUpload forking | Bridge module only — no MsUpload changes |
| Lockdown integration | Independent system — replaces Lockdown for files |
| SMW dependency | PageProps is sufficient — no Semantic MediaWiki required |
| Audit logging (v1) | Deferred to v2 — user confirmed not needed initially |
| Special:FilePermissions | Replaced by direct editing on File: pages |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| PERM-01 | TBD | Pending |
| PERM-02 | TBD | Pending |
| PERM-03 | TBD | Pending |
| PERM-04 | TBD | Pending |
| PERM-05 | TBD | Pending |
| PERM-06 | TBD | Pending |
| PERM-07 | TBD | Pending |
| PERM-08 | TBD | Pending |
| ENFC-01 | TBD | Pending |
| ENFC-02 | TBD | Pending |
| ENFC-03 | TBD | Pending |
| ENFC-04 | TBD | Pending |
| ENFC-05 | TBD | Pending |
| UPLD-01 | TBD | Pending |
| UPLD-02 | TBD | Pending |
| UPLD-03 | TBD | Pending |
| UPLD-04 | TBD | Pending |
| MSUP-01 | TBD | Pending |
| MSUP-02 | TBD | Pending |
| MSUP-03 | TBD | Pending |
| MSUP-04 | TBD | Pending |
| MSUP-05 | TBD | Pending |
| FPUI-01 | TBD | Pending |
| FPUI-02 | TBD | Pending |
| FPUI-03 | TBD | Pending |
| CONF-01 | TBD | Pending |
| CONF-02 | TBD | Pending |

**Coverage:**
- v1 requirements: 27 total
- Mapped to phases: 0
- Unmapped: 27 (pending roadmap)

---
*Requirements defined: 2025-01-28*
*Last updated: 2025-01-28 after initial definition*
