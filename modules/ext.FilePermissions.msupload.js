/**
 * FilePermissions MsUpload bridge module.
 *
 * Injects a permission-level dropdown into MsUpload's toolbar area
 * and intercepts upload requests to include the selected permission
 * level as wpFilePermLevel. Uses XMLHttpRequest prototype patching
 * because plupload uses native XHR (not jQuery.ajax) and MsUpload's
 * uploader is module-scoped and not accessible from external code.
 *
 * Loaded conditionally when MsUpload is installed (server-side check
 * in MsUploadHooks.php). Does NOT declare ext.MsUpload as a dependency.
 */
( function () {
	'use strict';

	var levels = mw.config.get( 'wgFilePermLevels' );
	var defaultLevel = mw.config.get( 'wgFilePermMsUploadDefault' );

	// Guard: levels must be available and non-empty
	if ( !levels || !levels.length ) {
		mw.hook( 'wikiEditor.toolbarReady' ).add( function () {
			var $msDiv = $( '#msupload-div' );
			if ( $msDiv.length ) {
				$msDiv.prepend(
					$( '<div>' )
						.addClass( 'fileperm-msupload-error' )
						.text( mw.msg( 'filepermissions-msupload-error-nolevels' ) )
				);
			}
		} );
		return;
	}

	/**
	 * Build the permission dropdown container with label and select.
	 *
	 * @return {jQuery} Container element ready to prepend into #msupload-div
	 */
	function buildDropdown() {
		var $container = $( '<div>' )
			.addClass( 'fileperm-msupload-controls' )
			.attr( 'id', 'fileperm-msupload-controls' );

		var $label = $( '<label>' )
			.attr( 'for', 'fileperm-msupload-select' )
			.text( mw.msg( 'filepermissions-msupload-label' ) );

		var $select = $( '<select>' )
			.attr( { id: 'fileperm-msupload-select', name: 'fileperm-msupload-select' } );

		var i, $option;
		for ( i = 0; i < levels.length; i++ ) {
			$option = $( '<option>' )
				.val( levels[ i ] )
				.text( levels[ i ] );
			if ( levels[ i ] === defaultLevel ) {
				$option.prop( 'selected', true );
			}
			$select.append( $option );
		}

		// If no default matched and levels exist, select the first option
		if ( defaultLevel === null && levels.length > 0 ) {
			$select.children().first().prop( 'selected', true );
		}

		$container.append( $label, $select );
		return $container;
	}

	/**
	 * Get the currently selected permission level.
	 *
	 * @return {string} Selected permission level value
	 */
	function getSelectedLevel() {
		return $( '#fileperm-msupload-select' ).val();
	}

	/**
	 * Verify permission was stored for a file via PageProps API query.
	 *
	 * @param {string} filename The uploaded filename
	 */
	function verifyPermission( filename ) {
		new mw.Api().get( {
			action: 'query',
			titles: 'File:' + filename,
			prop: 'pageprops',
			ppprop: 'fileperm_level'
		} ).then( function ( data ) {
			var pages, pageId, page;

			if ( !data.query || !data.query.pages ) {
				return;
			}

			pages = data.query.pages;
			for ( pageId in pages ) {
				page = pages[ pageId ];
				if ( !page.pageprops || !page.pageprops.fileperm_level ) {
					mw.notify(
						mw.msg( 'filepermissions-msupload-error-save', filename ),
						{ type: 'error', autoHide: false }
					);
				}
			}
		} );
	}

	/**
	 * Initialize the bridge: inject dropdown and patch XHR for uploads.
	 *
	 * @param {jQuery} $msDiv The #msupload-div container
	 */
	function init( $msDiv ) {
		// Inject dropdown before the dropzone
		$msDiv.prepend( buildDropdown() );

		// Intercept XMLHttpRequest to inject wpFilePermLevel into upload
		// FormData and monitor responses for post-upload verification.
		// plupload uses native XHR (not jQuery.ajax), so we patch at
		// the XHR prototype level.

		// Patch open() to tag API POST requests
		var origOpen = XMLHttpRequest.prototype.open;
		XMLHttpRequest.prototype.open = function ( method, url ) {
			if ( method === 'POST' && url && url.indexOf( 'api.php' ) !== -1 ) {
				this._filePermIsApiPost = true;
			}
			return origOpen.apply( this, arguments );
		};

		// Patch send() to inject param and attach response listeners
		var origSend = XMLHttpRequest.prototype.send;
		XMLHttpRequest.prototype.send = function ( body ) {
			if ( this._filePermIsApiPost && body instanceof FormData ) {
				var isUpload = ( typeof body.get === 'function' ) &&
					body.get( 'action' ) === 'upload';

				if ( isUpload ) {
					// Inject permission level if not already present
					if ( !body.get( 'wpFilePermLevel' ) ) {
						body.append( 'wpFilePermLevel', getSelectedLevel() );
					}

					// Disable dropdown while uploading
					$( '#fileperm-msupload-select' ).prop( 'disabled', true );

					// Listen for upload completion
					var xhr = this;
					xhr.addEventListener( 'load', function () {
						var response;
						try {
							response = JSON.parse( xhr.responseText );
						} catch ( e ) {
							return;
						}

						if ( response && response.upload &&
							response.upload.result === 'Success' ) {
							// Delay to allow DeferredUpdates to store permission
							setTimeout( function () {
								verifyPermission( response.upload.filename );
							}, 1000 );
						}

						// Re-enable dropdown when no more uploads pending
						setTimeout( function () {
							var pending = $( '#msupload-list li:not(.green):not(.yellow)' ).length;
							if ( pending === 0 ) {
								$( '#fileperm-msupload-select' ).prop( 'disabled', false );
							}
						}, 500 );
					} );

					xhr.addEventListener( 'error', function () {
						setTimeout( function () {
							var pending = $( '#msupload-list li:not(.green):not(.yellow)' ).length;
							if ( pending === 0 ) {
								$( '#fileperm-msupload-select' ).prop( 'disabled', false );
							}
						}, 500 );
					} );
				}
			}

			return origSend.apply( this, arguments );
		};
	}

	// Main initialization via memorized MW hook.
	// Both this module and MsUpload listen to wikiEditor.toolbarReady.
	// If our callback fires before MsUpload's, #msupload-div won't exist
	// yet. Use a MutationObserver to wait for MsUpload to create it.
	mw.hook( 'wikiEditor.toolbarReady' ).add( function () {
		var $msDiv = $( '#msupload-div' );
		if ( $msDiv.length ) {
			init( $msDiv );
			return;
		}

		// MsUpload hasn't created its container yet — watch for it
		var observer = new MutationObserver( function () {
			var $msDiv = $( '#msupload-div' );
			if ( $msDiv.length ) {
				observer.disconnect();
				init( $msDiv );
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	} );
}() );
