<?php
/**
 * Service wiring for FilePermissions extension.
 *
 * Registers services for dependency injection via MediaWikiServices.
 *
 * @file
 */

use FilePermissions\Config;
use FilePermissions\PermissionService;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;

return [
	'FilePermissions.Config' => static function (
		MediaWikiServices $services
	): Config {
		return new Config(
			new ServiceOptions( Config::CONSTRUCTOR_OPTIONS, $services->getMainConfig() )
		);
	},

	'FilePermissions.PermissionService' => static function (
		MediaWikiServices $services
	): PermissionService {
		return new PermissionService(
			$services->getConnectionProvider(),
			$services->getUserGroupManager(),
			$services->getService( 'FilePermissions.Config' )
		);
	},
];
