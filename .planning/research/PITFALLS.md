# Domain Pitfalls: MediaWiki File Permission Extensions

**Domain:** MediaWiki file access control extension
**Researched:** 2026-01-28
**Confidence:** MEDIUM (verified against official MW documentation, community reports, and extension histories)

---

## Critical Pitfalls

Mistakes that cause security failures or require architectural rewrites.

---

### Pitfall 1: Thumbnail/Derivative Bypass

**What goes wrong:** Protected files remain accessible through generated thumbnails. Extension protects original file but thumbnails in `/images/thumb/` are served directly by web server, completely bypassing permission checks.

**Why it happens:**
- MediaWiki generates thumbnails on-demand and stores them in a separate directory structure
- Developers focus on protecting the original file path
- Thumbnail paths follow predictable patterns (`/images/thumb/a/ab/File.jpg/300px-File.jpg`)
- Web server configuration often only blocks `/images/` but allows `/images/thumb/`

**Consequences:**
- Complete security bypass: any unauthorized user can view thumbnails
- Most MediaWiki file usage IS thumbnails (rarely full-size)
- False sense of security while data leaks

**Prevention:**
1. Ensure `img_auth.php` handles ALL paths including `/thumb/` derivatives
2. Web server must block direct access to entire `/images/` tree including all subdirectories
3. Test specifically for thumbnail access after configuring protection
4. Consider using `$wgThumbnailPath` to route thumbnails through img_auth.php

**Detection:**
- Warning sign: Can view protected file's thumbnail via direct URL
- Test: Try `/images/thumb/[hash]/[filename]/[size]-[filename]` directly
- Audit: Check web server config for `/images/` blocking completeness

**Phase mapping:** Infrastructure/Deployment phase - must be configured before any protection is meaningful

**Sources:**
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization) - HIGH confidence
- [Manual:Security](https://www.mediawiki.org/wiki/Manual:Security) - HIGH confidence

---

### Pitfall 2: Direct `/images/` Access Bypass

**What goes wrong:** img_auth.php is configured but web server still allows direct access to `/images/` directory, making all protection useless.

**Why it happens:**
- Setting `$wgUploadPath` to img_auth.php only changes URLs MediaWiki generates
- If user knows/guesses original path (`/images/a/ab/File.jpg`), direct access works
- IIS has additional complexity with virtual directory paths
- Developers test via MediaWiki interface, never test direct URL access

**Consequences:**
- Complete security bypass
- "If the above step is not done, the user can simply substitute images for img_auth.php in the URL to bypass the security controls" - official MW documentation
- All development effort on permission logic is wasted

**Prevention:**
1. **Apache:** Block in `.htaccess` or httpd.conf:
   ```apache
   <Directory "/path/to/images">
       Deny from all
   </Directory>
   ```
2. **Nginx:** Use `location` block to deny
3. **Best:** Move upload directory outside web root entirely
4. **IIS:** Create separate directory outside MediaWiki root (documented MW requirement)

**Detection:**
- Test: Navigate directly to `/images/[hash]/[filename]` - should get 403, not file
- Automated: Include direct-access test in deployment verification

**Phase mapping:** First phase - infrastructure must be validated before any code

**Sources:**
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization) - HIGH confidence
- Official MW states this explicitly as #1 mistake

---

### Pitfall 3: Caching Exposes Protected Content

**What goes wrong:** MediaWiki's caching serves protected content to unauthorized users, or leaks existence/metadata of protected files.

**Why it happens:**
- MediaWiki caches one version of a page and serves to ALL users without rechecking rights
- Parser cache doesn't have rights-specific variants
- API responses may be cached
- CDN/Varnish layers add external caching

**Consequences:**
- User A (authorized) views page with protected image
- Page is cached
- User B (unauthorized) sees the same cached version including protected image
- Particularly bad for file description pages and pages embedding protected files

**Prevention:**
1. Use `$wgParserCacheType = CACHE_NONE` for high-security scenarios (performance cost)
2. Implement `ParserOptions::setCurrentUser()` properly in hooks
3. Consider using `NOCACHE` magic word on pages with protected content
4. For embedded images: serve via img_auth.php (per-request permission check)
5. Disable or carefully configure Varnish/CDN for protected namespaces

