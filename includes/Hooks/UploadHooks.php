<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use FilePermissions\Config;
use FilePermissions\PermissionService;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\Hook\UploadFormInitDescriptorHook;
use MediaWiki\Hook\UploadVerifyUploadHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use UploadBase;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Upload hooks for FilePermissions extension.
 *
 * Adds a permission-level dropdown to Special:Upload and stores
 * the selected level in the fileperm_levels table on upload completion.
 *
 * Implements four upload requirements:
 * - UPLD-01: Permission dropdown appears on Special:Upload form
 * - UPLD-02: Dropdown lists all configured levels with group info
 * - UPLD-03: Empty placeholder default; re-upload pre-selects existing level
 * - UPLD-04: Selected level stored in fileperm_levels on upload
 */
class UploadHooks implements
	UploadFormInitDescriptorHook,
	UploadVerifyUploadHook,
	UploadCompleteHook
{
	private PermissionService $permissionService;
	private Config $config;

	public function __construct( PermissionService $permissionService, Config $config ) {
		$this->permissionService = $permissionService;
		$this->config = $config;
	}

	/**
	 * Add permission level dropdown to the upload form.
	 *
	 * @param array &$descriptor HTMLForm descriptor array
	 * @return bool
	 */
	public function onUploadFormInitDescriptor( &$descriptor ): bool {
		// Build options array: placeholder + configured levels with group info
		$options = $this->buildLevelOptions();

		$fieldDescriptor = [
			'type' => 'select',
			'label-message' => 'filepermissions-upload-label',
			'help-message' => 'filepermissions-upload-help',
			'options' => $options,
			'section' => 'description',
			'validation-callback' => [ $this, 'validatePermissionLevel' ],
		];

		// Handle re-upload: pre-select existing level if valid
		$default = $this->resolveReuploadDefault();
		if ( $default !== null ) {
			$fieldDescriptor['default'] = $default;
		}

		$descriptor['FilePermLevel'] = $fieldDescriptor;

		return true;
	}

	/**
	 * Validate permission level selection before upload is stored.
	 *
	 * UploadForm bypasses HTMLForm validation, so validation-callback
	 * on the descriptor is never invoked. This hook enforces the
	 * requirement server-side.
	 *
	 * @param UploadBase $upload
	 * @param User $user
	 * @param ?array $props
	 * @param string $comment
	 * @param string $pageText
	 * @param array|null &$error
	 * @return bool
	 */
	public function onUploadVerifyUpload(
		UploadBase $upload,
		User $user,
		?array $props,
		$comment,
		$pageText,
		&$error
	): bool {
		$request = RequestContext::getMain()->getRequest();
		$level = $request->getText( 'wpFilePermLevel' );

		if ( $level !== '' && !$this->config->isValidLevel( $level ) ) {
			$error = [ 'filepermissions-upload-invalid' ];
			return false;
		}

		if ( $level === '' ) {
			// Attempt to resolve a namespace/global default
			$default = $this->config->resolveDefaultLevel( NS_FILE );
			if ( $default !== null ) {
				// A default is available — allow the upload to proceed.
				// onUploadComplete will apply the resolved default.
				return true;
			}

			// No default configured. Only reject if this is a Special:Upload
			// form submission where the user had a dropdown to choose.
			// wpUploadFile is an HTMLForm field specific to Special:Upload.
			if ( $request->getVal( 'wpUploadFile' ) !== null
				|| $request->getVal( 'wpUploadFileURL' ) !== null
			) {
				$error = [ 'filepermissions-upload-required' ];
				return false;
			}

			// API upload with no default configured — allow without level
			// (grandfathered: file will have no level, treated as unrestricted)
			return true;
		}

		return true;
	}

	/**
	 * Store the selected permission level on upload completion.
	 *
	 * Page creation is deferred in LocalFile::upload() via AutoCommitUpdate,
	 * so the file page may not exist yet when this hook fires. We defer
	 * the permission storage to run after the current transaction commits.
	 *
	 * @param UploadBase $uploadBase The completed upload
	 * @return bool
	 */
	public function onUploadComplete( $uploadBase ): bool {
		$localFile = $uploadBase->getLocalFile();
		if ( $localFile === null ) {
			return true;
		}

		$title = $localFile->getTitle();
		if ( $title === null ) {
			return true;
		}

		$level = RequestContext::getMain()->getRequest()->getText( 'wpFilePermLevel' );

		// If no explicit level provided, resolve namespace/global default
		if ( $level === '' ) {
			$level = $this->config->resolveDefaultLevel( NS_FILE );
		}

		// No level available (neither explicit nor default) — nothing to store
		if ( $level === null || !$this->config->isValidLevel( $level ) ) {
			return true;
		}

		// Defer storage — the file page may not be committed yet
		$dbKey = $title->getDBkey();
		$ns = $title->getNamespace();
		$permissionService = $this->permissionService;

		DeferredUpdates::addCallableUpdate(
			static function () use ( $dbKey, $ns, $level, $permissionService ) {
				try {
					$freshTitle = Title::makeTitle( $ns, $dbKey );
					if ( $freshTitle->getArticleID( IDBAccessObject::READ_LATEST ) === 0 ) {
						return;
					}
					$permissionService->setLevel( $freshTitle, $level );
				} catch ( \Exception $e ) {
					LoggerFactory::getInstance( 'FilePermissions' )->error(
						'Failed to set permission level on upload: {error}',
						[ 'error' => $e->getMessage(), 'page' => "$ns:$dbKey" ]
					);
				}
			}
		);

		return true;
	}

	/**
	 * Validate the permission level selection.
	 *
	 * @param string $value The selected value
	 * @param array $alldata All form data
	 * @param \HTMLForm $form The form object
	 * @return bool|string True if valid, error message string if invalid
	 */
	public function validatePermissionLevel( $value, $alldata, $form ): bool|string {
		if ( $value === null || $value === '' ) {
			return wfMessage( 'filepermissions-upload-required' )->text();
		}

		if ( !$this->config->isValidLevel( $value ) ) {
			return wfMessage( 'filepermissions-upload-invalid' )->text();
		}

		return true;
	}

	/**
	 * Build the options array for the permission level dropdown.
	 *
	 * Format: [ 'label text' => 'value', ... ]
	 * Starts with empty placeholder, then each level with granted groups.
	 *
	 * @return array<string, string> Options for HTMLForm select
	 */
	private function buildLevelOptions(): array {
		$options = [];

		// Empty placeholder requiring selection
		$options[wfMessage( 'filepermissions-upload-choose' )->text()] = '';

		$levelGroupMap = $this->config->getLevelGroupMap();

		foreach ( $levelGroupMap as $level => $groups ) {
			if ( count( $groups ) > 0 ) {
				$label = $level . ' (' . implode( ', ', $groups ) . ')';
			} else {
				$label = $level;
			}
			$options[$label] = $level;
		}

		return $options;
	}

	/**
	 * Resolve the default level for a re-upload.
	 *
	 * Checks if a destination filename is specified and looks up
	 * the existing file's permission level. Only returns a default
	 * if the existing level is still valid in the current config.
	 *
	 * @return string|null The existing level if valid, null otherwise
	 */
	private function resolveReuploadDefault(): ?string {
		$destFile = RequestContext::getMain()->getRequest()->getVal( 'wpDestFile' );
		if ( $destFile === null || $destFile === '' ) {
			return null;
		}

		$title = Title::makeTitleSafe( NS_FILE, $destFile );
		if ( $title === null || $title->getArticleID() === 0 ) {
			return null;
		}

		$existingLevel = $this->permissionService->getLevel( $title );
		if ( $existingLevel === null ) {
			return null;
		}

		// Only pre-select if the level is still valid in current config
		if ( !$this->config->isValidLevel( $existingLevel ) ) {
			return null;
		}

		return $existingLevel;
	}
}
