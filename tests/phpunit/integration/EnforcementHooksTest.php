<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Integration;

use FilePermissions\Hooks\EnforcementHooks;
use FilePermissions\PermissionService;
use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for EnforcementHooks with real DB-backed PermissionService.
 *
 * Proves that all three enforcement hooks correctly deny unauthorized users
 * and allow authorized users when running against real MW services:
 * - INTG-01: getUserPermissionsErrors denies File: page access
 * - INTG-02: ImgAuthBeforeStream denies file downloads
 * - INTG-03: ImageBeforeProduceHTML replaces with placeholder
 *
 * EnforcementHooks is constructed with a real PermissionService from the
 * service container, proving the full wiring path works end-to-end.
 *
 * @covers \FilePermissions\Hooks\EnforcementHooks
 * @group Database
 */
class EnforcementHooksTest extends MediaWikiIntegrationTestCase {

	/** @var User Original RequestContext user, restored in tearDown */
	private User $originalUser;

	protected function setUp(): void {
		parent::setUp();

		// Save original RequestContext user for tearDown restoration
		$this->originalUser = RequestContext::getMain()->getUser();

		// Override all 5 FilePermissions config vars
		$this->overrideConfigValue( 'FilePermLevels',
			[ 'public', 'internal', 'confidential' ] );
		$this->overrideConfigValue( 'FilePermGroupGrants', [
			'sysop' => [ '*' ],
			'editor' => [ 'public', 'internal' ],
			'viewer' => [ 'public' ],
		] );
		$this->overrideConfigValue( 'FilePermDefaultLevel', null );
		$this->overrideConfigValue( 'FilePermNamespaceDefaults', [] );
		$this->overrideConfigValue( 'FilePermInvalidConfig', false );

		// Reset the service singleton to prevent cache poisoning across tests
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.PermissionService' );
	}

	protected function tearDown(): void {
		// Restore original RequestContext user to prevent cross-test pollution
		RequestContext::getMain()->setUser( $this->originalUser );
		parent::tearDown();
	}

	// =========================================================================
	// Helper methods
	// =========================================================================

	/**
	 * Get a fresh PermissionService from the service container.
	 *
	 * @return PermissionService
	 */
	private function getService(): PermissionService {
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.PermissionService' );
		return $this->getServiceContainer()
			->getService( 'FilePermissions.PermissionService' );
	}

	/**
	 * Create a File: page and set its permission level.
	 *
	 * @param string $name Page name without namespace prefix
	 * @param string $level Permission level to assign
	 * @return Title
	 */
	private function createProtectedFile( string $name, string $level ): Title {
		$result = $this->insertPage( "File:$name", 'test content', NS_FILE );
		$title = $result['title'];
		$this->getService()->setLevel( $title, $level );
		return $title;
	}

	/**
	 * Create a File: page without any permission level.
	 *
	 * @param string $name Page name without namespace prefix
	 * @return Title
	 */
	private function createUnprotectedFile( string $name ): Title {
		$result = $this->insertPage( "File:$name", 'test content', NS_FILE );
		return $result['title'];
	}

	/**
	 * Create an EnforcementHooks instance with a real PermissionService.
	 *
	 * @return EnforcementHooks
	 */
	private function createHooks(): EnforcementHooks {
		return new EnforcementHooks( $this->getService() );
	}

	// =========================================================================
	// INTG-01: getUserPermissionsErrors
	// =========================================================================

	/**
	 * Unauthorized user is denied access to a protected File: page.
	 */
	public function testDeniesUnauthorizedUserAccessToProtectedFilePage(): void {
		$title = $this->createProtectedFile( 'Secret01.png', 'confidential' );
		// 'viewer' group only has 'public' level
		$user = $this->getTestUser( [ 'viewer' ] )->getUser();
		$hooks = $this->createHooks();

		$result = '';
		$returnValue = $hooks->onGetUserPermissionsErrors( $title, $user, 'read', $result );

		$this->assertFalse( $returnValue );
		$this->assertSame( [ 'filepermissions-access-denied' ], $result );
	}

