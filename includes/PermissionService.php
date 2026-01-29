<?php

declare( strict_types=1 );

namespace FilePermissions;

use InvalidArgumentException;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserGroupManager;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Core permission service for FilePermissions extension.
 *
 * Provides the API for storing, retrieving, and checking file permissions.
 * Uses PageProps for storage and group-based grants for access control.
 */
class PermissionService {

	private const PROP_NAME = 'fileperm_level';

	private IConnectionProvider $dbProvider;
	private UserGroupManager $userGroupManager;

	/** @var array<int, ?string> In-process cache of page ID → permission level */
	private array $levelCache = [];

	/**
	 * @param IConnectionProvider $dbProvider Database connection provider
	 * @param UserGroupManager $userGroupManager User group manager
	 */
	public function __construct(
		IConnectionProvider $dbProvider,
		UserGroupManager $userGroupManager
	) {
		$this->dbProvider = $dbProvider;
		$this->userGroupManager = $userGroupManager;
	}

	/**
	 * Get the permission level for a file.
	 *
	 * @param Title $title The file title
	 * @return string|null The permission level, or null if not set/not applicable
	 */
	public function getLevel( Title $title ): ?string {
		// Only NS_FILE namespace applies
		if ( $title->getNamespace() !== NS_FILE ) {
			return null;
		}

		// Page must exist
		$pageId = $title->getArticleID();
		if ( $pageId === 0 ) {
			return null;
		}

		if ( array_key_exists( $pageId, $this->levelCache ) ) {
			return $this->levelCache[$pageId];
		}

		// Query page_props for the permission level
		$dbr = $this->dbProvider->getReplicaDatabase();
		$row = $dbr->newSelectQueryBuilder()
			->select( 'pp_value' )
			->from( 'page_props' )
			->where( [
				'pp_page' => $pageId,
				'pp_propname' => self::PROP_NAME,
			] )
			->caller( __METHOD__ )
			->fetchRow();

		$level = $row === false ? null : $row->pp_value;
		$this->levelCache[$pageId] = $level;

		return $level;
	}

	/**
	 * Set the permission level for a file.
	 *
	 * @param Title $title The file title
	 * @param string $level The permission level to set
	 * @throws InvalidArgumentException If page doesn't exist or level is invalid
	 */
	public function setLevel( Title $title, string $level ): void {
		$pageId = $title->getArticleID();
		if ( $pageId === 0 ) {
			throw new InvalidArgumentException( 'Cannot set permission level: page does not exist' );
		}

		if ( !Config::isValidLevel( $level ) ) {
			throw new InvalidArgumentException(
				"Invalid permission level: $level. Valid levels: " .
				implode( ', ', Config::getLevels() )
			);
		}

		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newReplaceQueryBuilder()
			->replaceInto( 'page_props' )
			->uniqueIndexFields( [ 'pp_page', 'pp_propname' ] )
			->row( [
				'pp_page' => $pageId,
				'pp_propname' => self::PROP_NAME,
				'pp_value' => $level,
				'pp_sortkey' => null,
			] )
			->caller( __METHOD__ )
			->execute();

		$this->levelCache[$pageId] = $level;
	}

	/**
	 * Remove the permission level for a file.
	 *
	 * @param Title $title The file title
	 */
	public function removeLevel( Title $title ): void {
		$pageId = $title->getArticleID();
		if ( $pageId === 0 ) {
			// Nothing to remove if page doesn't exist
			return;
		}

		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'page_props' )
			->where( [
				'pp_page' => $pageId,
				'pp_propname' => self::PROP_NAME,
			] )
			->caller( __METHOD__ )
			->execute();

		$this->levelCache[$pageId] = null;
	}

	/**
	 * Check if a user can access files at a given permission level.
	 *
	 * @param UserIdentity $user The user to check
	 * @param string $level The permission level to check
	 * @return bool True if user has access to this level
	 */
	public function canUserAccessLevel( UserIdentity $user, string $level ): bool {
		// Fail closed on invalid config
		if ( Config::isInvalidConfig() ) {
			return false;
		}

		$userGroups = $this->userGroupManager->getUserEffectiveGroups( $user );
		$groupGrants = Config::getGroupGrants();

		foreach ( $userGroups as $group ) {
			if ( !isset( $groupGrants[$group] ) ) {
				continue;
			}

			$grants = $groupGrants[$group];

			// Wildcard grants access to all levels
			if ( in_array( '*', $grants, true ) ) {
				return true;
			}

			// Check for specific level grant
			if ( in_array( $level, $grants, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the effective permission level for a file.
	 *
	 * Returns the explicit level if set, otherwise falls back to the
	 * namespace default (or global default). Returns null if no level
	 * applies (unrestricted file).
	 *
	 * @param Title $title The file title
	 * @return string|null The resolved permission level, or null if unrestricted
	 */
	public function getEffectiveLevel( Title $title ): ?string {
		$level = $this->getLevel( $title );

		if ( $level === null ) {
			$level = Config::resolveDefaultLevel( $title->getNamespace() );
		}

		return $level;
	}

	/**
	 * Check if a user can access a specific file.
	 *
	 * @param UserIdentity $user The user to check
	 * @param Title $title The file title
	 * @return bool True if user can access this file
	 */
	public function canUserAccessFile( UserIdentity $user, Title $title ): bool {
		$level = $this->getEffectiveLevel( $title );

		// If no level applies, treat as unrestricted.
		// This handles grandfathered files that predate the extension.
		if ( $level === null ) {
			return true;
		}

		return $this->canUserAccessLevel( $user, $level );
	}
}
