( function () {
	'use strict';

	QUnit.module( 'ext.FilePermissions.shared', QUnit.newMwEnvironment() );

	QUnit.test( 'mw.FilePermissions namespace exists', function ( assert ) {
		assert.true(
			mw.FilePermissions !== undefined,
			'mw.FilePermissions is defined'
		);
		assert.strictEqual(
			typeof mw.FilePermissions,
			'object',
			'mw.FilePermissions is an object'
		);
	} );

	QUnit.test( 'verifyPermission is a function', function ( assert ) {
		assert.strictEqual(
			typeof mw.FilePermissions.verifyPermission,
			'function',
			'verifyPermission is a function'
		);
	} );

	QUnit.test( 'onUploadSend is a function', function ( assert ) {
		assert.strictEqual(
			typeof mw.FilePermissions.onUploadSend,
			'function',
			'onUploadSend is a function'
		);
	} );

	QUnit.test( 'onUploadSend registers callbacks', function ( assert ) {
		var called = false;
		mw.FilePermissions.onUploadSend( function () {
			called = true;
		} );
		// Callback is registered but not yet invoked
		assert.false( called, 'Callback is not invoked on registration' );
	} );

	QUnit.test( 'XHR prototypes are patched', function ( assert ) {
		// After loading the shared module, open and send should be patched
		// (i.e. not native code)
		var openStr = XMLHttpRequest.prototype.open.toString();
		var sendStr = XMLHttpRequest.prototype.send.toString();

		assert.false(
			openStr.indexOf( '[native code]' ) !== -1,
			'XMLHttpRequest.prototype.open is patched (not native)'
		);
		assert.false(
			sendStr.indexOf( '[native code]' ) !== -1,
			'XMLHttpRequest.prototype.send is patched (not native)'
		);
	} );
}() );
