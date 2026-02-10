<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Unit;

use FilePermissions\Config;
use FilePermissions\PermissionService;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Unit tests for the FilePermissions\PermissionService class.
 *
 * Covers UNIT-04 (permission checks with mocked DB provider),
 * UNIT-05 (default level assignment), and UNIT-06 (unknown/missing files).
 *
 * Security principle: Files are protected at the byte level. Fail-closed
 * behavior must be guaranteed -- if config is invalid, deny all access.
 * When no permission level applies, treat files as unrestricted (backward compat).
 *
 * Critical: Every test creates a FRESH PermissionService instance via
 * createService() to prevent cache poisoning from the private $levelCache.
 *
 * @covers \FilePermissions\PermissionService
 */
class PermissionServiceTest extends MediaWikiUnitTestCase {

	// =========================================================================
	// Helper methods
	// =========================================================================

	/**
	 * Create a mock Config with the given settings.
	 *
	 * @param array $overrides Config values to override defaults
	 * @return Config
	 */
	private function createMockConfig( array $overrides = [] ): Config {
		$defaults = [
			'levels' => [ 'public', 'internal', 'confidential' ],
			'groupGrants' => [
				'sysop' => [ '*' ],
				'editor' => [ 'public', 'internal' ],
				'viewer' => [ 'public' ],
			],
			'defaultLevel' => null,
			'namespaceDefaults' => [],
			'invalidConfig' => false,
		];

		$values = array_merge( $defaults, $overrides );

		$config = $this->createMock( Config::class );
		$config->method( 'getLevels' )->willReturn( $values['levels'] );
		$config->method( 'getGroupGrants' )->willReturn( $values['groupGrants'] );
		$config->method( 'getDefaultLevel' )->willReturn( $values['defaultLevel'] );
		$config->method( 'getNamespaceDefaults' )->willReturn( $values['namespaceDefaults'] );
		$config->method( 'isInvalidConfig' )->willReturn( $values['invalidConfig'] );
		$config->method( 'isValidLevel' )->willReturnCallback(
			static fn ( string $level ): bool => in_array( $level, $values['levels'], true )
		);
		$config->method( 'resolveDefaultLevel' )->willReturnCallback(
			static function ( int $ns ) use ( $values ): ?string {
				// Check namespace-specific default first
				if ( isset( $values['namespaceDefaults'][$ns] ) ) {
					$level = $values['namespaceDefaults'][$ns];
					if ( in_array( $level, $values['levels'], true ) ) {
						return $level;
					}
				}
				// Fall back to global default
				if ( $values['defaultLevel'] !== null
					&& in_array( $values['defaultLevel'], $values['levels'], true )
				) {
					return $values['defaultLevel'];
				}
				return null;
			}
		);

		return $config;
	}

	/**
	 * Create a fresh PermissionService with the given mocks.
	 *
	 * CRITICAL: Call this per test method. Never reuse a PermissionService
	 * instance across tests -- the private $levelCache persists on the object.
	 *
	 * @param IConnectionProvider $dbProvider
	 * @param UserGroupManager $userGroupManager
	 * @param Config|null $config
	 * @return PermissionService
	 */
	private function createService(
		IConnectionProvider $dbProvider,
		UserGroupManager $userGroupManager,
		?Config $config = null
	): PermissionService {
		return new PermissionService(
			$dbProvider,
			$userGroupManager,
			$config ?? $this->createMockConfig()
		);
	}

	/**
	 * Create a mock IConnectionProvider that returns a mock replica DB.
	 *
	 * The mock DB has a SelectQueryBuilder that returns the given row
	 * from fetchRow(). Builder methods (select, from, where, caller) are
	 * fluent (return $this).
	 *
	 * @param object|false $fetchRowResult The result fetchRow() should return
	 * @param int $expectedCallCount Expected number of times getReplicaDatabase is called (0 = any)
	 * @return IConnectionProvider
	 */
	private function createMockDbProvider(
		$fetchRowResult = false,
		int $expectedCallCount = 0
	): IConnectionProvider {
		$queryBuilder = $this->createMock( SelectQueryBuilder::class );
		$queryBuilder->method( 'select' )->willReturnSelf();
		$queryBuilder->method( 'from' )->willReturnSelf();
		$queryBuilder->method( 'where' )->willReturnSelf();
		$queryBuilder->method( 'caller' )->willReturnSelf();
		$queryBuilder->method( 'fetchRow' )->willReturn( $fetchRowResult );

		$dbr = $this->createMock( IDatabase::class );
		$dbr->method( 'newSelectQueryBuilder' )->willReturn( $queryBuilder );

		$dbProvider = $this->createMock( IConnectionProvider::class );
		if ( $expectedCallCount > 0 ) {
			$dbProvider->expects( $this->exactly( $expectedCallCount ) )
				->method( 'getReplicaDatabase' )
				->willReturn( $dbr );
		} else {
			$dbProvider->method( 'getReplicaDatabase' )->willReturn( $dbr );
		}

		return $dbProvider;
	}

