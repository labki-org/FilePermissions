# Project Research Summary

**Project:** FilePermissions - MediaWiki per-file group-based access control extension
**Domain:** MediaWiki extension development, file permissions and access control
**Researched:** 2026-01-28
**Confidence:** MEDIUM-HIGH

## Executive Summary

FilePermissions is a MediaWiki extension providing per-file, group-based access control with upload-time permission selection. The domain is well-documented but challenging: MediaWiki was designed for openness, not access restriction, creating multiple potential security bypass paths (thumbnails, caching, search, logs). Success requires both PHP hook implementation and critical infrastructure configuration (img_auth.php routing, web server blocking).

The recommended approach uses MediaWiki's PageProps for storage, getUserPermissionsErrors and ImgAuthBeforeStream hooks for enforcement, and a JavaScript bridge for MsUpload integration. Core technologies are standard (MW 1.44, PHP 8.1+, ResourceLoader), with no exotic dependencies. The architecture follows established patterns from sibling extensions like Lockdown and NSFileRepo.

The key risk is incomplete enforcement: protecting File: pages but missing thumbnails, direct access, or cached content creates a false sense of security while data leaks. Mitigation requires infrastructure-first validation (web server config, img_auth setup) before any PHP code, defense-in-depth testing of all content exit paths, and accepting that some metadata leakage (logs, search) may be unavoidable in MediaWiki's architecture.

## Key Findings

### Recommended Stack

MediaWiki 1.44 on PHP 8.1-8.3 provides the foundation. The extension uses modern MW patterns: PSR-4 autoloading via extension.json, HookHandlers with dependency injection (MW 1.35+), and PageProps for permission storage. Critical hooks are getUserPermissionsErrors (wiki page access) and ImgAuthBeforeStream (direct file downloads). MsUpload integration uses ResourceLoader modules with JavaScript bridges, not code forking.

**Core technologies:**
- MediaWiki 1.44.x: LTS target platform, well-documented hook system
- PHP 8.1-8.3: Runtime matching production requirements
- PageProps service: Persistent, queryable storage via page_props table
- img_auth.php: Mandatory for byte-level file protection
- ResourceLoader: JavaScript/CSS delivery with OOJS-UI for permission selectors

**Critical version constraints:**
- MW 1.42+ requires string values in PageProps (no objects/arrays)
- getUserPermissionsErrors is the correct hook (userCan deprecated since 1.37)
- HookHandlers pattern is standard since MW 1.35

### Expected Features

Research identified a clear feature hierarchy. Existing extensions (Lockdown, NSFileRepo, AccessControl) do namespace or page-level protection; none offer clean per-file permissions with upload-time selection.

**Must have (table stakes):**
- Per-file permission assignment via PageProps
- Group-based access control using MW's existing groups
- Byte-level enforcement via img_auth.php hook
- Upload-time permission selection in Special:Upload
- Permission display on file pages
- Admin ability to change permissions post-upload
- Graceful 403 for unauthorized access

**Should have (competitive differentiators):**
- Simple permission levels (public/internal/lab/restricted) vs complex ACLs
- MsUpload drag-drop integration
- Permission change audit logging
- Visual permission indicators on file listings
- Thumbnail protection (critical security requirement)
- Archive/old version protection

**Defer (v2+):**
- Bulk permission changes (admin convenience)
- Search/filter by permission level
- API for programmatic access
- UploadWizard integration (complex)
- Private links/temporary access tokens (scope creep)

