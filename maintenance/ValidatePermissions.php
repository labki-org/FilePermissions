<?php

declare( strict_types=1 );

namespace FilePermissions\Maintenance;

use FilePermissions\Config;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

/**
 * Maintenance script to detect and repair orphaned permission levels.
 *
 * Permission levels are stored as strings in page_props. If an admin
 * renames or removes a level from $wgFilePermLevels, existing rows
 * still reference the old name. This script finds those mismatches
 * and optionally replaces them.
 *
 * Usage:
 *   # Report only (dry-run):
 *   php maintenance/run.php extensions/FilePermissions/maintenance/ValidatePermissions.php
 *
 *   # Replace an old level with a new one:
 *   php maintenance/run.php extensions/FilePermissions/maintenance/ValidatePermissions.php \
 *       --fix old_level:new_level
 */
class ValidatePermissions extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Detect and optionally repair orphaned file permission levels.'
		);
		$this->addOption(
			'fix',
			'Replace an orphaned level. Format: old_level:new_level',
			false,
			true
		);
		$this->requireExtension( 'FilePermissions' );
	}

	public function execute(): void {
		$validLevels = Config::getLevels();
		$this->output( "Valid permission levels: " . implode( ', ', $validLevels ) . "\n\n" );

		$orphans = $this->findOrphans( $validLevels );

		if ( count( $orphans ) === 0 ) {
			$this->output( "No orphaned permission levels found.\n" );
			return;
		}

		$this->reportOrphans( $orphans, $validLevels );

		$fix = $this->getOption( 'fix' );
		if ( $fix === null ) {
			$this->output(
				"\nTo repair, re-run with --fix old_level:new_level\n"
			);
			return;
		}

		$this->applyFix( $fix, $orphans, $validLevels );
	}

	/**
	 * Find all page_props rows whose fileperm_level value is not in the
	 * current valid set.
	 *
	 * @param array<string> $validLevels
	 * @return array<array{page_id: int, title: string, level: string}>
	 */
	private function findOrphans( array $validLevels ): array {
		$dbr = $this->getReplicaDB();

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'pp_page', 'pp_value', 'page_title' ] )
			->from( 'page_props' )
			->join( 'page', null, 'page_id = pp_page' )
			->where( [
				'pp_propname' => 'fileperm_level',
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$orphans = [];
		foreach ( $rows as $row ) {
			if ( !in_array( $row->pp_value, $validLevels, true ) ) {
				$orphans[] = [
					'page_id' => (int)$row->pp_page,
					'title' => $row->page_title,
					'level' => $row->pp_value,
				];
			}
		}

		return $orphans;
	}

	/**
	 * Print a table of orphaned entries.
	 *
	 * @param array<array{page_id: int, title: string, level: string}> $orphans
	 * @param array<string> $validLevels
	 */
	private function reportOrphans( array $orphans, array $validLevels ): void {
		$this->output( "Found " . count( $orphans ) . " orphaned permission level(s):\n" );

		foreach ( $orphans as $entry ) {
			$this->output(
				"  Page {$entry['page_id']} (File:{$entry['title']}): "
				. "\"{$entry['level']}\" is not in ["
				. implode( ', ', $validLevels ) . "]\n"
			);
		}
	}

	/**
	 * Parse the --fix value and apply the replacement.
	 *
	 * @param string $fix The raw --fix option value (old_level:new_level)
	 * @param array<array{page_id: int, title: string, level: string}> $orphans
	 * @param array<string> $validLevels
	 */
	private function applyFix(
		string $fix,
		array $orphans,
		array $validLevels
	): void {
		$parts = explode( ':', $fix, 2 );
		if ( count( $parts ) !== 2 || $parts[0] === '' || $parts[1] === '' ) {
			$this->fatalError( "Invalid --fix format. Expected old_level:new_level\n" );
		}

		[ $oldLevel, $newLevel ] = $parts;

		if ( !in_array( $newLevel, $validLevels, true ) ) {
			$this->fatalError(
				"Target level \"$newLevel\" is not a valid level. "
				. "Valid levels: " . implode( ', ', $validLevels ) . "\n"
			);
		}

		$matching = array_filter(
			$orphans,
			static fn ( array $e ): bool => $e['level'] === $oldLevel
		);

		if ( count( $matching ) === 0 ) {
			$this->output(
				"No orphaned entries found with level \"$oldLevel\". Nothing to fix.\n"
			);
			return;
		}

		$this->output(
			"\nUpdating " . count( $matching )
			. " entry/entries from \"$oldLevel\" to \"$newLevel\"...\n"
		);

		$permService = MediaWikiServices::getInstance()
			->getService( 'FilePermissions.PermissionService' );

		$updated = 0;
		foreach ( $matching as $entry ) {
			$title = \MediaWiki\Title\Title::newFromID( $entry['page_id'] );
			if ( $title === null ) {
				$this->output(
					"  Skipping page {$entry['page_id']}: could not load title\n"
				);
				continue;
			}

			$permService->setLevel( $title, $newLevel );
			$updated++;
			$this->output(
				"  Updated File:{$entry['title']} → \"$newLevel\"\n"
			);
		}

		$this->output( "Done. Updated $updated entry/entries.\n" );
	}
}

$maintClass = ValidatePermissions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