	/**
	 * Authorized user (sysop with wildcard) passes through.
	 */
	public function testAllowsAuthorizedUserAccessToProtectedFilePage(): void {
		$title = $this->createProtectedFile( 'Secret02.png', 'confidential' );
		// 'sysop' has wildcard grant ['*']
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$hooks = $this->createHooks();

		$result = '';
		$returnValue = $hooks->onGetUserPermissionsErrors( $title, $user, 'read', $result );

		$this->assertTrue( $returnValue );
		$this->assertSame( '', $result );
	}

	/**
	 * File with no permission level set allows access to any user.
	 */
	public function testAllowsAccessToFilePageWithNoPermissionLevel(): void {
		$title = $this->createUnprotectedFile( 'Open01.png' );
		$user = $this->getTestUser( [] )->getUser();
		$hooks = $this->createHooks();

		$result = '';
		$returnValue = $hooks->onGetUserPermissionsErrors( $title, $user, 'read', $result );

		$this->assertTrue( $returnValue );
		$this->assertSame( '', $result );
	}

	/**
	 * Non-File namespace titles are ignored by the hook.
	 */
	public function testIgnoresNonFileNamespace(): void {
		$result = $this->insertPage( 'RegularPage01', 'content', NS_MAIN );
		$title = $result['title'];
		$user = $this->getTestUser( [] )->getUser();
		$hooks = $this->createHooks();

		$hookResult = '';
		$returnValue = $hooks->onGetUserPermissionsErrors( $title, $user, 'read', $hookResult );

		$this->assertTrue( $returnValue );
		$this->assertSame( '', $hookResult );
	}

	/**
	 * Non-read actions are ignored by the hook.
	 */
	public function testIgnoresNonReadAction(): void {
		$title = $this->createProtectedFile( 'Secret03.png', 'confidential' );
		// Unauthorized user, but action is 'edit' not 'read'
		$user = $this->getTestUser( [] )->getUser();
		$hooks = $this->createHooks();

		$result = '';
		$returnValue = $hooks->onGetUserPermissionsErrors( $title, $user, 'edit', $result );

		$this->assertTrue( $returnValue );
		$this->assertSame( '', $result );
	}

	/**
	 * FAIL-CLOSED: When config is invalid, deny access to any File: page
	 * with a permission level, even for sysop.
	 */
	public function testDeniesAccessWhenConfigInvalid_FailClosed(): void {
		$this->overrideConfigValue( 'FilePermInvalidConfig', true );

		$title = $this->createProtectedFile( 'Secret04.png', 'public' );
		// sysop would normally have access, but config is invalid
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$hooks = $this->createHooks();

		$result = '';
		$returnValue = $hooks->onGetUserPermissionsErrors( $title, $user, 'read', $result );

		$this->assertFalse( $returnValue );
		$this->assertSame( [ 'filepermissions-access-denied' ], $result );
	}

	// =========================================================================
	// INTG-02: ImgAuthBeforeStream
	// =========================================================================

	/**
	 * Unauthorized user is denied file download.
	 */
	public function testDeniesUnauthorizedFileDownload(): void {
		$title = $this->createProtectedFile( 'Download01.png', 'confidential' );
		// Set RequestContext to unauthorized user (viewer has 'public' only)
		$user = $this->getTestUser( [ 'viewer' ] )->getUser();
		RequestContext::getMain()->setUser( $user );
		$hooks = $this->createHooks();

		$path = '/images/Download01.png';
		$name = 'Download01.png';
		$result = [];
		$returnValue = $hooks->onImgAuthBeforeStream( $title, $path, $name, $result );

		$this->assertFalse( $returnValue );
		$this->assertSame( [
			'img-auth-accessdenied',
			'filepermissions-img-denied',
		], $result );
	}

	/**
	 * Authorized user is allowed to download file.
	 */
	public function testAllowsAuthorizedFileDownload(): void {
		$title = $this->createProtectedFile( 'Download02.png', 'confidential' );
		// Set RequestContext to authorized user (sysop has wildcard)
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		RequestContext::getMain()->setUser( $user );
		$hooks = $this->createHooks();

		$path = '/images/Download02.png';
		$name = 'Download02.png';
		$result = [];
		$returnValue = $hooks->onImgAuthBeforeStream( $title, $path, $name, $result );

		$this->assertTrue( $returnValue );
		$this->assertSame( [], $result );
	}

