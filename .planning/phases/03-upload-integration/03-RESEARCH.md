# Phase 3: Upload Integration - Research

**Researched:** 2026-01-28
**Domain:** MediaWiki Special:Upload form extension, HTMLForm descriptors, upload hooks
**Confidence:** HIGH

## Summary

This phase integrates a permission-level dropdown into MediaWiki's Special:Upload form. The approach uses two well-documented hooks: `UploadFormInitDescriptor` to inject the dropdown field into the upload form descriptor, and `UploadComplete` to store the selected permission level in PageProps after a successful upload. Both hooks have stable interfaces since MW 1.35+ and are the standard approach used by extensions that customize the upload workflow.

The existing codebase (Phase 1 and 2) already provides `Config.php` for reading configured levels, `PermissionService` for storing/retrieving PageProps, and the `EnforcementHooks` class as a pattern reference. Phase 3 adds a new `UploadHooks` class (or extends the existing hook handler) that implements `UploadFormInitDescriptorHook` and `UploadCompleteHook`. No JavaScript is needed for this phase -- the dropdown is a server-side HTMLForm `select` field. No API endpoint is needed -- the form submission flows through MediaWiki's standard Special:Upload processing.

**Primary recommendation:** Use `UploadFormInitDescriptor` hook with an HTMLForm `select` descriptor to add the dropdown, and `UploadComplete` hook with `RequestContext::getMain()->getRequest()->getVal()` to retrieve the selected value and store it via `PermissionService::setLevel()`.

## Standard Stack

### Core (MediaWiki built-in -- no additional dependencies)

| Component | Type | Purpose | Why Standard |
|-----------|------|---------|--------------|
| `UploadFormInitDescriptorHook` | Hook Interface | Inject dropdown into Special:Upload form | Official MW hook for modifying upload form descriptor (since MW 1.16) |
| `UploadCompleteHook` | Hook Interface | Store permission level after upload | Official MW hook fired after successful upload |
| HTMLForm descriptor | Form System | Define dropdown field with type/options/section | MW's standard form rendering system used by UploadForm |
| `RequestContext::getMain()->getRequest()` | WebRequest | Retrieve submitted form values in UploadComplete | Standard MW pattern for accessing POST data in hooks |
| `PermissionService` (existing) | Service | Store level in PageProps | Already built in Phase 1 |
| `Config` (existing) | Static class | Read configured levels and groups | Already built in Phase 1 |

### Supporting

| Component | Purpose | When to Use |
|-----------|---------|-------------|
| i18n messages | Localized label, help text, error messages | All user-facing strings |
| `validation-callback` | Server-side validation of dropdown selection | Ensuring a real level is selected (not empty placeholder) |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| HTMLForm select | OOUI DropdownInputWidget via JS | Unnecessary complexity; HTMLForm select is sufficient for a simple dropdown |
| `UploadFormInitDescriptor` hook | Manual HTML injection via `UploadForm:initial` | Fragile, bypasses HTMLForm validation, breaks on form changes |
| `RequestContext` in UploadComplete | Injected WebRequest via services | UploadComplete only passes UploadBase; RequestContext is the established pattern |

**Installation:** No additional packages needed. All components are MediaWiki core.

## Architecture Patterns

### Recommended File Structure

```
FilePermissions/
  includes/
    Hooks/
      EnforcementHooks.php      # (existing) Phase 2 enforcement
      UploadHooks.php            # (NEW) Phase 3 upload form + storage
    Config.php                   # (existing) Level configuration
    PermissionService.php        # (existing) PageProps storage
  i18n/
    en.json                      # (UPDATE) Add upload form messages
  extension.json                 # (UPDATE) Register new hooks + handler
```

### Pattern 1: Hook Handler with Service Injection

**What:** A dedicated `UploadHooks` class implementing both `UploadFormInitDescriptorHook` and `UploadCompleteHook`, with `PermissionService` injected via `HookHandlers` in `extension.json`.

**When to use:** Always for this phase. Follows the exact pattern established by `EnforcementHooks` in Phase 2.

