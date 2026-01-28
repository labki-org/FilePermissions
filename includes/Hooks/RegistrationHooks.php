<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use MediaWiki\Logger\LoggerFactory;

/**
 * Hooks that run during extension registration.
 *
 * The onRegistration callback is invoked immediately after LocalSettings.php
 * is processed, before services are instantiated. This is the correct time
 * to validate configuration and set fail-closed flags.
 */
class RegistrationHooks {

	/**
	 * Callback invoked during extension registration.
	 *
	 * Validates configuration and sets the InvalidConfig flag on error.
	 * Does NOT throw exceptions - the wiki continues to load but all
	 * permission checks will deny access (fail-closed behavior).
	 */
	public static function onRegistration(): void {
		$errors = self::validateConfiguration();

		if ( !empty( $errors ) ) {
			// Set fail-closed flag - permission checks will deny all access
			$GLOBALS['wgFilePermInvalidConfig'] = true;

			$logger = LoggerFactory::getInstance( 'FilePermissions' );
			foreach ( $errors as $error ) {
				$logger->warning( 'FilePermissions: Invalid configuration - {error}', [
					'error' => $error
				] );
			}
		}
	}

	/**
	 * Validate the extension configuration.
	 *
	 * @return array<string> List of validation error messages (empty if valid)
	 */
	private static function validateConfiguration(): array {
		global $wgFilePermLevels, $wgFilePermGroupGrants,
			$wgFilePermDefaultLevel, $wgFilePermNamespaceDefaults;

		$errors = [];

		// Validate $wgFilePermLevels - must be non-empty array of non-empty strings
		if ( !is_array( $wgFilePermLevels ) || empty( $wgFilePermLevels ) ) {
			$errors[] = '$wgFilePermLevels must be a non-empty array';
		} else {
			foreach ( $wgFilePermLevels as $index => $level ) {
				if ( !is_string( $level ) || $level === '' ) {
					$errors[] = "\$wgFilePermLevels[$index] must be a non-empty string";
					break;
				}
			}
		}

		// Build valid levels lookup (for grant validation)
		$validLevels = [];
		if ( is_array( $wgFilePermLevels ) ) {
			$validLevels = array_flip( $wgFilePermLevels );
		}

		// Validate $wgFilePermGroupGrants - values must be arrays with valid levels
		if ( isset( $wgFilePermGroupGrants ) && is_array( $wgFilePermGroupGrants ) ) {
			foreach ( $wgFilePermGroupGrants as $group => $levels ) {
				if ( !is_array( $levels ) ) {
					$errors[] = "Grant for group '$group' must be an array";
					continue;
				}
				foreach ( $levels as $level ) {
					// Wildcard '*' is always valid
					if ( $level === '*' ) {
						continue;
					}
					if ( !isset( $validLevels[$level] ) ) {
						$errors[] = "Grant for group '$group' references unknown level '$level'";
					}
				}
			}
		}

		// Validate $wgFilePermDefaultLevel - if set, must be a valid level
		if ( $wgFilePermDefaultLevel !== null ) {
			if ( !is_string( $wgFilePermDefaultLevel ) ) {
				$errors[] = '$wgFilePermDefaultLevel must be a string or null';
			} elseif ( !isset( $validLevels[$wgFilePermDefaultLevel] ) ) {
				$errors[] = "\$wgFilePermDefaultLevel references unknown level '$wgFilePermDefaultLevel'";
			}
		}

		// Validate $wgFilePermNamespaceDefaults - values must be valid levels
		if ( isset( $wgFilePermNamespaceDefaults ) && is_array( $wgFilePermNamespaceDefaults ) ) {
			foreach ( $wgFilePermNamespaceDefaults as $namespace => $level ) {
				if ( !is_int( $namespace ) ) {
					$errors[] = "\$wgFilePermNamespaceDefaults keys must be namespace IDs (integers)";
					break;
				}
				if ( !is_string( $level ) ) {
					$errors[] = "\$wgFilePermNamespaceDefaults[$namespace] must be a string";
					continue;
				}
				if ( !isset( $validLevels[$level] ) ) {
					$errors[] = "\$wgFilePermNamespaceDefaults[$namespace] references unknown level '$level'";
				}
			}
		}

		return $errors;
	}
}
