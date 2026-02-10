# Changelog

## v1.0.1

### Documentation
- Fixed license identifier in `extension.json` and `composer.json` (GPL-2.0-or-later → GPL-3.0-or-later to match LICENSE file)
- Fixed stale "PageProps" references in PHPDoc for `ApiFilePermSetLevel` and `UploadHooks` (now references `fileperm_levels` table)
- Added class docblock to `SchemaChangesHandler`
- Added `action=query&prop=fileperm` API documentation to README
- Added rate limiting documentation to README
- Added database schema subsection to README
- Added Maintenance section to README (ValidatePermissions.php usage)
- Added Troubleshooting section to README
- Fixed incorrect class names in Extension Integration section (`MsUploadHooks`/`VisualEditorHooks` → `DisplayHooks`)
- Created CONTRIBUTING.md with development setup, architecture overview, test instructions, and PR guidelines

## v1.0.0

Initial release of FilePermissions for MediaWiki.

### Features
- Group-based file access control with configurable permission levels (public, internal, confidential)
- Enforcement via `getUserPermissionsErrors`, `ImgAuthBeforeStream`, and `ImageBeforeProduceHTML` hooks
- Permission level selector on Special:Upload form with server-side validation
- Inline permission level editor on file description pages (OOUI dropdown + API)
- API modules: `action=query&prop=fileperm` and `action=fileperm-set-level`
- MsUpload integration — permission level dropdown injected into drag-and-drop upload UI
- VisualEditor integration — permission level dropdown injected into media upload dialog
- Structured logging of all permission level changes (`Special:Log/fileperm`)
- Namespace-based default permission levels (`$wgFilePermNamespaceDefaults`)
- Fail-closed design — blocks all file access if configuration is invalid

### Security
- Remediated 12 findings from independent security audit (SEC-01 through SEC-12)
- CSRF protection on all state-changing API endpoints
- Input validation and output escaping on all user-facing surfaces
- Rate limiting on permission-change API
