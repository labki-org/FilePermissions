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

	protected function setUp(): void {
		parent::setUp();

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

		// Reset service to prevent cache poisoning across tests
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.PermissionService' );
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
	 * Insert a File: page and return its Title.
	 *
	 * @param string $name Page name without namespace prefix
	 * @return Title
	 */
	private function createFilePage( string $name ): Title {
		$result = $this->insertPage( "File:$name", 'test content', NS_FILE );
		return $result['title'];
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

		[ $data ] = $this->doApiRequestWithToken( 'csrf', [
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
		$this->doApiRequestWithToken( 'csrf', [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiOverwrite.png',
			'level' => 'public',
		], null, $sysop->getAuthority() );

		// Second: overwrite to confidential
		[ $data ] = $this->doApiRequestWithToken( 'csrf', [
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

		$this->doApiRequestWithToken( 'csrf', [
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

		$this->doApiRequestWithToken( 'csrf', [
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

		$this->doApiRequestWithToken( 'csrf', [
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

		// Pass no authority -- uses default (anon)
		$this->doApiRequestWithToken( 'csrf', [
			'action' => 'fileperm-set-level',
			'title' => 'File:ApiAnon.png',
			'level' => 'public',
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
}
