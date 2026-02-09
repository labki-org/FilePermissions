<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Integration;

use FilePermissions\Hooks\DisplayHooks;
use MediaWiki\Context\RequestContext;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for DisplayHooks output verification.
 *
 * Covers onImagePageAfterImageLinks(), onBeforePageDisplay(), and
 * onEditPage__showEditForm_initial() to verify HTML output, module
 * loading, and JS config injection.
 *
 * @covers \FilePermissions\Hooks\DisplayHooks
 * @group Database
 */
class DisplayHooksOutputTest extends MediaWikiIntegrationTestCase {

	use FilePermissionsIntegrationTrait;

	private User $originalUser;

	protected function setUp(): void {
		parent::setUp();
		$this->originalUser = RequestContext::getMain()->getUser();
		$this->setUpFilePermissionsConfig();
		// DisplayHooksOutputTest uses different GroupGrants than the trait default
		$this->overrideConfigValue( 'FilePermGroupGrants', [
			'sysop' => [ '*' ],
			'user' => [ 'public', 'internal' ],
		] );
	}

	protected function tearDown(): void {
		RequestContext::getMain()->setUser( $this->originalUser );
		parent::tearDown();
	}

	private function createHooks(): DisplayHooks {
		return new DisplayHooks( $this->getService() );
	}

	/**
	 * Create a File: page and set its permission level.
	 */
	private function createProtectedFile( string $name, string $level ): Title {
		$result = $this->insertPage( "File:$name", 'test content', NS_FILE );
		$title = $result['title'];
		$this->getService()->setLevel( $title, $level );
		return $title;
	}

	/**
	 * Create an ImagePage mock with the given title and user context.
	 */
	private function createImagePageMock( Title $title, User $user ): \ImagePage {
		$context = new \RequestContext();
		$context->setTitle( $title );
		$context->setUser( $user );
		$out = new OutputPage( $context );
		$context->setOutput( $out );

		$imagePage = $this->createMock( \ImagePage::class );
		$imagePage->method( 'getTitle' )->willReturn( $title );
		$imagePage->method( 'getContext' )->willReturn( $context );

		return $imagePage;
	}

	// =========================================================================
	// ImagePageAfterImageLinks output tests
	// =========================================================================

	/**
	 * Renders fileperm-indicator and fileperm-level-badge with correct level text.
	 */
	public function testRendersIndicatorWithCorrectLevel(): void {
		$title = $this->createProtectedFile( 'DisplayTest01.png', 'internal' );
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$imagePage = $this->createImagePageMock( $title, $user );
		$hooks = $this->createHooks();

		$html = '';
		$hooks->onImagePageAfterImageLinks( $imagePage, $html );

		$this->assertStringContainsString( 'fileperm-indicator', $html );
		$this->assertStringContainsString( 'fileperm-level-badge', $html );
		$this->assertStringContainsString( 'internal', $html );
	}

	/**
	 * Renders edit controls (dropdown + save button) for sysop.
	 */
	public function testRendersEditControlsForSysop(): void {
		$title = $this->createProtectedFile( 'DisplayTest02.png', 'public' );
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$imagePage = $this->createImagePageMock( $title, $user );
		$hooks = $this->createHooks();

		$html = '';
		$hooks->onImagePageAfterImageLinks( $imagePage, $html );

		$this->assertStringContainsString( 'fileperm-edit-dropdown', $html );
		$this->assertStringContainsString( 'fileperm-edit-save', $html );
	}

	/**
	 * No edit controls for regular user (badge still present).
	 */
	public function testNoEditControlsForRegularUser(): void {
		$title = $this->createProtectedFile( 'DisplayTest03.png', 'public' );
		// Regular user without edit-fileperm right
		$user = $this->getTestUser( [ 'user' ] )->getUser();
		$imagePage = $this->createImagePageMock( $title, $user );
		$hooks = $this->createHooks();

		$html = '';
		$hooks->onImagePageAfterImageLinks( $imagePage, $html );

		$this->assertStringNotContainsString( 'fileperm-edit-dropdown', $html );
		$this->assertStringNotContainsString( 'fileperm-edit-save', $html );
		// Badge should still be present
		$this->assertStringContainsString( 'fileperm-level-badge', $html );
	}

	/**
	 * No output for file without permission level.
	 */
	public function testNoOutputForFileWithoutLevel(): void {
		$result = $this->insertPage( 'File:DisplayTestNoLevel.png', 'test', NS_FILE );
		$title = $result['title'];
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$imagePage = $this->createImagePageMock( $title, $user );
		$hooks = $this->createHooks();

		$html = '';
		$hooks->onImagePageAfterImageLinks( $imagePage, $html );

		$this->assertSame( '', $html );
	}

	/**
	 * Edit dropdown contains all configured levels.
	 */
	public function testEditDropdownContainsAllLevels(): void {
		$title = $this->createProtectedFile( 'DisplayTest04.png', 'internal' );
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$imagePage = $this->createImagePageMock( $title, $user );
		$hooks = $this->createHooks();

		$html = '';
		$hooks->onImagePageAfterImageLinks( $imagePage, $html );

		// All 3 levels should appear as options in the OOUI dropdown
		$this->assertStringContainsString( 'public', $html );
		$this->assertStringContainsString( 'internal', $html );
		$this->assertStringContainsString( 'confidential', $html );
	}

	/**
	 * Output wrapped in div.fileperm-section#fileperm-section.
	 */
	public function testOutputWrappedInSection(): void {
		$title = $this->createProtectedFile( 'DisplayTest05.png', 'public' );
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$imagePage = $this->createImagePageMock( $title, $user );
		$hooks = $this->createHooks();

		$html = '';
		$hooks->onImagePageAfterImageLinks( $imagePage, $html );

		$this->assertStringContainsString( 'class="fileperm-section"', $html );
		$this->assertStringContainsString( 'id="fileperm-section"', $html );
	}

