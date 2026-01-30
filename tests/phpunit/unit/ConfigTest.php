<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Unit;

use FilePermissions\Config;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the FilePermissions\Config class.
 *
 * Covers UNIT-01 (valid configuration), UNIT-02 (fail-closed / invalid config),
 * and UNIT-03 (edge cases).
 *
 * Security principle: When configuration is missing, invalid, or ambiguous,
 * the system MUST fail closed -- deny access, return empty grants, or require
 * explicit selection. These tests prove that guarantee.
 *
 * @covers \FilePermissions\Config
 */
class ConfigTest extends MediaWikiUnitTestCase {

	/**
	 * All 5 FilePermissions globals that must be reset between tests.
	 */
	private const GLOBALS_TO_RESET = [
		'wgFilePermLevels',
		'wgFilePermGroupGrants',
		'wgFilePermDefaultLevel',
		'wgFilePermNamespaceDefaults',
		'wgFilePermInvalidConfig',
	];

	/**
	 * Saved global values for restoration in tearDown.
	 * @var array<string, mixed>
	 */
	private array $savedGlobals = [];

	protected function setUp(): void {
		parent::setUp();
		// Save current global state before each test
		foreach ( self::GLOBALS_TO_RESET as $global ) {
			$this->savedGlobals[$global] = $GLOBALS[$global] ?? '__UNSET__';
		}
	}

	protected function tearDown(): void {
		// Restore all 5 globals to prevent cross-test pollution
		foreach ( self::GLOBALS_TO_RESET as $global ) {
			if ( $this->savedGlobals[$global] === '__UNSET__' ) {
				unset( $GLOBALS[$global] );
			} else {
				$GLOBALS[$global] = $this->savedGlobals[$global];
			}
		}
		parent::tearDown();
	}

	// =========================================================================
	// UNIT-01: Valid configuration
	// =========================================================================

	/**
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsReturnsConfiguredLevels(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal', 'confidential' ];
		$this->assertSame(
			[ 'public', 'internal', 'confidential' ],
			Config::getLevels()
		);
	}

	/**
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsDeduplicatesWhenMwMergesCauseDuplicates(): void {
		// MediaWiki merges extension.json defaults with LocalSettings values,
		// which can result in duplicate entries.
		$GLOBALS['wgFilePermLevels'] = [
			'public', 'internal', 'confidential', 'public', 'internal'
		];
		$result = Config::getLevels();
		$this->assertSame( [ 'public', 'internal', 'confidential' ], $result );
		// Verify sequential integer keys after deduplication
		$this->assertSame( array_values( $result ), $result );
	}

	/**
	 * @covers \FilePermissions\Config::getGroupGrants
	 */
	public function testGetGroupGrantsReturnsConfiguredGrants(): void {
		$grants = [
			'sysop' => [ '*' ],
			'user' => [ 'public', 'internal' ],
		];
		$GLOBALS['wgFilePermGroupGrants'] = $grants;
		$this->assertSame( $grants, Config::getGroupGrants() );
	}

	/**
	 * @covers \FilePermissions\Config::getDefaultLevel
	 */
	public function testGetDefaultLevelReturnsConfiguredDefault(): void {
		$GLOBALS['wgFilePermDefaultLevel'] = 'internal';
		$this->assertSame( 'internal', Config::getDefaultLevel() );
	}

	/**
	 * @covers \FilePermissions\Config::getDefaultLevel
	 */
	public function testGetDefaultLevelReturnsNullWhenSetToNull(): void {
		$GLOBALS['wgFilePermDefaultLevel'] = null;
		$this->assertNull( Config::getDefaultLevel() );
	}

	/**
	 * @covers \FilePermissions\Config::getNamespaceDefaults
	 */
	public function testGetNamespaceDefaultsReturnsConfiguredNamespaceMap(): void {
		$nsDefaults = [ 6 => 'internal', 0 => 'public' ];
		$GLOBALS['wgFilePermNamespaceDefaults'] = $nsDefaults;
		$this->assertSame( $nsDefaults, Config::getNamespaceDefaults() );
	}

	/**
	 * @covers \FilePermissions\Config::isValidLevel
	 */
	public function testIsValidLevelReturnsTrueForConfiguredLevel(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal', 'confidential' ];
		$this->assertTrue( Config::isValidLevel( 'internal' ) );
	}

	/**
	 * @covers \FilePermissions\Config::isValidLevel
	 */
	public function testIsValidLevelReturnsFalseForUnconfiguredLevel(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal', 'confidential' ];
		$this->assertFalse( Config::isValidLevel( 'top-secret' ) );
	}

