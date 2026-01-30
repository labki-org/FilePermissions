# Project Milestones: FilePermissions

## v1.1 Testing & CI (Shipped: 2026-01-30)

**Delivered:** Complete test suite proving byte-level file permission enforcement across unit, integration, and E2E HTTP layers, with automated CI pipeline running all tiers on every PR.

**Phases completed:** 7-10 (7 plans total)

**Key accomplishments:**
- PHPUnit test infrastructure with MediaWiki discovery via TestAutoloadNamespaces
- 89 unit tests proving Config and PermissionService logic with fully mocked dependencies
- 48 integration tests verifying enforcement hooks, upload hooks, and API modules within MW runtime
- 33 E2E HTTP tests proving unauthorized users cannot download protected file bytes through any access vector
- Full permission matrix tested: 3 levels × 3 users × 2 vectors = 18 scenarios
- GitHub Actions CI pipeline with Docker health checks running all test tiers automatically

**Stats:**
- 39 files created/modified
- 4,485 lines of test code (PHP)
- 4 phases, 7 plans
- 2 days (2026-01-29 to 2026-01-30)
- 33 git commits
- 152 test methods across 10 test files

**Git range:** `ec18646` (docs(07): capture phase context) → `53c04d6` (docs(v1.1): complete milestone audit)

**What's next:** TBD — next milestone to be planned with `/gsd:new-milestone`

---

## v1.0 MVP (Shipped: 2026-01-29)

**Delivered:** Group-based, byte-level access control for MediaWiki uploaded files with permission enforcement across all content paths and three upload integration points.

**Phases completed:** 1-6 (12 plans + 1 gap closure, total 13)

**Key accomplishments:**
- Permission model with configurable levels, group grants, namespace defaults, and fail-closed validation
- Byte-level enforcement across all access paths: File: pages, img_auth.php downloads, thumbnails, and embedded images
- Permission selection during Special:Upload with server-side validation and deferred PageProps storage
- File page permission display (badge indicator) and sysop edit interface with audit logging
- MsUpload drag-drop integration via JS bridge with plupload event binding
- VisualEditor upload dialog integration via BookletLayout monkey-patching and XHR interception

**Stats:**
- 20 files created
- 2,196 lines of code (1,365 PHP, 471 JS, 102 CSS, 258 JSON)
- 6 phases, 13 plans
- 2 days (2026-01-28 to 2026-01-29)
- 74 git commits

**Git range:** `fb29416` (docs: initialize project) → `8781d5c` (docs: update state after UAT pass)

**What's next:** Project complete. v2 scope deferred (admin tools, search integration, audit logging).

---
