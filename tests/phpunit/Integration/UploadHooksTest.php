<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Integration;

use FilePermissions\Hooks\UploadHooks;
use FilePermissions\PermissionService;
use LocalFile;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use UploadBase;

/**
 * Integration tests for UploadHooks within the MediaWiki runtime.
 *
 * Covers INTG-04 (UploadVerifyUpload rejects invalid permission levels,
 * accepts valid levels, and handles missing level with/without default)
 * and INTG-05 (UploadComplete stores permission level in fileperm_levels
 * via DeferredUpdates, handles null file/title gracefully, applies default).
 *
 * All tests use the real database, real service container, and FauxRequest
 * for parameter simulation. Each test fetches a fresh PermissionService
 * to prevent cache poisoning.
 *
 * @covers \FilePermissions\Hooks\UploadHooks
 * @group Database
 */
class UploadHooksTest extends MediaWikiIntegrationTestCase {

	use FilePermissionsIntegrationTrait;

	private ?FauxRequest $savedRequest = null;
	private ?User $savedUser = null;

	protected function setUp(): void {
		parent::setUp();
		$this->savedRequest = RequestContext::getMain()->getRequest();
		$this->savedUser = RequestContext::getMain()->getUser();
		$this->setUpFilePermissionsConfig();
	}

	protected function tearDown(): void {
		if ( $this->savedRequest !== null ) {
			RequestContext::getMain()->setRequest( $this->savedRequest );
		}
		if ( $this->savedUser !== null ) {
			RequestContext::getMain()->setUser( $this->savedUser );
		}
		parent::tearDown();
	}

	// =========================================================================
	// Helper methods
	// =========================================================================

	/**
	 * Set request parameters on RequestContext via FauxRequest.
	 *
	 * @param array $params Key-value pairs for the request
	 */
	private function setRequestParams( array $params ): void {
		$request = new FauxRequest( $params );
		RequestContext::getMain()->setRequest( $request );
	}

	/**
	 * Create a mock UploadBase with getLocalFile() chain returning a title.
	 *
	 * @param Title|null $title The title getLocalFile()->getTitle() returns
	 * @return UploadBase
	 */
	private function createMockUploadBase( ?Title $title ): UploadBase {
		$upload = $this->createMock( UploadBase::class );

		if ( $title === null ) {
			// getLocalFile returns null
			$upload->method( 'getLocalFile' )->willReturn( null );
		} else {
			$localFile = $this->createMock( LocalFile::class );
			$localFile->method( 'getTitle' )->willReturn( $title );
			$upload->method( 'getLocalFile' )->willReturn( $localFile );
		}

		return $upload;
	}

	/**
	 * Create a mock UploadBase where getLocalFile() returns a LocalFile
	 * with getTitle() returning null.
	 *
	 * @return UploadBase
	 */
	private function createMockUploadBaseWithNullTitle(): UploadBase {
		$upload = $this->createMock( UploadBase::class );
		$localFile = $this->createMock( LocalFile::class );
		$localFile->method( 'getTitle' )->willReturn( null );
		$upload->method( 'getLocalFile' )->willReturn( $localFile );

		return $upload;
	}

	/**
	 * Create UploadHooks with a fresh PermissionService.
	 *
	 * @return UploadHooks
	 */
	private function createHooks(): UploadHooks {
		return new UploadHooks(
			$this->getService(),
			$this->getServiceContainer()->getService( 'FilePermissions.Config' )
		);
	}

	// =========================================================================
	// INTG-04: UploadVerifyUpload rejection tests
	// =========================================================================

	/**
	 * Upload with an invalid (unconfigured) permission level is rejected.
	 */
	public function testRejectsUploadWithInvalidPermissionLevel(): void {
		$this->setRequestParams( [ 'wpFilePermLevel' => 'nonexistent-level' ] );

		$hooks = $this->createHooks();
		$upload = $this->createMock( UploadBase::class );
		$user = $this->getTestUser()->getUser();
		$error = null;

		$result = $hooks->onUploadVerifyUpload(
			$upload, $user, null, '', '', $error
		);

		$this->assertFalse( $result );
		$this->assertSame( [ 'filepermissions-upload-invalid' ], $error );
	}

	/**
	 * Upload with no permission level selection when no default is configured
	 * is rejected (when submission came from Special:Upload form).
	 */
	public function testRejectsUploadWithMissingLevelWhenNoDefault(): void {
		// Simulate Special:Upload form submission with no level selected
		$this->setRequestParams( [ 'wpUploadFile' => 'test.png' ] );

		$this->overrideConfigValue( 'FilePermDefaultLevel', null );

		$hooks = $this->createHooks();
		$upload = $this->createMock( UploadBase::class );
		$user = $this->getTestUser()->getUser();
		$error = null;

		$result = $hooks->onUploadVerifyUpload(
			$upload, $user, null, '', '', $error
		);

		$this->assertFalse( $result );
		$this->assertSame( [ 'filepermissions-upload-required' ], $error );
	}