**Detection:**
- Warning sign: Protected content visible after logout
- Test: View protected file page as admin, logout, navigate directly to cached URL
- Monitor cache hit rates on protected content

**Phase mapping:** Architecture phase - caching strategy must be designed early

**Sources:**
- [Security issues with authorization extensions](https://www.mediawiki.org/wiki/Security_issues_with_authorization_extensions) - MEDIUM confidence (couldn't fetch page directly, verified via search excerpts)
- Multiple community reports confirm this issue

---

### Pitfall 4: Search Index Leaks Protected Content

**What goes wrong:** Protected file names, descriptions, and even content snippets appear in search results for unauthorized users.

**Why it happens:**
- Search index is built without regard to access permissions
- Special:Search shows excerpts/snippets around matched terms
- File description pages are indexed like any other page
- CirrusSearch (Elasticsearch) doesn't integrate with per-file permissions

**Consequences:**
- File existence disclosed (metadata leak)
- File descriptions (which may contain sensitive info) visible in snippets
- "Excerpts of page content may be shown by Special:Search, regardless of read permission" - official MW docs

**Prevention:**
1. **Immediate:** Disable searching in protected file namespace
2. **Extension-level:** Hook into `SearchResultProvided` to filter results
3. **Lockdown pattern:** Add protected namespace to `$wgNonincludableNamespaces` and exclude from search config
4. **Content:** Never put sensitive information in file descriptions (defense in depth)
5. **CirrusSearch:** Configure index to exclude protected namespaces

**Detection:**
- Test: Search for text that appears only in protected file descriptions
- Audit: Review search config for namespace exclusions

**Phase mapping:** Feature phase - must be addressed when implementing search integration

**Sources:**
- [Manual:Preventing access](https://www.mediawiki.org/wiki/Manual:Preventing_access) - HIGH confidence
- [Extension:AccessControl](https://www.mediawiki.org/wiki/Extension:AccessControl) - MEDIUM confidence

---

### Pitfall 5: Recent Changes/Logs Disclose Protected Files

**What goes wrong:** Upload, edit, and delete operations on protected files appear in public logs and Special:RecentChanges.

**Why it happens:**
- MediaWiki's logging system is separate from content access control
- Special pages don't inherit file permission restrictions
- Log entries include file names, edit summaries, uploader names

**Consequences:**
- File existence disclosed
- File metadata (edit summaries) visible
- Upload patterns can reveal sensitive activity
- "Changes to 'hidden' pages are still shown in Special:Recentchanges, etc., including the edit summary" - official docs

**Prevention:**
1. Hook into `LogEventsListLineEnding` to filter log entries
2. Hook into `ChangesListSpecialPageQuery` to filter RC entries
3. Use `$wgLogRestrictions` for upload/delete logs
4. Educate uploaders: never put sensitive info in edit summaries
5. Consider separate, permission-controlled upload log

**Detection:**
- Test: Upload protected file, check Recent Changes while logged out
- Audit: Review visible information in Special:Log/upload

**Phase mapping:** Feature phase - after core protection, before production

**Sources:**
- [Manual:Preventing access](https://www.mediawiki.org/wiki/Manual:Preventing_access) - HIGH confidence

---

## Moderate Pitfalls

Mistakes that cause delays, technical debt, or partial security gaps.

---

### Pitfall 6: Embedded Image Check Missing

**What goes wrong:** Protected file shows in File: page permission denied, but embeds fine in other pages via `[[File:Protected.jpg]]`.

**Why it happens:**
- Page rendering fetches file from LocalFile/FileRepo without checking viewer permissions
- Parser transform happens at parse time, stored in parser cache
- Individual image embed doesn't trigger permission hook

**Prevention:**
1. Hook into `TitleQuickPermissions` for NS_FILE read checks
2. Register handler for `ParserFetchTemplate` / `FetchTemplate` to intercept file usage
3. For proper enforcement, files must be served through img_auth.php (catches ALL access)
4. Test: Create page that embeds protected file, view as unauthorized user

**Detection:**
- Protected file visible when embedded on allowed page
- Check parser output for protected file references

**Phase mapping:** Core enforcement phase - part of main permission checking

---

### Pitfall 7: Upload Form Permission Not Persisted

**What goes wrong:** User selects permission level in upload form, but it's lost or not stored on upload completion.

**Why it happens:**
- Upload process is multi-step (form -> verification -> stash -> publish)
- Custom form fields must be carried through entire pipeline
- `UploadComplete` hook receives UploadBase, not form parameters
- Session/stash doesn't preserve custom metadata by default

**Prevention:**
1. Store permission selection in upload stash metadata
2. Use `UploadForm:BeforeProcessing` carefully (deprecated, no user feedback on failure)
3. Better: Use `UploadVerifyUpload` to validate and `UploadComplete` to persist
4. For chunked uploads: permission must persist across chunks
5. Test: Upload file with non-default permission, verify PageProps after completion

**Detection:**
- Permission defaults to fallback after upload
- Debug: Log at each upload stage to trace where data is lost

**Phase mapping:** Upload integration phase

**Sources:**
- [Manual:Hooks/UploadComplete](https://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete) - MEDIUM confidence

---

### Pitfall 8: MsUpload Bypass (JavaScript Bridge Failure)

**What goes wrong:** Files uploaded through MsUpload drag-drop don't get permission level applied.

**Why it happens:**
- MsUpload uses plupload library, separate from standard MediaWiki upload
- JS bridge must inject form data before upload starts
- MsUpload's `BeforeUpload` event timing can be tricky
- Upload API call bypasses Special:Upload entirely

**Prevention:**
1. Hook `mw.hook('wikiEditor.toolbarReady')` at correct time
2. Inject into plupload's `BeforeUpload` to add form data
3. Server-side: Handle permission parameter in API upload handler
4. Fallback: Apply default permission if parameter missing (log warning)
5. Test: Drag-drop file via MsUpload, verify permission stored

**Detection:**
- Files uploaded via MsUpload have wrong/default permission
- Browser console shows if JS hook fires
- Network tab shows if `fileperm_level` parameter sent

**Phase mapping:** MsUpload integration phase - separate from core upload flow

---

### Pitfall 9: PageProps Not Set on Re-upload

**What goes wrong:** File permission only set on initial upload; re-upload doesn't update or validate permission.

**Why it happens:**
- Re-upload is different code path (`reupload` permission, not `upload`)
- `UploadComplete` hook fires for both, but form context differs
- Admin might re-upload without setting permission
- Existing PageProps value might be overwritten with default

**Prevention:**
1. Check `$upload->isReupload()` in UploadComplete hook
2. Decide policy: preserve existing permission on re-upload or require explicit selection
3. UI should show current permission and allow changing
4. Validate permission level matches user's authorization (can't escalate own file)

**Detection:**
- Re-upload changes permission unexpectedly
- Test: Upload with permission A, re-upload, check if permission changed

**Phase mapping:** Upload integration phase

---

### Pitfall 10: Template/Transclusion Bypass

**What goes wrong:** Protected file's description page content visible by transcluding it into another page.

**Why it happens:**
- Transclusion works at wikitext level before permission checks
- `{{:File:Protected.jpg}}` includes content from protected page
- Similar to Lockdown's known transclusion bypass

**Prevention:**
1. Add NS_FILE to `$wgNonincludableNamespaces` if file descriptions contain sensitive info
2. Hook `ParserFetchTemplate` to block transclusion of protected files
3. Note: This is about file description pages, not the actual file content
4. Decide if file descriptions need same protection level as files

**Detection:**
- Create page transcluding protected file description
- Test: View as unauthorized user

**Phase mapping:** Feature phase - defense in depth

**Sources:**
- [Extension:Lockdown](https://www.mediawiki.org/wiki/Extension:Lockdown) - documents this as known bypass

---

## Minor Pitfalls

Mistakes that cause annoyance but are recoverable.

---

### Pitfall 11: Permission Level Typo/Mismatch

**What goes wrong:** Permission level in config doesn't match what's stored in PageProps due to case sensitivity or typo.

**Why it happens:**
- PHP string comparison is case-sensitive by default
- Config uses 'public', PageProps has 'Public'
- After rename, old level in PageProps no longer valid

**Prevention:**
1. Normalize permission levels (lowercase or uppercase consistently)
2. Validate against allowed levels on storage
3. Define canonical names in Config class
4. Migration script for level renames
5. Log warning when unknown level encountered

**Detection:**
- Files "stuck" with invalid permission level
- Unexpected permission denials

**Phase mapping:** Core implementation - data validation

---

### Pitfall 12: img_auth.php Error Messages Leak Information

**What goes wrong:** Error messages when access denied reveal file existence or permission details.

**Why it happens:**
- Default img_auth returns different errors for "file doesn't exist" vs "permission denied"
- Custom error messages may include file path or permission level
- Attackers can enumerate files by analyzing error responses

**Prevention:**
1. Use generic error for all denials: "File not accessible"
2. Don't differentiate between "doesn't exist" and "not authorized"
3. Consistent response time (prevent timing attacks)
4. Official MW advice: "you usually don't want your user to know why access was denied, just that it was"

**Detection:**
- Try accessing various files, compare error messages
- Non-existent file vs protected file should look identical

**Phase mapping:** Core enforcement phase

**Sources:**
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization) - HIGH confidence

---

### Pitfall 13: Orphaned Permissions After File Deletion

**What goes wrong:** Permission metadata remains in PageProps after file deleted, causing confusion or inconsistency on restore.

**Why it happens:**
- File deletion doesn't automatically clean PageProps
- File restoration brings back file but PageProps may be stale or deleted
- No hook coordination between file lifecycle and metadata

**Prevention:**
1. Hook into `FileDeleteComplete` to handle PageProps cleanup
2. Decide policy: delete PageProps on file delete, or preserve for restore?
3. If preserving: validate on restore that permission still valid
4. Consider filearchive table for storing permission during deletion

**Detection:**
- Restore deleted file, check if permission correct
- Query PageProps for File: pages that no longer exist

**Phase mapping:** Feature phase - file lifecycle handling

---

## Phase-Specific Warnings

| Phase Topic | Likely Pitfall | Mitigation |
|-------------|---------------|------------|
| Infrastructure setup | Direct `/images/` access, thumbnail bypass | Validate web server config FIRST, before any code |
| Core permission hooks | Caching issues, embedded image checks | Design cache strategy upfront; test embedded scenarios |
| Upload integration | Permission not persisted, re-upload handling | Trace data through full upload pipeline |
| MsUpload bridge | JS timing, form data injection | Test drag-drop specifically; check browser console |
| Search integration | Index leaks | Exclude protected namespaces from search config |
| Logging/RC | Metadata disclosure | Filter logs before production use |
| File lifecycle | Orphaned metadata on delete/restore | Define clear lifecycle policy |
| Error handling | Information disclosure | Generic errors, no enumeration |

---

## Sources

**HIGH Confidence (Official Documentation):**
- [Manual:Image authorization](https://www.mediawiki.org/wiki/Manual:Image_authorization)
- [Manual:Security](https://www.mediawiki.org/wiki/Manual:Security)
- [Manual:Preventing access](https://www.mediawiki.org/wiki/Manual:Preventing_access)
- [Manual:Hooks/ImgAuthBeforeStream](https://www.mediawiki.org/wiki/Manual:Hooks/ImgAuthBeforeStream)
- [Manual:Hooks/UploadComplete](https://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete)

**MEDIUM Confidence (Extension Documentation, Community Reports):**
- [Extension:Lockdown](https://www.mediawiki.org/wiki/Extension:Lockdown) - known limitation documentation
- [Extension:NSFileRepo](https://www.mediawiki.org/wiki/Extension:NSFileRepo) - file protection extension
- [Extension:AccessControl](https://www.mediawiki.org/wiki/Extension:AccessControl) - search/caching issues

**LOW Confidence (Community Discussion, Forums):**
- Extension talk pages with user-reported issues
- MediaWiki support desk discussions

---

## Key Takeaway

> "MediaWiki is not designed to be a Content Management System (CMS), or to protect sensitive data. To the contrary, it was designed to be as open as possible." - Official MediaWiki Security Documentation

Every layer of protection you add is fighting against MediaWiki's design philosophy. Success requires:
1. **Defense in depth:** Multiple layers, not single point of protection
2. **Infrastructure first:** Web server config is more important than PHP code
3. **Test attack paths:** Think like an attacker, test direct URLs, thumbnails, embeds, search
4. **Accept limitations:** Some leaks (metadata, existence) may be unavoidable; document and accept
