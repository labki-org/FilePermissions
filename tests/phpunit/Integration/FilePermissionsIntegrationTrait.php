<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Integration;

use FilePermissions\PermissionService;
use MediaWiki\Title\Title;

/**
 * Shared helpers for FilePermissions integration tests.
 *
 * Provides the standard config overrides, service accessor, and file page
 * factory that are duplicated across most integration test classes.
 *
 * Host classes must extend MediaWikiIntegrationTestCase (or ApiTestCase)
 * so that overrideConfigValue(), getServiceContainer(), and insertPage()
 * are available.
 */
trait FilePermissionsIntegrationTrait {

	/**
	 * Override all 5 FilePermissions config vars with standard test values
	 * and reset the PermissionService singleton.
	 *
	 * Call from setUp() after parent::setUp().
	 */
	protected function setUpFilePermissionsConfig(): void {
		$this->overrideConfigValue( 'FilePermLevels',
			[ 'public', 'internal', 'confidential' ] );
		$this->overrideConfigValue( 'FilePermGroupGrants', [
			'sysop' => [ '*' ],
			'editor' => [ 'public', 'internal' ],
			'viewer' => [ 'public' ],
		] );
		$this->overrideConfigValue( 'FilePermDefaultLevel', null );
		$this->overrideConfigValue( 'FilePermNamespaceDefaults', [] );
		$this->overrideConfigValue( 'FilePermInvalidConfig', false );

		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.Config' );
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.PermissionService' );
	}

	/**
	 * Get a fresh PermissionService from the service container.
	 *
	 * Resets the service singleton first to guarantee a clean cache.
	 *
	 * @return PermissionService
	 */
	protected function getService(): PermissionService {
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.Config' );
		$this->getServiceContainer()
			->resetServiceForTesting( 'FilePermissions.PermissionService' );
		return $this->getServiceContainer()
			->getService( 'FilePermissions.PermissionService' );
	}

	/**
	 * Insert a File: page and return its Title.
	 *
	 * @param string $name Page name without namespace prefix
	 * @return Title
	 */
	protected function createFilePage( string $name ): Title {
		$result = $this->insertPage( "File:$name", 'test content', NS_FILE );
		return $result['title'];
	}
}
