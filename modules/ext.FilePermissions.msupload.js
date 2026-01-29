/**
 * FilePermissions MsUpload bridge module.
 *
 * Injects a permission-level dropdown into MsUpload's toolbar area,
 * hooks into plupload's BeforeUpload event to transmit the selected
 * permission level as wpFilePermLevel in multipart_params, disables
 * the dropdown during upload, and verifies permission storage after
 * each file upload via a PageProps API query.
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
	 * BeforeUpload handler — inject wpFilePermLevel into multipart_params.
	 *
	 * CRITICAL: This runs AFTER MsUpload's onBeforeUpload handler, which calls
	 * uploader.setOption('multipart_params', {...}) replacing the entire object.
	 * We read the CURRENT params and add our field. We do NOT call setOption()
	 * because that would replace MsUpload's params (action, filename, token, etc.).
	 *
	 * @param {Object} uploader The plupload Uploader instance
	 */
	function onBeforeUpload( uploader ) {
		var params = uploader.settings.multipart_params || {};
		params.wpFilePermLevel = getSelectedLevel();
		uploader.settings.multipart_params = params;
	}

	/**
	 * FileUploaded handler — verify permission was stored via PageProps API query.
	 *
	 * After each successful file upload, query the API to confirm that the
	 * fileperm_level page property was saved. If not, show a persistent error
	 * notification so the user can check the file manually.
	 *
	 * @param {Object} uploader The plupload Uploader instance
	 * @param {Object} file The plupload File object
	 * @param {Object} response The server response object (response.response is raw text)
	 */
	function onFileUploaded( uploader, file, response ) {
		var result;

		try {
			result = JSON.parse( response.response );
		} catch ( e ) {
			// Response parsing failed — MsUpload handles upload errors
			return;
		}

		// Only verify on successful uploads
		if ( !result.upload || result.upload.result !== 'Success' ) {
			return;
		}

		var filename = result.upload.filename;

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
			// API returns pages keyed by page ID
			for ( pageId in pages ) {
				page = pages[ pageId ];
				if ( !page.pageprops || !page.pageprops.fileperm_level ) {
					mw.notify(
						mw.msg( 'filepermissions-msupload-error-save', file.name ),
						{ type: 'error', autoHide: false }
					);
				}
			}
		} );
	}

	/**
	 * StateChanged handler — disable/enable dropdown during upload.
	 *
	 * Uses plupload state constants when available, numeric fallbacks otherwise.
	 * plupload.STARTED = 2, plupload.STOPPED = 1
	 *
	 * @param {Object} uploader The plupload Uploader instance
	 */
	function onStateChanged( uploader ) {
		var STARTED = ( typeof plupload !== 'undefined' && plupload.STARTED ) || 2;
		var STOPPED = ( typeof plupload !== 'undefined' && plupload.STOPPED ) || 1;

		if ( uploader.state === STARTED ) {
			$( '#fileperm-msupload-select' ).prop( 'disabled', true );
		} else if ( uploader.state === STOPPED ) {
			$( '#fileperm-msupload-select' ).prop( 'disabled', false );
		}
	}

	// Main initialization via memorized MW hook.
	// MsUpload registers its handler on the same hook via
	// mw.hook('wikiEditor.toolbarReady').add(MsUpload.createUploader).
	// Since our module loads after MsUpload's PHP hook adds ext.MsUpload,
	// MsUpload's handler runs first, ensuring MsUpload.uploader exists.
	mw.hook( 'wikiEditor.toolbarReady' ).add( function () {
		// Guard: MsUpload must be initialized with an uploader instance
		if ( typeof MsUpload === 'undefined' || !MsUpload.uploader ) {
			return;
		}

		// Guard: MsUpload DOM container must exist
		var $msDiv = $( '#msupload-div' );
		if ( !$msDiv.length ) {
			return;
		}

		// Inject dropdown before the dropzone
		$msDiv.prepend( buildDropdown() );

		// Bind plupload events
		MsUpload.uploader.bind( 'BeforeUpload', onBeforeUpload );
		MsUpload.uploader.bind( 'FileUploaded', onFileUploaded );
		MsUpload.uploader.bind( 'StateChanged', onStateChanged );
	} );
}() );