**Example:**
```php
// Source: established project pattern (EnforcementHooks.php) + MW hook docs
namespace FilePermissions\Hooks;

use FilePermissions\Config;
use FilePermissions\PermissionService;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\UploadFormInitDescriptorHook;
use MediaWiki\Upload\Hook\UploadCompleteHook;

class UploadHooks implements
    UploadFormInitDescriptorHook,
    UploadCompleteHook
{
    private PermissionService $permissionService;

    public function __construct( PermissionService $permissionService ) {
        $this->permissionService = $permissionService;
    }

    // ... hook methods
}
```

**Registration in extension.json:**
```json
{
    "HookHandlers": {
        "upload": {
            "class": "FilePermissions\\Hooks\\UploadHooks",
            "services": [
                "FilePermissions.PermissionService"
            ]
        }
    },
    "Hooks": {
        "UploadFormInitDescriptor": "upload",
        "UploadComplete": "upload"
    }
}
```

### Pattern 2: HTMLForm Select Descriptor

**What:** Add a `select` type field to the upload form descriptor via the `UploadFormInitDescriptor` hook.

**When to use:** For the permission dropdown.

**Key descriptor properties:**
```php
// Source: MW HTMLForm docs + UploadForm source analysis
$descriptor['FilePermLevel'] = [
    'type' => 'select',
    'section' => 'description',
    'id' => 'wpFilePermLevel',
    'label-message' => 'filepermissions-upload-label',
    'help-message' => 'filepermissions-upload-help',
    'options' => $options,     // label => value mapping
    'default' => '',           // empty = placeholder selected
    'required' => true,        // only partly enforced; use validation-callback too
    'validation-callback' => [ $this, 'validatePermissionLevel' ],
    'cssclass' => 'fileperm-level-select',
];
```

**Field naming convention:** HTMLForm automatically prefixes field names with `wp`. A field keyed as `'FilePermLevel'` in the descriptor produces an HTML `<select name="wpFilePermLevel">`. To read the submitted value: `$request->getVal( 'wpFilePermLevel' )`.

### Pattern 3: Options Array Construction

**What:** Build the dropdown options from `Config::getLevels()` and `Config::getGroupGrants()`, with an empty placeholder at the top.

**When to use:** In `onUploadFormInitDescriptor` to populate the dropdown.

**Example:**
```php
// Source: CONTEXT.md decisions + Config.php API
private function buildOptions(): array {
    $options = [
        wfMessage( 'filepermissions-upload-choose' )->text() => ''
    ];

    $groupGrants = Config::getGroupGrants();
    foreach ( Config::getLevels() as $level ) {
        $groups = [];
        foreach ( $groupGrants as $group => $grants ) {
            if ( in_array( '*', $grants, true ) || in_array( $level, $grants, true ) ) {
                $groups[] = $group;
            }
        }
        $groupsDisplay = implode( ', ', $groups );
        $label = ucfirst( $level );
        if ( $groupsDisplay !== '' ) {
            $label .= " ($groupsDisplay)";
        }
        $options[$label] = $level;
    }

    return $options;
}
```

### Pattern 4: Re-upload Detection and Pre-selection

**What:** When re-uploading an existing file, pre-select its current permission level in the dropdown.

**When to use:** In `onUploadFormInitDescriptor` when the target filename is known.

**Example:**
```php
// Source: UploadForm options + PermissionService API
public function onUploadFormInitDescriptor( array &$descriptor ): void {
    // ... build options ...

    // Detect re-upload: check if wpDestFile has an existing file with a level
    $request = RequestContext::getMain()->getRequest();
    $destFile = $request->getVal( 'wpDestFile', '' );
    $default = '';
    if ( $destFile !== '' ) {
        $title = \MediaWiki\Title\Title::makeTitleSafe( NS_FILE, $destFile );
        if ( $title && $title->exists() ) {
            $existingLevel = $this->permissionService->getLevel( $title );
            if ( $existingLevel !== null && Config::isValidLevel( $existingLevel ) ) {
                $default = $existingLevel;
            }
            // If existing level was removed from config, fall back to empty
        }
    }

    $descriptor['FilePermLevel'] = [
        'type' => 'select',
        'section' => 'description',
        'label-message' => 'filepermissions-upload-label',
        'help-message' => 'filepermissions-upload-help',
        'options' => $this->buildOptions(),
        'default' => $default,
        'validation-callback' => [ $this, 'validatePermissionLevel' ],
        'cssclass' => 'fileperm-level-select',
    ];
}
```

