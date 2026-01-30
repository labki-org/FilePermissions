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
 * in DisplayHooks.php). Does NOT declare ext.MsUpload as a dependency.
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
	 * Initialize the bridge: inject dropdown and register upload callback.
	 *
	 * @param {jQuery} $msDiv The #msupload-div container
	 */
	function init( $msDiv ) {
		// Inject dropdown before the dropzone
		$msDiv.prepend( buildDropdown() );

		// Register callback with shared XHR interceptor to inject
		// wpFilePermLevel and monitor upload responses.
		mw.FilePermissions.onUploadSend( function ( xhr, body ) {
			if ( !( body instanceof FormData ) ) {
				return;
			}
			var $select = $( '#fileperm-msupload-select' );
			if ( !$select.length ) {
				return;
			}

			// Inject permission level if not already present
			if ( !body.get( 'wpFilePermLevel' ) ) {
				body.append( 'wpFilePermLevel', getSelectedLevel() );
			}

			// Disable dropdown while uploading
			$select.prop( 'disabled', true );

			// Listen for upload completion
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
						mw.FilePermissions.verifyPermission( response.upload.filename, 'filepermissions-msupload-error-save' );
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
		} );
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
