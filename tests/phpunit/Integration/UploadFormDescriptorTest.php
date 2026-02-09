<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Integration;

use FilePermissions\Hooks\UploadHooks;
use FilePermissions\PermissionService;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for the Special:Upload form descriptor.
 *
 * Covers onUploadFormInitDescriptor(), buildLevelOptions(), and
 * resolveReuploadDefault() to verify the form field structure,
 * option content, and re-upload pre-selection behavior.
 *
 * @covers \FilePermissions\Hooks\UploadHooks::onUploadFormInitDescriptor
 * @group Database
 */
class UploadFormDescriptorTest extends MediaWikiIntegrationTestCase {

	private ?FauxRequest $savedRequest = null;

	protected function setUp(): void {
		parent::setUp();

		$this->savedRequest = RequestContext::getMain()->getRequest();

		$this->overrideConfigValue( 'FilePermLevels',
			[ 'public', 'internal', 'confidential' ] );
		$this->overrideConfigValue( 'FilePermGroupGrants', [
			'sysop' => [ '*' ],
			'user' => [ 'public', 'internal' ],
		] );
		$this->overrideConfigValue( 'FilePermDefaultLevel', null );
		$this->overrideConfigValue( 'FilePermNamespaceDefaults', [] );
		$this->overrideConfigValue( 'FilePermInvalidConfig', false );

		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.Config' );
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.PermissionService' );
	}

	protected function tearDown(): void {
		if ( $this->savedRequest !== null ) {
			RequestContext::getMain()->setRequest( $this->savedRequest );
		}
		parent::tearDown();
	}

	private function getService(): PermissionService {
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.Config' );
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.PermissionService' );
		return $this->getServiceContainer()
			->getService( 'FilePermissions.PermissionService' );
	}

	private function createHooks(): UploadHooks {
		return new UploadHooks(
			$this->getService(),
			$this->getServiceContainer()->getService( 'FilePermissions.Config' )
		);
	}

	private function setRequestParams( array $params ): void {
		RequestContext::getMain()->setRequest( new FauxRequest( $params ) );
	}

	/**
	 * Get the form descriptor by invoking the hook.
	 *
	 * @return array The descriptor array after hook processing
	 */
	private function getDescriptor(): array {
		$descriptor = [];
		$hooks = $this->createHooks();
		$hooks->onUploadFormInitDescriptor( $descriptor );
		return $descriptor;
	}

	// =========================================================================
	// Descriptor structure tests
	// =========================================================================

	/**
	 * Descriptor contains FilePermLevel field with correct type, messages, and section.
	 */
	public function testDescriptorContainsFilePermLevelField(): void {
		$this->setRequestParams( [] );
		$descriptor = $this->getDescriptor();

		$this->assertArrayHasKey( 'FilePermLevel', $descriptor );
		$field = $descriptor['FilePermLevel'];

		$this->assertSame( 'select', $field['type'] );
		$this->assertSame( 'filepermissions-upload-label', $field['label-message'] );
		$this->assertSame( 'filepermissions-upload-help', $field['help-message'] );
		$this->assertSame( 'description', $field['section'] );
	}

	/**
	 * Options have placeholder + all 3 configured levels.
	 */
	public function testOptionsHavePlaceholderAndAllLevels(): void {
		$this->setRequestParams( [] );
		$descriptor = $this->getDescriptor();
		$options = $descriptor['FilePermLevel']['options'];

		// Options is label => value map. First entry is placeholder (empty value).
		$values = array_values( $options );
		$this->assertSame( '', $values[0], 'First option should be empty placeholder' );

		// Remaining values should be the 3 levels
		$levelValues = array_slice( $values, 1 );
		$this->assertSame( [ 'public', 'internal', 'confidential' ], $levelValues );
	}

	/**
	 * Option labels include group names in parentheses.
	 */
	public function testOptionLabelsIncludeGroupNames(): void {
		$this->setRequestParams( [] );
		$descriptor = $this->getDescriptor();
		$options = $descriptor['FilePermLevel']['options'];

		$labels = array_keys( $options );

		// Find the label for "public" value
		$publicLabel = array_search( 'public', $options );
		$this->assertNotFalse( $publicLabel );
		$this->assertStringContainsString( 'sysop', (string)$publicLabel );
		$this->assertStringContainsString( 'user', (string)$publicLabel );

		// Find the label for "confidential" value
		$confLabel = array_search( 'confidential', $options );
		$this->assertNotFalse( $confLabel );
		$this->assertStringContainsString( 'sysop', (string)$confLabel );
	}

	/**
	 * No 'default' key when no wpDestFile param (fresh upload).
	 */
	public function testNoDefaultKeyWhenNoDestFileParam(): void {
		$this->setRequestParams( [] );
		$descriptor = $this->getDescriptor();

		$this->assertArrayNotHasKey( 'default', $descriptor['FilePermLevel'] );
	}

	/**
	 * Pre-selects existing level on re-upload.
	 */
	public function testPreSelectsExistingLevelOnReupload(): void {
		// Create a file with a permission level
		$result = $this->insertPage( 'File:ReuploadPreselect.png', 'test', NS_FILE );
		$title = $result['title'];
		$this->getService()->setLevel( $title, 'confidential' );

		// Set request with wpDestFile pointing to existing file
		$this->setRequestParams( [ 'wpDestFile' => 'ReuploadPreselect.png' ] );
		$descriptor = $this->getDescriptor();

		$this->assertArrayHasKey( 'default', $descriptor['FilePermLevel'] );
		$this->assertSame( 'confidential', $descriptor['FilePermLevel']['default'] );
	}

	/**
	 * Does not pre-select a level that has been removed from config.
	 */
	public function testDoesNotPreSelectRemovedLevel(): void {
		// Create a file and set a level that will be "removed"
		$result = $this->insertPage( 'File:RemovedLevel.png', 'test', NS_FILE );
		$title = $result['title'];
		$this->getService()->setLevel( $title, 'confidential' );

		// Now override config to remove "confidential" from valid levels
		$this->overrideConfigValue( 'FilePermLevels', [ 'public', 'internal' ] );
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.PermissionService' );

		$this->setRequestParams( [ 'wpDestFile' => 'RemovedLevel.png' ] );
		$descriptor = $this->getDescriptor();

		// Should NOT have a default because "confidential" is no longer valid
		$this->assertArrayNotHasKey( 'default', $descriptor['FilePermLevel'] );
	}

	/**
	 * Does not pre-select for nonexistent file.
	 */
	public function testDoesNotPreSelectForNonexistentFile(): void {
		$this->setRequestParams( [ 'wpDestFile' => 'NonexistentFile_12345.png' ] );
		$descriptor = $this->getDescriptor();

		$this->assertArrayNotHasKey( 'default', $descriptor['FilePermLevel'] );
	}
}
