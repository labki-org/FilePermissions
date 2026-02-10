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

	// SEC-12: Warn if namespace was already defined (possible extension conflict)
	if ( mw.FilePermissions !== undefined ) {
		mw.log.warn(
			'FilePermissions: mw.FilePermissions namespace already defined. ' +
			'Possible conflict with another extension or script.'
		);
	}

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
		} ).then( ( data ) => {
			if ( !data.query || !data.query.pages ) {
				return;
			}

			const pages = data.query.pages;
			for ( const pageId of Object.keys( pages ) ) {
				const page = pages[ pageId ];
				if ( !page.fileperm_level ) {
					mw.notify(
						$( '<span>' ).append(
							mw.msg( errorMsgKey, filename ),
							' ',
							$( '<a>' )
								.attr( 'href', mw.util.getUrl( 'File:' + filename ) )
								.text( mw.msg( 'filepermissions-verify-link' ) )
						),
						{ type: 'error', autoHide: false }
					);
				}
			}
		} );
	};

	// --- Callback registry for upload XHR interception ---

	const uploadCallbacks = [];

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
		uploadCallbacks.push( callback );
	};

	// SEC-03: Use WeakMap to avoid exposing state on XHR instances
	const xhrState = new WeakMap();

	// CQ-08: Detect if another script already patched XHR prototypes
	const origOpen = XMLHttpRequest.prototype.open;
	if ( origOpen.toString().indexOf( '[native code]' ) === -1 ) {
		mw.log.warn(
			'FilePermissions: XMLHttpRequest.prototype.open already patched ' +
			'by another script. Upload interception may conflict.'
		);
	}

	const origSend = XMLHttpRequest.prototype.send;
	if ( origSend.toString().indexOf( '[native code]' ) === -1 ) {
		mw.log.warn(
			'FilePermissions: XMLHttpRequest.prototype.send already patched ' +
			'by another script. Upload interception may conflict.'
		);
	}

	// Patch XMLHttpRequest.prototype.open once to tag API POST requests.
	XMLHttpRequest.prototype.open = function ( method, url ) {
		if ( method === 'POST' && url ) {
			// CQ-08: Use URL parser for tighter pathname matching
			try {
				const parsed = new URL( url, location.origin );
				if ( parsed.pathname.endsWith( 'api.php' ) ) {
					xhrState.set( this, { isApiPost: true } );
				}
			} catch ( e ) {
				// Malformed URL, skip tagging
			}
		}
		return origOpen.apply( this, arguments );
	};

	// Patch XMLHttpRequest.prototype.send once to detect action=upload
	// in both FormData and string bodies, then invoke all registered callbacks.
	XMLHttpRequest.prototype.send = function ( body ) {
		const state = xhrState.get( this );
		if ( state && state.isApiPost ) {
			let isUpload = false;

			if ( body instanceof FormData ) {
				isUpload = ( typeof body.get === 'function' ) &&
					body.get( 'action' ) === 'upload';
			} else if ( typeof body === 'string' ) {
				// CQ-08: Use URLSearchParams for safer string body parsing
				try {
					const params = new URLSearchParams( body );
					isUpload = params.get( 'action' ) === 'upload';
				} catch ( e ) {
					isUpload = false;
				}
			}

			if ( isUpload ) {
				for ( const callback of uploadCallbacks ) {
					const result = callback( this, body );
					if ( typeof result === 'string' ) {
						body = result;
					}
				}
			}
		}

		return origSend.call( this, body );
	};
}() );
