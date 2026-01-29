/**
 * FilePermissions edit module.
 *
 * Handles save button clicks for the permission level edit controls on
 * File: description pages. Calls the fileperm-set-level API with CSRF
 * protection, updates the badge text on success, and shows mw.notify
 * feedback for success and error states.
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
		var $saveBtn = $( '#fileperm-edit-save' );

		// Guard: edit controls not rendered (user lacks permission)
		if ( !$saveBtn.length ) {
			return;
		}

		$saveBtn.on( 'click', function () {
			// OOUI DropdownInputWidget may render as <select> or <input>
			var newLevel = $( '#fileperm-edit-dropdown select, #fileperm-edit-dropdown input' ).val();

			if ( !newLevel || newLevel === currentLevel ) {
				return;
			}

			// Disable save button to prevent double-submit
			$saveBtn.prop( 'disabled', true );

			var api = new mw.Api();
			api.postWithToken( 'csrf', {
				action: 'fileperm-set-level',
				title: pageTitle,
				level: newLevel
			} ).then( function () {
				mw.notify( mw.msg( 'filepermissions-edit-success' ), { type: 'success' } );
				$( '#fileperm-level-badge' ).text( newLevel );
				currentLevel = newLevel;
				$saveBtn.prop( 'disabled', false );
			}, function () {
				mw.notify( mw.msg( 'filepermissions-edit-error' ), { type: 'error' } );
				$saveBtn.prop( 'disabled', false );
			} );
		} );
	} );
}() );