### Pattern 5: UploadComplete Storage

**What:** In the `UploadComplete` hook, read the selected permission level from the request and store it via `PermissionService::setLevel()`.

**When to use:** Every time an upload completes.

**Example:**
```php
// Source: MW UploadComplete hook docs + PermissionService API
public function onUploadComplete( $uploadBase ): void {
    $request = RequestContext::getMain()->getRequest();
    $level = $request->getVal( 'wpFilePermLevel', '' );

    if ( $level === '' || !Config::isValidLevel( $level ) ) {
        // Should not happen if form validation passed, but fail safely
        return;
    }

    $localFile = $uploadBase->getLocalFile();
    if ( $localFile === null ) {
        return;
    }

    $title = $localFile->getTitle();
    if ( $title === null || $title->getArticleID() === 0 ) {
        return;
    }

    $this->permissionService->setLevel( $title, $level );
}
```

### Anti-Patterns to Avoid

- **Injecting raw HTML into the upload form:** Use the HTMLForm descriptor system, not raw HTML. The `UploadForm:initial` hook injects raw HTML before the form; prefer `UploadFormInitDescriptor` which integrates with HTMLForm's validation and rendering.
- **Using JavaScript for the dropdown:** No JS needed. HTMLForm renders the `<select>` element server-side. JS would add unnecessary complexity.
- **Storing the level via ParserOutput::setPageProperty:** File uploads do not go through the parser pipeline in the same way page edits do. Use direct database writes via `PermissionService::setLevel()` (which uses `REPLACE INTO page_props`).
- **Relying solely on `'required' => true`:** The `required` HTML attribute only provides client-side validation. Always add a `validation-callback` for server-side enforcement, especially since any `validation-callback` overrides the `required` behavior in HTMLForm.
- **Reading `$_POST` directly:** Always use `WebRequest::getVal()` through `RequestContext`. Direct superglobal access bypasses MW's sanitization.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Upload form field | Raw HTML injection or JS widget | HTMLForm descriptor via `UploadFormInitDescriptor` hook | Integrates with MW form validation, CSRF, rendering, i18n |
| Form field validation | Custom POST parsing | `validation-callback` in descriptor | HTMLForm handles display of errors, re-rendering form |
| Reading submitted values | `$_POST['field']` | `RequestContext::getMain()->getRequest()->getVal('wpFieldName')` | MW sanitization, encoding normalization |
| Storing permission after upload | Direct SQL in hook | `PermissionService::setLevel()` (existing) | Already handles validation, REPLACE INTO, error cases |
| Level label formatting | Hardcoded strings | i18n messages with parameters | Localization support, MW convention |
| Upload form integration | Custom SpecialPage overriding SpecialUpload | Hooks on existing SpecialUpload | Maintainable, survives MW upgrades, no core modifications |

**Key insight:** MediaWiki's HTMLForm system and hook architecture handle all the complexity of form rendering, validation, CSRF protection, and error display. The extension only needs to provide the descriptor and the storage logic.

## Common Pitfalls

### Pitfall 1: Form Field Name Prefix

**What goes wrong:** Developer uses `$request->getVal('FilePermLevel')` but the actual POST field name is `wpFilePermLevel`.
**Why it happens:** HTMLForm automatically prefixes field names with `wp`. A field keyed as `'FilePermLevel'` in the descriptor array becomes `<select name="wpFilePermLevel">` in the rendered HTML.
**How to avoid:** Always use `$request->getVal('wpFilePermLevel')` -- note the `wp` prefix. Alternatively, set `'name' => 'fileperm_level'` in the descriptor to override the default naming, but the `wp` prefix convention is standard.
**Warning signs:** Form submission appears to work but the permission level is never stored (getVal returns null/empty).

### Pitfall 2: Validation Callback Overrides Required

**What goes wrong:** Setting `'required' => true` on the field and expecting the empty placeholder to be rejected automatically.
**Why it happens:** In HTMLForm, any `validation-callback` overwrites the `required` behavior. If you specify both, the validation callback must handle the "required" check itself.
**How to avoid:** Always implement empty-value rejection in the `validation-callback`, not just relying on `'required' => true`.
**Warning signs:** Users can submit the form with the "-- Choose --" placeholder selected.

