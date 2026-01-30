<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param \MediaWiki\Installer\DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../../sql';

		$updater->addExtensionTable(
			'fileperm_levels',
			"$dir/{$updater->getDB()->getType()}/tables-generated.sql"
		);
	}
}
