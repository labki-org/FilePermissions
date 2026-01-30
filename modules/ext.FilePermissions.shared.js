/**
 * FilePermissions shared module.
 *
 * Provides common utilities used by both the MsUpload and VisualEditor bridge
 * modules:
 * - mw.FilePermissions.verifyPermission(): post-upload permission verification
 * - mw.FilePermissions.onUploadSend(): callback registry for XHR upload interception
 * - One-time XMLHttpRequest.prototype.open/send patch to tag and intercept API uploads
 *
 * Loaded as a dependency of ext.FilePermissions.msupload and
 * ext.FilePermissions.visualeditor, ensuring these utilities are initialized
 * exactly once even when both bridges are active on the same page.
 */
( function () {
	'use strict';

	// Namespace for shared API
	mw.FilePermissions = mw.FilePermissions || {};

	/**
	 * Verify permission was stored for a file via the fileperm API prop module.
	 * Shows a persistent error notification if the permission level was
	 * not found after upload.
	 *
	 * @param {string} filename The uploaded filename
	 * @param {string} errorMsgKey The i18n message key for the error notification
	 */
	mw.FilePermissions.verifyPermission = function ( filename, errorMsgKey ) {
		new mw.Api().get( {
			action: 'query',
			titles: 'File:' + filename,
			prop: 'fileperm'
		} ).then( function ( data ) {
			var pages, pageId, page;

			if ( !data.query || !data.query.pages ) {
				return;
			}

			pages = data.query.pages;
			for ( pageId in pages ) {
				page = pages[ pageId ];
				if ( !page.fileperm_level ) {
					mw.notify(
						mw.msg( errorMsgKey, filename ),
						{ type: 'error', autoHide: false }
					);
				}
			}
		} );
	};

	// --- Callback registry for upload XHR interception ---

	mw.FilePermissions._uploadCallbacks = [];

	/**
	 * Register a callback to be invoked when an upload XHR send() is detected.
	 *
	 * Callbacks receive (xhr, body) and may return a modified body string
	 * (for URL-encoded string bodies only). FormData bodies are modified
	 * in-place by the callback.
	 *
	 * @param {Function} callback Function(xhr, body) => modified body or undefined
	 */
	mw.FilePermissions.onUploadSend = function ( callback ) {
		mw.FilePermissions._uploadCallbacks.push( callback );
	};

	// Patch XMLHttpRequest.prototype.open once to tag API POST requests.
	var origOpen = XMLHttpRequest.prototype.open;
	XMLHttpRequest.prototype.open = function ( method, url ) {
		if ( method === 'POST' && url && url.indexOf( 'api.php' ) !== -1 ) {
			this._filePermIsApiPost = true;
		}
		return origOpen.apply( this, arguments );
	};

	// Patch XMLHttpRequest.prototype.send once to detect action=upload
	// in both FormData and string bodies, then invoke all registered callbacks.
	var origSend = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.send = function ( body ) {
		if ( this._filePermIsApiPost ) {
			var isUpload = false;

			if ( body instanceof FormData ) {
				isUpload = ( typeof body.get === 'function' ) &&
					body.get( 'action' ) === 'upload';
			} else if ( typeof body === 'string' ) {
				isUpload = body.indexOf( 'action=upload' ) !== -1;
			}

			if ( isUpload ) {
				var callbacks = mw.FilePermissions._uploadCallbacks;
				for ( var i = 0; i < callbacks.length; i++ ) {
					var result = callbacks[ i ]( this, body );
					if ( typeof result === 'string' ) {
						body = result;
					}
				}
			}
		}

		return origSend.call( this, body );
	};
}() );
