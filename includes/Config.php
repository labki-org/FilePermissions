<?php

declare( strict_types=1 );

namespace FilePermissions;

use MediaWiki\Config\ServiceOptions;

/**
 * Configuration class for FilePermissions extension.
 *
 * Provides typed access to all configuration variables with fallback defaults.
 * Registered as 'FilePermissions.Config' service via ServiceWiring.
 */
class Config {

	public const CONSTRUCTOR_OPTIONS = [
		'FilePermLevels',
		'FilePermGroupGrants',
		'FilePermDefaultLevel',
		'FilePermNamespaceDefaults',
		'FilePermInvalidConfig',
	];

	private ServiceOptions $options;
	private ?array $levelGroupMapCache = null;

	/**
	 * @param ServiceOptions $options Service options with the 5 config keys
	 */
	public function __construct( ServiceOptions $options ) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
	}

	/**
	 * Get the configured permission levels.
	 *
	 * @return array<string> List of valid permission level names
	 */
	public function getLevels(): array {
		// array_unique: MW merges extension.json defaults with LocalSettings values
		return array_values( array_unique(
			$this->options->get( 'FilePermLevels' ) ?? [ 'public' ]
		) );
	}

	/**
	 * Get the group-to-levels grant mapping.
	 *
	 * @return array<string, array<string>> Map of group names to granted levels
	 */
	public function getGroupGrants(): array {
		return $this->options->get( 'FilePermGroupGrants' ) ?? [];
	}

	/**
	 * Get the global default permission level.
	 *
	 * @return string|null Default level or null if explicit selection required
	 */
	public function getDefaultLevel(): ?string {
		return $this->options->get( 'FilePermDefaultLevel' ) ?? null;
	}

	/**
	 * Get namespace-specific default permission levels.
	 *
	 * @return array<int, string> Map of namespace IDs to default levels
	 */
	public function getNamespaceDefaults(): array {
		return $this->options->get( 'FilePermNamespaceDefaults' ) ?? [];
	}

	/**
	 * Check if configuration validation has failed.
	 *
	 * When true, all permission checks should fail closed (deny access).
	 *
	 * @return bool True if configuration is invalid
	 */
	public function isInvalidConfig(): bool {
		return $this->options->get( 'FilePermInvalidConfig' ) ?? false;
	}

	/**
	 * Get a reverse map of permission levels to the groups that grant each level.
	 *
	 * Expands wildcard (`*`) grants so that a group granting `*` appears under
	 * every configured level.
	 *
	 * @return array<string, array<string>> Map of level => list of group names
	 */
	public function getLevelGroupMap(): array {
		if ( $this->levelGroupMapCache !== null ) {
			return $this->levelGroupMapCache;
		}

		$levels = $this->getLevels();
		$groupGrants = $this->getGroupGrants();

		$map = [];
		foreach ( $levels as $level ) {
			$map[$level] = [];
		}
		foreach ( $groupGrants as $group => $grants ) {
			foreach ( $levels as $level ) {
				if ( in_array( '*', $grants, true ) || in_array( $level, $grants, true ) ) {
					$map[$level][] = $group;
				}
			}
		}

		$this->levelGroupMapCache = $map;
		return $map;
	}

	/**
	 * Check if a permission level is valid.
	 *
	 * @param string $level The level to check
	 * @return bool True if level exists in configured levels
	 */
	public function isValidLevel( string $level ): bool {
		return in_array( $level, $this->getLevels(), true );
	}

	/**
	 * Resolve the default permission level for a given namespace.
	 *
	 * Resolution order:
	 * 1. Namespace-specific default (if valid)
	 * 2. Global default (if valid)
	 * 3. null (require explicit selection)
	 *
	 * @param int $namespace Namespace ID
	 * @return string|null Resolved default level or null if none
	 */
	public function resolveDefaultLevel( int $namespace ): ?string {
		// Check namespace-specific default first
		$namespaceDefaults = $this->getNamespaceDefaults();
		if ( isset( $namespaceDefaults[$namespace] ) ) {
			$level = $namespaceDefaults[$namespace];
			if ( $this->isValidLevel( $level ) ) {
				return $level;
			}
		}

		// Fall back to global default
		$globalDefault = $this->getDefaultLevel();
		if ( $globalDefault !== null && $this->isValidLevel( $globalDefault ) ) {
			return $globalDefault;
		}

		// No default configured - require explicit selection
		return null;
	}
}
