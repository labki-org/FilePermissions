<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use FilePermissions\Config;
use FilePermissions\PermissionService;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Hook\EditPage__showEditForm_initialHook;
use MediaWiki\Html\Html;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\ImagePageAfterImageLinksHook;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * Display hooks for FilePermissions extension.
 *
 * Renders the permission level indicator on File: description pages
 * and conditionally loads the sysop edit interface.
 *
 * Implements:
 * - FPUI-01: Permission indicator on File pages (ImagePageAfterImageLinks)
 * - FPUI-02: Conditional edit module loading (BeforePageDisplay)
 * - VE-01: Conditional bridge module loading (BeforePageDisplay)
 * - VE-02: Namespace-aware default level in JS config vars
 * - MSUP-01: Conditional bridge module loading (EditPage::showEditForm:initial)
 * - MSUP-02: Namespace-aware default level in JS config vars
 */
class DisplayHooks implements
	BeforePageDisplayHook,
	ImagePageAfterImageLinksHook,
	EditPage__showEditForm_initialHook
{
	private PermissionService $permissionService;
	private Config $config;

	public function __construct( PermissionService $permissionService, Config $config ) {
		$this->permissionService = $permissionService;
		$this->config = $config;
	}

	/**
	 * Inject permission indicator and optional edit controls after image links.
	 *
	 * @param \ImagePage $imagePage
	 * @param string &$html
	 */
	public function onImagePageAfterImageLinks( $imagePage, &$html ): void {
		$title = $imagePage->getTitle();
		$level = $this->permissionService->getLevel( $title );

		// No indicator for files without a permission level
		if ( $level === null ) {
			return;
		}

		$context = $imagePage->getContext();
		$out = $context->getOutput();
		$user = $context->getUser();

		// Enable OOUI for the lock icon in the indicator
		$out->enableOOUI();

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

		// File page display: indicator styles + edit module
		if ( $title && $title->getNamespace() === NS_FILE ) {
			// Always load indicator styles on File pages
			$out->addModuleStyles( [ 'ext.FilePermissions.indicator' ] );

			// Load edit module and config vars for users with edit-fileperm right
			if ( $out->getUser()->isAllowed( 'edit-fileperm' ) ) {
				$out->addModules( [ 'ext.FilePermissions.edit' ] );
				$out->addJsConfigVars( [
					'wgFilePermCurrentLevel' => $this->permissionService->getLevel( $title ),
					'wgFilePermLevels' => $this->config->getLevels(),
					'wgFilePermPageTitle' => $title->getPrefixedDBkey(),
				] );
			}
		}

		// VisualEditor bridge: load module when VE is installed
		if ( ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' ) ) {
			$ns = $out->getTitle()->getNamespace();

			$out->addModules( [ 'ext.FilePermissions.visualeditor' ] );
			$out->addJsConfigVars( [
				'wgFilePermLevels' => $this->config->getLevels(),
				'wgFilePermVEDefault' => $this->config->resolveDefaultLevel( $ns ),
			] );
		}
	}

	/**
	 * Load MsUpload bridge module and inject JS config vars on edit pages.
	 *
	 * Only activates when MsUpload extension is installed.
	 *
	 * @param EditPage $editor The EditPage instance
	 * @param OutputPage $out The OutputPage to add modules/config to
	 * @return bool|void True or no return value to continue
	 */
	public function onEditPage__showEditForm_initial( $editor, $out ): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'MsUpload' ) ) {
			return;
		}

		$ns = $out->getTitle()->getNamespace();

		$out->addModules( [ 'ext.FilePermissions.msupload' ] );
		$out->addJsConfigVars( [
			'wgFilePermLevels' => $this->config->getLevels(),
			'wgFilePermMsUploadDefault' => $this->config->resolveDefaultLevel( $ns ),
		] );
	}

	/**
	 * Build the permission level indicator HTML.
	 *
	 * @param string $level Current permission level
	 * @return string HTML for the indicator
	 */
	private function buildIndicatorHtml( string $level ): string {
		$icon = new \OOUI\IconWidget( [
			'icon' => 'lock',
			'classes' => [ 'fileperm-indicator-icon' ],
		] );

		return Html::rawElement( 'div', [
			'class' => 'fileperm-indicator',
		], $icon . Html::element( 'strong', [],
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
		foreach ( $this->config->getLevels() as $lvl ) {
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
