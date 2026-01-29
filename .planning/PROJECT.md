# FilePermissions

## What This Is

A MediaWiki extension providing fine-grained, group-based access control for uploaded files. Each file gets exactly one permission level (e.g., public, internal, lab, restricted), and user groups are granted access to specific levels. Permissions are enforced consistently for file description pages, embedded images, raw file downloads, and thumbnails via img_auth.php integration. Permission levels can be set during upload (Special:Upload, MsUpload, VisualEditor) and edited by sysop users on File: pages.

## Core Value

Files are protected at the byte level — unauthorized users cannot view, embed, or download protected files, period.

## Requirements

### Validated

- Permission model with configurable levels, group grants, namespace defaults, and fail-closed validation — v1.0
- Byte-level enforcement across File: pages, img_auth.php, thumbnails, and embedded images — v1.0
- Permission selection during Special:Upload with server-side validation — v1.0
- Permission selection during MsUpload drag-drop via JS bridge — v1.0
- Permission selection during VisualEditor upload via BookletLayout monkey-patch — v1.0
- File page permission indicator (badge) and sysop edit interface with audit logging — v1.0
- Static Config class with typed access and fail-closed validation — v1.0

### Active

(No active requirements — v1 complete, v2 not started)

### Out of Scope

- Per-user ACLs — group-based only, no individual user grants
- Complex role hierarchies — flat permission levels only
- File inheritance trees — no nested policies or parent-child relationships
- MediaWiki core modifications — extension hooks only
- MsUpload forking — bridge module only, no MsUpload changes
- Lockdown integration — independent, replaces Lockdown for files
- SMW dependency — no Semantic MediaWiki required

## Context

**Environment:**
- MediaWiki 1.44
- Must work with `$wgEnableImageAuth = true` and protected `/images/` directory
- MsUpload extension installed (standard MediaWiki extension from Gerrit)
- VisualEditor extension installed

**Current State (v1.0 shipped):**
- 20 source files, 2,196 LOC (1,365 PHP, 471 JS, 102 CSS, 258 JSON)
- Extension structure: `includes/` (Config, PermissionService, ServiceWiring), `includes/Hooks/` (6 hook classes), `includes/Api/` (1 API module), `modules/` (6 JS/CSS files)
- All 27 v1 requirements satisfied across 6 phases
- All upload paths covered: Special:Upload, MsUpload, VisualEditor

**Deployment Requirements:**
- `$wgGroupPermissions['*']['read'] = false` (private wiki) for img_auth.php enforcement
- Web server must block direct `/images/` access
- Parser cache disabled for pages with protected embedded images (performance tradeoff)

**Existing Patterns:**
- Extension structure follows MSAssistant/SemanticSchemas conventions
- `includes/` directory with PSR-4 autoloading (`FilePermissions\`)
- Subdirectories: `Api/`, `Hooks/`
- Static `Config.php` class for configuration access
- ResourceLoader modules with IIFE JavaScript pattern

## Constraints

- **Tech stack**: PHP 8.x, MediaWiki 1.44 hooks and APIs
- **No core mods**: Must use extension hooks only
- **No MsUpload fork**: Bridge module injects into existing MsUpload
- **Storage**: PageProps for permission metadata (fast, cached, native MW)
- **Compatibility**: Must work alongside existing extensions without conflicts

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| PageProps for storage | Fast lookup, cached, no parsing, native MW infrastructure | Good |
| Single permission level per file | Simplicity, clear mental model, matches "virtual namespace" concept | Good |
| JS bridge for MsUpload | Avoids forking, uses existing events/hooks | Good |
| Group-based only | Reduces complexity, aligns with MW permission model | Good |
| Fail-closed via global flag | Wiki loads but denies all access on invalid config | Good |
| DeferredUpdates for PageProps storage | Page not committed when UploadComplete fires | Good |
| UploadVerifyUploadHook for validation | UploadForm bypasses HTMLForm validation | Good |
| OOUI server-side rendering for edit controls | MW 1.44 supports OOUI; Codex requires Vue.js overhead | Good |
| Custom edit-fileperm right | MW convention, allows admin reassignment to other groups | Good |
| ManualLogEntry audit logging | Trivial cost, high admin value, follows MW convention | Good |
| Direct multipart_params mutation for MsUpload | setOption would overwrite MsUpload's existing params | Good |
| BookletLayout monkey-patching for VE | Injects OOUI dropdown into VE upload dialog | Good |
| XHR prototype patching for VE | Intercepts publish-from-stash to append permission level | Good |
| URLSearchParams for VE string body | mw.Api serializes as URL-encoded string, not FormData | Good |

---
*Last updated: 2026-01-29 after v1.0 milestone*