	/**
	 * @covers \FilePermissions\Config::isValidLevel
	 * @dataProvider provideIsValidLevelCases
	 */
	public function testIsValidLevelWithVariousInputs(
		array $configuredLevels,
		string $levelToCheck,
		bool $expected
	): void {
		$GLOBALS['wgFilePermLevels'] = $configuredLevels;
		$this->assertSame( $expected, Config::isValidLevel( $levelToCheck ) );
	}

	/**
	 * @return array<string, array{array<string>, string, bool}>
	 */
	public static function provideIsValidLevelCases(): array {
		return [
			'first level is valid' => [
				[ 'public', 'internal' ], 'public', true
			],
			'last level is valid' => [
				[ 'public', 'internal' ], 'internal', true
			],
			'unknown level is invalid' => [
				[ 'public', 'internal' ], 'classified', false
			],
			'empty string is invalid' => [
				[ 'public', 'internal' ], '', false
			],
			'case-sensitive mismatch is invalid' => [
				[ 'public', 'internal' ], 'Public', false
			],
			'substring is not valid' => [
				[ 'public', 'internal' ], 'pub', false
			],
		];
	}

	/**
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNamespaceDefault(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal', 'confidential' ];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [ 6 => 'confidential' ];
		$GLOBALS['wgFilePermDefaultLevel'] = 'public';
		$this->assertSame( 'confidential', Config::resolveDefaultLevel( 6 ) );
	}

	/**
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelFallsBackToGlobalDefault(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal' ];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [];
		$GLOBALS['wgFilePermDefaultLevel'] = 'internal';
		$this->assertSame( 'internal', Config::resolveDefaultLevel( 6 ) );
	}

	/**
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenNoDefaultsConfigured(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal' ];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [];
		$GLOBALS['wgFilePermDefaultLevel'] = null;
		$this->assertNull( Config::resolveDefaultLevel( 6 ) );
	}

	/**
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelSkipsInvalidNamespaceDefaultAndFallsToGlobal(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal' ];
		// Namespace default references a level NOT in the configured levels
		$GLOBALS['wgFilePermNamespaceDefaults'] = [ 6 => 'nonexistent' ];
		$GLOBALS['wgFilePermDefaultLevel'] = 'public';
		$this->assertSame( 'public', Config::resolveDefaultLevel( 6 ) );
	}

	/**
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenGlobalDefaultIsInvalidLevel(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal' ];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [];
		// Global default references a level NOT in the configured levels
		$GLOBALS['wgFilePermDefaultLevel'] = 'does-not-exist';
		$this->assertNull( Config::resolveDefaultLevel( 6 ) );
	}

	// =========================================================================
	// UNIT-02: Fail-closed / invalid config
	// =========================================================================

	/**
	 * Default state: config is not flagged as invalid.
	 *
	 * @covers \FilePermissions\Config::isInvalidConfig
	 */
	public function testIsInvalidConfigReturnsFalseByDefault(): void {
		$GLOBALS['wgFilePermInvalidConfig'] = false;
		$this->assertFalse( Config::isInvalidConfig() );
	}

	/**
	 * When config validation fails, isInvalidConfig signals fail-closed mode.
	 *
	 * @covers \FilePermissions\Config::isInvalidConfig
	 */
	public function testIsInvalidConfigReturnsTrueWhenConfigFlaggedInvalid(): void {
		$GLOBALS['wgFilePermInvalidConfig'] = true;
		$this->assertTrue( Config::isInvalidConfig() );
	}

	/**
	 * When levels config is null/unset, fallback to ['public'] only.
	 * This is a safe fallback: public is the least-restrictive level,
	 * meaning no access beyond public is granted.
	 *
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsReturnsSafeFallbackWhenNull(): void {
		$GLOBALS['wgFilePermLevels'] = null;
		$this->assertSame( [ 'public' ], Config::getLevels() );
	}

	/**
	 * Fail-closed: when group grants are unset, no grants means no access.
	 *
	 * @covers \FilePermissions\Config::getGroupGrants
	 */
	public function testGetGroupGrantsReturnsEmptyArrayWhenUnset_NoGrantsMeansNoAccess(): void {
		unset( $GLOBALS['wgFilePermGroupGrants'] );
		$this->assertSame( [], Config::getGroupGrants() );
	}

