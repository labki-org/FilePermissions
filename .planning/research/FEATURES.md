# Feature Landscape: MediaWiki File Permission Extensions

**Domain:** MediaWiki file/page access control extensions
**Researched:** 2026-01-28
**Confidence:** MEDIUM (WebSearch verified against multiple sources, official MediaWiki documentation)

## Executive Summary

The MediaWiki file permissions space has a clear hierarchy of features. Existing extensions (Lockdown, NSFileRepo, AccessControl, SemanticACL) focus on namespace-level or page-level access control, with file protection being an afterthought requiring separate img_auth.php configuration. FilePermissions' per-file, group-based model with upload-time selection is genuinely differentiating - no existing extension offers this workflow cleanly.

**Key insight:** Write restrictions work well in MediaWiki; read restrictions are the hard problem due to caching and multiple content exit paths. FilePermissions' simple permission level model (public/internal/lab/restricted) sidesteps the complexity of full ACL systems.

---

## Table Stakes

Features users expect. Missing = product feels incomplete or unusable.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **Per-file permission assignment** | Core value proposition - must be able to set permission on individual files | Medium | This is what differentiates from Lockdown's namespace-only model |
| **Group-based access control** | Standard MW pattern - users expect group membership to drive access | Low | Map MW groups to permission levels; don't invent new group system |
| **Byte-level enforcement via img_auth.php** | Files must be truly protected, not just page-hidden | High | Critical - without this, "hidden" files are still directly accessible via URL |
| **Upload-time permission selection** | Users need to set permission when uploading, not hunt for it later | Medium | Requires Special:Upload hook integration |
| **Namespace-based defaults** | Reduces friction - files inherit sensible defaults based on where uploaded | Low | Config-driven, simple mapping |
| **Permission display on file pages** | Users need to see current permission level | Low | Simple page indicator/badge |
| **Admin ability to change permissions** | Permissions need to be adjustable after upload | Medium | Special page or action tab |
| **Deny unauthorized access gracefully** | Return 403, not stack trace or partial content | Low | img_auth.php hook must handle this cleanly |
| **Integration with existing MW groups** | Don't require separate group management | Low | Use $wgGroupPermissions groups directly |
| **Works with standard File: namespace** | Don't require custom namespace syntax like NSFileRepo | Medium | NSFileRepo requires `[[File:Private:name.jpg]]` - clunky |

## Differentiators

Features that set FilePermissions apart. Not expected but highly valued.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **Simple permission levels (public/internal/lab/restricted)** | Avoids ACL complexity; users pick from dropdown, not configure matrices | Low | Major UX win vs. AccessControl's template syntax or IntraACL's ACL pages |
| **MsUpload integration** | Drag-and-drop upload with permission selection | Medium | MsUpload is popular; no existing extension integrates permissions with it |
| **Bulk permission changes** | Admin can change multiple files at once | High | No existing extension does this well; common admin need |
| **Permission change audit log** | Track who changed what permission when | Medium | MW has logging infrastructure; BlueSpice does this |
| **Visual permission indicators** | Color-coded badges or icons on file listings | Low | UX polish that signals professionalism |
| **Search/filter by permission level** | Admin can find all "restricted" files | Medium | Useful for auditing; requires custom special page |
| **Permission inheritance for file versions** | Old versions retain permission of current file | Medium | Security concern - archive files need protection too |
| **Thumbnail protection** | Generated thumbnails respect same permissions | Medium | Common security hole - thumbs often bypass protection |
| **API for permission management** | Programmatic access for automation | Medium | Enterprise need; enables integration with other systems |
| **UploadWizard integration** | Step-by-step upload with permission selection | High | Requires deeper UploadWizard campaign configuration |

## Anti-Features

Features to explicitly NOT build. Common mistakes in this domain.

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| **Per-user ACLs** | Complexity explosion; hard to audit; doesn't match user's mental model | Group-based only. If someone needs per-user, they create a single-user group |
| **Custom group management system** | Duplicates MW core; creates sync issues; confuses admins | Use MW's existing groups via $wgGroupPermissions |
| **In-page permission tags like AccessControl** | Requires editing pages; can be accidentally removed; pollutes content | Store permissions in database, separate from content |
| **Namespace-only permissions** | That's what Lockdown does; doesn't solve per-file need | Per-file with namespace defaults |
| **Complex cascading/inheritance rules** | IntraACL's EXTEND/OVERRIDE/SHRINK modes confuse users | Simple model: file has one level, that's it |
| **Whitelist approach for public content** | Requires maintaining whitelist; error-prone | Blacklist approach with default-public or default-internal |
| **Permission caching with page cache** | Major security hole - MW caches don't support per-user variation | Disable caching for protected content OR use separate cache keys |
| **Trying to plug all MW content leaks** | Impossible - search, RSS, transclusion, special pages all leak | Focus on img_auth.php for byte protection; accept page metadata may leak |
| **Private links / temporary access tokens** | Complexity for edge case; scope creep | Out of scope for MVP; can add later if needed |
| **Protecting via obscurity (renaming files)** | Security through obscurity fails | Real permission enforcement via img_auth.php |

