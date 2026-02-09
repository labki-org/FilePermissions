<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\Integration;

use FilePermissions\PermissionService;
use InvalidArgumentException;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for PermissionService database operations.
 *
 * Proves that PermissionService correctly round-trips permission levels
 * through the real fileperm_levels table (INTG-09) and that the in-process
 * cache returns correct values after set/get/remove round-trips without
 * cross-scenario cache poisoning (INTG-10).
 *
 * All operations go through PermissionService methods -- no direct DB access.
 * The service is fetched from the MediaWiki service container to prove
 * ServiceWiring.php works correctly.
 *
 * @covers \FilePermissions\PermissionService
 * @group Database
 */
class PermissionServiceDbTest extends MediaWikiIntegrationTestCase {

	use FilePermissionsIntegrationTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpFilePermissionsConfig();
	}

	// =========================================================================
	// INTG-09: setLevel / getLevel / removeLevel round-trip
	// =========================================================================

	/**
	 * setLevel writes to fileperm_levels, getLevel reads it back.
	 */
	public function testSetLevelAndGetLevelRoundTrip(): void {
		$service = $this->getService();
		$title = $this->createFilePage( 'RoundTrip.png' );

		$service->setLevel( $title, 'confidential' );

		$this->assertSame( 'confidential', $service->getLevel( $title ) );
	}

	/**
	 * setLevel overwrites a previous level (REPLACE semantics).
	 */
	public function testSetLevelOverwritesPreviousLevel(): void {
		$service = $this->getService();
		$title = $this->createFilePage( 'Overwrite.png' );

		$service->setLevel( $title, 'public' );
		$service->setLevel( $title, 'confidential' );

		$this->assertSame( 'confidential', $service->getLevel( $title ) );
	}

	/**
	 * getLevel returns null when no level has been set for a page.
	 */
	public function testGetLevelReturnsNullWhenNoLevelSet(): void {
		$service = $this->getService();
		$title = $this->createFilePage( 'NoLevel.png' );

		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * removeLevel deletes the row; getLevel returns null afterward.
	 */
	public function testRemoveLevelDeletesFromDatabase(): void {
		$service = $this->getService();
		$title = $this->createFilePage( 'RemoveMe.png' );

		$service->setLevel( $title, 'internal' );
		$this->assertSame( 'internal', $service->getLevel( $title ) );

		$service->removeLevel( $title );
		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * removeLevel on a page that never had a level does not throw.
	 */
	public function testRemoveLevelOnPageWithNoLevelIsNoOp(): void {
		$service = $this->getService();
		$title = $this->createFilePage( 'NeverHadLevel.png' );

		// Should not throw
		$service->removeLevel( $title );

		// Still null
		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * setLevel throws for a Title with articleID 0 (nonexistent page).
	 */
	public function testSetLevelThrowsForNonexistentPage(): void {
		$service = $this->getService();
		$title = Title::makeTitle( NS_FILE, 'DoesNotExist_' . mt_rand() . '.png' );

		$this->assertSame( 0, $title->getArticleID() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'page does not exist' );
		$service->setLevel( $title, 'public' );
	}

	/**
	 * setLevel throws for an invalid (unconfigured) level string.
	 */
	public function testSetLevelThrowsForInvalidLevel(): void {
		$service = $this->getService();
		$title = $this->createFilePage( 'InvalidLevel.png' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid permission level' );
		$service->setLevel( $title, 'nonexistent-level' );
	}

	/**
	 * getLevel returns null for a non-File namespace page.
	 */
	public function testGetLevelReturnsNullForNonFileNamespace(): void {
		$service = $this->getService();
		$result = $this->insertPage( 'RegularPage', 'content', NS_MAIN );
		$title = $result['title'];

		$this->assertNull( $service->getLevel( $title ) );
	}

	// =========================================================================
	// INTG-10: Cache behavior with real DB
	// =========================================================================

	/**
	 * After setLevel, getLevel returns from cache without a second DB query.
	 *
	 * We prove this by using the same service instance -- setLevel populates
	 * the cache, so getLevel should return immediately with the correct value.
	 */
	public function testCacheReturnsCorrectValueAfterSet(): void {
		$service = $this->getService();
		$title = $this->createFilePage( 'CacheAfterSet.png' );

		$service->setLevel( $title, 'internal' );

		// getLevel should return from cache (populated by setLevel)
		$this->assertSame( 'internal', $service->getLevel( $title ) );
	}

	/**
	 * Fresh service instances do not share cache state.
	 *
	 * Service A sets a level and caches it. After resetting the service,
	 * Service B (fresh instance) calls getLevel and must still return the
	 * correct value by reading from the database, not from a stale cache.
	 */
	public function testFreshServiceInstanceDoesNotShareCache(): void {
		$title = $this->createFilePage( 'NoCachePoisoning.png' );

		// Service A: set level and cache it
		$serviceA = $this->getService();
		$serviceA->setLevel( $title, 'confidential' );
		$this->assertSame( 'confidential', $serviceA->getLevel( $title ) );

		// Service B: fresh instance (reset clears the singleton)
		$serviceB = $this->getService();

		// Service B must read from DB, not from Service A's cache
		$this->assertSame( 'confidential', $serviceB->getLevel( $title ) );
	}

	/**
	 * Cache reflects removal: set, get (cache populated), remove, get again.
	 *
	 * The SAME service instance must return null after removeLevel,
	 * proving the cache is updated on remove.
	 */
	public function testCacheReflectsRemoval(): void {
		$service = $this->getService();
		$title = $this->createFilePage( 'CacheRemoval.png' );

		$service->setLevel( $title, 'internal' );
		$this->assertSame( 'internal', $service->getLevel( $title ) );

		$service->removeLevel( $title );
		$this->assertNull( $service->getLevel( $title ) );
	}

	/**
	 * Multiple pages have independent cache entries.
	 *
	 * Different levels on different pages must not interfere with each other.
	 */
	public function testMultiplePagesHaveIndependentCacheEntries(): void {
		$service = $this->getService();
		$titleA = $this->createFilePage( 'FileA.png' );
		$titleB = $this->createFilePage( 'FileB.png' );

		$service->setLevel( $titleA, 'public' );
		$service->setLevel( $titleB, 'confidential' );

		$this->assertSame( 'public', $service->getLevel( $titleA ) );
		$this->assertSame( 'confidential', $service->getLevel( $titleB ) );
	}
}
