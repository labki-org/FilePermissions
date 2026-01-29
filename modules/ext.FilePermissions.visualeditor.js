/**
 * FilePermissions VisualEditor bridge module.
 *
 * Injects a permission-level dropdown into VisualEditor's upload dialog
 * (ForeignStructuredUpload.BookletLayout) and intercepts the publish-from-stash
 * XHR to include the selected permission level as wpFilePermLevel.
 *
 * Uses monkey-patching on BookletLayout prototype methods because VE's
 * MWMediaDialog hard-codes `new mw.ForeignStructuredUpload.BookletLayout(...)`
 * and cannot be subclassed. Uses XMLHttpRequest prototype patching because
 * mw.Api's upload methods filter unknown parameters during the stash phase.
 *
 * Loaded conditionally when VisualEditor is installed (server-side check
 * in VisualEditorHooks.php). Does NOT declare ext.visualEditor as a dependency.
 */
( function () {
	'use strict';

	var levels = mw.config.get( 'wgFilePermLevels' );
	var defaultLevel = mw.config.get( 'wgFilePermVEDefault' );

	// Guard: levels must be available and non-empty
	if ( !levels || !levels.length ) {
		return;
	}

	// Module-level reference to the active dropdown widget.
	// Set when renderInfoForm creates it, read by XHR interceptor.
	var activeDropdown = null;

	/**
	 * Get the currently selected permission level from the active dropdown.
	 *
	 * @return {string} Selected permission level value
	 */
	function getSelectedPermLevel() {
		return activeDropdown ? activeDropdown.getValue() : ( defaultLevel || levels[ 0 ] );
	}

	/**
	 * Verify permission was stored for a file via PageProps API query.
	 * Shows a persistent error notification if the permission level was
	 * not found in PageProps after upload.
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
						mw.msg( 'filepermissions-ve-error-save', filename ),
						{ type: 'error', autoHide: false }
					);
				}
			}
		} );
	}

	// --- PART 1: Monkey-patch BookletLayout ---

	// Patch renderInfoForm to inject OOUI DropdownInputWidget into the
	// upload dialog's info form panel.
	var origRenderInfoForm = mw.ForeignStructuredUpload.BookletLayout.prototype.renderInfoForm;
	mw.ForeignStructuredUpload.BookletLayout.prototype.renderInfoForm = function () {
		var form = origRenderInfoForm.call( this );

		// Skip dropdown for foreign upload targets (e.g. Commons).
		// Only local uploads support FilePermissions.
		if ( this.upload && this.upload.target !== 'local' ) {
			return form;
		}

		// Create OOUI dropdown with permission levels
		this.filePermDropdown = new OO.ui.DropdownInputWidget( {
			options: levels.map( function ( lvl ) {
				return { data: lvl, label: lvl };
			} ),
			value: defaultLevel || levels[ 0 ],
			classes: [ 'fileperm-ve-dropdown' ]
		} );

		// Wrap in a labeled field layout for consistent VE dialog styling
		var fieldLayout = new OO.ui.FieldLayout( this.filePermDropdown, {
			label: mw.msg( 'filepermissions-ve-label' ),
			align: 'top'
		} );

		// Append to the existing info form fieldset
		form.$element.find( '.oo-ui-fieldsetLayout' ).append(
			fieldLayout.$element
		);

		// Store reference for XHR interceptor to read
		activeDropdown = this.filePermDropdown;

		return form;
	};

	// Patch clear() to reset dropdown when dialog is reused.
	// VE reuses the BookletLayout instance across multiple upload operations,
	// so the dropdown must reset to the default value.
	var origClear = mw.ForeignStructuredUpload.BookletLayout.prototype.clear;
	mw.ForeignStructuredUpload.BookletLayout.prototype.clear = function () {
		origClear.call( this );
		if ( this.filePermDropdown ) {
			this.filePermDropdown.setValue( defaultLevel || levels[ 0 ] );
		}
	};

	// --- PART 2: XHR prototype patching for publish-from-stash ---
	//
	// VE uploads in two phases:
	//   Phase 1 (stash): uploadToStash -> uploadWithFormData (fieldsAllowed STRIPS unknown params)
	//   Phase 2 (publish): finishStashUpload -> uploadFromStash -> postWithEditToken (NO filtering)
	//
	// We ONLY inject wpFilePermLevel during Phase 2, identified by:
	//   action=upload AND filekey present in FormData.
	//
	// Coexistence with MsUpload bridge: Both bridges wrap XMLHttpRequest
	// prototype methods. Standard monkey-patching chains correctly because
	// each stores and calls the previous version. The !body.get('wpFilePermLevel')
	// guard prevents double-injection.

	// Patch open() to tag API POST requests
	var origOpen = XMLHttpRequest.prototype.open;
	XMLHttpRequest.prototype.open = function ( method, url ) {
		if ( method === 'POST' && url && url.indexOf( 'api.php' ) !== -1 ) {
			this._filePermIsApiPost = true;
		}
		return origOpen.apply( this, arguments );
	};

	// Patch send() to inject wpFilePermLevel on publish-from-stash requests
	var origSend = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.send = function ( body ) {
		if ( this._filePermIsApiPost && body instanceof FormData ) {
			var isUpload = ( typeof body.get === 'function' ) &&
				body.get( 'action' ) === 'upload';
			var hasFilekey = ( typeof body.get === 'function' ) &&
				!!body.get( 'filekey' );

			// CRITICAL: Only inject on publish-from-stash (action=upload + filekey present).
			// This distinguishes VE's publish step from the initial stash upload.
			if ( isUpload && hasFilekey && !body.get( 'wpFilePermLevel' ) ) {
				body.append( 'wpFilePermLevel', getSelectedPermLevel() );
			}
		}

		return origSend.apply( this, arguments );
	};

	// --- PART 3: Post-upload verification ---

	// Patch saveFile to verify PageProps storage after successful upload.
	// saveFile() performs the publish-from-stash step and returns a promise.
	var origSaveFile = mw.ForeignStructuredUpload.BookletLayout.prototype.saveFile;
	mw.ForeignStructuredUpload.BookletLayout.prototype.saveFile = function () {
		var self = this;
		return origSaveFile.call( this ).then( function () {
			// Delay verification to allow DeferredUpdates to store permission
			setTimeout( function () {
				verifyPermission( self.getFilename() );
			}, 1000 );
		} );
	};
}() );