**Anti-features (explicitly avoid):**
- Per-user ACLs (use groups only)
- Custom group management (use MW core)
- In-page permission tags (store in DB)
- Namespace-only permissions (that's Lockdown)
- Complex inheritance/cascading rules

### Architecture Approach

The architecture is layered: PermissionManager (business logic) → PagePropsStorage (data layer) → HookHandler (enforcement) → API/UI (interfaces). This separates concerns and enables testing. The design integrates with MW's permission infrastructure rather than replacing it.

**Major components:**
1. **PermissionManager service** — Core logic for permission checks, uses DI pattern
2. **PagePropsStorage** — Abstracts page_props table access, stores level strings
3. **HookHandler** — Implements getUserPermissionsErrors + ImgAuthBeforeStream, injected via ServiceWiring
4. **ApiSetFilePermission** — REST endpoint for JS to set permissions, CSRF-protected
5. **ResourceLoader bridge** — Observes MsUpload via DOM events, prompts for level, calls API

**Key patterns:**
- Service registration via ServiceWiring.php for dependency injection
- Early return optimization (check namespace before expensive queries)
- Static Config class for reading $wgFilePermissions* globals
- IIFE pattern for ResourceLoader modules
- No global hook registration ($wgHooks deprecated)

**Critical flow:** Upload → JS bridge detects completion → User selects level → API stores in PageProps → Read requests hit hook → Hook queries PageProps → Check user groups → Allow or deny

### Critical Pitfalls

Five pitfalls can cause complete security bypass. All require architectural decisions, not just bug fixes.

1. **Thumbnail/derivative bypass** — Thumbnails in /images/thumb/ bypass protection if web server allows direct access. Web server must block entire /images/ tree including subdirectories. Test specifically for thumbnail URLs. (PHASE: Infrastructure)

2. **Direct /images/ access** — Setting $wgUploadPath only changes MW-generated URLs; users can still request /images/a/ab/File.jpg directly. Must block in Apache/Nginx config OR move uploads outside webroot. This is #1 mistake per official docs. (PHASE: Infrastructure)

3. **Caching exposes protected content** — Parser cache serves one version to all users. Admin views protected file page → cached → unauthorized user sees it. Consider CACHE_NONE for protected content or implement cache key variants. (PHASE: Architecture)

4. **Search index leaks** — File descriptions appear in Special:Search regardless of permissions. Must exclude protected namespaces from search config or filter results. (PHASE: Feature)

5. **Recent Changes/logs disclosure** — Upload logs show file names and summaries publicly. Hook into LogEventsListLineEnding to filter. Educate users: no sensitive info in edit summaries. (PHASE: Feature)

**Moderate risks:**
- Embedded images bypass checks if parser doesn't enforce (test [[File:Protected.jpg]] embeds)
- MsUpload uploads may lose permission if JS bridge timing wrong
- Re-upload might overwrite permission unexpectedly
- Template transclusion of File: description pages leaks content

**Minor issues:**
- Permission level typos/case mismatches
- Error messages revealing file existence (use generic errors)
- Orphaned PageProps after file deletion

## Implications for Roadmap

Based on dependencies and risk mitigation, infrastructure must come first, then core enforcement, then UI polish. This order ensures we never have partial protection (dangerous).

### Suggested Phase Structure: 5 Phases

### Phase 1: Foundation & Infrastructure
**Rationale:** Web server configuration is more important than PHP code. Must validate direct access blocking before writing any permission logic.

**Delivers:**
- img_auth.php routing configured and tested
- Web server blocks direct /images/ access
- PageProps schema and storage abstraction
- Config.php for permission level definitions
- Static permission level constants

**Addresses (from FEATURES.md):**
- Database foundation for file-permission mapping
- Group-to-level mapping configuration

**Avoids (from PITFALLS.md):**
- Direct /images/ access bypass (Pitfall #2)
- Thumbnail bypass via web server holes (Pitfall #1)

**Architecture components:**
- Config.php
- PagePropsStorage.php
- ServiceWiring.php

**Research flag:** Skip research — well-documented MW patterns (PageProps, img_auth.php docs are comprehensive)

---

### Phase 2: Core Enforcement
**Rationale:** Permission checks must be complete before adding UI. Enforcement without UI is safe (defaults to deny); UI without enforcement is dangerous.

**Delivers:**
- PermissionManager service with permission logic
- getUserPermissionsErrors hook implementation
- ImgAuthBeforeStream hook for byte-level protection
- HookHandler with dependency injection
- Basic error messages (generic, no enumeration)

**Addresses (from FEATURES.md):**
- Group-based access control
- Byte-level enforcement via img_auth.php
- Deny unauthorized access gracefully

**Avoids (from PITFALLS.md):**
- img_auth.php error message leaks (Pitfall #12)
- Embedded image check missing (Pitfall #6)

**Architecture components:**
- PermissionManager.php
- HookHandler.php (getUserPermissionsErrors, ImgAuthBeforeStream)

**Research flag:** Skip research — hook interfaces fully documented, reference implementations exist (Lockdown)

---

### Phase 3: Upload Integration
**Rationale:** With enforcement working, safely add upload-time permission selection. Users can now set permissions; unauthorized access already blocked.

**Delivers:**
- Special:Upload form integration (dropdown for level selection)
- UploadComplete hook to persist selection
- Default permission logic based on namespace
- Re-upload handling (preserve vs. reset decision)
- Basic validation of permission levels

**Addresses (from FEATURES.md):**
- Upload-time permission selection
- Namespace-based defaults

**Avoids (from PITFALLS.md):**
- Upload form permission not persisted (Pitfall #7)
- PageProps not set on re-upload (Pitfall #9)

**Architecture components:**
- UploadHooks.php
- Form field injection

**Research flag:** Skip research — UploadComplete hook is straightforward, examples in MW docs

---

### Phase 4: Display & Management UI
**Rationale:** Core workflow complete. Now add visibility and admin tools.

**Delivers:**
- Permission display badge on file pages
- Admin permission change interface (Special page or file page form)
- ApiSetFilePermission endpoint
- Permission change audit logging
- Visual indicators (color-coded badges)

**Addresses (from FEATURES.md):**
- Permission display on file pages
- Admin ability to change permissions
- Audit logging (differentiator)
- Visual indicators (differentiator)

**Avoids (from PITFALLS.md):**
- Orphaned permissions after deletion (Pitfall #13)

**Architecture components:**
- Api/ApiSetFilePermission.php
- BeforePageDisplay hook
- SpecialFilePermissions.php (optional)

**Research flag:** Skip research — MW API patterns and SpecialPage patterns are standard

---

### Phase 5: MsUpload Integration
**Rationale:** Nice-to-have feature, complex integration, deferred to avoid blocking core functionality.

**Delivers:**
- ResourceLoader module for MsUpload bridge
- JavaScript observes MsUpload completion events
- Permission selection dialog on drag-drop upload
- API call to set permission after upload
- Fallback to default if selection skipped

**Addresses (from FEATURES.md):**
- MsUpload integration (key differentiator)

**Avoids (from PITFALLS.md):**
- MsUpload bypass (Pitfall #8)

**Architecture components:**
- resources/msupload-bridge.js
- OOUI permission selector dialog

**Research flag:** NEEDS RESEARCH — MsUpload JavaScript API is undocumented; will need to inspect source during planning to find integration points (event hooks, DOM structure, callback API)

---

### Phase Ordering Rationale

1. **Infrastructure first prevents false security:** No PHP code until img_auth.php and web server blocking are validated. Can't have protection that only works in some cases.

2. **Enforcement before UI avoids security window:** Enforcement with default-deny is safe. UI without enforcement is a vulnerability waiting to happen.

3. **Upload integration after enforcement enables testing:** Can manually set permissions via DB, test enforcement, then add UI once enforcement proven.

4. **Display/management after upload establishes admin tools:** Upload covers 80% of user workflow; display/management is admin polish.

5. **MsUpload last allows complexity containment:** Undocumented integration; if it fails, core functionality unaffected.

**Dependency chain:**
- Phase 2 requires Phase 1 (PermissionManager needs PagePropsStorage)
- Phase 3 requires Phase 2 (Upload needs enforcement to test against)
- Phase 4 requires Phase 3 (Display shows what upload sets)
- Phase 5 requires Phase 4 (MsUpload bridge uses API from Phase 4)

**Risk mitigation alignment:**
- Critical pitfalls addressed in Phases 1-2 (infrastructure, enforcement)
- Moderate pitfalls addressed in Phases 3-5 (upload persistence, MsUpload bypass)
- Minor pitfalls handled as we encounter them

### Research Flags

**Phases needing deeper research during planning:**
- **Phase 5 (MsUpload Integration):** MsUpload has no documented JavaScript API for extensions. Will need to:
  - Inspect MsUpload.js source for event hooks or callbacks
  - Test with actual MsUpload instance to find DOM structure
  - Determine if plupload library exposes BeforeUpload event
  - Validate that form data can be injected before upload starts

**Phases with standard patterns (skip research-phase):**
- **Phase 1 (Foundation):** PageProps, img_auth.php, web server config all documented
- **Phase 2 (Core Enforcement):** getUserPermissionsErrors and ImgAuthBeforeStream hooks have full documentation and reference implementations
- **Phase 3 (Upload Integration):** UploadComplete hook is straightforward with examples
- **Phase 4 (Display & Management):** MW API and SpecialPage patterns are standard

**Additional validation needed:**
- **Caching strategy** (Pitfall #3): Requires testing with actual MW parser cache to determine if CACHE_NONE is mandatory or if cache key variants work
- **Search filtering** (Pitfall #4): Verify CirrusSearch (if used) can be configured to exclude File: namespace; if not, need hook-based filtering

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | MW 1.44 and hooks fully documented; PSR-4 autoloading standard; PageProps well-understood |
| Features | MEDIUM-HIGH | Feature landscape clear from existing extensions; table stakes vs differentiators validated; anti-features identified from known failures |
| Architecture | MEDIUM-HIGH | Component boundaries proven by sibling extensions; patterns verified in Lockdown/NSFileRepo; some uncertainty on caching strategy |
| Pitfalls | HIGH | Security issues extensively documented in official MW docs; thumbnail/direct access bypasses are #1/#2 documented failures; caching/search leaks confirmed in multiple sources |

**Overall confidence:** MEDIUM-HIGH

All major architectural decisions are grounded in official documentation or verified reference implementations. The medium rating (not high) is due to:
1. MsUpload integration details require source inspection (no official API docs)
2. Caching strategy may need production testing to finalize
3. Some edge cases (transclusion, embedded images) need validation during implementation

### Gaps to Address

**MsUpload JavaScript API:**
- Gap: No official documentation for extension integration
- Resolution: Phase 5 planning should include MsUpload source code review
- Fallback: If integration proves too fragile, defer to v2 or recommend standard Special:Upload

**Parser cache behavior with per-user content:**
- Gap: Unclear if MediaWiki supports cache key variants for per-user file access
- Resolution: Test during Phase 2 with actual parser cache enabled
- Fallback: Disable parser cache for File: namespace if variants don't work

**Search integration options:**
- Gap: Multiple MW search backends (built-in, CirrusSearch, others) have different filtering capabilities
- Resolution: Document config for each search backend during Phase 4
- Fallback: Disable File: namespace indexing entirely if filtering too complex

**Thumbnail protection completeness:**
- Gap: MW generates multiple derivative sizes; need to verify all go through img_auth
- Resolution: Test all thumbnail sizes during Phase 1 infrastructure validation
- Fallback: Document known bypass paths if some derivatives can't be protected

**Permission inheritance for file versions:**
- Gap: Archive table (old file versions) needs separate consideration
- Resolution: Address in Phase 2 when implementing enforcement hooks
- Decision needed: Do old versions keep their original permission or inherit current?

## Sources

### Primary (HIGH confidence)
- [MediaWiki 1.44 Release Notes](https://www.mediawiki.org/wiki/MediaWiki_1.44)
- [Manual:Hooks/getUserPermissionsErrors](https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors)
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization)
- [Manual:Page props table](https://www.mediawiki.org/wiki/Manual:Page_props_table)
- [Manual:Preventing access](https://www.mediawiki.org/wiki/Manual:Preventing_access)
- [Manual:Security](https://www.mediawiki.org/wiki/Manual:Security)
- [Security issues with authorization extensions](https://www.mediawiki.org/wiki/Security_issues_with_authorization_extensions)
- [ResourceLoader/Developing](https://www.mediawiki.org/wiki/ResourceLoader/Developing_with_ResourceLoader)
- [Dependency Injection](https://www.mediawiki.org/wiki/Dependency_Injection)

### Secondary (MEDIUM confidence)
- [Extension:Lockdown](https://www.mediawiki.org/wiki/Extension:Lockdown) — Reference implementation, known limitations
- [Extension:NSFileRepo](https://www.mediawiki.org/wiki/Extension:NSFileRepo) — File protection patterns
- [Extension:MsUpload](https://www.mediawiki.org/wiki/Extension:MsUpload) — Target integration
- [Extension:AccessControl](https://www.mediawiki.org/wiki/Extension:AccessControl) — Caching/search issues
- [GitHub: mediawiki-extensions-Lockdown](https://github.com/wikimedia/mediawiki-extensions-Lockdown) — Hook implementation examples
- [GitHub: mediawiki-extensions-MsUpload](https://github.com/wikimedia/mediawiki-extensions-MsUpload) — Upload extension structure
- [Description2 Extension](https://github.com/wikimedia/mediawiki-extensions-Description2) — PageProps usage pattern

### Tertiary (LOW confidence)
- Community forum discussions on file protection challenges
- Extension talk pages with user-reported bypass methods

---
*Research completed: 2026-01-28*
*Ready for roadmap: yes*
