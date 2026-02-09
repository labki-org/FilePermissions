<?php

declare( strict_types=1 );

namespace FilePermissions;

/**
 * Static configuration class for FilePermissions extension.
 *
 * Provides typed access to all configuration variables with fallback defaults.
 */
class Config {

	/**
	 * Get the configured permission levels.
	 *
	 * @return array<string> List of valid permission level names
	 */
	public static function getLevels(): array {
		global $wgFilePermLevels;
		// array_unique: MW merges extension.json defaults with LocalSettings values
		return array_values( array_unique( $wgFilePermLevels ?? [ 'public' ] ) );
	}

	/**
	 * Get the group-to-levels grant mapping.
	 *
	 * @return array<string, array<string>> Map of group names to granted levels
	 */
	public static function getGroupGrants(): array {
		global $wgFilePermGroupGrants;
		return $wgFilePermGroupGrants ?? [];
	}

	/**
	 * Get the global default permission level.
	 *
	 * @return string|null Default level or null if explicit selection required
	 */
	public static function getDefaultLevel(): ?string {
		global $wgFilePermDefaultLevel;
		return $wgFilePermDefaultLevel ?? null;
	}

	/**
	 * Get namespace-specific default permission levels.
	 *
	 * @return array<int, string> Map of namespace IDs to default levels
	 */
	public static function getNamespaceDefaults(): array {
		global $wgFilePermNamespaceDefaults;
		return $wgFilePermNamespaceDefaults ?? [];
	}

	/**
	 * Check if configuration validation has failed.
	 *
	 * When true, all permission checks should fail closed (deny access).
	 *
	 * @return bool True if configuration is invalid
	 */
	public static function isInvalidConfig(): bool {
		global $wgFilePermInvalidConfig;
		return $wgFilePermInvalidConfig ?? false;
	}

	/**
	 * Get a reverse map of permission levels to the groups that grant each level.
	 *
	 * Expands wildcard (`*`) grants so that a group granting `*` appears under
	 * every configured level.
	 *
	 * @return array<string, array<string>> Map of level => list of group names
	 */
	public static function getLevelGroupMap(): array {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}

		$levels = self::getLevels();
		$groupGrants = self::getGroupGrants();

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

		$cache = $map;
		return $map;
	}

	/**
	 * Check if a permission level is valid.
	 *
	 * @param string $level The level to check
	 * @return bool True if level exists in configured levels
	 */
	public static function isValidLevel( string $level ): bool {
		return in_array( $level, self::getLevels(), true );
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
	public static function resolveDefaultLevel( int $namespace ): ?string {
		// Check namespace-specific default first
		$namespaceDefaults = self::getNamespaceDefaults();
		if ( isset( $namespaceDefaults[$namespace] ) ) {
			$level = $namespaceDefaults[$namespace];
			if ( self::isValidLevel( $level ) ) {
				return $level;
			}
		}

		// Fall back to global default
		$globalDefault = self::getDefaultLevel();
		if ( $globalDefault !== null && self::isValidLevel( $globalDefault ) ) {
			return $globalDefault;
		}

		// No default configured - require explicit selection
		return null;
	}
}