---

## Feature Dependencies

```
Core Foundation (Phase 1)
    |
    +-- Database schema for file permissions
    |
    +-- Permission level definitions (public/internal/lab/restricted)
    |
    +-- Group-to-level mapping configuration
    |
    v
Upload Integration (Phase 2) -- depends on Core
    |
    +-- Special:Upload hook for permission selection
    |
    +-- MsUpload integration for drag-drop
    |
    +-- Namespace default logic
    |
    v
Enforcement (Phase 3) -- depends on Core
    |
    +-- img_auth.php hook for access checks
    |
    +-- Thumbnail protection
    |
    +-- Archive/old version protection
    |
    v
Admin UI (Phase 4) -- depends on Core + Upload
    |
    +-- Permission display on file pages
    |
    +-- Permission change interface
    |
    +-- Audit logging
    |
    v
Advanced Features (Phase 5+) -- depends on all above
    |
    +-- Bulk operations
    +-- Search/filter by permission
    +-- API access
```

---

## MVP Recommendation

For MVP, prioritize these features (in order):

### Must Have (MVP)
1. **Database schema for file-permission mapping** - Foundation for everything
2. **Permission level definitions** - The four levels (public/internal/lab/restricted)
3. **Group-to-level mapping** - Configuration for which groups can access which levels
4. **Special:Upload integration** - Dropdown to select permission at upload time
5. **img_auth.php enforcement** - Actual byte-level protection
6. **Permission display on file pages** - Users can see the current level
7. **Basic permission change UI** - Admin can modify after upload

### Defer to Post-MVP
- **MsUpload integration** - Nice to have, not blocking
- **Bulk permission changes** - Admin convenience, not core functionality
- **Audit logging** - Important but can retrofit
- **Search/filter by permission** - Admin tooling, not core flow
- **UploadWizard integration** - Complex, low priority initially
- **API access** - Enterprise feature, defer
- **Visual indicators on file listings** - Polish

---

## Competitor Feature Matrix

| Feature | Lockdown | NSFileRepo | AccessControl | SemanticACL | **FilePermissions** |
|---------|----------|------------|---------------|-------------|---------------------|
| Per-file permissions | No | No (namespace) | Yes (tag-based) | Yes (property) | **Yes (db-backed)** |
| Group-based | Yes | Yes | Yes | Yes | **Yes** |
| Upload-time selection | No | No | No | No | **Yes** |
| img_auth.php support | Requires setup | Yes | No | Partial | **Yes** |
| Simple UX | Medium | Low | Low | Low | **High** |
| No content pollution | Yes | Yes | No (tags in page) | No (properties) | **Yes** |
| Thumbnail protection | No | Yes | No | Partial | **Yes** |
| Audit logging | No | No | No | No | **Yes** |
| Bulk operations | No | No | No | No | **Yes (planned)** |

---

## Sources

### HIGH Confidence (Official MediaWiki Documentation)
- [Extension:Lockdown](https://www.mediawiki.org/wiki/Extension:Lockdown) - Namespace-level permissions, limitations clearly documented
- [Extension:NSFileRepo](https://www.mediawiki.org/wiki/Extension:NSFileRepo) - Namespace-based file repository
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization) - img_auth.php documentation
- [Security issues with authorization extensions](https://www.mediawiki.org/wiki/Security_issues_with_authorization_extensions) - Caching and security hole documentation

### MEDIUM Confidence (Official + WebSearch Verified)
- [Extension:AccessControl](https://www.mediawiki.org/wiki/Extension:AccessControl) - Tag-based per-page permissions
- [Extension:Semantic ACL](https://www.mediawiki.org/wiki/Extension:Semantic_ACL) - Property-based ACL with SMW
- [Extension:PagePermissions](https://www.mediawiki.org/wiki/Extension:PagePermissions) - Per-page permission UI
- [Extension:MsUpload](https://www.mediawiki.org/wiki/Extension:MsUpload) - Drag-drop upload
- [BlueSpice PermissionManager](https://en.wiki.bluespice.com/wiki/Manual:Extension/BlueSpicePermissionManager) - Enterprise permission management

### LOW Confidence (WebSearch Only - Needs Validation)
- IntraACL extension features (GitHub repo, limited docs)
- Specific version compatibility claims (verify against actual code)
