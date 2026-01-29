<?php
/**
 * Service wiring for FilePermissions extension.
 *
 * Registers services for dependency injection via MediaWikiServices.
 *
 * @file
 */

use FilePermissions\PermissionService;
use MediaWiki\MediaWikiServices;

return [
	'FilePermissions.PermissionService' => static function (
		MediaWikiServices $services
	): PermissionService {
		return new PermissionService(
			$services->getConnectionProvider(),
			$services->getUserGroupManager()
		);
	},
];
