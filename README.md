# FilePermissions

Group-based file access control for MediaWiki.

**Version:** 1.0.0 | **License:** GPL-3.0-or-later

## Overview

FilePermissions adds group-based permission levels to uploaded files in MediaWiki. Administrators assign a permission level (e.g. `public`, `internal`, `confidential`) to each file, and the extension enforces access based on the user's group memberships.

Key capabilities:

- Permission level dropdown on Special:Upload, MsUpload, and VisualEditor upload dialogs
- Enforcement on File: description pages, `img_auth.php` raw/thumbnail access, and embedded images
- Fail-closed security model with placeholder SVG for unauthorized embeds
- Audit log at `Special:Log/fileperm`
- Dedicated `fileperm_levels` table (survives page re-parses)

## Requirements

| Requirement | Version |
|---|---|
| MediaWiki | >= 1.44.0 |
| PHP | As required by MediaWiki 1.44+ |

**Optional extensions** (detected automatically, no-op when absent):

- **MsUpload** -- Adds permission dropdown to the MsUpload drag-and-drop interface
- **VisualEditor** -- Adds permission dropdown to the VE upload dialog

## Installation

1. Clone or extract the extension into your `extensions/` directory:

   ```
   cd extensions
   git clone <repo-url> FilePermissions
   ```

2. Add to `LocalSettings.php`:

   ```php
   wfLoadExtension( 'FilePermissions' );
   ```

3. Configure `$wgUploadPath` to route file access through `img_auth.php` so that raw file and thumbnail downloads are subject to permission checks:

   ```php
   $wgUploadPath = "{$wgScriptPath}/img_auth.php";
   ```

   Without this, direct URLs to files in the upload directory bypass enforcement.

4. **Disable anonymous read access.** MediaWiki's `img_auth.php` skips **all** permission hooks (including `ImgAuthBeforeStream`) when the wiki is public. The extension's file access control will not work unless the wiki is private:

   ```php
   // Required: img_auth.php only enforces hooks on private wikis
   $wgGroupPermissions['*']['read'] = false;

   // Allow all logged-in users to read wiki pages
   $wgGroupPermissions['user']['read'] = true;

   // Whitelist pages that anonymous users need (login, etc.)
   $wgWhitelistRead = [ 'Special:UserLogin', 'Special:CreateAccount', 'Main Page' ];
   ```

   This is a MediaWiki core limitation, not specific to this extension. Without this setting, `img_auth.php` treats the wiki as public and streams all files without calling any authorization hooks.

5. **Block direct access to the upload directory.** Configure your web server to deny requests to the upload directory (typically `/images/`), so files can only be served through `img_auth.php`. For Apache:

   ```apache
   <Directory "/path/to/mediawiki/images">
       Require all denied
   </Directory>
   ```

   For Nginx:

   ```nginx
   location /images/ {
       deny all;
   }
   ```

   For Caddy:

   ```caddy
   @images path /images/*
   respond @images 403
   ```

## Configuration Reference

All configuration variables are set in `LocalSettings.php` after the `wfLoadExtension` call.

### `$wgFilePermLevels`

| | |
|---|---|
| **Type** | `array<string>` |
| **Default** | `[ "public", "internal", "confidential" ]` |

Defines the available permission levels. These appear in upload dropdowns and the File page editor.

```php
$wgFilePermLevels = [ 'public', 'restricted', 'secret' ];
```

### `$wgFilePermGroupGrants`

| | |
|---|---|
| **Type** | `array<string, array<string>>` |
| **Default** | `{ "sysop": ["*"], "user": ["public", "internal"] }` |

Maps user groups to the permission levels they can access. Use `"*"` as a wildcard to grant access to all levels.

```php
$wgFilePermGroupGrants = [
    'sysop' => [ '*' ],           // all levels
    'user'  => [ 'public' ],      // public only
    'staff' => [ 'public', 'restricted' ],
];
```

### `$wgFilePermDefaultLevel`

| | |
|---|---|
| **Type** | `string\|null` |
| **Default** | `null` |

Default permission level for new uploads. When `null`, users must explicitly select a level on Special:Upload (the dropdown has no pre-selected value). API uploads without an explicit level are treated as unrestricted.

```php
$wgFilePermDefaultLevel = 'internal';
```

### `$wgFilePermNamespaceDefaults`

| | |
|---|---|
| **Type** | `array<int, string>` |
| **Default** | `{}` |

Maps namespace IDs to default permission levels. Namespace-specific defaults take priority over `$wgFilePermDefaultLevel`.

```php
$wgFilePermNamespaceDefaults = [
    NS_FILE => 'internal',
];
```

## Usage Guide

### Setting permissions on upload

On **Special:Upload**, a "Permission level" dropdown appears in the description section. Select the desired level before uploading. If no default is configured, the upload is rejected until a level is selected.

When **MsUpload** is installed, a dropdown appears next to each file in the drag-and-drop upload interface. When **VisualEditor** is installed, a dropdown is injected into the VE upload dialog's info form.

### Managing permissions on File pages

