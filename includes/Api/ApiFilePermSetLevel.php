<?php

declare( strict_types=1 );

namespace FilePermissions\Api;

use FilePermissions\Config;
use FilePermissions\PermissionService;
use ManualLogEntry;
use MediaWiki\Api\ApiBase;
use MediaWiki\Title\Title;

/**
 * API module for setting file permission levels.
 *
 * Provides a CSRF-protected write endpoint that validates the target page,
 * stores the new permission level via PermissionService, and logs the
 * change to Special:Log/fileperm.
 *
 * Registered as action=fileperm-set-level in extension.json.
 */
class ApiFilePermSetLevel extends ApiBase {

	private PermissionService $permissionService;

	/**
	 * @param \MediaWiki\Api\ApiMain $mainModule
	 * @param string $moduleName
	 * @param PermissionService $permissionService
	 */
	public function __construct( $mainModule, $moduleName, PermissionService $permissionService ) {
		parent::__construct( $mainModule, $moduleName );
		$this->permissionService = $permissionService;
	}

	/**
	 * Execute the API action: validate inputs, update the permission level, and log the change.
	 *
	 * Requires the `edit-fileperm` user right. Stores the new level in PageProps
	 * via PermissionService and publishes an audit log entry to Special:Log/fileperm.
	 *
	 * @throws \MediaWiki\Api\ApiUsageException If the user lacks `edit-fileperm`,
	 *   the title does not exist, or the level is not in $wgFilePermLevels.
	 */
	public function execute() {
		$this->checkUserRightsAny( 'edit-fileperm' );

		if ( $this->getUser()->pingLimiter( 'fileperm-setlevel' ) ) {
			$this->dieWithError( 'apierror-ratelimited' );
		}

		$params = $this->extractRequestParams();
		$title = Title::newFromText( $params['title'], NS_FILE );

		if ( !$title || !$title->exists() ) {
			$this->dieWithError( 'filepermissions-api-nosuchpage' );
		}

		if ( $title->getNamespace() !== NS_FILE ) {
			$this->dieWithError( 'filepermissions-api-nosuchpage' );
		}

		$newLevel = $params['level'];
		$oldLevel = $this->permissionService->getLevel( $title );

		$this->permissionService->setLevel( $title, $newLevel );

		// Audit log to Special:Log/fileperm
		$logEntry = new ManualLogEntry( 'fileperm', 'change' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $title );
		$logEntry->setParameters( [
			'4::oldlevel' => $oldLevel ?? '(none)',
			'5::newlevel' => $newLevel,
		] );
		$logid = $logEntry->insert();
		$logEntry->publish( $logid );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'level' => $newLevel,
		] );
	}

	/** @return string */
	public function needsToken() {
		return 'csrf';
	}

	/** @return bool */
	public function mustBePosted() {
		return true;
	}

	/** @return bool */
	public function isWriteMode() {
		return true;
	}

	/** @return array */
	public function getAllowedParams() {
		return [
			'title' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			],
			'level' => [
				ApiBase::PARAM_TYPE => Config::getLevels(),
				ApiBase::PARAM_REQUIRED => true,
			],
		];
	}
}