	/**
	 * Create a mock IConnectionProvider where getReplicaDatabase is never called.
	 *
	 * Used for tests that only exercise canUserAccessLevel (no DB needed).
	 *
	 * @return IConnectionProvider
	 */
	private function createNeverCalledDbProvider(): IConnectionProvider {
		$dbProvider = $this->createMock( IConnectionProvider::class );
		$dbProvider->expects( $this->never() )
			->method( 'getReplicaDatabase' );
		return $dbProvider;
	}

	/**
	 * Create a mock UserGroupManager that returns the given groups for any user.
	 *
	 * @param array<string> $groups Groups the user belongs to
	 * @return UserGroupManager
	 */
	private function createMockUserGroupManager( array $groups ): UserGroupManager {
		$ugm = $this->createMock( UserGroupManager::class );
		$ugm->method( 'getUserEffectiveGroups' )->willReturn( $groups );
		return $ugm;
	}

	/**
	 * Create a mock Title for NS_FILE with the given page ID.
	 *
	 * @param int $pageId The article ID (0 = nonexistent)
	 * @return Title
	 */
	private function createMockFileTitle( int $pageId = 42 ): Title {
		$title = $this->createMock( Title::class );
		$title->method( 'getNamespace' )->willReturn( NS_FILE );
		$title->method( 'getArticleID' )->willReturn( $pageId );
		return $title;
	}

	/**
	 * Create a mock Title for a non-file namespace.
	 *
	 * @param int $namespace The namespace ID (default NS_MAIN = 0)
	 * @param int $pageId The article ID
	 * @return Title
	 */
	private function createMockNonFileTitle(
		int $namespace = NS_MAIN,
		int $pageId = 100
	): Title {
		$title = $this->createMock( Title::class );
		$title->method( 'getNamespace' )->willReturn( $namespace );
		$title->method( 'getArticleID' )->willReturn( $pageId );
		return $title;
	}

	/**
	 * Create a mock UserIdentity.
	 *
	 * @return UserIdentity
	 */
	private function createMockUser(): UserIdentity {
		return $this->createMock( UserIdentity::class );
	}

	// =========================================================================
	// UNIT-04: Permission checks with mocked DB provider
	// =========================================================================

	// -------------------------------------------------------------------------
	// canUserAccessLevel: grant matching
	// -------------------------------------------------------------------------

