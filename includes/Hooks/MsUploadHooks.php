<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use FilePermissions\Config;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Hook\EditPage__showEditForm_initialHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * MsUpload integration hooks for FilePermissions extension.
 *
 * Conditionally loads the MsUpload bridge module when MsUpload is installed.
 * This handler fires on EditPage::showEditForm:initial — the same hook
 * MsUpload uses to load its own module — ensuring the bridge JS is available
 * on edit pages where MsUpload operates.
 *
 * When MsUpload is not installed, this is a silent no-op.
 *
 * Implements:
 * - MSUP-01: Conditional bridge module loading
 * - MSUP-02: Namespace-aware default level in JS config vars
 */
class MsUploadHooks implements EditPage__showEditForm_initialHook {

	/**
	 * Load MsUpload bridge module and inject JS config vars on edit pages.
	 *
	 * Only activates when MsUpload extension is installed. Provides the
	 * bridge JS module with permission levels and the resolved default
	 * for the current page's namespace.
	 *
	 * @param EditPage $editor The EditPage instance
	 * @param OutputPage $out The OutputPage to add modules/config to
	 * @return bool|void True or no return value to continue
	 */
	public function onEditPage__showEditForm_initial( $editor, $out ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'MsUpload' ) ) {
			return;
		}

		$ns = $out->getTitle()->getNamespace();

		$out->addModules( [ 'ext.FilePermissions.msupload' ] );
		$out->addJsConfigVars( [
			'wgFilePermLevels' => Config::getLevels(),
			'wgFilePermMsUploadDefault' => Config::resolveDefaultLevel( $ns ),
		] );
	}
}
