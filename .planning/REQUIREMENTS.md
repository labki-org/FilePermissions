# Requirements: FilePermissions v1.1 Testing & CI

**Defined:** 2026-01-29
**Core Value:** Files are protected at the byte level — unauthorized users cannot view, embed, or download protected files, period.

## v1.1 Requirements

Requirements for testing milestone. Proves that v1.0 enforcement works across all access vectors.

### Unit Tests

- [x] **UNIT-01**: Config tests validate correct behavior with valid configuration (levels, grants, defaults)
- [x] **UNIT-02**: Config tests verify fail-closed behavior on invalid/missing configuration
- [x] **UNIT-03**: Config tests cover edge cases (empty levels array, missing grants, unknown level names)
- [x] **UNIT-04**: PermissionService tests verify permission checks with mocked DB provider (group matching, deny, allow)
- [x] **UNIT-05**: PermissionService tests verify default level assignment for files without explicit level
- [x] **UNIT-06**: PermissionService tests verify behavior for unknown/missing files (null level)

### Integration Tests

- [x] **INTG-01**: EnforcementHooks getUserPermissionsErrors denies unauthorized user access to File: pages
- [x] **INTG-02**: EnforcementHooks ImgAuthBeforeStream denies unauthorized file downloads via img_auth.php
- [x] **INTG-03**: EnforcementHooks ImageBeforeProduceHTML blocks embedding of protected images for unauthorized users
- [x] **INTG-04**: UploadHooks UploadVerifyUpload rejects uploads with invalid permission levels
- [x] **INTG-05**: UploadHooks stores permission level in fileperm_levels table on successful upload
- [x] **INTG-06**: ApiFilePermSetLevel sets permission level via API with proper authorization
- [x] **INTG-07**: ApiFilePermSetLevel denies permission changes from non-sysop users
- [x] **INTG-08**: ApiQueryFilePermLevel returns correct permission level for queried files
- [x] **INTG-09**: PermissionService round-trips setLevel/getLevel/removeLevel through fileperm_levels table
- [x] **INTG-10**: PermissionService in-process cache returns correct values and doesn't poison cross-scenario tests

### E2E HTTP Leak Checks

- [x] **LEAK-01**: Unauthorized user gets 403 from img_auth.php for confidential file download
- [x] **LEAK-02**: Unauthorized user gets 403 from img_auth.php for confidential file thumbnail
- [x] **LEAK-03**: Direct /images/ path is blocked by Apache (403 for all users)
- [x] **LEAK-04**: Direct /images/thumb/ path is blocked by Apache (403 for all users)
- [x] **LEAK-05**: Authorized user can download files at their granted permission levels
- [x] **LEAK-06**: Public files are accessible to all authenticated users
- [x] **LEAK-07**: Full permission matrix tested: 3 levels × 2 user roles × all access vectors
- [x] **LEAK-08**: Test authentication uses MW API login (cookie-based sessions, not anonymous)

### CI Pipeline

- [ ] **CI-01**: GitHub Actions workflow runs on PRs and pushes to main
- [ ] **CI-02**: Docker Compose starts with health checks (not fixed sleep)
- [ ] **CI-03**: PHPUnit job runs unit + integration tests inside container
- [ ] **CI-04**: E2E job runs HTTP leak checks against live wiki
- [ ] **CI-05**: Both jobs must pass for PR to be mergeable

### Test Infrastructure

- [x] **INFRA-01**: phpunit.xml configured for extension test discovery
- [x] **INFRA-02**: extension.json TestAutoloadNamespaces configured for test classes
- [x] **INFRA-03**: Test data setup script seeds files at each permission level with correct fileperm_levels entries
- [x] **INFRA-04**: E2E bootstrap handles MW API authentication for test users

## Future Requirements

Deferred to future milestone. Tracked but not in current roadmap.

### Browser Testing

- **BROWSER-01**: Selenium/Playwright tests for Special:Upload permission selection UI
- **BROWSER-02**: Browser tests for MsUpload drag-drop permission dialog
- **BROWSER-03**: Browser tests for VisualEditor upload permission selection

### Advanced Coverage

- **ADV-01**: Code coverage reporting integrated into CI
- **ADV-02**: Performance benchmarks for permission checks under load
- **ADV-03**: Fuzz testing of permission level strings

## Out of Scope

| Feature | Reason |
|---------|--------|
| Browser/Selenium tests | HTTP-level checks cover security surface; browser tests add complexity without security value for v1.1 |
| JavaScript unit tests | JS modules are UI bridges; security enforcement is server-side PHP |
| Code coverage thresholds | Focus on meaningful test scenarios, not arbitrary % targets |
| Load/stress testing | Extension is lightweight; performance issues unlikely at expected scale |
| MsUpload/VE integration tests | Upload path integration is JS-level; enforcement is tested via hooks |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| UNIT-01 | Phase 7 | Complete |
| UNIT-02 | Phase 7 | Complete |
| UNIT-03 | Phase 7 | Complete |
| UNIT-04 | Phase 7 | Complete |
| UNIT-05 | Phase 7 | Complete |
| UNIT-06 | Phase 7 | Complete |
| INTG-01 | Phase 8 | Complete |
| INTG-02 | Phase 8 | Complete |
| INTG-03 | Phase 8 | Complete |
| INTG-04 | Phase 8 | Complete |
| INTG-05 | Phase 8 | Complete |
| INTG-06 | Phase 8 | Complete |
| INTG-07 | Phase 8 | Complete |
| INTG-08 | Phase 8 | Complete |
| INTG-09 | Phase 8 | Complete |
| INTG-10 | Phase 8 | Complete |
| LEAK-01 | Phase 9 | Complete |
| LEAK-02 | Phase 9 | Complete |
| LEAK-03 | Phase 9 | Complete |
| LEAK-04 | Phase 9 | Complete |
| LEAK-05 | Phase 9 | Complete |
| LEAK-06 | Phase 9 | Complete |
| LEAK-07 | Phase 9 | Complete |
| LEAK-08 | Phase 9 | Complete |
| CI-01 | Phase 10 | Pending |
| CI-02 | Phase 10 | Pending |
| CI-03 | Phase 10 | Pending |
| CI-04 | Phase 10 | Pending |
| CI-05 | Phase 10 | Pending |
| INFRA-01 | Phase 7 | Complete |
| INFRA-02 | Phase 7 | Complete |
| INFRA-03 | Phase 9 | Complete |
| INFRA-04 | Phase 9 | Complete |

**Coverage:**
- v1.1 requirements: 33 total (UNIT: 6, INTG: 10, LEAK: 8, CI: 5, INFRA: 4)
- Mapped to phases: 33
- Unmapped: 0

---
*Requirements defined: 2026-01-29*
*Last updated: 2026-01-30 (Phase 9 requirements complete)*
