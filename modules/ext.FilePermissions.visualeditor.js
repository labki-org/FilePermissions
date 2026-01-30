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
 * in DisplayHooks.php). Does NOT declare ext.visualEditor as a dependency.
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

	// --- PART 1: Monkey-patch BookletLayout ---

	// Patch renderInfoForm to inject OOUI DropdownInputWidget into the
	// upload dialog's info form panel.
	var origRenderInfoForm = mw.ForeignStructuredUpload.BookletLayout.prototype.renderInfoForm;
	/**
	 * Override of BookletLayout#renderInfoForm.
	 *
	 * Calls the original method, then appends an OOUI DropdownInputWidget
	 * for permission level selection to the info form fieldset. Skips
	 * injection for foreign upload targets (e.g. Commons).
	 *
	 * @return {OO.ui.PageLayout} The info form page with the dropdown appended
	 */
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
	/**
	 * Override of BookletLayout#clear.
	 *
	 * Calls the original method, then resets the permission dropdown to the
	 * default value. VE reuses the BookletLayout instance across multiple
	 * upload operations, so the dropdown must be reset each time.
	 */
	mw.ForeignStructuredUpload.BookletLayout.prototype.clear = function () {
		origClear.call( this );
		if ( this.filePermDropdown ) {
			this.filePermDropdown.setValue( defaultLevel || levels[ 0 ] );
		}
	};

	// --- PART 2: Upload XHR interception for publish-from-stash ---
	//
	// VE uploads in two phases:
	//   Phase 1 (stash): uploadToStash -> uploadWithFormData (fieldsAllowed STRIPS unknown params)
	//   Phase 2 (publish): finishStashUpload -> uploadFromStash -> postWithEditToken (NO filtering)
	//
	// We ONLY inject wpFilePermLevel during Phase 2, identified by:
	//   action=upload AND filekey present in the request body.
	//
	// Body format varies by caller:
	//   - MsUpload (plupload): sends FormData directly via native XHR
	//   - VE (mw.Api): serializes as URL-encoded string via jQuery.ajax
	//
	// Both formats are handled via the shared callback registry.

	mw.FilePermissions.onUploadSend( function ( xhr, body ) {
		if ( body instanceof FormData ) {
			var hasFilekey = typeof body.get === 'function' && !!body.get( 'filekey' );
			if ( hasFilekey && !body.get( 'wpFilePermLevel' ) ) {
				body.append( 'wpFilePermLevel', getSelectedPermLevel() );
			}
		} else if ( typeof body === 'string' ) {
			var params = new URLSearchParams( body );
			if ( params.has( 'filekey' ) && !params.has( 'wpFilePermLevel' ) ) {
				params.set( 'wpFilePermLevel', getSelectedPermLevel() );
				return params.toString();
			}
		}
	} );

	// --- PART 3: Post-upload verification ---

	// Patch saveFile to verify PageProps storage after successful upload.
	// saveFile() performs the publish-from-stash step and returns a promise.
	var origSaveFile = mw.ForeignStructuredUpload.BookletLayout.prototype.saveFile;
	mw.ForeignStructuredUpload.BookletLayout.prototype.saveFile = function () {
		var self = this;
		return origSaveFile.call( this ).then( function () {
			// Delay verification to allow DeferredUpdates to store permission
			setTimeout( function () {
				mw.FilePermissions.verifyPermission( self.getFilename(), 'filepermissions-ve-error-save' );
			}, 1000 );
		} );
	};
}() );
