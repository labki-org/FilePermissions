<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use FilePermissions\Config;
use FilePermissions\PermissionService;
use MediaWiki\Html\Html;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\ImagePageAfterImageLinksHook;

/**
 * Display hooks for FilePermissions extension.
 *
 * Renders the permission level indicator on File: description pages
 * and conditionally loads the sysop edit interface.
 *
 * Implements:
 * - FPUI-01: Permission indicator on File pages (ImagePageAfterImageLinks)
 * - FPUI-02: Conditional edit module loading (BeforePageDisplay)
 */
class DisplayHooks implements BeforePageDisplayHook, ImagePageAfterImageLinksHook
{
	private PermissionService $permissionService;

	public function __construct( PermissionService $permissionService ) {
		$this->permissionService = $permissionService;
	}

	/**
	 * Inject permission indicator and optional edit controls after image links.
	 *
	 * @param \ImagePage $imagePage
	 * @param string &$html
	 */
	public function onImagePageAfterImageLinks( $imagePage, &$html ) {
		$title = $imagePage->getTitle();
		$level = $this->permissionService->getLevel( $title );

		// No indicator for files without a permission level
		if ( $level === null ) {
			return;
		}

		$context = $imagePage->getContext();
		$out = $context->getOutput();
		$user = $context->getUser();

		// Build indicator HTML (visible to all authorized users)
		$indicatorHtml = $this->buildIndicatorHtml( $level );

		// Build edit controls HTML (sysop only)
		$editHtml = '';
		if ( $user->isAllowed( 'edit-fileperm' ) ) {
			$editHtml = $this->buildEditHtml( $level, $out );
		}

		$html .= Html::rawElement( 'div', [
			'class' => 'fileperm-section',
			'id' => 'fileperm-section',
		], $indicatorHtml . $editHtml );
	}

	/**
	 * Conditionally load ResourceLoader modules on File pages.
	 *
	 * @param OutputPage $out
	 * @param \Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title || $title->getNamespace() !== NS_FILE ) {
			return;
		}

		// Always load indicator styles on File pages
		$out->addModuleStyles( [ 'ext.FilePermissions.indicator' ] );

		// Load edit module and config vars for users with edit-fileperm right
		if ( $out->getUser()->isAllowed( 'edit-fileperm' ) ) {
			$out->addModules( [ 'ext.FilePermissions.edit' ] );
			$out->addJsConfigVars( [
				'wgFilePermCurrentLevel' => $this->permissionService->getLevel( $title ),
				'wgFilePermLevels' => Config::getLevels(),
				'wgFilePermPageTitle' => $title->getPrefixedDBkey(),
			] );
		}
	}

	/**
	 * Build the permission level indicator HTML.
	 *
	 * @param string $level Current permission level
	 * @return string HTML for the indicator
	 */
	private function buildIndicatorHtml( string $level ): string {
		return Html::rawElement( 'div', [
			'class' => 'fileperm-indicator',
		], Html::element( 'strong', [],
			wfMessage( 'filepermissions-level-label' )->text()
		) . ' ' . Html::element( 'span', [
			'class' => 'fileperm-level-badge',
			'id' => 'fileperm-level-badge',
		], $level ) );
	}

	/**
	 * Build the edit controls HTML (dropdown + save button).
	 *
	 * @param string $currentLevel Current permission level
	 * @param OutputPage $out OutputPage for enableOOUI
	 * @return string HTML for the edit controls
	 */
	private function buildEditHtml( string $currentLevel, OutputPage $out ): string {
		$out->enableOOUI();

		$options = [];
		foreach ( Config::getLevels() as $lvl ) {
			$options[] = [
				'data' => $lvl,
				'label' => $lvl,
			];
		}

		$dropdown = new \OOUI\DropdownInputWidget( [
			'name' => 'fileperm-level',
			'options' => $options,
			'value' => $currentLevel,
			'id' => 'fileperm-edit-dropdown',
			'infusable' => true,
		] );

		$button = new \OOUI\ButtonInputWidget( [
			'label' => wfMessage( 'filepermissions-edit-save' )->text(),
			'flags' => [ 'primary', 'progressive' ],
			'id' => 'fileperm-edit-save',
			'type' => 'button',
			'infusable' => true,
		] );

		return Html::rawElement( 'div', [
			'class' => 'fileperm-edit-controls',
			'id' => 'fileperm-edit-controls',
		], $dropdown . ' ' . $button );
	}
}
