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
	$( () => {
		let currentLevel = mw.config.get( 'wgFilePermCurrentLevel' );
		const pageTitle = mw.config.get( 'wgFilePermPageTitle' );
		const $dropdownEl = $( '#fileperm-edit-dropdown' );
		const $saveBtnEl = $( '#fileperm-edit-save' );

		// Guard: edit controls not rendered (user lacks permission)
		if ( !$saveBtnEl.length ) {
			return;
		}

		// Infuse server-rendered OOUI widgets to make them interactive.
		// DropdownInputWidget hides the native <select> and uses a
		// DropdownWidget overlay that only works after infusion.
		const dropdown = OO.ui.infuse( $dropdownEl );
		const saveBtn = OO.ui.infuse( $saveBtnEl );
		const originalLabel = saveBtn.getLabel();

		saveBtn.on( 'click', () => {
			const newLevel = dropdown.getValue();

			if ( !newLevel || newLevel === currentLevel ) {
				return;
			}

			// Show loading state
			saveBtn.setDisabled( true );
			saveBtn.setLabel( mw.msg( 'filepermissions-edit-saving' ) );

			const api = new mw.Api();
			api.postWithToken( 'csrf', {
				action: 'fileperm-set-level',
				title: pageTitle,
				level: newLevel
			} ).then( () => {
				mw.notify( mw.msg( 'filepermissions-edit-success' ), { type: 'success' } );
				const $badge = $( '#fileperm-level-badge' );
				$badge.text( newLevel ).addClass( 'fileperm-updated' );
				setTimeout( () => $badge.removeClass( 'fileperm-updated' ), 1500 );
				currentLevel = newLevel;
				saveBtn.setLabel( originalLabel );
				saveBtn.setDisabled( false );
			}, () => {
				mw.notify( mw.msg( 'filepermissions-edit-error' ), { type: 'error' } );
				saveBtn.setLabel( originalLabel );
				saveBtn.setDisabled( false );
			} );
		} );
	} );
}() );
