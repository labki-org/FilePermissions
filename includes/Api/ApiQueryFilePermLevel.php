<?php

declare( strict_types=1 );

namespace FilePermissions\Api;

use FilePermissions\PermissionService;
use MediaWiki\Api\ApiQueryBase;

/**
 * API prop module that exposes file permission levels.
 *
 * Registered as prop=fileperm in extension.json. Returns the permission
 * level from the fileperm_levels table via PermissionService::getLevel().
 */
class ApiQueryFilePermLevel extends ApiQueryBase {

	private PermissionService $permissionService;

	/**
	 * @param \MediaWiki\Api\ApiQuery $queryModule
	 * @param string $moduleName
	 * @param PermissionService $permissionService
	 */
	public function __construct( $queryModule, $moduleName, PermissionService $permissionService ) {
		parent::__construct( $queryModule, $moduleName, 'fp' );
		$this->permissionService = $permissionService;
	}

	public function execute() {
		$pages = $this->getPageSet()->getGoodPages();

		foreach ( $pages as $pageId => $title ) {
			$level = $this->permissionService->getLevel( $title );
			if ( $level !== null ) {
				$this->getResult()->addValue(
					[ 'query', 'pages', $pageId ],
					'fileperm_level',
					$level
				);
			}
		}
	}

	/**
	 * @param array $params
	 * @return string
	 */
	public function getCacheMode( $params ) {
		return 'private';
	}
}
