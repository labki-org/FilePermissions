<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use FilePermissions\Config;
use FilePermissions\PermissionService;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\Hook\UploadFormInitDescriptorHook;
use MediaWiki\Title\Title;

/**
 * Upload hooks for FilePermissions extension.
 *
 * Adds a permission-level dropdown to Special:Upload and stores
 * the selected level in PageProps on upload completion.
 *
 * Implements four upload requirements:
 * - UPLD-01: Permission dropdown appears on Special:Upload form
 * - UPLD-02: Dropdown lists all configured levels with group info
 * - UPLD-03: Empty placeholder default; re-upload pre-selects existing level
 * - UPLD-04: Selected level stored in PageProps on upload
 */
class UploadHooks implements
	UploadFormInitDescriptorHook,
	UploadCompleteHook
{
	private PermissionService $permissionService;

	public function __construct( PermissionService $permissionService ) {
		$this->permissionService = $permissionService;
	}

	/**
	 * Add permission level dropdown to the upload form.
	 *
	 * @param array &$descriptor HTMLForm descriptor array
	 * @return bool
	 */
	public function onUploadFormInitDescriptor( &$descriptor ) {
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
	 * Store the selected permission level on upload completion.
	 *
	 * @param \UploadBase &$upload The completed upload
	 * @return bool
	 */
	public function onUploadComplete( &$upload ) {
		$localFile = $upload->getLocalFile();
		if ( $localFile === null ) {
			return true;
		}

		$title = $localFile->getTitle();
		if ( $title === null || $title->getArticleID() === 0 ) {
			return true;
		}

		// Read the selected level from the form submission
		$level = RequestContext::getMain()->getRequest()->getVal( 'wpFilePermLevel' );

		// Defense in depth: validation-callback should have caught this
		if ( $level === null || $level === '' || !Config::isValidLevel( $level ) ) {
			return true;
		}

		$this->permissionService->setLevel( $title, $level );

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
	public function validatePermissionLevel( $value, $alldata, $form ) {
		if ( $value === null || $value === '' ) {
			return wfMessage( 'filepermissions-upload-required' )->text();
		}

		if ( !Config::isValidLevel( $value ) ) {
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

		$levels = Config::getLevels();
		$groupGrants = Config::getGroupGrants();

		// Build reverse map: level => list of groups that grant it
		$levelGroups = [];
		foreach ( $levels as $level ) {
			$levelGroups[$level] = [];
		}
		foreach ( $groupGrants as $group => $grants ) {
			foreach ( $levels as $level ) {
				if ( in_array( '*', $grants, true ) || in_array( $level, $grants, true ) ) {
					$levelGroups[$level][] = $group;
				}
			}
		}

		// Build option labels
		foreach ( $levels as $level ) {
			$groups = $levelGroups[$level];
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
		if ( !Config::isValidLevel( $existingLevel ) ) {
			return null;
		}

		return $existingLevel;
	}
}