	// =========================================================================
	// BeforePageDisplay module loading tests
	// =========================================================================

	/**
	 * Adds indicator styles on File pages.
	 */
	public function testAddsIndicatorStylesOnFilePages(): void {
		$title = Title::makeTitle( NS_FILE, 'DisplayModuleTest.png' );
		$context = new \RequestContext();
		$context->setTitle( $title );
		$context->setUser( $this->getTestUser()->getUser() );
		$out = new OutputPage( $context );
		$context->setOutput( $out );
		$out->setTitle( $title );

		$skin = $this->createMock( \Skin::class );
		$hooks = $this->createHooks();

		$hooks->onBeforePageDisplay( $out, $skin );

		$this->assertContains(
			'ext.FilePermissions.indicator',
			$out->getModuleStyles()
		);
	}

	/**
	 * Adds edit module for sysop on File pages.
	 */
	public function testAddsEditModuleForSysop(): void {
		$title = $this->createProtectedFile( 'DisplayModuleTest02.png', 'internal' );
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$context = new \RequestContext();
		$context->setTitle( $title );
		$context->setUser( $user );
		$out = new OutputPage( $context );
		$context->setOutput( $out );
		$out->setTitle( $title );

		$skin = $this->createMock( \Skin::class );
		$hooks = $this->createHooks();

		$hooks->onBeforePageDisplay( $out, $skin );

		$this->assertContains( 'ext.FilePermissions.edit', $out->getModules() );
	}

	/**
	 * Adds correct JS config vars for sysop.
	 */
	public function testAddsJsConfigVarsForSysop(): void {
		$title = $this->createProtectedFile( 'DisplayModuleTest03.png', 'internal' );
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$context = new \RequestContext();
		$context->setTitle( $title );
		$context->setUser( $user );
		$out = new OutputPage( $context );
		$context->setOutput( $out );
		$out->setTitle( $title );

		$skin = $this->createMock( \Skin::class );
		$hooks = $this->createHooks();

		$hooks->onBeforePageDisplay( $out, $skin );

		$vars = $out->getJsConfigVars();
		$this->assertArrayHasKey( 'wgFilePermCurrentLevel', $vars );
		$this->assertSame( 'internal', $vars['wgFilePermCurrentLevel'] );
		$this->assertArrayHasKey( 'wgFilePermLevels', $vars );
		$this->assertSame( [ 'public', 'internal', 'confidential' ], $vars['wgFilePermLevels'] );
		$this->assertArrayHasKey( 'wgFilePermPageTitle', $vars );
	}

	/**
	 * No edit module for regular user.
	 */
	public function testNoEditModuleForRegularUser(): void {
		$title = $this->createProtectedFile( 'DisplayModuleTest04.png', 'internal' );
		$user = $this->getTestUser( [ 'user' ] )->getUser();
		$context = new \RequestContext();
		$context->setTitle( $title );
		$context->setUser( $user );
		$out = new OutputPage( $context );
		$context->setOutput( $out );
		$out->setTitle( $title );

		$skin = $this->createMock( \Skin::class );
		$hooks = $this->createHooks();

		$hooks->onBeforePageDisplay( $out, $skin );

		$this->assertNotContains( 'ext.FilePermissions.edit', $out->getModules() );
	}

	/**
	 * Adds VE bridge module and config when VisualEditor is loaded.
	 */
	public function testAddsVeBridgeModuleWhenVeLoaded(): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' ) ) {
			$this->markTestSkipped( 'VisualEditor not installed' );
		}

		$title = Title::makeTitle( NS_MAIN, 'VEBridgeTest' );
		$user = $this->getTestUser()->getUser();
		$context = new \RequestContext();
		$context->setTitle( $title );
		$context->setUser( $user );
		$out = new OutputPage( $context );
		$context->setOutput( $out );
		$out->setTitle( $title );

		$skin = $this->createMock( \Skin::class );
		$hooks = $this->createHooks();

		$hooks->onBeforePageDisplay( $out, $skin );

		$this->assertContains( 'ext.FilePermissions.visualeditor', $out->getModules() );
		$vars = $out->getJsConfigVars();
		$this->assertArrayHasKey( 'wgFilePermLevels', $vars );
		$this->assertArrayHasKey( 'wgFilePermVEDefault', $vars );
	}

	/**
	 * Adds MsUpload bridge module and config when MsUpload is loaded.
	 *
	 * Tests the EditPage::showEditForm:initial hook handler.
	 */
	public function testAddsMsUploadBridgeModuleWhenMsUploadLoaded(): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'MsUpload' ) ) {
			$this->markTestSkipped( 'MsUpload not installed' );
		}

		$title = Title::makeTitle( NS_MAIN, 'MsUploadBridgeTest' );
		$user = $this->getTestUser()->getUser();
		$context = new \RequestContext();
		$context->setTitle( $title );
		$context->setUser( $user );
		$out = new OutputPage( $context );
		$context->setOutput( $out );
		$out->setTitle( $title );

		// Create a mock EditPage
		$editor = $this->createMock( \MediaWiki\EditPage\EditPage::class );

		$hooks = $this->createHooks();
		$hooks->onEditPage__showEditForm_initial( $editor, $out );

		$this->assertContains( 'ext.FilePermissions.msupload', $out->getModules() );
		$vars = $out->getJsConfigVars();
		$this->assertArrayHasKey( 'wgFilePermLevels', $vars );
		$this->assertArrayHasKey( 'wgFilePermMsUploadDefault', $vars );
	}
}
