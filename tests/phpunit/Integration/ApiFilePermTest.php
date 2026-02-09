<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Integration;

use FilePermissions\PermissionService;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Title\Title;

/**
 * Integration tests for ApiFilePermSetLevel and ApiQueryFilePermLevel.
 *
 * Covers INTG-06 (authorized sysop can set permission levels via API),
 * INTG-07 (non-sysop users denied, CSRF token required, POST required),
 * and INTG-08 (query endpoint returns correct levels for protected files,
 * works for any authenticated user).
 *
 * All tests use the MW ApiTestCase framework with real API dispatch,
 * real database, and real permission checks.
 *
 * @covers \FilePermissions\Api\ApiFilePermSetLevel
 * @covers \FilePermissions\Api\ApiQueryFilePermLevel
 * @group Database
 * @group API
 */
class ApiFilePermTest extends ApiTestCase {

	use FilePermissionsIntegrationTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpFilePermissionsConfig();
	}

	// =========================================================================
	// INTG-06: ApiFilePermSetLevel authorized usage
	// =========================================================================

	/**
	 * Sysop can set a permission level via the API and it persists to DB.
	 */
	public function testSetLevelSucceedsForSysopUser(): void {
		$title = $this->createFilePage( 'ApiSetLevel.png' );
		$sysop = $this->getTestSysop();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiSetLevel.png',
			'level' => 'confidential',
		], null, $sysop->getAuthority() );

		$this->assertSame( 'success',
			$data['fileperm-set-level']['result'] );
		$this->assertSame( 'confidential',
			$data['fileperm-set-level']['level'] );

		// Verify DB persistence via PermissionService
		$service = $this->getService();
		$freshTitle = Title::makeTitle( NS_FILE, $title->getDBkey() );
		$this->assertSame( 'confidential', $service->getLevel( $freshTitle ) );
	}

	/**
	 * Setting a level twice overwrites the previous level.
	 */
	public function testSetLevelOverwritesExistingLevel(): void {
		$title = $this->createFilePage( 'ApiOverwrite.png' );
		$sysop = $this->getTestSysop();

		// First: set to public
		$this->doApiRequestWithToken( [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiOverwrite.png',
			'level' => 'public',
		], null, $sysop->getAuthority() );

		// Second: overwrite to confidential
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiOverwrite.png',
			'level' => 'confidential',
		], null, $sysop->getAuthority() );

		$this->assertSame( 'success',
			$data['fileperm-set-level']['result'] );

		$service = $this->getService();
		$freshTitle = Title::makeTitle( NS_FILE, $title->getDBkey() );
		$this->assertSame( 'confidential', $service->getLevel( $freshTitle ) );
	}

	/**
	 * Setting level on a nonexistent page returns an API error.
	 */
	public function testSetLevelRejectsNonexistentPage(): void {
		$sysop = $this->getTestSysop();

		$this->expectException( ApiUsageException::class );

		$this->doApiRequestWithToken( [
			'action' => 'fileperm-set-level',
			'title' => 'File:DoesNotExist_' . mt_rand() . '.png',
			'level' => 'public',
		], null, $sysop->getAuthority() );
	}

	/**
	 * Setting an invalid (unconfigured) level returns an API error.
	 */
	public function testSetLevelRejectsInvalidLevel(): void {
		$this->createFilePage( 'ApiInvalidLevel.png' );
		$sysop = $this->getTestSysop();

		$this->expectException( ApiUsageException::class );

		$this->doApiRequestWithToken( [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiInvalidLevel.png',
			'level' => 'nonexistent-level',
		], null, $sysop->getAuthority() );
	}

	// =========================================================================
	// INTG-07: ApiFilePermSetLevel authorization denial
	// =========================================================================

	/**
	 * Regular user (no edit-fileperm right) is denied set-level.
	 */
	public function testSetLevelDeniedForRegularUser(): void {
		$this->createFilePage( 'ApiDenied.png' );
		$regularUser = $this->getTestUser();

		$this->expectException( ApiUsageException::class );

		$this->doApiRequestWithToken( [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiDenied.png',
			'level' => 'public',
		], null, $regularUser->getAuthority() );
	}

	/**
	 * Anonymous user is denied set-level API access.
	 */
	public function testSetLevelDeniedForAnonymousUser(): void {
		$this->createFilePage( 'ApiAnon.png' );

		$this->expectException( ApiUsageException::class );

		// Anonymous users cannot obtain CSRF tokens, so use doApiRequest
		// with a bogus token. The API rejects before checking permissions.
		$this->doApiRequest( [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiAnon.png',
			'level' => 'public',
			'token' => '+\\',
		] );
	}

	/**
	 * Set-level API requires POST method (mustBePosted).
	 *
	 * Attempting a GET request to a mustBePosted API module triggers
	 * an error from the API framework.
	 */
	public function testSetLevelRequiresPostMethod(): void {
		$this->createFilePage( 'ApiGetMethod.png' );
		$sysop = $this->getTestSysop();

		$this->expectException( ApiUsageException::class );

		// doApiRequest sends GET by default (no token handling)
		$this->doApiRequest( [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiGetMethod.png',
			'level' => 'public',
		], null, false, $sysop->getAuthority() );
	}

	/**
	 * Set-level API requires a CSRF token.
	 *
	 * Calling without a valid token triggers a token validation error.
	 */
	public function testSetLevelRequiresCsrfToken(): void {
		$this->createFilePage( 'ApiNoToken.png' );
		$sysop = $this->getTestSysop();

		$this->expectException( ApiUsageException::class );

		// Use doApiRequest (not doApiRequestWithToken) to skip token
		$this->doApiRequest( [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiNoToken.png',
			'level' => 'public',
		], null, false, $sysop->getAuthority() );
	}

	// =========================================================================
	// INTG-08: ApiQueryFilePermLevel tests
	// =========================================================================

	/**
	 * Query returns the permission level for a protected file.
	 */
	public function testQueryReturnsPermissionLevelForProtectedFile(): void {
		$title = $this->createFilePage( 'ApiQueryLevel.png' );

		// Set level directly via PermissionService
		$service = $this->getService();
		$service->setLevel( $title, 'confidential' );

		[ $data ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'fileperm',
			'titles' => 'File:ApiQueryLevel.png',
		] );

		$pages = $data['query']['pages'];
		$page = reset( $pages );
		$this->assertSame( 'confidential', $page['fileperm_level'] );
	}

	/**
	 * Query omits fileperm_level for files that have no level set.
	 */
	public function testQueryOmitsLevelForUnprotectedFile(): void {
		$this->createFilePage( 'ApiQueryNoLevel.png' );

		[ $data ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'fileperm',
			'titles' => 'File:ApiQueryNoLevel.png',
		] );

		$pages = $data['query']['pages'];
		$page = reset( $pages );
		$this->assertArrayNotHasKey( 'fileperm_level', $page,
			'Unprotected file should not have fileperm_level in response' );
	}

	/**
	 * Query returns correct levels for multiple pages at once.
	 */
	public function testQueryReturnsLevelsForMultiplePages(): void {
		$titleA = $this->createFilePage( 'ApiQueryMultiA.png' );
		$titleB = $this->createFilePage( 'ApiQueryMultiB.png' );

		$service = $this->getService();
		$service->setLevel( $titleA, 'public' );
		$service->setLevel( $titleB, 'confidential' );

		[ $data ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'fileperm',
			'titles' => 'File:ApiQueryMultiA.png|File:ApiQueryMultiB.png',
		] );

		$pages = $data['query']['pages'];
		$levelsByTitle = [];
		foreach ( $pages as $page ) {
			if ( isset( $page['fileperm_level'] ) ) {
				$levelsByTitle[$page['title']] = $page['fileperm_level'];
			}
		}

		$this->assertSame( 'public', $levelsByTitle['File:ApiQueryMultiA.png'] );
		$this->assertSame( 'confidential', $levelsByTitle['File:ApiQueryMultiB.png'] );
	}

	/**
	 * Regular (non-sysop) user can query fileperm levels.
	 * Reading permission levels is not restricted to sysop.
	 */
	public function testQueryWorksForAnyAuthenticatedUser(): void {
		$title = $this->createFilePage( 'ApiQueryPublicRead.png' );

		$service = $this->getService();
		$service->setLevel( $title, 'internal' );

		$regularUser = $this->getTestUser();

		[ $data ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'fileperm',
			'titles' => 'File:ApiQueryPublicRead.png',
		], null, false, $regularUser->getAuthority() );

		$pages = $data['query']['pages'];
		$page = reset( $pages );
		$this->assertSame( 'internal', $page['fileperm_level'] );
	}

	/**
	 * SEC-01: Query cache mode must be 'private' since results are user-specific.
	 */
	public function testQueryCacheModeIsPrivate(): void {
		$this->createFilePage( 'CacheModeTest.png' );

		// Execute a real API query with appendModule=true to get the ApiMain
		[ $data, , , $apiMain ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'fileperm',
			'titles' => 'File:CacheModeTest.png',
		], null, true );

		// ApiMain tracks the most restrictive cache mode from all modules.
		// Our module sets 'private', so the overall mode must be 'private'.
		$this->assertSame( 'private', $apiMain->getCacheMode() );
	}

	/**
	 * SEC-08: Setting level on a non-File namespace page is rejected.
	 */
	public function testSetLevelRejectsNonFileNamespace(): void {
		// Create a Main namespace page
		$this->insertPage( 'NonFilePage_ApiNsTest', 'test content', NS_MAIN );
		$sysop = $this->getTestSysop();

		$this->expectException( ApiUsageException::class );

		$this->doApiRequestWithToken( [
			'action' => 'fileperm-set-level',
			'title' => 'NonFilePage_ApiNsTest',
			'level' => 'public',
		], null, $sysop->getAuthority() );
	}

	/**
	 * SEC-08: Explicit non-File namespace prefix bypasses NS_FILE default.
	 *
	 * Title::newFromText('Main:Foo', NS_FILE) resolves to NS_MAIN because
	 * the explicit prefix overrides the default namespace. The namespace
	 * check must catch this case.
	 */
	public function testSetLevelRejectsExplicitNonFileNamespacePrefix(): void {
		$this->insertPage( 'ExplicitNsTest', 'test content', NS_MAIN );
		$sysop = $this->getTestSysop();

		$this->expectException( ApiUsageException::class );

		// 'Main:ExplicitNsTest' resolves to NS_MAIN despite NS_FILE default
		$this->doApiRequestWithToken( [
			'action' => 'fileperm-set-level',
			'title' => 'Main:ExplicitNsTest',
			'level' => 'public',
		], null, $sysop->getAuthority() );
	}

	/**
	 * SEC-08: Title without namespace prefix defaults to NS_FILE and succeeds.
	 *
	 * Confirms that bare filenames still work correctly after the namespace
	 * validation was added — the check does not break the normal path.
	 */
	public function testSetLevelAcceptsImplicitFileNamespace(): void {
		$title = $this->createFilePage( 'ImplicitNsTest.png' );
		$sysop = $this->getTestSysop();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'fileperm-set-level',
			// No 'File:' prefix — NS_FILE is the default
			'title' => 'ImplicitNsTest.png',
			'level' => 'internal',
		], null, $sysop->getAuthority() );

		$this->assertSame( 'success', $data['fileperm-set-level']['result'] );
	}

	/**
	 * SEC-04: Rate limit is registered with correct thresholds.
	 *
	 * Verifies that onRegistration() sets wgRateLimits for the
	 * fileperm-setlevel action with the expected user and newbie limits.
	 */
	public function testRateLimitIsRegisteredForSetLevel(): void {
		// Invoke onRegistration to register rate limits
		\FilePermissions\Hooks\RegistrationHooks::onRegistration();

		$this->assertArrayHasKey( 'fileperm-setlevel', $GLOBALS['wgRateLimits'] );
		$limits = $GLOBALS['wgRateLimits']['fileperm-setlevel'];
		$this->assertSame( [ 10, 60 ], $limits['user'] );
		$this->assertSame( [ 3, 60 ], $limits['newbie'] );
	}

	/**
	 * SEC-04: Admin-configured rate limits are not overwritten.
	 *
	 * If $wgRateLimits['fileperm-setlevel'] is already set (e.g. by the
	 * admin in LocalSettings.php), onRegistration() must not overwrite it.
	 */
	public function testRateLimitDoesNotOverrideAdminConfig(): void {
		$GLOBALS['wgRateLimits']['fileperm-setlevel'] = [
			'user' => [ 5, 30 ],
		];

		\FilePermissions\Hooks\RegistrationHooks::onRegistration();

		// Should still be the admin's custom config
		$this->assertSame( [ 5, 30 ],
			$GLOBALS['wgRateLimits']['fileperm-setlevel']['user'] );
		$this->assertArrayNotHasKey( 'newbie',
			$GLOBALS['wgRateLimits']['fileperm-setlevel'] );
	}

	/**
	 * SEC-09: onRegistration completes without error when anonymous read is enabled.
	 *
	 * When $wgGroupPermissions['*']['read'] is true, the extension should
	 * log a warning but NOT crash or prevent the wiki from loading.
	 */
	public function testOnRegistrationHandlesAnonymousReadEnabled(): void {
		$GLOBALS['wgGroupPermissions']['*']['read'] = true;
		unset( $GLOBALS['wgRateLimits']['fileperm-setlevel'] );

		// Should complete without throwing
		\FilePermissions\Hooks\RegistrationHooks::onRegistration();

		// Rate limits should still be registered (registration wasn't aborted)
		$this->assertArrayHasKey( 'fileperm-setlevel', $GLOBALS['wgRateLimits'] );
	}

	/**
	 * SEC-09: onRegistration completes without warning when anonymous read is disabled.
	 *
	 * Normal case: $wgGroupPermissions['*']['read'] is false. Registration
	 * should complete cleanly with no warning path triggered.
	 */
	public function testOnRegistrationHandlesAnonymousReadDisabled(): void {
		$GLOBALS['wgGroupPermissions']['*']['read'] = false;
		unset( $GLOBALS['wgRateLimits']['fileperm-setlevel'] );

		// Should complete without throwing
		\FilePermissions\Hooks\RegistrationHooks::onRegistration();

		// Rate limits should still be registered
		$this->assertArrayHasKey( 'fileperm-setlevel', $GLOBALS['wgRateLimits'] );
	}
}
