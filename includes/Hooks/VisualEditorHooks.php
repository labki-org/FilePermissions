<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use FilePermissions\Config;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * VisualEditor integration hooks for FilePermissions extension.
 *
 * Conditionally loads the VisualEditor bridge module when VisualEditor is
 * installed. This handler fires on BeforePageDisplay — unlike MsUploadHooks
 * which uses EditPage::showEditForm:initial — because VisualEditor can be
 * opened on any content page, not just edit form pages.
 *
 * When VisualEditor is not installed, this is a silent no-op.
 *
 * Implements:
 * - VE-01: Conditional bridge module loading
 * - VE-02: Namespace-aware default level in JS config vars
 */
class VisualEditorHooks implements BeforePageDisplayHook {

	/**
	 * Load VisualEditor bridge module and inject JS config vars.
	 *
	 * Only activates when VisualEditor extension is installed. Provides the
	 * bridge JS module with permission levels and the resolved default
	 * for the current page's namespace.
	 *
	 * @param OutputPage $out The OutputPage to add modules/config to
	 * @param \Skin $skin The Skin instance
	 * @return void
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' ) ) {
			return;
		}

		$ns = $out->getTitle()->getNamespace();

		$out->addModules( [ 'ext.FilePermissions.visualeditor' ] );
		$out->addJsConfigVars( [
			'wgFilePermLevels' => Config::getLevels(),
			'wgFilePermVEDefault' => Config::resolveDefaultLevel( $ns ),
		] );
	}
}
