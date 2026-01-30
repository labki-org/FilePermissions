/**
 * FilePermissions shared module.
 *
 * Provides common utilities used by both the MsUpload and VisualEditor bridge
 * modules:
 * - mw.FilePermissions.verifyPermission(): post-upload permission verification
 * - One-time XMLHttpRequest.prototype.open patch to tag API POST requests
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

	// Patch XMLHttpRequest.prototype.open once to tag API POST requests.
	// Both MsUpload and VE bridge modules rely on _filePermIsApiPost being
	// set on XHR instances for their send() interceptors.
	var origOpen = XMLHttpRequest.prototype.open;
	XMLHttpRequest.prototype.open = function ( method, url ) {
		if ( method === 'POST' && url && url.indexOf( 'api.php' ) !== -1 ) {
			this._filePermIsApiPost = true;
		}
		return origOpen.apply( this, arguments );
	};
}() );
