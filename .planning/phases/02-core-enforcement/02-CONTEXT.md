# Phase 2: Core Enforcement - Context

**Gathered:** 2026-01-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Prevent unauthorized users from accessing protected files through any content path: File: description pages, raw file requests via img_auth.php, thumbnails, and embedded images. This is pure enforcement — no UI for setting permissions (that's Phase 3+).

</domain>

<decisions>
## Implementation Decisions

### Denial Experience
- Show MediaWiki standard permission error page when unauthorized user visits File: description page
- Error message is generic ("You don't have permission") — does NOT reveal which permission level is required
- No "request access" affordance — just the error, access requests happen out-of-band
- Logged-in users without permission see same error as anonymous users

### Embedded Image Handling
- Show a placeholder image (not broken image icon, not stripped)
- Placeholder matches the requested dimensions — preserves page layout
- Placeholder shows icon only, no text like "Protected" or "Access Required"
- Placeholder is NOT clickable — dead end, reduces discoverability

### Edge Cases
- Thumbnails inherit parent file permission — no leakage via thumbnail URLs
- Permission applies to ALL versions of a file (current + archived revisions)
- Disable parser cache for File: namespace pages — ensures permission changes take effect immediately

### Claude's Discretion
- 403 response body content for img_auth.php (follow MediaWiki conventions)
- API metadata behavior (practical approach for v1)
- Exact placeholder icon design
- Specific cache header implementation

</decisions>

<specifics>
## Specific Ideas

- Security posture is "reveal nothing" — don't leak permission structure, don't hint at what level is needed
- Placeholder should be minimal and unobtrusive — an icon, same size as would-be image, but not attention-grabbing

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 02-core-enforcement*
*Context gathered: 2026-01-28*