	/**
	 * Upload with a valid permission level is accepted.
	 */
	public function testAcceptsUploadWithValidPermissionLevel(): void {
		$this->setRequestParams( [ 'wpFilePermLevel' => 'internal' ] );

		$hooks = $this->createHooks();
		$upload = $this->createMock( UploadBase::class );
		$user = $this->getTestUser()->getUser();
		$error = 'sentinel';

		$result = $hooks->onUploadVerifyUpload(
			$upload, $user, null, '', '', $error
		);

		$this->assertTrue( $result );
		$this->assertSame( 'sentinel', $error,
			'Error should remain unchanged when upload is accepted' );
	}

	/**
	 * Upload with no level selection but a default configured is accepted.
	 */
	public function testAcceptsUploadWithMissingLevelWhenDefaultConfigured(): void {
		// No wpFilePermLevel in request
		$this->setRequestParams( [] );
		$this->overrideConfigValue( 'FilePermDefaultLevel', 'public' );

		$hooks = $this->createHooks();
		$upload = $this->createMock( UploadBase::class );
		$user = $this->getTestUser()->getUser();
		$error = null;

		$result = $hooks->onUploadVerifyUpload(
			$upload, $user, null, '', '', $error
		);

		$this->assertTrue( $result );
		$this->assertNull( $error );
	}

	/**
	 * Upload with empty string level selection but a default configured is accepted.
	 */
	public function testAcceptsUploadWithEmptyLevelWhenDefaultConfigured(): void {
		$this->setRequestParams( [ 'wpFilePermLevel' => '' ] );
		$this->overrideConfigValue( 'FilePermDefaultLevel', 'public' );

		$hooks = $this->createHooks();
		$upload = $this->createMock( UploadBase::class );
		$user = $this->getTestUser()->getUser();
		$error = null;

		$result = $hooks->onUploadVerifyUpload(
			$upload, $user, null, '', '', $error
		);

		$this->assertTrue( $result );
		$this->assertNull( $error );
	}

	// =========================================================================
	// INTG-05: UploadComplete storage tests
	// =========================================================================

	/**
	 * UploadComplete stores the selected permission level in fileperm_levels.
	 */
	public function testStoresPermissionLevelOnUploadComplete(): void {
		$this->setRequestParams( [ 'wpFilePermLevel' => 'confidential' ] );

		$title = $this->createFilePage( 'StoreLevel.png' );
		$upload = $this->createMockUploadBase( $title );
		$hooks = $this->createHooks();

		$hooks->onUploadComplete( $upload );
		DeferredUpdates::doUpdates();

		$service = $this->getService();
		$freshTitle = Title::makeTitle( NS_FILE, $title->getDBkey() );
		$this->assertSame( 'confidential', $service->getLevel( $freshTitle ) );
	}

	/**
	 * UploadComplete stores the default level when no explicit selection.
	 */
	public function testStoresDefaultLevelWhenNoExplicitSelection(): void {
		$this->setRequestParams( [] );
		$this->overrideConfigValue( 'FilePermDefaultLevel', 'public' );

		$title = $this->createFilePage( 'StoreDefault.png' );
		$upload = $this->createMockUploadBase( $title );
		$hooks = $this->createHooks();

		$hooks->onUploadComplete( $upload );
		DeferredUpdates::doUpdates();

		$service = $this->getService();
		$freshTitle = Title::makeTitle( NS_FILE, $title->getDBkey() );
		$this->assertSame( 'public', $service->getLevel( $freshTitle ) );
	}

	/**
	 * UploadComplete does not error when upload has no local file.
	 */
	public function testDoesNotStoreLevelWhenUploadHasNoLocalFile(): void {
		$this->setRequestParams( [ 'wpFilePermLevel' => 'internal' ] );

		$upload = $this->createMockUploadBase( null );
		$hooks = $this->createHooks();

		// Should not throw
		$result = $hooks->onUploadComplete( $upload );
		$this->assertTrue( $result );
	}

	/**
	 * UploadComplete does not error when title is null.
	 */
	public function testDoesNotStoreLevelWhenTitleIsNull(): void {
		$this->setRequestParams( [ 'wpFilePermLevel' => 'internal' ] );

		$upload = $this->createMockUploadBaseWithNullTitle();
		$hooks = $this->createHooks();

		// Should not throw
		$result = $hooks->onUploadComplete( $upload );
		$this->assertTrue( $result );
	}

