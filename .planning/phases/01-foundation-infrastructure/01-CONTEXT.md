# Phase 1: Foundation & Infrastructure - Context

**Gathered:** 2026-01-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Establish the permission model, configuration system, and storage layer. This phase creates:
- Configuration variables ($wgFilePermLevels, $wgFilePermGroupGrants, $wgFilePermNamespaceDefaults)
- Validation logic with fail-closed behavior
- PageProps-based storage for file permission levels
- API for other phases to query/set permissions

No UI, no enforcement hooks — just the foundation.

</domain>

<decisions>
## Implementation Decisions

### Permission Level Definition
- Simple array of names: `$wgFilePermLevels = ['public', 'internal', 'confidential']`
- Array order is for UI display only — no hierarchy or security meaning
- No required metadata (labels, colors) — just string identifiers

### Grant Configuration
- Claude's discretion on format (group-centric vs level-centric)
- Choose based on what's intuitive for MediaWiki admins

### Validation & Failure Behavior
- On invalid configuration: fall back to deny-all default
- Wiki loads but all files inaccessible until config fixed
- Log warning with diagnostics
- No Special page for config status — keep extension minimal

### Default Resolution Chain
- Namespace-specific defaults via `$wgFilePermNamespaceDefaults` (separate config)
- No global default — force explicit permission selection on upload
- Legacy files (pre-extension) are treated as public/unrestricted — grandfathered in

### Storage
- PageProps key: `fileperm_level`

### Claude's Discretion
- Whether 'public' is a reserved level that skips checks, or just another level with grants
- Validation timing (extension load vs runtime)
- Logging verbosity for config issues
- Service class vs static helpers for API
- Whether API includes canAccess() check or just getLevel()
- Behavior when upload has no selection and no default (block upload vs apply most restrictive)
- Change logging (audit trail for permission modifications)

</decisions>

<specifics>
## Specific Ideas

- "Force user to set a level" — no silent defaults, explicit selection required
- Legacy files should remain accessible — don't break existing wikis on install

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 01-foundation-infrastructure*
*Context gathered: 2026-01-28*
