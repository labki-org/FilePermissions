/**
 * @module ext.FilePermissions.edit
 *
 * FilePermissions edit module.
 *
 * Infuses server-rendered OOUI widgets and handles save button clicks
 * for the permission level edit controls on File: description pages.
 * Calls the fileperm-set-level API with CSRF protection, updates the
 * badge text on success, and shows mw.notify feedback.
 *
 * Loaded conditionally for users with the edit-fileperm right via
 * BeforePageDisplay hook in DisplayHooks.php.
 */
( function () {
	'use strict';

	// Wait for DOM ready — RL packageFiles modules may execute before body is parsed
	$( function () {
		var currentLevel = mw.config.get( 'wgFilePermCurrentLevel' );
		var pageTitle = mw.config.get( 'wgFilePermPageTitle' );
		var $dropdownEl = $( '#fileperm-edit-dropdown' );
		var $saveBtnEl = $( '#fileperm-edit-save' );

		// Guard: edit controls not rendered (user lacks permission)
		if ( !$saveBtnEl.length ) {
			return;
		}

		// Infuse server-rendered OOUI widgets to make them interactive.
		// DropdownInputWidget hides the native <select> and uses a
		// DropdownWidget overlay that only works after infusion.
		var dropdown = OO.ui.infuse( $dropdownEl );
		var saveBtn = OO.ui.infuse( $saveBtnEl );

		saveBtn.on( 'click', function () {
			var newLevel = dropdown.getValue();

			if ( !newLevel || newLevel === currentLevel ) {
				return;
			}

			// Disable save button to prevent double-submit
			saveBtn.setDisabled( true );

			var api = new mw.Api();
			api.postWithToken( 'csrf', {
				action: 'fileperm-set-level',
				title: pageTitle,
				level: newLevel
			} ).then( function () {
				mw.notify( mw.msg( 'filepermissions-edit-success' ), { type: 'success' } );
				$( '#fileperm-level-badge' ).text( newLevel );
				currentLevel = newLevel;
				saveBtn.setDisabled( false );
			}, function () {
				mw.notify( mw.msg( 'filepermissions-edit-error' ), { type: 'error' } );
				saveBtn.setDisabled( false );
			} );
		} );
	} );
}() );