	/**
	 * UploadComplete does not store an invalid level (silently skipped).
	 * The verification hook catches invalid levels before upload completes,
	 * but onUploadComplete also guards against storing invalid levels.
	 */
	public function testDoesNotStoreInvalidLevel(): void {
		$this->setRequestParams( [ 'wpFilePermLevel' => 'nonexistent' ] );

		$title = $this->createFilePage( 'InvalidLevelComplete.png' );
		$upload = $this->createMockUploadBase( $title );
		$hooks = $this->createHooks();

		$hooks->onUploadComplete( $upload );
		DeferredUpdates::doUpdates();

		$service = $this->getService();
		$freshTitle = Title::makeTitle( NS_FILE, $title->getDBkey() );
		$this->assertNull( $service->getLevel( $freshTitle ),
			'Invalid level should not be stored in fileperm_levels' );
	}

	// =========================================================================
	// Security audit tests
	// =========================================================================

	/**
	 * SEC-07: Invalid level is rejected before default resolution logic.
	 *
	 * Even when a default level is configured, an explicitly invalid level
	 * in the request must be rejected immediately — it should NOT fall
	 * through to the default resolution path.
	 */
	public function testEarlyValidationRejectsInvalidLevelBeforeDefaultResolution(): void {
		// Set a default so the default-resolution path would succeed
		$this->overrideConfigValue( 'FilePermDefaultLevel', 'public' );
		// But the request contains an explicitly invalid level
		$this->setRequestParams( [ 'wpFilePermLevel' => 'hacked-level' ] );

		$hooks = $this->createHooks();
		$upload = $this->createMock( UploadBase::class );
		$user = $this->getTestUser()->getUser();
		$error = null;

		$result = $hooks->onUploadVerifyUpload(
			$upload, $user, null, '', '', $error
		);

		$this->assertFalse( $result,
			'Invalid level must be rejected even when a default is configured' );
		$this->assertSame( [ 'filepermissions-upload-invalid' ], $error );
	}

	/**
	 * SEC-06: DeferredUpdate error does not propagate as an exception.
	 *
	 * If PermissionService::setLevel() throws inside the DeferredUpdate,
	 * the exception is caught and logged rather than crashing the request.
	 */
	public function testDeferredUpdateCatchesExceptionsFromSetLevel(): void {
		$this->setRequestParams( [ 'wpFilePermLevel' => 'internal' ] );

		$title = $this->createFilePage( 'DeferredErrorTest.png' );

		// Create a mock PermissionService that throws on setLevel
		$mockService = $this->createMock( PermissionService::class );
		$mockService->method( 'setLevel' )
			->willThrowException( new \RuntimeException( 'DB connection lost' ) );

		$upload = $this->createMock( UploadBase::class );
		$localFile = $this->createMock( \LocalFile::class );
		$localFile->method( 'getTitle' )->willReturn( $title );
		$upload->method( 'getLocalFile' )->willReturn( $localFile );

		$hooks = new UploadHooks(
			$mockService,
			$this->getServiceContainer()->getService( 'FilePermissions.Config' )
		);

		// onUploadComplete itself should succeed (deferred update is registered)
		$result = $hooks->onUploadComplete( $upload );
		$this->assertTrue( $result );

		// Running deferred updates should NOT throw despite setLevel exception
		DeferredUpdates::doUpdates();

		// If we got here without an exception, the try-catch worked
		$this->assertTrue( true );
	}

	/**
	 * SEC-07: getText() returns empty string for missing params, not null.
	 *
	 * Verifies that onUploadComplete handles getText() behavior correctly:
	 * missing wpFilePermLevel returns '' and falls through to default
	 * resolution, not a null-based code path.
	 */
	public function testUploadCompleteHandlesMissingParamViaGetText(): void {
		// Empty request - getText('wpFilePermLevel') returns ''
		$this->setRequestParams( [] );
		$this->overrideConfigValue( 'FilePermDefaultLevel', 'internal' );

		$title = $this->createFilePage( 'GetTextTest.png' );
		$upload = $this->createMockUploadBase( $title );
		$hooks = $this->createHooks();

		$hooks->onUploadComplete( $upload );
		DeferredUpdates::doUpdates();

		// Should have stored the default level 'internal'
		$service = $this->getService();
		$freshTitle = Title::makeTitle( NS_FILE, $title->getDBkey() );
		$this->assertSame( 'internal', $service->getLevel( $freshTitle ),
			'Default level should be applied when getText returns empty string' );
	}
}
