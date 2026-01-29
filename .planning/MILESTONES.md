# Project Milestones: FilePermissions

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