	/**
	 * User in a group that is granted the requested level gets access.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsTrueWhenGroupGrantsLevel(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [ 'editor' ] )
		);

		$this->assertTrue( $service->canUserAccessLevel(
			$this->createMockUser(),
			'internal'
		) );
	}

	/**
	 * User in a group that does NOT have the requested level is denied.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsFalseWhenGroupLacksLevel(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [ 'viewer' ] )
		);

		// viewer only has 'public', not 'confidential'
		$this->assertFalse( $service->canUserAccessLevel(
			$this->createMockUser(),
			'confidential'
		) );
	}

	/**
	 * Wildcard '*' grant gives access to ANY level.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsTrueWithWildcardGrant(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [ 'sysop' ] )
		);

		// sysop has [ '*' ] -- should grant access to any level
		$this->assertTrue( $service->canUserAccessLevel(
			$this->createMockUser(),
			'confidential'
		) );
	}

	/**
	 * Wildcard grant works for arbitrary level strings.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsTrueWithWildcardForAnyLevelString(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [ 'sysop' ] )
		);

		$this->assertTrue( $service->canUserAccessLevel(
			$this->createMockUser(),
			'top-secret-custom-level'
		) );
	}

	/**
	 * User in multiple groups, one of which grants access.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsTrueWhenOneOfMultipleGroupsGrants(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			// viewer has public only, editor has public+internal
			$this->createMockUserGroupManager( [ 'viewer', 'editor' ] )
		);

		$this->assertTrue( $service->canUserAccessLevel(
			$this->createMockUser(),
			'internal'
		) );
	}

	/**
	 * User in multiple groups, none of which grants the requested level.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsFalseWhenNoGroupGrantsLevel(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			// viewer has public, editor has public+internal -- neither has confidential
			$this->createMockUserGroupManager( [ 'viewer', 'editor' ] )
		);

		$this->assertFalse( $service->canUserAccessLevel(
			$this->createMockUser(),
			'confidential'
		) );
	}

	/**
	 * User with no groups has no access.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsFalseWhenUserHasNoGroups(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] )
		);

		$this->assertFalse( $service->canUserAccessLevel(
			$this->createMockUser(),
			'public'
		) );
	}

	/**
	 * Group exists in grants config but has empty levels array -- no access.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsFalseWhenGroupHasEmptyGrantsArray(): void {
		$config = $this->createMockConfig( [
			'groupGrants' => [ 'intern' => [] ],
		] );
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [ 'intern' ] ),
			$config
		);

		$this->assertFalse( $service->canUserAccessLevel(
			$this->createMockUser(),
			'public'
		) );
	}

	/**
	 * User group not present in grants config at all -- no access.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsFalseWhenGroupNotInConfig(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [ 'unknown-group' ] )
		);

		$this->assertFalse( $service->canUserAccessLevel(
			$this->createMockUser(),
			'public'
		) );
	}

	// -------------------------------------------------------------------------
	// canUserAccessLevel: fail-closed behavior
	// -------------------------------------------------------------------------

	/**
	 * FAIL-CLOSED: When config is flagged invalid, deny access regardless
	 * of grants. This is THE critical security guarantee.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsFalseWhenConfigInvalid_FailClosed(): void {
		$config = $this->createMockConfig( [ 'invalidConfig' => true ] );

		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			// sysop has wildcard -- would normally grant access
			$this->createMockUserGroupManager( [ 'sysop' ] ),
			$config
		);

		$this->assertFalse(
			$service->canUserAccessLevel( $this->createMockUser(), 'public' ),
			'Fail-closed: invalid config must deny access even for sysop with wildcard grant'
		);
	}

	/**
	 * FAIL-CLOSED: Invalid config denies access for ALL levels, not just some.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 * @dataProvider provideAllConfiguredLevels
	 */
	public function testCanUserAccessLevelDeniesAllLevelsWhenConfigInvalid_FailClosed(
		string $level
	): void {
		$config = $this->createMockConfig( [ 'invalidConfig' => true ] );

		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [ 'sysop' ] ),
			$config
		);

		$this->assertFalse(
			$service->canUserAccessLevel( $this->createMockUser(), $level ),
			"Fail-closed: invalid config must deny level '$level' even for sysop"
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provideAllConfiguredLevels(): array {
		return [
			'public' => [ 'public' ],
			'internal' => [ 'internal' ],
			'confidential' => [ 'confidential' ],
		];
	}

	/**
	 * FAIL-CLOSED: Invalid config does NOT consult UserGroupManager at all.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelDoesNotCheckGroupsWhenConfigInvalid_FailClosed(): void {
		$config = $this->createMockConfig( [ 'invalidConfig' => true ] );

		$ugm = $this->createMock( UserGroupManager::class );
		$ugm->expects( $this->never() )
			->method( 'getUserEffectiveGroups' );

		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$ugm,
			$config
		);

		$this->assertFalse( $service->canUserAccessLevel(
			$this->createMockUser(),
			'public'
		) );
	}

	// -------------------------------------------------------------------------
	// canUserAccessFile: with explicit level
	// -------------------------------------------------------------------------

	/**
	 * File with explicit level, user group grants access.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsTrueWhenUserHasAccessToExplicitLevel(): void {
		$row = (object)[ 'fpl_level' => 'internal' ];
		$service = $this->createService(
			$this->createMockDbProvider( $row ),
			$this->createMockUserGroupManager( [ 'editor' ] )
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertTrue( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	/**
	 * File with explicit level, user group does NOT grant access.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsFalseWhenUserLacksAccessToExplicitLevel(): void {
		$row = (object)[ 'fpl_level' => 'confidential' ];
		$service = $this->createService(
			$this->createMockDbProvider( $row ),
			// viewer only has 'public'
			$this->createMockUserGroupManager( [ 'viewer' ] )
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertFalse( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	// -------------------------------------------------------------------------
	// getLevel: DB interaction and caching
	// -------------------------------------------------------------------------

	/**
	 * getLevel returns the level from DB for a valid NS_FILE title with existing page.
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelReturnsLevelFromDbForValidFileTitle(): void {
		$row = (object)[ 'fpl_level' => 'confidential' ];
		$service = $this->createService(
			$this->createMockDbProvider( $row ),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertSame( 'confidential', $service->getLevel( $title ) );
	}

	/**
	 * getLevel returns null for a non-NS_FILE title (e.g., NS_MAIN).
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelReturnsNullForNonFileNamespace(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockNonFileTitle( NS_MAIN, 100 );
		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * getLevel returns null for NS_FILE title with page ID 0 (nonexistent page).
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelReturnsNullForNonexistentPage(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 0 );
		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * getLevel returns null when DB has no row for the file (no level set).
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelReturnsNullWhenDbRowNotFound(): void {
		// fetchRow returns false (no row found)
		$service = $this->createService(
			$this->createMockDbProvider( false ),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * getLevel caches the result -- second call does NOT query DB again.
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelCachesResultOnSecondCall(): void {
		$row = (object)[ 'fpl_level' => 'internal' ];
		// Expect exactly 1 call to getReplicaDatabase (cache should prevent second)
		$service = $this->createService(
			$this->createMockDbProvider( $row, 1 ),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 42 );

		// First call hits DB
		$result1 = $service->getLevel( $title );
		// Second call should use cache
		$result2 = $service->getLevel( $title );

		$this->assertSame( 'internal', $result1 );
		$this->assertSame( 'internal', $result2 );
	}

	/**
	 * getLevel caches null result too -- DB not queried again for missing level.
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelCachesNullResultOnSecondCall(): void {
		// fetchRow returns false (no row)
		$service = $this->createService(
			$this->createMockDbProvider( false, 1 ),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 42 );

		$result1 = $service->getLevel( $title );
		$result2 = $service->getLevel( $title );

		$this->assertNull( $result1 );
		$this->assertNull( $result2 );
	}

	/**
	 * getLevel returns null for NS_TALK title (not NS_FILE).
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelReturnsNullForTalkNamespace(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockNonFileTitle( NS_TALK, 50 );
		$this->assertNull( $service->getLevel( $title ) );
	}

	// =========================================================================
	// UNIT-05: Default level assignment
	// =========================================================================

	/**
	 * getEffectiveLevel returns the explicit level when set in DB.
	 *
	 * @covers \FilePermissions\PermissionService::getEffectiveLevel
	 */
	public function testGetEffectiveLevelReturnsExplicitLevelWhenSet(): void {
		$row = (object)[ 'fpl_level' => 'confidential' ];
		$service = $this->createService(
			$this->createMockDbProvider( $row ),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertSame( 'confidential', $service->getEffectiveLevel( $title ) );
	}

	/**
	 * getEffectiveLevel returns namespace default when no explicit level
	 * and namespace default is configured.
	 *
	 * @covers \FilePermissions\PermissionService::getEffectiveLevel
	 */
	public function testGetEffectiveLevelReturnsNamespaceDefaultWhenNoExplicitLevel(): void {
		$config = $this->createMockConfig( [
			'namespaceDefaults' => [ NS_FILE => 'internal' ],
		] );

		// DB returns no row (no explicit level)
		$service = $this->createService(
			$this->createMockDbProvider( false ),
			$this->createMockUserGroupManager( [] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertSame( 'internal', $service->getEffectiveLevel( $title ) );
	}

	/**
	 * getEffectiveLevel returns global default when no explicit level
	 * and no namespace default.
	 *
	 * @covers \FilePermissions\PermissionService::getEffectiveLevel
	 */
	public function testGetEffectiveLevelReturnsGlobalDefaultWhenNoNamespaceDefault(): void {
		$config = $this->createMockConfig( [
			'namespaceDefaults' => [],
			'defaultLevel' => 'public',
		] );

		// DB returns no row (no explicit level)
		$service = $this->createService(
			$this->createMockDbProvider( false ),
			$this->createMockUserGroupManager( [] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertSame( 'public', $service->getEffectiveLevel( $title ) );
	}

	/**
	 * getEffectiveLevel returns null when no explicit level, no namespace
	 * default, and no global default. File is unrestricted.
	 *
	 * @covers \FilePermissions\PermissionService::getEffectiveLevel
	 */
	public function testGetEffectiveLevelReturnsNullWhenNoDefaults_Unrestricted(): void {
		$config = $this->createMockConfig( [
			'namespaceDefaults' => [],
			'defaultLevel' => null,
		] );

		// DB returns no row
		$service = $this->createService(
			$this->createMockDbProvider( false ),
			$this->createMockUserGroupManager( [] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertNull( $service->getEffectiveLevel( $title ) );
	}

	/**
	 * getEffectiveLevel prefers namespace default over global default.
	 *
	 * @covers \FilePermissions\PermissionService::getEffectiveLevel
	 */
	public function testGetEffectiveLevelPrefersNamespaceDefaultOverGlobal(): void {
		$config = $this->createMockConfig( [
			'namespaceDefaults' => [ NS_FILE => 'confidential' ],
			'defaultLevel' => 'public',
		] );

		$service = $this->createService(
			$this->createMockDbProvider( false ),
			$this->createMockUserGroupManager( [] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertSame( 'confidential', $service->getEffectiveLevel( $title ) );
	}

	/**
	 * getEffectiveLevel prefers explicit level over namespace default.
	 *
	 * @covers \FilePermissions\PermissionService::getEffectiveLevel
	 */
	public function testGetEffectiveLevelPrefersExplicitLevelOverNamespaceDefault(): void {
		$config = $this->createMockConfig( [
			'namespaceDefaults' => [ NS_FILE => 'public' ],
			'defaultLevel' => 'public',
		] );

		$row = (object)[ 'fpl_level' => 'confidential' ];
		$service = $this->createService(
			$this->createMockDbProvider( $row ),
			$this->createMockUserGroupManager( [] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertSame( 'confidential', $service->getEffectiveLevel( $title ) );
	}

	// -------------------------------------------------------------------------
	// canUserAccessFile with default levels
	// -------------------------------------------------------------------------

	/**
	 * canUserAccessFile with default level: user granted the default level.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsTrueWhenUserHasDefaultLevel(): void {
		$config = $this->createMockConfig( [
			'defaultLevel' => 'internal',
			'namespaceDefaults' => [],
		] );

		// DB returns no row -- default level applies
		$service = $this->createService(
			$this->createMockDbProvider( false ),
			// editor has [ 'public', 'internal' ]
			$this->createMockUserGroupManager( [ 'editor' ] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertTrue( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	/**
	 * canUserAccessFile with default level: user NOT granted the default level.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsFalseWhenUserLacksDefaultLevel(): void {
		$config = $this->createMockConfig( [
			'defaultLevel' => 'confidential',
			'namespaceDefaults' => [],
		] );

		// DB returns no row -- default level applies
		$service = $this->createService(
			$this->createMockDbProvider( false ),
			// viewer only has [ 'public' ]
			$this->createMockUserGroupManager( [ 'viewer' ] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertFalse( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	/**
	 * canUserAccessFile with namespace default: user granted the namespace default level.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsTrueWhenUserHasNamespaceDefaultLevel(): void {
		$config = $this->createMockConfig( [
			'namespaceDefaults' => [ NS_FILE => 'internal' ],
		] );

		// DB returns no row -- namespace default applies
		$service = $this->createService(
			$this->createMockDbProvider( false ),
			$this->createMockUserGroupManager( [ 'editor' ] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertTrue( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	/**
	 * canUserAccessFile with namespace default: user NOT granted the namespace default level.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsFalseWhenUserLacksNamespaceDefaultLevel(): void {
		$config = $this->createMockConfig( [
			'namespaceDefaults' => [ NS_FILE => 'confidential' ],
		] );

		$service = $this->createService(
			$this->createMockDbProvider( false ),
			// viewer only has 'public'
			$this->createMockUserGroupManager( [ 'viewer' ] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertFalse( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	// =========================================================================
	// UNIT-06: Unknown/missing files
	// =========================================================================

	/**
	 * getLevel: nonexistent page (articleID = 0) returns null.
	 * DB is NOT queried for nonexistent pages.
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelReturnsNullForNonexistentPage_NoDbQuery(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 0 );
		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * getLevel: wrong namespace (NS_MAIN, not NS_FILE) returns null.
	 * DB is NOT queried for non-file namespaces.
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelReturnsNullForWrongNamespace_NoDbQuery(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockNonFileTitle( NS_MAIN, 100 );
		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * canUserAccessFile: file with no level and no default is unrestricted.
	 * This handles grandfathered files that predate the extension.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsTrueWhenNoLevelSet_UnrestrictedFile(): void {
		$config = $this->createMockConfig( [
			'defaultLevel' => null,
			'namespaceDefaults' => [],
		] );

		// DB returns no row (no level set)
		$service = $this->createService(
			$this->createMockDbProvider( false ),
			// Even with no groups, unrestricted files are accessible
			$this->createMockUserGroupManager( [] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertTrue(
			$service->canUserAccessFile( $this->createMockUser(), $title ),
			'Unrestricted file (no level, no default) must be accessible to all users'
		);
	}

	/**
	 * canUserAccessFile: unrestricted file accessible even to user with no groups.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileUnrestrictedFileAccessibleToGrouplessUser(): void {
		$config = $this->createMockConfig( [
			'defaultLevel' => null,
			'namespaceDefaults' => [],
			'groupGrants' => [],
		] );

		$service = $this->createService(
			$this->createMockDbProvider( false ),
			$this->createMockUserGroupManager( [] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertTrue( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	/**
	 * canUserAccessFile: file with no explicit level but default applies,
	 * and user lacks access to the default level.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsFalseWhenDefaultAppliesAndUserLacksAccess(): void {
		$config = $this->createMockConfig( [
			'defaultLevel' => 'confidential',
			'namespaceDefaults' => [],
		] );

		$service = $this->createService(
			$this->createMockDbProvider( false ),
			// viewer only has 'public', not 'confidential'
			$this->createMockUserGroupManager( [ 'viewer' ] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertFalse( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	/**
	 * getEffectiveLevel: nonexistent file (page ID 0) returns default level
	 * (not null) when a default is configured.
	 *
	 * Note: getLevel returns null for page ID 0 (early return before DB query),
	 * then getEffectiveLevel falls through to resolveDefaultLevel.
	 *
	 * @covers \FilePermissions\PermissionService::getEffectiveLevel
	 */
	public function testGetEffectiveLevelReturnsDefaultForNonexistentFile(): void {
		$config = $this->createMockConfig( [
			'namespaceDefaults' => [ NS_FILE => 'internal' ],
		] );

		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] ),
			$config
		);

		// Page ID 0 = nonexistent
		$title = $this->createMockFileTitle( 0 );
		$this->assertSame( 'internal', $service->getEffectiveLevel( $title ) );
	}

	/**
	 * getEffectiveLevel: nonexistent file with no defaults returns null.
	 *
	 * @covers \FilePermissions\PermissionService::getEffectiveLevel
	 */
	public function testGetEffectiveLevelReturnsNullForNonexistentFileWithNoDefaults(): void {
		$config = $this->createMockConfig( [
			'namespaceDefaults' => [],
			'defaultLevel' => null,
		] );

		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] ),
			$config
		);

		$title = $this->createMockFileTitle( 0 );
		$this->assertNull( $service->getEffectiveLevel( $title ) );
	}

	/**
	 * canUserAccessFile: nonexistent file (page ID 0) with no default
	 * is unrestricted.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsTrueForNonexistentUnrestrictedFile(): void {
		$config = $this->createMockConfig( [
			'defaultLevel' => null,
			'namespaceDefaults' => [],
		] );

		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] ),
			$config
		);

		$title = $this->createMockFileTitle( 0 );
		$this->assertTrue( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	/**
	 * canUserAccessFile: non-file namespace title with no level is unrestricted.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsTrueForNonFileNamespace(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockNonFileTitle( NS_MAIN, 100 );
		$this->assertTrue( $service->canUserAccessFile(
			$this->createMockUser(),
			$title
		) );
	}

	// =========================================================================
	// Additional coverage: setLevel and removeLevel
	// =========================================================================

	/**
	 * setLevel throws InvalidArgumentException for nonexistent page (page ID 0).
	 *
	 * @covers \FilePermissions\PermissionService::setLevel
	 */
	public function testSetLevelThrowsForNonexistentPage(): void {
		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 0 );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'page does not exist' );
		$service->setLevel( $title, 'public' );
	}

	/**
	 * setLevel throws InvalidArgumentException for an invalid level.
	 *
	 * @covers \FilePermissions\PermissionService::setLevel
	 */
	public function testSetLevelThrowsForInvalidLevel(): void {
		$dbProvider = $this->createMock( IConnectionProvider::class );
		$dbProvider->expects( $this->never() )
			->method( 'getPrimaryDatabase' );

		$service = $this->createService(
			$dbProvider,
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 42 );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid permission level' );
		$service->setLevel( $title, 'nonexistent-level' );
	}

	/**
	 * setLevel writes to primary DB and updates cache.
	 *
	 * @covers \FilePermissions\PermissionService::setLevel
	 */
	public function testSetLevelWritesToPrimaryDbAndUpdatesCache(): void {
		$replaceBuilder = $this->createMock( \Wikimedia\Rdbms\ReplaceQueryBuilder::class );
		$replaceBuilder->method( 'replaceInto' )->willReturnSelf();
		$replaceBuilder->method( 'uniqueIndexFields' )->willReturnSelf();
		$replaceBuilder->method( 'row' )->willReturnSelf();
		$replaceBuilder->method( 'caller' )->willReturnSelf();
		$replaceBuilder->expects( $this->once() )->method( 'execute' );

		$dbw = $this->createMock( IDatabase::class );
		$dbw->method( 'newReplaceQueryBuilder' )->willReturn( $replaceBuilder );

		$dbProvider = $this->createMock( IConnectionProvider::class );
		$dbProvider->expects( $this->once() )
			->method( 'getPrimaryDatabase' )
			->willReturn( $dbw );
		// After setLevel, getLevel should use cache and NOT call getReplicaDatabase
		$dbProvider->expects( $this->never() )
			->method( 'getReplicaDatabase' );

		$service = $this->createService(
			$dbProvider,
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 42 );
		$service->setLevel( $title, 'internal' );

		// Verify cache was updated: getLevel should return 'internal' without DB query
		$this->assertSame( 'internal', $service->getLevel( $title ) );
	}

	/**
	 * removeLevel deletes from primary DB and nulls the cache.
	 *
	 * @covers \FilePermissions\PermissionService::removeLevel
	 */
	public function testRemoveLevelDeletesFromPrimaryDbAndNullsCache(): void {
		$deleteBuilder = $this->createMock( \Wikimedia\Rdbms\DeleteQueryBuilder::class );
		$deleteBuilder->method( 'deleteFrom' )->willReturnSelf();
		$deleteBuilder->method( 'where' )->willReturnSelf();
		$deleteBuilder->method( 'caller' )->willReturnSelf();
		$deleteBuilder->expects( $this->once() )->method( 'execute' );

		$dbw = $this->createMock( IDatabase::class );
		$dbw->method( 'newDeleteQueryBuilder' )->willReturn( $deleteBuilder );

		$dbProvider = $this->createMock( IConnectionProvider::class );
		$dbProvider->expects( $this->once() )
			->method( 'getPrimaryDatabase' )
			->willReturn( $dbw );
		$dbProvider->expects( $this->never() )
			->method( 'getReplicaDatabase' );

		$service = $this->createService(
			$dbProvider,
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 42 );
		$service->removeLevel( $title );

		// Verify cache was set to null: getLevel should return null without DB query
		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * removeLevel is a no-op for nonexistent pages (page ID 0).
	 * DB is NOT queried.
	 *
	 * @covers \FilePermissions\PermissionService::removeLevel
	 */
	public function testRemoveLevelIsNoOpForNonexistentPage(): void {
		$dbProvider = $this->createMock( IConnectionProvider::class );
		$dbProvider->expects( $this->never() )
			->method( 'getPrimaryDatabase' );

		$service = $this->createService(
			$dbProvider,
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 0 );
		// Should not throw or query DB
		$service->removeLevel( $title );
	}

	// =========================================================================
	// Cross-cutting: fresh instance prevents cache poisoning
	// =========================================================================

	/**
	 * Demonstrates that separate PermissionService instances have independent caches.
	 * Service A caches 'confidential' for page 42; Service B queries DB and gets 'public'.
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testFreshServiceInstanceHasIndependentCache(): void {
		$rowA = (object)[ 'fpl_level' => 'confidential' ];
		$serviceA = $this->createService(
			$this->createMockDbProvider( $rowA ),
			$this->createMockUserGroupManager( [] )
		);

		$rowB = (object)[ 'fpl_level' => 'public' ];
		$serviceB = $this->createService(
			$this->createMockDbProvider( $rowB ),
			$this->createMockUserGroupManager( [] )
		);

		$title = $this->createMockFileTitle( 42 );

		// Service A returns 'confidential'
		$this->assertSame( 'confidential', $serviceA->getLevel( $title ) );
		// Service B returns 'public' (NOT 'confidential' from A's cache)
		$this->assertSame( 'public', $serviceB->getLevel( $title ) );
	}

	// =========================================================================
	// Edge cases and additional robustness
	// =========================================================================

	/**
	 * canUserAccessLevel: user group matches first group checked (short-circuit).
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelShortCircuitsOnFirstGrantMatch(): void {
		$config = $this->createMockConfig( [
			'groupGrants' => [
				'first-group' => [ 'confidential' ],
				'second-group' => [ 'public' ],
			],
		] );

		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [ 'first-group', 'second-group' ] ),
			$config
		);

		$this->assertTrue( $service->canUserAccessLevel(
			$this->createMockUser(),
			'confidential'
		) );
	}

	/**
	 * canUserAccessFile: fail-closed config also applies through canUserAccessFile.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessFile
	 */
	public function testCanUserAccessFileReturnsFalseWhenConfigInvalid_FailClosed(): void {
		$config = $this->createMockConfig( [ 'invalidConfig' => true ] );

		$row = (object)[ 'fpl_level' => 'public' ];
		$service = $this->createService(
			$this->createMockDbProvider( $row ),
			$this->createMockUserGroupManager( [ 'sysop' ] ),
			$config
		);

		$title = $this->createMockFileTitle( 42 );
		$this->assertFalse(
			$service->canUserAccessFile( $this->createMockUser(), $title ),
			'Fail-closed: canUserAccessFile must deny when config is invalid'
		);
	}

	/**
	 * canUserAccessLevel with empty grants config.
	 *
	 * @covers \FilePermissions\PermissionService::canUserAccessLevel
	 */
	public function testCanUserAccessLevelReturnsFalseWithEmptyGrantsConfig(): void {
		$config = $this->createMockConfig( [
			'groupGrants' => [],
		] );

		$service = $this->createService(
			$this->createNeverCalledDbProvider(),
			$this->createMockUserGroupManager( [ 'sysop' ] ),
			$config
		);

		$this->assertFalse( $service->canUserAccessLevel(
			$this->createMockUser(),
			'public'
		) );
	}

	/**
	 * getLevel: different page IDs maintain independent cache entries.
	 *
	 * @covers \FilePermissions\PermissionService::getLevel
	 */
	public function testGetLevelCachesIndependentlyPerPageId(): void {
		// Build a mock that returns different rows based on call order
		$queryBuilder1 = $this->createMock( SelectQueryBuilder::class );
		$queryBuilder1->method( 'select' )->willReturnSelf();
		$queryBuilder1->method( 'from' )->willReturnSelf();
		$queryBuilder1->method( 'where' )->willReturnSelf();
		$queryBuilder1->method( 'caller' )->willReturnSelf();
		$queryBuilder1->method( 'fetchRow' )
			->willReturn( (object)[ 'fpl_level' => 'internal' ] );

		$queryBuilder2 = $this->createMock( SelectQueryBuilder::class );
		$queryBuilder2->method( 'select' )->willReturnSelf();
		$queryBuilder2->method( 'from' )->willReturnSelf();
		$queryBuilder2->method( 'where' )->willReturnSelf();
		$queryBuilder2->method( 'caller' )->willReturnSelf();
		$queryBuilder2->method( 'fetchRow' )
			->willReturn( (object)[ 'fpl_level' => 'confidential' ] );

		$dbr = $this->createMock( IDatabase::class );
		$dbr->method( 'newSelectQueryBuilder' )
			->willReturnOnConsecutiveCalls( $queryBuilder1, $queryBuilder2 );

		$dbProvider = $this->createMock( IConnectionProvider::class );
		$dbProvider->expects( $this->exactly( 2 ) )
			->method( 'getReplicaDatabase' )
			->willReturn( $dbr );

		$service = $this->createService(
			$dbProvider,
			$this->createMockUserGroupManager( [] )
		);

		$title42 = $this->createMockFileTitle( 42 );
		$title99 = $this->createMockFileTitle( 99 );

		$this->assertSame( 'internal', $service->getLevel( $title42 ) );
		$this->assertSame( 'confidential', $service->getLevel( $title99 ) );
		// Cached: no additional DB calls
		$this->assertSame( 'internal', $service->getLevel( $title42 ) );
		$this->assertSame( 'confidential', $service->getLevel( $title99 ) );
	}
}