	/**
	 * Unprotected file download allowed for any user.
	 */
	public function testAllowsDownloadOfUnprotectedFile(): void {
		$title = $this->createUnprotectedFile( 'Open02.png' );
		// Set RequestContext to a user with no groups
		$user = $this->getTestUser( [] )->getUser();
		RequestContext::getMain()->setUser( $user );
		$hooks = $this->createHooks();

		$path = '/images/Open02.png';
		$name = 'Open02.png';
		$result = [];
		$returnValue = $hooks->onImgAuthBeforeStream( $title, $path, $name, $result );

		$this->assertTrue( $returnValue );
		$this->assertSame( [], $result );
	}

	// =========================================================================
	// INTG-03: ImageBeforeProduceHTML
	// =========================================================================

	/**
	 * Unauthorized user sees placeholder instead of protected image.
	 */
	public function testReplacesProtectedImageWithPlaceholderForUnauthorizedUser(): void {
		$title = $this->createProtectedFile( 'Embed01.png', 'confidential' );
		// Set RequestContext to unauthorized user
		$user = $this->getTestUser( [ 'viewer' ] )->getUser();
		RequestContext::getMain()->setUser( $user );
		$hooks = $this->createHooks();

		$unused = null;
		$file = false;
		$frameParams = [];
		$handlerParams = [];
		$time = false;
		$res = null;
		$parser = $this->createMock( \Parser::class );
		$parserOutput = $this->createMock( \ParserOutput::class );
		$parserOutput->expects( $this->atLeastOnce() )
			->method( 'updateCacheExpiry' )
			->with( 0 );
		$parser->method( 'getOutput' )->willReturn( $parserOutput );
		$query = '';
		$widthOption = null;

		$returnValue = $hooks->onImageBeforeProduceHTML(
			$unused, $title, $file, $frameParams, $handlerParams,
			$time, $res, $parser, $query, $widthOption
		);

		$this->assertFalse( $returnValue );
		$this->assertIsString( $res );
		$this->assertStringContainsString( 'fileperm-placeholder', $res );
	}

	/**
	 * Authorized user sees the image normally (hook returns true).
	 */
	public function testAllowsEmbeddingForAuthorizedUser(): void {
		$title = $this->createProtectedFile( 'Embed02.png', 'confidential' );
		// Set RequestContext to authorized user (sysop has wildcard)
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		RequestContext::getMain()->setUser( $user );
		$hooks = $this->createHooks();

		$unused = null;
		$file = false;
		$frameParams = [];
		$handlerParams = [];
		$time = false;
		$res = null;
		$parser = $this->createMock( \Parser::class );
		$parserOutput = $this->createMock( \ParserOutput::class );
		$parserOutput->expects( $this->atLeastOnce() )
			->method( 'updateCacheExpiry' )
			->with( 0 );
		$parser->method( 'getOutput' )->willReturn( $parserOutput );
		$query = '';
		$widthOption = null;

		$returnValue = $hooks->onImageBeforeProduceHTML(
			$unused, $title, $file, $frameParams, $handlerParams,
			$time, $res, $parser, $query, $widthOption
		);

		$this->assertTrue( $returnValue );
		$this->assertNull( $res );
	}

	/**
	 * Placeholder HTML contains SVG lock icon data URI.
	 */
	public function testPlaceholderContainsSvgLockIcon(): void {
		$title = $this->createProtectedFile( 'Embed03.png', 'confidential' );
		$user = $this->getTestUser( [ 'viewer' ] )->getUser();
		RequestContext::getMain()->setUser( $user );
		$hooks = $this->createHooks();

		$unused = null;
		$file = false;
		$frameParams = [];
		$handlerParams = [];
		$time = false;
		$res = null;
		$parser = $this->createMock( \Parser::class );
		$parser->method( 'getOutput' )
			->willReturn( $this->createMock( \ParserOutput::class ) );
		$query = '';
		$widthOption = null;

		$hooks->onImageBeforeProduceHTML(
			$unused, $title, $file, $frameParams, $handlerParams,
			$time, $res, $parser, $query, $widthOption
		);

		$this->assertIsString( $res );
		$this->assertStringContainsString( 'data:image/svg+xml', $res );
	}