	/**
	 * Fail-closed: when group grants are null, no grants means no access.
	 *
	 * @covers \FilePermissions\Config::getGroupGrants
	 */
	public function testGetGroupGrantsReturnsEmptyArrayWhenNull_NoGrantsMeansNoAccess(): void {
		$GLOBALS['wgFilePermGroupGrants'] = null;
		$this->assertSame( [], Config::getGroupGrants() );
	}

	/**
	 * When default level is unset, null means explicit selection required.
	 *
	 * @covers \FilePermissions\Config::getDefaultLevel
	 */
	public function testGetDefaultLevelReturnsNullWhenUnset_ExplicitSelectionRequired(): void {
		unset( $GLOBALS['wgFilePermDefaultLevel'] );
		$this->assertNull( Config::getDefaultLevel() );
	}

	/**
	 * Fail-closed: when namespace defaults are unset, no defaults means
	 * no automatic permission assignment.
	 *
	 * @covers \FilePermissions\Config::getNamespaceDefaults
	 */
	public function testGetNamespaceDefaultsReturnsEmptyArrayWhenUnset_NoAutoAssignment(): void {
		unset( $GLOBALS['wgFilePermNamespaceDefaults'] );
		$this->assertSame( [], Config::getNamespaceDefaults() );
	}

	/**
	 * Fail-closed: when namespace defaults are null, no defaults means
	 * no automatic permission assignment.
	 *
	 * @covers \FilePermissions\Config::getNamespaceDefaults
	 */
	public function testGetNamespaceDefaultsReturnsEmptyArrayWhenNull_NoAutoAssignment(): void {
		$GLOBALS['wgFilePermNamespaceDefaults'] = null;
		$this->assertSame( [], Config::getNamespaceDefaults() );
	}

	// =========================================================================
	// UNIT-03: Edge cases
	// =========================================================================

	/**
	 * Zero levels configured: empty array is respected as-is.
	 *
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsWithEmptyArrayReturnsEmptyArray(): void {
		$GLOBALS['wgFilePermLevels'] = [];
		$this->assertSame( [], Config::getLevels() );
	}

	/**
	 * Single level configured: returns single-element array.
	 *
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsWithSingleLevelReturnsSingleElementArray(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'restricted' ];
		$this->assertSame( [ 'restricted' ], Config::getLevels() );
	}

	/**
	 * Many levels: system handles 5+ levels correctly.
	 *
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsWithManyLevelsReturnsAllLevels(): void {
		$levels = [ 'public', 'internal', 'confidential', 'secret', 'top-secret', 'classified' ];
		$GLOBALS['wgFilePermLevels'] = $levels;
		$this->assertSame( $levels, Config::getLevels() );
		$this->assertCount( 6, Config::getLevels() );
	}

	/**
	 * Group exists but has empty grants array -- group gets NO access.
	 *
	 * @covers \FilePermissions\Config::getGroupGrants
	 */
	public function testGetGroupGrantsWithGroupGrantedEmptyArray(): void {
		$GLOBALS['wgFilePermGroupGrants'] = [ 'viewer' => [] ];
		$result = Config::getGroupGrants();
		$this->assertSame( [], $result['viewer'] );
	}

	/**
	 * Group has wildcard '*' grant -- data structure preserved for
	 * downstream permission checks.
	 *
	 * @covers \FilePermissions\Config::getGroupGrants
	 */
	public function testGetGroupGrantsWithWildcardGrant(): void {
		$GLOBALS['wgFilePermGroupGrants'] = [ 'sysop' => [ '*' ] ];
		$result = Config::getGroupGrants();
		$this->assertSame( [ '*' ], $result['sysop'] );
	}

	/**
	 * Multiple groups with overlapping levels -- data structure preserved.
	 *
	 * @covers \FilePermissions\Config::getGroupGrants
	 */
	public function testGetGroupGrantsWithMultipleGroupsOverlappingLevels(): void {
		$grants = [
			'sysop' => [ '*' ],
			'editor' => [ 'public', 'internal' ],
			'viewer' => [ 'public' ],
		];
		$GLOBALS['wgFilePermGroupGrants'] = $grants;
		$result = Config::getGroupGrants();
		$this->assertSame( $grants, $result );
		$this->assertCount( 3, $result );
	}

	/**
	 * Fail-closed: when levels array is empty, nothing is valid.
	 *
	 * @covers \FilePermissions\Config::isValidLevel
	 */
	public function testIsValidLevelReturnsFalseWhenLevelsEmpty_NothingIsValid(): void {
		$GLOBALS['wgFilePermLevels'] = [];
		$this->assertFalse( Config::isValidLevel( 'public' ) );
	}