### Pitfall 3: UploadComplete Fires Before Page Fully Exists

**What goes wrong:** `$title->getArticleID()` returns 0 in the UploadComplete hook.
**Why it happens:** The UploadComplete hook fires after `UploadBase::performUpload()` stores the file, but timing depends on the upload path. The page should exist by this point, but race conditions or stash-then-publish flows could cause issues.
**How to avoid:** Check that `$title->getArticleID()` is non-zero before calling `setLevel()`. If zero, load from primary database (`READ_LATEST`) or defer. The existing `PermissionService::setLevel()` already throws on pageId === 0, so wrap in try/catch or pre-check.
**Warning signs:** Occasional `InvalidArgumentException` from `setLevel()` in logs.

### Pitfall 4: Re-upload with Removed Permission Level

**What goes wrong:** File was uploaded with level "X", admin removes level "X" from config, user re-uploads the file, and the dropdown shows "X" pre-selected even though it's no longer valid.
**Why it happens:** The existing level is read from PageProps but not validated against current config.
**How to avoid:** Context decision already covers this: "If a file's existing level was removed from config, fall back to empty (require re-selection)." Validate the existing level with `Config::isValidLevel()` before pre-selecting it.
**Warning signs:** User sees an invalid option in the dropdown, or the form submits a level that is no longer configured.

### Pitfall 5: Form Reset on Upload Failure

**What goes wrong:** Upload fails (file too large, duplicate detected, etc.), the form redisplays, but the permission dropdown retains the previous selection instead of resetting to empty.
**Why it happens:** HTMLForm populates fields from the submitted request data on form re-display.
**How to avoid:** Context decision says: "If upload fails, form resets to empty -- user must re-select." This requires the `validation-callback` or the descriptor `default` to reset to empty on error. Since HTMLForm re-populates from POST data by default, the natural behavior is to retain the selection. To force reset, the validation callback can clear the value, or this can be handled in the `UploadFormInitDescriptor` hook by detecting an error state.
**Warning signs:** Users re-submit a failed upload without re-confirming their permission choice.

### Pitfall 6: Missing Hook Registration in extension.json

**What goes wrong:** The hook handler class is created but never called.
**Why it happens:** The `Hooks` and `HookHandlers` entries in `extension.json` are not updated, or the handler name doesn't match.
**How to avoid:** Update extension.json with both the `HookHandlers` entry (defining the class and services) and the `Hooks` entry (mapping hook names to handler names). Verify the handler name string matches exactly between `Hooks` and `HookHandlers`.
**Warning signs:** Dropdown never appears on the upload form. No errors in logs.

## Code Examples

### Complete UploadFormInitDescriptor Handler

```php
// Source: MW HTMLForm docs, UploadFormInitDescriptorHook interface, project Config.php
public function onUploadFormInitDescriptor( array &$descriptor ): void {
    // Build options: label => value
    $options = [
        wfMessage( 'filepermissions-upload-choose' )->text() => ''
    ];

    $groupGrants = Config::getGroupGrants();
    foreach ( Config::getLevels() as $level ) {
        $groups = [];
        foreach ( $groupGrants as $group => $grants ) {
            if ( in_array( '*', $grants, true ) || in_array( $level, $grants, true ) ) {
                $groups[] = $group;
            }
        }
        $groupsStr = implode( ', ', $groups );
        $label = ucfirst( $level );
        if ( $groupsStr !== '' ) {
            $label .= " ($groupsStr)";
        }
        $options[$label] = $level;
    }

    // Detect re-upload: pre-select existing level
    $request = RequestContext::getMain()->getRequest();
    $destFile = $request->getVal( 'wpDestFile', '' );
    $default = '';

    if ( $destFile !== '' ) {
        $title = \MediaWiki\Title\Title::makeTitleSafe( NS_FILE, $destFile );
        if ( $title !== null && $title->exists() ) {
            $existingLevel = $this->permissionService->getLevel( $title );
            if ( $existingLevel !== null && Config::isValidLevel( $existingLevel ) ) {
                $default = $existingLevel;
            }
        }
    }

    $descriptor['FilePermLevel'] = [
        'type' => 'select',
        'section' => 'description',
        'label-message' => 'filepermissions-upload-label',
        'help-message' => 'filepermissions-upload-help',
        'options' => $options,
        'default' => $default,
        'validation-callback' => [ $this, 'validatePermissionLevel' ],
        'cssclass' => 'fileperm-level-select',
    ];
}
```

