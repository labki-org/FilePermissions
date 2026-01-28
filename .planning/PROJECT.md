# FilePermissions

## What This Is

A MediaWiki extension providing fine-grained, group-based access control for uploaded files. Each file gets exactly one permission level (e.g., public, internal, lab, restricted), and user groups are granted access to specific levels. Permissions are enforced consistently for file description pages, embedded images, raw file downloads, and thumbnails via img_auth.php integration.

## Core Value

Files are protected at the byte level — unauthorized users cannot view, embed, or download protected files, period.

## Requirements

### Validated

(None yet — ship to validate)

### Active

**Permission Model**
- [ ] Each file stores exactly one permission level in PageProps
- [ ] Permission levels are configurable via `$wgFilePermLevels`
- [ ] User groups map to allowed permission levels via `$wgFilePermGroupGrants`
- [ ] Wildcard `'*'` in grants means access to all levels
- [ ] User's effective permissions = union of all their group grants

**Permission Enforcement**
- [ ] Hook into `GetUserPermissionsErrors` for NS_FILE + read action
- [ ] Fetch file's permission level from PageProps (fallback to default)
- [ ] Deny access if user lacks required level
- [ ] Enforcement applies to: File: pages, embedded images, img_auth.php, thumbnails

**Upload Integration — Special:Upload**
- [ ] Add permission dropdown to upload form
- [ ] Default based on namespace context or global default
- [ ] Store selected level on upload completion via `UploadComplete` hook

**Upload Integration — MsUpload**
- [ ] JS bridge module waits for `wikiEditor.toolbarReady`
- [ ] Injects dropdown into MsUpload toolbar
- [ ] Hooks `BeforeUpload` to append `fileperm_level` to FormData
- [ ] Respects namespace-based defaults via `mw.config`

**Default Permission Logic**
- [ ] Global default via `$wgFilePermDefaultLevel`
- [ ] Namespace overrides via `$wgFilePermByNamespaceDefault`
- [ ] Invalid/missing permissions treated as default

**Administrative Editing**
- [ ] Special:FilePermissions page for changing file permissions
- [ ] Input: file title, dropdown of permission levels
- [ ] Restricted to privileged users (sysop)

### Out of Scope

- Per-user ACLs — group-based only, no individual user grants
- Complex role hierarchies — flat permission levels only
- File inheritance trees — no nested policies or parent-child relationships
- MediaWiki core modifications — extension hooks only
- MsUpload forking — bridge module only, no MsUpload changes
- Lockdown integration — independent, replaces Lockdown for files
- SMW dependency — no Semantic MediaWiki required
- Audit logging — not needed for v1

## Context

**Environment:**
- MediaWiki 1.44
- Must work with `$wgEnableImageAuth = true` and protected `/images/` directory
- MsUpload extension installed (standard MediaWiki extension from Gerrit)

**Existing Patterns:**
- Extension structure follows MSAssistant/SemanticSchemas conventions
- `includes/` directory with PSR-4 autoloading (`FilePermissions\`)
- Subdirectories: `Api/`, `Hooks/`, `Special/`
- Static `Config.php` class for configuration access
- ResourceLoader modules with IIFE JavaScript pattern
- `LocalSettings.test.php` for labki-platform test configuration

**MsUpload Integration:**
- MsUpload uses `mw.hook('wikiEditor.toolbarReady')` for initialization
- Uploads via standard MW API (`action: 'upload'`) through plupload
- `BeforeUpload` event allows injecting additional form parameters

**Security Model:**
- Deployment requires either upload directory outside web root OR web server blocks direct `/images/` access
- Without proper deployment, only page-level protection works (not byte-level)

## Constraints

- **Tech stack**: PHP 8.x, MediaWiki 1.44 hooks and APIs
- **No core mods**: Must use extension hooks only
- **No MsUpload fork**: Bridge module injects into existing MsUpload
- **Storage**: PageProps for permission metadata (fast, cached, native MW)
- **Compatibility**: Must work alongside existing extensions without conflicts

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| PageProps for storage | Fast lookup, cached, no parsing, native MW infrastructure | — Pending |
| Single permission level per file | Simplicity, clear mental model, matches "virtual namespace" concept | — Pending |
| JS bridge for MsUpload | Avoids forking, uses existing events/hooks | — Pending |
| Group-based only | Reduces complexity, aligns with MW permission model | — Pending |

---
*Last updated: 2025-01-28 after initialization*
