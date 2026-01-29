---
status: diagnosed
trigger: "VE bridge module fails to store the permission level during VisualEditor uploads"
created: 2026-01-29T00:00:00Z
updated: 2026-01-29T00:05:00Z
---

## Current Focus

hypothesis: CONFIRMED - The publish-from-stash request sends a URL-encoded string body, not FormData. Our XHR interceptor checks `body instanceof FormData` which is false, so injection is silently skipped.
test: Traced full call chain from uploadFromStash -> postWithEditToken -> postWithToken -> post -> ajax -> jQuery.ajax -> xhr.send
expecting: N/A - root cause confirmed
next_action: Return diagnosis

## Symptoms

expected: After uploading a file through VE with a permission level selected, the File: page shows the permission level
actual: File: page shows no permission level after VE upload
errors: None visible (silent failure)
reproduction: Upload file via VE media dialog with permission dropdown set
started: Since initial VE bridge implementation (Phase 6)

## Eliminated

- hypothesis: jQuery.ajax might bypass XMLHttpRequest entirely (using fetch or another mechanism)
  evidence: jQuery source (jquery.js line 9798-9940) confirms jQuery.ajaxSettings.xhr creates `new window.XMLHttpRequest()`, and the ajax transport calls `xhr.open()` then `xhr.send(options.data)`. Our prototype patches on XMLHttpRequest.prototype.open and .send DO intercept jQuery.ajax calls.
  timestamp: 2026-01-29T00:03:00Z

- hypothesis: The publish-from-stash request might not reach our XHR interceptor at all
  evidence: It does reach the interceptor. jQuery.ajax uses XMLHttpRequest internally. The request goes through our patched .open() and .send(). But the body type check fails.
  timestamp: 2026-01-29T00:04:00Z

## Evidence

- timestamp: 2026-01-29T00:01:00Z
  checked: ext.FilePermissions.visualeditor.js - full source review
  found: XHR interception logic patches XMLHttpRequest.prototype.open and .send. The send() patch checks `body instanceof FormData` at line 150 before attempting injection.
  implication: The interception logic itself is correct IF the body is FormData. The guards (isUpload, hasFilekey, !already-present) are sound.

- timestamp: 2026-01-29T00:02:00Z
  checked: 06-RESEARCH.md - VE upload flow analysis
  found: VE publish path is finishStashUpload -> uploadFromStash -> postWithEditToken -> underlying HTTP. Research confirms postWithEditToken does NOT filter params.
  implication: The parameter filtering concern is resolved (publish phase does not strip params). The remaining question is about how the data is serialized.

- timestamp: 2026-01-29T00:03:00Z
  checked: mediawiki.api/upload.js lines 414-429 - uploadFromStash implementation
  found: `uploadFromStash(filekey, data)` calls `this.postWithEditToken(data)` with NO second ajaxOptions argument. Critically, it does NOT pass `{ contentType: 'multipart/form-data' }` in ajaxOptions.
  implication: Without the contentType hint, mw.Api.ajax() will NOT create a FormData body.

- timestamp: 2026-01-29T00:04:00Z
  checked: mediawiki.api/index.js lines 260-296 - mw.Api.ajax() body serialization
  found: The ajax method checks `ajaxOptions.contentType === 'multipart/form-data'` to decide between FormData (line 267) and $.param() URL-encoding (line 285). When contentType is NOT 'multipart/form-data', it calls `ajaxOptions.data = $.param(parameters)` which produces a URL-encoded string like "action=upload&filekey=abc&filename=test.png&text=...&token=xyz".
  implication: The publish-from-stash XHR body is a STRING, not FormData. Our interceptor's `body instanceof FormData` check at line 150 returns false.

- timestamp: 2026-01-29T00:04:30Z
  checked: Compared with uploadWithFormData (stash phase) at upload.js lines 64-117
  found: uploadWithFormData DOES pass `{ contentType: 'multipart/form-data' }` as ajaxOptions (line 85), which is why the stash phase uses FormData. But only uploadWithFormData does this. uploadFromStash just calls postWithEditToken with no ajaxOptions.
  implication: The two upload phases use fundamentally different serialization. Stash = FormData (for file binary). Publish = URL-encoded string (no file needed, just metadata).

- timestamp: 2026-01-29T00:04:45Z
  checked: ext.FilePermissions.msupload.js - why MsUpload bridge works
  found: MsUpload uses plupload which constructs its own FormData and calls native XMLHttpRequest.send(formData) directly. The comment at line 7-8 states: "plupload uses native XHR (not jQuery.ajax)". So for MsUpload, body IS FormData.
  implication: The XHR interception pattern was designed for plupload's native XHR usage. It was copied to the VE bridge without accounting for the different serialization path used by mw.Api.

- timestamp: 2026-01-29T00:05:00Z
  checked: jQuery source (jquery.js lines 9798-9940) - ajax transport mechanism
  found: jQuery.ajaxSettings.xhr returns `new window.XMLHttpRequest()` (line 9800). The transport's send method calls `xhr.open()` (line 9828) then `xhr.send(options.data)` (line 9940). Our prototype patches intercept both calls. But `options.data` is whatever mw.Api.ajax() set - in this case a URL-encoded string.
  implication: jQuery.ajax DOES go through XMLHttpRequest. The problem is not the transport mechanism but the body format.

## Resolution

root_cause: The publish-from-stash API call (`mw.Api.uploadFromStash`) sends its request body as a URL-encoded string, NOT as FormData. Our XHR interceptor at line 150 checks `body instanceof FormData` which evaluates to `false` for the publish-from-stash request. The entire injection block is silently skipped. This happens because `uploadFromStash` calls `postWithEditToken(data)` without passing `{ contentType: 'multipart/form-data' }` in ajaxOptions - unlike `uploadWithFormData` (used in the stash phase) which does pass that contentType, triggering FormData serialization. The XHR interception pattern was borrowed from the MsUpload bridge where plupload directly constructs FormData and calls XMLHttpRequest.send(formData). In the VE case, mw.Api serializes the same parameters as a query string.
fix:
verification:
files_changed: []
