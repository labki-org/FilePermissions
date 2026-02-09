<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Unit;

use FilePermissions\Config;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
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
	 * Create a Config instance with the given configuration values.
	 *
	 * @param array $overrides Config values to override defaults
	 * @return Config
	 */
	private function createConfig( array $overrides = [] ): Config {
		$defaults = [
			'FilePermLevels' => [ 'public', 'internal', 'confidential' ],
			'FilePermGroupGrants' => [
				'sysop' => [ '*' ],
				'user' => [ 'public', 'internal' ],
			],
			'FilePermDefaultLevel' => null,
			'FilePermNamespaceDefaults' => [],
			'FilePermInvalidConfig' => false,
		];

		$values = array_merge( $defaults, $overrides );

		return new Config(
			new ServiceOptions(
				Config::CONSTRUCTOR_OPTIONS,
				new HashConfig( $values )
			)
		);
	}

	// =========================================================================
	// UNIT-01: Valid configuration
	// =========================================================================

	/**
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsReturnsConfiguredLevels(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal', 'confidential' ],
		] );
		$this->assertSame(
			[ 'public', 'internal', 'confidential' ],
			$config->getLevels()
		);
	}

	/**
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsDeduplicatesWhenMwMergesCauseDuplicates(): void {
		// MediaWiki merges extension.json defaults with LocalSettings values,
		// which can result in duplicate entries.
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal', 'confidential', 'public', 'internal' ],
		] );
		$result = $config->getLevels();
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
		$config = $this->createConfig( [
			'FilePermGroupGrants' => $grants,
		] );
		$this->assertSame( $grants, $config->getGroupGrants() );
	}

	/**
	 * @covers \FilePermissions\Config::getDefaultLevel
	 */
	public function testGetDefaultLevelReturnsConfiguredDefault(): void {
		$config = $this->createConfig( [
			'FilePermDefaultLevel' => 'internal',
		] );
		$this->assertSame( 'internal', $config->getDefaultLevel() );
	}

	/**
	 * @covers \FilePermissions\Config::getDefaultLevel
	 */
	public function testGetDefaultLevelReturnsNullWhenSetToNull(): void {
		$config = $this->createConfig( [
			'FilePermDefaultLevel' => null,
		] );
		$this->assertNull( $config->getDefaultLevel() );
	}

	/**
	 * @covers \FilePermissions\Config::getNamespaceDefaults
	 */
	public function testGetNamespaceDefaultsReturnsConfiguredNamespaceMap(): void {
		$nsDefaults = [ 6 => 'internal', 0 => 'public' ];
		$config = $this->createConfig( [
			'FilePermNamespaceDefaults' => $nsDefaults,
		] );
		$this->assertSame( $nsDefaults, $config->getNamespaceDefaults() );
	}

	/**
	 * @covers \FilePermissions\Config::isValidLevel
	 */
	public function testIsValidLevelReturnsTrueForConfiguredLevel(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal', 'confidential' ],
		] );
		$this->assertTrue( $config->isValidLevel( 'internal' ) );
	}

	/**
	 * @covers \FilePermissions\Config::isValidLevel
	 */
	public function testIsValidLevelReturnsFalseForUnconfiguredLevel(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal', 'confidential' ],
		] );
		$this->assertFalse( $config->isValidLevel( 'top-secret' ) );
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
		$config = $this->createConfig( [
			'FilePermLevels' => $configuredLevels,
		] );
		$this->assertSame( $expected, $config->isValidLevel( $levelToCheck ) );
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
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal', 'confidential' ],
			'FilePermNamespaceDefaults' => [ 6 => 'confidential' ],
			'FilePermDefaultLevel' => 'public',
		] );
		$this->assertSame( 'confidential', $config->resolveDefaultLevel( 6 ) );
	}

	/**
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelFallsBackToGlobalDefault(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal' ],
			'FilePermNamespaceDefaults' => [],
			'FilePermDefaultLevel' => 'internal',
		] );
		$this->assertSame( 'internal', $config->resolveDefaultLevel( 6 ) );
	}

	/**
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenNoDefaultsConfigured(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal' ],
			'FilePermNamespaceDefaults' => [],
			'FilePermDefaultLevel' => null,
		] );
		$this->assertNull( $config->resolveDefaultLevel( 6 ) );
	}

	/**
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelSkipsInvalidNamespaceDefaultAndFallsToGlobal(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal' ],
			// Namespace default references a level NOT in the configured levels
			'FilePermNamespaceDefaults' => [ 6 => 'nonexistent' ],
			'FilePermDefaultLevel' => 'public',
		] );
		$this->assertSame( 'public', $config->resolveDefaultLevel( 6 ) );
	}

	/**
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenGlobalDefaultIsInvalidLevel(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal' ],
			'FilePermNamespaceDefaults' => [],
			// Global default references a level NOT in the configured levels
			'FilePermDefaultLevel' => 'does-not-exist',
		] );
		$this->assertNull( $config->resolveDefaultLevel( 6 ) );
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
		$config = $this->createConfig( [
			'FilePermInvalidConfig' => false,
		] );
		$this->assertFalse( $config->isInvalidConfig() );
	}

	/**
	 * When config validation fails, isInvalidConfig signals fail-closed mode.
	 *
	 * @covers \FilePermissions\Config::isInvalidConfig
	 */
	public function testIsInvalidConfigReturnsTrueWhenConfigFlaggedInvalid(): void {
		$config = $this->createConfig( [
			'FilePermInvalidConfig' => true,
		] );
		$this->assertTrue( $config->isInvalidConfig() );
	}

	/**
	 * When levels config is null, fallback to ['public'] only.
	 * This is a safe fallback: public is the least-restrictive level,
	 * meaning no access beyond public is granted.
	 *
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsReturnsSafeFallbackWhenNull(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => null,
		] );
		$this->assertSame( [ 'public' ], $config->getLevels() );
	}

	/**
	 * Fail-closed: when group grants are null, no grants means no access.
	 *
	 * @covers \FilePermissions\Config::getGroupGrants
	 */
	public function testGetGroupGrantsReturnsEmptyArrayWhenNull_NoGrantsMeansNoAccess(): void {
		$config = $this->createConfig( [
			'FilePermGroupGrants' => null,
		] );
		$this->assertSame( [], $config->getGroupGrants() );
	}

	/**
	 * Fail-closed: when namespace defaults are null, no defaults means
	 * no automatic permission assignment.
	 *
	 * @covers \FilePermissions\Config::getNamespaceDefaults
	 */
	public function testGetNamespaceDefaultsReturnsEmptyArrayWhenNull_NoAutoAssignment(): void {
		$config = $this->createConfig( [
			'FilePermNamespaceDefaults' => null,
		] );
		$this->assertSame( [], $config->getNamespaceDefaults() );
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
		$config = $this->createConfig( [
			'FilePermLevels' => [],
		] );
		$this->assertSame( [], $config->getLevels() );
	}

	/**
	 * Single level configured: returns single-element array.
	 *
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsWithSingleLevelReturnsSingleElementArray(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'restricted' ],
		] );
		$this->assertSame( [ 'restricted' ], $config->getLevels() );
	}

	/**
	 * Many levels: system handles 5+ levels correctly.
	 *
	 * @covers \FilePermissions\Config::getLevels
	 */
	public function testGetLevelsWithManyLevelsReturnsAllLevels(): void {
		$levels = [ 'public', 'internal', 'confidential', 'secret', 'top-secret', 'classified' ];
		$config = $this->createConfig( [
			'FilePermLevels' => $levels,
		] );
		$this->assertSame( $levels, $config->getLevels() );
		$this->assertCount( 6, $config->getLevels() );
	}

	/**
	 * Group exists but has empty grants array -- group gets NO access.
	 *
	 * @covers \FilePermissions\Config::getGroupGrants
	 */
	public function testGetGroupGrantsWithGroupGrantedEmptyArray(): void {
		$config = $this->createConfig( [
			'FilePermGroupGrants' => [ 'viewer' => [] ],
		] );
		$result = $config->getGroupGrants();
		$this->assertSame( [], $result['viewer'] );
	}

	/**
	 * Group has wildcard '*' grant -- data structure preserved for
	 * downstream permission checks.
	 *
	 * @covers \FilePermissions\Config::getGroupGrants
	 */
	public function testGetGroupGrantsWithWildcardGrant(): void {
		$config = $this->createConfig( [
			'FilePermGroupGrants' => [ 'sysop' => [ '*' ] ],
		] );
		$result = $config->getGroupGrants();
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
		$config = $this->createConfig( [
			'FilePermGroupGrants' => $grants,
		] );
		$result = $config->getGroupGrants();
		$this->assertSame( $grants, $result );
		$this->assertCount( 3, $result );
	}

	/**
	 * Fail-closed: when levels array is empty, nothing is valid.
	 *
	 * @covers \FilePermissions\Config::isValidLevel
	 */
	public function testIsValidLevelReturnsFalseWhenLevelsEmpty_NothingIsValid(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [],
		] );
		$this->assertFalse( $config->isValidLevel( 'public' ) );
	}

	/**
	 * Semantic error: namespace default references a nonexistent level.
	 * Must NOT crash -- should fall through gracefully.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelWithNamespaceDefaultReferencingNonexistentLevel(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public' ],
			'FilePermNamespaceDefaults' => [ 6 => 'nonexistent-level' ],
			'FilePermDefaultLevel' => 'public',
		] );
		// Should skip invalid namespace default and fall back to global
		$this->assertSame( 'public', $config->resolveDefaultLevel( 6 ) );
	}

	/**
	 * Semantic error: global default references a nonexistent level.
	 * Must return null -- no silent escalation.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelWithGlobalDefaultReferencingNonexistentLevel_ReturnsNull(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal' ],
			'FilePermNamespaceDefaults' => [],
			'FilePermDefaultLevel' => 'secret',
		] );
		$this->assertNull( $config->resolveDefaultLevel( 6 ) );
	}

	/**
	 * Both namespace and global defaults are invalid -- returns null (fail-closed).
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenBothDefaultsAreInvalid_FailClosed(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public' ],
			'FilePermNamespaceDefaults' => [ 6 => 'bogus' ],
			'FilePermDefaultLevel' => 'also-bogus',
		] );
		$this->assertNull( $config->resolveDefaultLevel( 6 ) );
	}

	/**
	 * Namespace without a specific default uses global default.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelForUnconfiguredNamespaceUsesGlobalDefault(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal' ],
			'FilePermNamespaceDefaults' => [ 6 => 'internal' ],
			'FilePermDefaultLevel' => 'public',
		] );
		// Namespace 0 has no specific default, should get global
		$this->assertSame( 'public', $config->resolveDefaultLevel( 0 ) );
	}

	/**
	 * resolveDefaultLevel with empty levels AND empty namespace defaults AND null global default.
	 * Triple-unset edge case -- must return null.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenEverythingIsEmpty_FailClosed(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [],
			'FilePermNamespaceDefaults' => [],
			'FilePermDefaultLevel' => null,
		] );
		$this->assertNull( $config->resolveDefaultLevel( 6 ) );
	}

	/**
	 * resolveDefaultLevel with empty levels but global default set --
	 * the global default is invalid because no levels exist.
	 *
	 * @covers \FilePermissions\Config::resolveDefaultLevel
	 */
	public function testResolveDefaultLevelReturnsNullWhenLevelsEmptyEvenIfGlobalDefaultSet_FailClosed(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [],
			'FilePermNamespaceDefaults' => [],
			'FilePermDefaultLevel' => 'public',
		] );
		// 'public' is not valid because levels is empty
		$this->assertNull( $config->resolveDefaultLevel( 6 ) );
	}

	/**
	 * isValidLevel uses strict comparison -- no type coercion.
	 *
	 * @covers \FilePermissions\Config::isValidLevel
	 */
	public function testIsValidLevelUsesStrictComparison(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', '0', '1' ],
		] );
		// '0' is a valid level (exact string match)
		$this->assertTrue( $config->isValidLevel( '0' ) );
		$this->assertTrue( $config->isValidLevel( '1' ) );
		// But only exact string matches work
		$this->assertFalse( $config->isValidLevel( 'false' ) );
	}

	/**
	 * getDefaultLevel returns string type, not coerced value.
	 *
	 * @covers \FilePermissions\Config::getDefaultLevel
	 */
	public function testGetDefaultLevelReturnsExactStringValue(): void {
		$config = $this->createConfig( [
			'FilePermDefaultLevel' => '0',
		] );
		$this->assertSame( '0', $config->getDefaultLevel() );
		$this->assertIsString( $config->getDefaultLevel() );
	}

	/**
	 * getLevelGroupMap caches the result on the instance.
	 *
	 * @covers \FilePermissions\Config::getLevelGroupMap
	 */
	public function testGetLevelGroupMapReturnsCachedResult(): void {
		$config = $this->createConfig( [
			'FilePermLevels' => [ 'public', 'internal' ],
			'FilePermGroupGrants' => [
				'sysop' => [ '*' ],
				'viewer' => [ 'public' ],
			],
		] );
		$result1 = $config->getLevelGroupMap();
		$result2 = $config->getLevelGroupMap();
		$this->assertSame( $result1, $result2 );
		$this->assertSame( [ 'sysop', 'viewer' ], $result1['public'] );
		$this->assertSame( [ 'sysop' ], $result1['internal'] );
	}
}