	/**
	 * Placeholder uses provided width and height dimensions.
	 */
	public function testPlaceholderUsesProvidedDimensions(): void {
		$title = $this->createProtectedFile( 'Embed04.png', 'confidential' );
		$user = $this->getTestUser( [ 'viewer' ] )->getUser();
		RequestContext::getMain()->setUser( $user );
		$hooks = $this->createHooks();

		$unused = null;
		$file = false;
		$frameParams = [];
		$handlerParams = [ 'width' => 300, 'height' => 200 ];
		$time = false;
		$res = null;
		$parser = $this->createMock( \Parser::class );
		$parser->method( 'getOutput' )
			->willReturn( $this->createMock( \ParserOutput::class ) );
		$query = '';
		$widthOption = null;

		$hooks->onImageBeforeProduceHTML(
			$unused, $title, $file, $frameParams, $handlerParams,
			$time, $res, $parser, $query, $widthOption
		);

		$this->assertIsString( $res );
		$this->assertStringContainsString( 'width:300px', $res );
		$this->assertStringContainsString( 'height:200px', $res );
	}

	/**
	 * Parser cache is disabled for pages with protected embedded images.
	 *
	 * When a file has a permission level, updateCacheExpiry(0) is called
	 * on the parser output to prevent caching of the placeholder.
	 */
	public function testDisablesParserCacheForProtectedImages(): void {
		$title = $this->createProtectedFile( 'Embed05.png', 'confidential' );
		// Use sysop to test cache disable even for authorized users
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		RequestContext::getMain()->setUser( $user );
		$hooks = $this->createHooks();

		$unused = null;
		$file = false;
		$frameParams = [];
		$handlerParams = [];
		$time = false;
		$res = null;
		$parserOutput = $this->createMock( \ParserOutput::class );
		$parserOutput->expects( $this->once() )
			->method( 'updateCacheExpiry' )
			->with( 0 );
		$parser = $this->createMock( \Parser::class );
		$parser->method( 'getOutput' )->willReturn( $parserOutput );
		$query = '';
		$widthOption = null;

		$hooks->onImageBeforeProduceHTML(
			$unused, $title, $file, $frameParams, $handlerParams,
			$time, $res, $parser, $query, $widthOption
		);

		// The assertion is in the mock expectation above (updateCacheExpiry called with 0)
		$this->assertTrue( true );
	}

	/**
	 * SEC-02: Parser cache is only disabled once even with multiple protected images.
	 *
	 * When a page embeds multiple protected images, updateCacheExpiry(0) should
	 * be called exactly once to avoid redundant work.
	 */
	public function testDisablesParserCacheOnlyOnceForMultipleProtectedImages(): void {
		$title1 = $this->createProtectedFile( 'MultiCache01.png', 'confidential' );
		$title2 = $this->createProtectedFile( 'MultiCache02.png', 'internal' );
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		RequestContext::getMain()->setUser( $user );
		$hooks = $this->createHooks();

		$unused = null;
		$file = false;
		$frameParams = [];
		$handlerParams = [];
		$time = false;
		$res = null;
		$parserOutput = $this->createMock( \ParserOutput::class );
		$parserOutput->expects( $this->once() )
			->method( 'updateCacheExpiry' )
			->with( 0 );
		$parser = $this->createMock( \Parser::class );
		$parser->method( 'getOutput' )->willReturn( $parserOutput );
		$query = '';
		$widthOption = null;

		// First call with title1 - should call updateCacheExpiry
		$hooks->onImageBeforeProduceHTML(
			$unused, $title1, $file, $frameParams, $handlerParams,
			$time, $res, $parser, $query, $widthOption
		);

		// Second call with title2 - should NOT call updateCacheExpiry again
		$res2 = null;
		$hooks->onImageBeforeProduceHTML(
			$unused, $title2, $file, $frameParams, $handlerParams,
			$time, $res2, $parser, $query, $widthOption
		);
	}
}