### Validation Callback

```php
// Source: MW HTMLForm Tutorial 2 (validation-callback)
public function validatePermissionLevel( string $value, array $allData ): bool|string|\Message {
    if ( $value === '' ) {
        return wfMessage( 'filepermissions-upload-required' )->text();
    }
    if ( !Config::isValidLevel( $value ) ) {
        return wfMessage( 'filepermissions-upload-invalid' )->text();
    }
    return true;
}
```

### Complete UploadComplete Handler

```php
// Source: MW UploadCompleteHook docs, project PermissionService
public function onUploadComplete( $uploadBase ): void {
    $request = RequestContext::getMain()->getRequest();
    $level = $request->getVal( 'wpFilePermLevel', '' );

    // Validate the submitted level
    if ( $level === '' || !Config::isValidLevel( $level ) ) {
        return;
    }

    $localFile = $uploadBase->getLocalFile();
    if ( $localFile === null ) {
        return;
    }

    $title = $localFile->getTitle();
    if ( $title === null || $title->getArticleID() === 0 ) {
        return;
    }

    $this->permissionService->setLevel( $title, $level );
}
```

### extension.json Updates

```json
{
    "HookHandlers": {
        "enforcement": {
            "class": "FilePermissions\\Hooks\\EnforcementHooks",
            "services": ["FilePermissions.PermissionService"]
        },
        "upload": {
            "class": "FilePermissions\\Hooks\\UploadHooks",
            "services": ["FilePermissions.PermissionService"]
        }
    },
    "Hooks": {
        "getUserPermissionsErrors": "enforcement",
        "ImgAuthBeforeStream": "enforcement",
        "ImageBeforeProduceHTML": "enforcement",
        "UploadFormInitDescriptor": "upload",
        "UploadComplete": "upload"
    }
}
```

### i18n Messages

