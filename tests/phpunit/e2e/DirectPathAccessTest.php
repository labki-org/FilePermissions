<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\E2E;

/**
 * E2E tests for LEAK-03 and LEAK-04: Apache-layer direct path denial.
 *
 * Verifies that direct /images/ and /images/thumb/ paths return HTTP 403
 * for ALL users, regardless of authentication or permission level. This is
 * Apache-layer denial (the "Require all denied" directive in
 * apache-filepermissions.conf), not MediaWiki-level enforcement.
 *
 * Uses E2E_Test_Public.png -- a file that even regular users can access via
 * img_auth.php -- to prove Apache blocks the direct path regardless of the
 * file's permission level.
 *
 * @group e2e
 */
class DirectPathAccessTest extends E2ETestBase {

	/** @var string Test file used for direct path tests (accessible to all via img_auth) */
	private const TEST_FILE = 'E2E_Test_Public.png';

	/** @var string Thumbnail name format for direct path tests */
	private const THUMB_NAME = '120px-E2E_Test_Public.png';

	// =========================================================================
	// LEAK-03: Direct /images/{hash}/{filename} returns 403
	// =========================================================================

	/**
	 * Admin (sysop with wildcard access) cannot bypass img_auth.php via direct
	 * /images/ path. Apache blocks before MediaWiki is involved.
	 */
	public function testDirectImagePath_Admin_Returns403(): void {
		$url = self::getDirectImageUrl( self::TEST_FILE );
		$response = self::httpGet( $url, self::getAdminCookies() );

		self::assertSame(
			403,
			$response['status'],
			"Apache direct path block: Admin request to $url should return 403, "
			. "got {$response['status']}"
		);
	}

	/**
	 * Authenticated regular user cannot bypass img_auth.php via direct
	 * /images/ path. Apache blocks before MediaWiki is involved.
	 */
	public function testDirectImagePath_TestUser_Returns403(): void {
		$url = self::getDirectImageUrl( self::TEST_FILE );
		$response = self::httpGet( $url, self::getTestUserCookies() );

		self::assertSame(
			403,
			$response['status'],
			"Apache direct path block: TestUser request to $url should return 403, "
			. "got {$response['status']}"
		);
	}

	/**
	 * Anonymous user (no cookies) cannot access files via direct /images/ path.
	 * Apache blocks before MediaWiki is involved.
	 */
	public function testDirectImagePath_Anonymous_Returns403(): void {
		$url = self::getDirectImageUrl( self::TEST_FILE );
		$response = self::httpGet( $url );

		self::assertSame(
			403,
			$response['status'],
			"Apache direct path block: Anonymous request to $url should return 403, "
			. "got {$response['status']}"
		);
	}

	// =========================================================================
	// LEAK-04: Direct /images/thumb/{hash}/{filename}/{thumbName} returns 403
	// =========================================================================

	/**
	 * Admin (sysop with wildcard access) cannot bypass img_auth.php via direct
	 * /images/thumb/ path. Apache blocks before MediaWiki is involved.
	 */
	public function testDirectThumbPath_Admin_Returns403(): void {
		$url = self::getDirectThumbUrl( self::TEST_FILE, self::THUMB_NAME );
		$response = self::httpGet( $url, self::getAdminCookies() );

		self::assertSame(
			403,
			$response['status'],
			"Apache direct path block: Admin request to $url should return 403, "
			. "got {$response['status']}"
		);
	}

	/**
	 * Authenticated regular user cannot bypass img_auth.php via direct
	 * /images/thumb/ path. Apache blocks before MediaWiki is involved.
	 */
	public function testDirectThumbPath_TestUser_Returns403(): void {
		$url = self::getDirectThumbUrl( self::TEST_FILE, self::THUMB_NAME );
		$response = self::httpGet( $url, self::getTestUserCookies() );

		self::assertSame(
			403,
			$response['status'],
			"Apache direct path block: TestUser request to $url should return 403, "
			. "got {$response['status']}"
		);
	}

	/**
	 * Anonymous user (no cookies) cannot access thumbnails via direct
	 * /images/thumb/ path. Apache blocks before MediaWiki is involved.
	 */
	public function testDirectThumbPath_Anonymous_Returns403(): void {
		$url = self::getDirectThumbUrl( self::TEST_FILE, self::THUMB_NAME );
		$response = self::httpGet( $url );

		self::assertSame(
			403,
			$response['status'],
			"Apache direct path block: Anonymous request to $url should return 403, "
			. "got {$response['status']}"
		);
	}
}