Users with the `edit-fileperm` right see an edit section on File: description pages containing a dropdown and save button. Changing the level and clicking save calls the API and updates the displayed badge immediately.

### Viewing the audit log

All permission level changes are recorded at `Special:Log/fileperm`. Each entry shows the performer, target file, old level, and new level.

## Security Model

### Enforcement paths

The extension enforces permissions at four points:

1. **File: description pages** -- `getUserPermissionsErrors` hook blocks the `read` action for unauthorized users, returning a generic denial message.
2. **Raw file / thumbnail access** -- `ImgAuthBeforeStream` hook returns HTTP 403 for unauthorized requests through `img_auth.php`. This covers both original files and thumbnails (MediaWiki resolves thumbnail paths to the source Title before the hook fires).
3. **Embedded images** -- `ImageBeforeProduceHTML` hook replaces `[[File:...]]` embeds with a placeholder for unauthorized users. Parser cache is disabled for pages containing protected images to ensure per-user rendering.
4. **Placeholder SVG** -- Unauthorized embedded images render as a gray box with a lock icon SVG (inline data URI, no extra HTTP request), sized to match the requested dimensions to preserve page layout.

### Fail-closed behavior

- If configuration validation fails at registration time, an internal flag (`$wgFilePermInvalidConfig`) is set and **all permission checks deny access**.
- Files without an explicit level and no configured default are treated as unrestricted (grandfathered files).

### Security model details

The extension relies on `img_auth.php` as the enforcement boundary for raw file access. Several API endpoints return file-related data, but their output is safe because:

- **`action=query&prop=imageinfo`** returns file URLs that route through `img_auth.php` when `$wgUploadPath` is configured correctly. The `ImgAuthBeforeStream` hook enforces permissions on these URLs, so URL exposure does not bypass protection.
- **`action=parse`** triggers the `ImageBeforeProduceHTML` hook, which replaces protected images with placeholders for unauthorized users. Parsed output is therefore protected.
- **Direct file access** must be blocked at the web server level by denying requests to the upload directory (see Installation step 5). This is a defense-in-depth measure independent of `img_auth.php`.

### Timing considerations

Permission checks may exhibit timing differences based on database lookups (e.g., checking whether a file has a permission level). This is inherent to the architecture and is mitigated by in-process caching in `PermissionService`, which reduces the timing delta for repeated checks within the same request.

## API Reference

### `action=fileperm-set-level`

Sets the permission level for a file. Requires CSRF token and `edit-fileperm` right.

**Method:** POST

**Parameters:**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `title` | string | Yes | File page title (without `File:` prefix) |
| `level` | string | Yes | Permission level (must be in `$wgFilePermLevels`) |
| `token` | string | Yes | CSRF token |

**Response:**

```json
{
  "fileperm-set-level": {
    "result": "success",
    "level": "confidential"
  }
}
```

**Errors:**

- `filepermissions-api-nosuchpage` -- Title does not exist in the File namespace
- `permissiondenied` -- User lacks the `edit-fileperm` right
- `badtoken` -- Invalid or missing CSRF token

## Extension Integration

### Shared Module

Both upload bridge modules depend on `ext.FilePermissions.shared`, which provides:

- `mw.FilePermissions.verifyPermission(filename, errorMsgKey)` -- post-upload permission verification
- A single `XMLHttpRequest.prototype.open` patch to tag API POST requests

This ensures the XHR prototype is patched exactly once, even when both MsUpload and VisualEditor are active on the same page.

### MsUpload

When the MsUpload extension is detected, `MsUploadHooks` loads the `ext.FilePermissions.msupload` module on edit pages. This adds a permission-level dropdown to the MsUpload interface. If MsUpload is not installed, the hook handler is a silent no-op.

### VisualEditor

When VisualEditor is detected, `VisualEditorHooks` loads the `ext.FilePermissions.visualeditor` module on pages where VE is active. The module monkey-patches `mw.ForeignStructuredUpload.BookletLayout` to inject a dropdown into the upload dialog and intercepts the publish-from-stash XHR to include the selected level. If VisualEditor is not installed, the hook handler is a silent no-op.

## Storage

Permission levels are stored in a dedicated `fileperm_levels` table with columns `fpl_page` (primary key, references `page.page_id`) and `fpl_level`. This avoids the `page_props` table, whose rows are owned by the parser pipeline and silently wiped on page re-parse.

The table is created automatically by `php maintenance/run.php update`.

On upload, storage is deferred via `DeferredUpdates` to ensure the file page exists before writing the level.

## Permissions

| Right | Description | Default groups |
|---|---|---|
| `edit-fileperm` | Change file permission levels | `sysop` |

Grant this right to additional groups in `LocalSettings.php`:

```php
$wgGroupPermissions['bureaucrat']['edit-fileperm'] = true;
```

## Logging

All permission level changes are logged to `Special:Log/fileperm`. Log entries include:

- **Performer** -- The user who made the change
- **Target** -- The file page
- **Parameters** -- Old level and new level

## License

GPL-3.0-or-later. See [LICENSE](LICENSE) for the full text.