	/**
	 * Semantic error: namespace default references a nonexistent level.
	 * Must NOT crash -- should fall through gracefully.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelWithNamespaceDefaultReferencingNonexistentLevel(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public' ];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [ 6 => 'nonexistent-level' ];
		$GLOBALS['wgFilePermDefaultLevel'] = 'public';
		// Should skip invalid namespace default and fall back to global
		$this->assertSame( 'public', Config::resolveDefaultLevel( 6 ) );
	}

	/**
	 * Semantic error: global default references a nonexistent level.
	 * Must return null -- no silent escalation.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelWithGlobalDefaultReferencingNonexistentLevel_ReturnsNull(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal' ];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [];
		$GLOBALS['wgFilePermDefaultLevel'] = 'secret';
		$this->assertNull( Config::resolveDefaultLevel( 6 ) );
	}

	/**
	 * Both namespace and global defaults are invalid -- returns null (fail-closed).
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenBothDefaultsAreInvalid_FailClosed(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public' ];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [ 6 => 'bogus' ];
		$GLOBALS['wgFilePermDefaultLevel'] = 'also-bogus';
		$this->assertNull( Config::resolveDefaultLevel( 6 ) );
	}

	/**
	 * Namespace without a specific default uses global default.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelForUnconfiguredNamespaceUsesGlobalDefault(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', 'internal' ];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [ 6 => 'internal' ];
		$GLOBALS['wgFilePermDefaultLevel'] = 'public';
		// Namespace 0 has no specific default, should get global
		$this->assertSame( 'public', Config::resolveDefaultLevel( 0 ) );
	}

	/**
	 * isInvalidConfig returns false when global is completely unset
	 * (as opposed to explicitly set to false).
	 *
	 * @covers \FilePermissions\Config::isInvalidConfig
	 */
	public function testIsInvalidConfigReturnsFalseWhenGlobalIsUnset(): void {
		unset( $GLOBALS['wgFilePermInvalidConfig'] );
		$this->assertFalse( Config::isInvalidConfig() );
	}

	/**
	 * getLevels with null global falls back to safe default.
	 * Tests the unset case (vs null case tested separately).
	 *
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsReturnsSafeFallbackWhenGlobalIsUnset(): void {
		unset( $GLOBALS['wgFilePermLevels'] );
		$this->assertSame( [ 'public' ], Config::getLevels() );
	}

	/**
	 * resolveDefaultLevel with empty levels AND empty namespace defaults AND null global default.
	 * Triple-unset edge case -- must return null.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenEverythingIsEmpty_FailClosed(): void {
		$GLOBALS['wgFilePermLevels'] = [];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [];
		$GLOBALS['wgFilePermDefaultLevel'] = null;
		$this->assertNull( Config::resolveDefaultLevel( 6 ) );
	}

	/**
	 * resolveDefaultLevel with empty levels but global default set --
	 * the global default is invalid because no levels exist.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenLevelsEmptyEvenIfGlobalDefaultSet_FailClosed(): void {
		$GLOBALS['wgFilePermLevels'] = [];
		$GLOBALS['wgFilePermNamespaceDefaults'] = [];
		$GLOBALS['wgFilePermDefaultLevel'] = 'public';
		// 'public' is not valid because levels is empty
		$this->assertNull( Config::resolveDefaultLevel( 6 ) );
	}

	/**
	 * isValidLevel uses strict comparison -- no type coercion.
	 *
	 * @covers \FilePermissions\Config::isValidLevel
	 */
	public function testIsValidLevelUsesStrictComparison(): void {
		$GLOBALS['wgFilePermLevels'] = [ 'public', '0', '1' ];
		// '0' is a valid level (exact string match)
		$this->assertTrue( Config::isValidLevel( '0' ) );
		$this->assertTrue( Config::isValidLevel( '1' ) );
		// But only exact string matches work
		$this->assertFalse( Config::isValidLevel( 'false' ) );
	}

	/**
	 * getDefaultLevel returns string type, not coerced value.
	 *
	 * @covers \FilePermissions\Config::getDefaultLevel
	 */
	public function testGetDefaultLevelReturnsExactStringValue(): void {
		$GLOBALS['wgFilePermDefaultLevel'] = '0';
		$this->assertSame( '0', Config::getDefaultLevel() );
		$this->assertIsString( Config::getDefaultLevel() );
	}
}