```json
{
    "filepermissions-upload-label": "Permission level",
    "filepermissions-upload-help": "Controls which groups can view this file.",
    "filepermissions-upload-choose": "-- Choose --",
    "filepermissions-upload-required": "You must select a permission level.",
    "filepermissions-upload-invalid": "The selected permission level is not valid."
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `UploadForm:initial` hook with raw HTML injection | `UploadFormInitDescriptor` hook with HTMLForm descriptor | MW 1.16+ (hook), MW 1.35+ (typed interface) | Proper form integration, validation, i18n |
| Static hook methods in `$wgHooks` array | `HookHandlers` with DI in extension.json | MW 1.35+ | Testable, service injection, typed interfaces |
| `$uploadBase->mLocalFile` direct access | `$uploadBase->getLocalFile()` method | MW core refactoring | Protected property; use accessor method |
| `$wgRequest` global | `RequestContext::getMain()->getRequest()` | MW 1.18+ | Proper context, testable |
| Non-string PageProps values | String-only PageProps values | MW 1.42+ deprecation | Permission levels already stored as strings (no impact) |
| `UploadComplete` hook with return value | `UploadComplete` hook returning void | MW 1.44+ | Use `void` return type in interface implementation |

**Deprecated/outdated:**
- `UploadForm:initial` hook: Still works but injects raw HTML, bypasses HTMLForm system. Use `UploadFormInitDescriptor` instead.
- `$wgHooks['UploadComplete'][]` array: Legacy registration. Use `extension.json` `Hooks` key with `HookHandlers`.

## Open Questions

1. **Form reset on upload failure**
   - What we know: HTMLForm naturally re-populates fields from POST data when re-rendering after an error. The CONTEXT.md decision says the dropdown should reset to empty on upload failure.
   - What's unclear: Whether the `UploadFormInitDescriptor` hook is re-invoked when the form re-renders after a failed upload, and whether the `default` key is used or the POST data takes precedence. In standard HTMLForm, POST data overrides `default` on re-display.
   - Recommendation: During implementation, test this behavior. If HTMLForm retains the selection (likely), this may need a client-side JS reset on error, OR accept the natural HTMLForm behavior (retained selection) as reasonable since the user already made a conscious choice. The CONTEXT.md wording may need revisiting with the user.

2. **UploadComplete hook and UploadBase namespace**
   - What we know: The hook interface is `MediaWiki\Upload\Hook\UploadCompleteHook` based on the file path `includes/upload/Hook/UploadCompleteHook.php`. The parameter type is `UploadBase` (likely `MediaWiki\Upload\UploadBase` in MW 1.44).
   - What's unclear: The exact fully-qualified class names may have shifted in MW 1.44's ongoing namespace reorganization. The `UploadBase` class was historically in the root namespace.
   - Recommendation: LOW confidence on exact import paths. During implementation, verify against the actual MW 1.44 source. If the import fails, check `includes/upload/` directory for the current class paths.

3. **UploadFormInitDescriptorHook namespace**
   - What we know: The interface is `MediaWiki\Hook\UploadFormInitDescriptorHook` based on the Doxygen documentation.
   - What's unclear: MW 1.44 may have moved this to a different namespace.
   - Recommendation: LOW confidence on exact import path. Verify against actual MW 1.44 autoload map during implementation.

## Sources

### Primary (HIGH confidence)
- [Manual:Hooks/UploadFormInitDescriptor](https://www.mediawiki.org/wiki/Manual:Hooks/UploadFormInitDescriptor) - Hook documentation, since MW 1.16
- [Manual:Hooks/UploadComplete](https://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete) - Hook documentation, UploadBase parameter
- [HTMLForm](https://www.mediawiki.org/wiki/HTMLForm) - Form descriptor format, field types, properties
- [Manual:HTMLForm Tutorial 2](https://www.mediawiki.org/wiki/Manual:HTMLForm_Tutorial_2) - Generic field properties (required, validation-callback, cssclass, help-message, default, name)
- [Manual:HTMLForm Tutorial 3](https://www.mediawiki.org/wiki/Manual:HTMLForm_Tutorial_3) - Select field type specifics
- [Manual:WebRequest.php](https://www.mediawiki.org/wiki/Manual:WebRequest.php) - getVal, getText, field name conventions
- [Manual:RequestContext.php](https://www.mediawiki.org/wiki/Manual:RequestContext.php) - RequestContext::getMain() pattern
- UploadForm.php source analysis (via Gerrit Gitiles) - Constructor, descriptor assembly, section methods
- Existing codebase: `EnforcementHooks.php`, `PermissionService.php`, `Config.php`, `extension.json` - Established patterns

### Secondary (MEDIUM confidence)
- [UploadCompleteHook.php File Reference](https://doc.wikimedia.org/mediawiki-core/master/php/UploadCompleteHook_8php.html) - Interface location
- [UploadFormInitDescriptorHook Interface Reference](https://doc.wikimedia.org/mediawiki-core/REL1_36/php/interfaceMediaWiki_1_1Hook_1_1UploadFormInitDescriptorHook.html) - Interface namespace (REL1_36 ref)
- [Extension:UploadFields](https://www.mediawiki.org/wiki/Extension:UploadFields) - Reference implementation pattern
- [Category:UploadFormInitDescriptor extensions](https://www.mediawiki.org/wiki/Category:UploadFormInitDescriptor_extensions) - Other extensions using this hook

### Tertiary (LOW confidence)
- Exact fully-qualified class names for MW 1.44 hook interfaces -- namespace reorganization may have changed paths
- Form re-display behavior after upload failure -- needs empirical testing

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Uses well-documented core MW hooks and HTMLForm system
- Architecture: HIGH - Follows exact pattern established in Phase 2 (EnforcementHooks), matches existing extension.json structure
- Pitfalls: HIGH for #1-4 (verified against official docs), MEDIUM for #5 (empirical testing needed)
- Code examples: HIGH for structure/pattern, LOW for exact import paths in MW 1.44

**Research date:** 2026-01-28
**Valid until:** 2026-02-28 (30 days -- stable MW core hooks)
