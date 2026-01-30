<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\E2E;

/**
 * E2E tests for img_auth.php denial and authorized access.
 *
 * Covers LEAK-01 (unauthorized original download denied),
 * LEAK-02 (unauthorized thumbnail denied),
 * LEAK-05 (authorized users can download at granted levels),
 * and LEAK-06 (public files accessible to all authenticated users).
 *
 * These are MediaWiki-level denials (img_auth.php enforces via
 * FilePermissions hooks), distinct from Apache-level blocks tested
 * in DirectPathAccessTest.
 *
 * @group e2e
 */
class ImgAuthLeakTest extends E2ETestBase {

	// =========================================================================
	// LEAK-01: Unauthorized user denied confidential original file download
	// =========================================================================

	/**
	 * TestUser (user group with public+internal grants, NOT confidential)
	 * is denied access to the confidential file's original path via
	 * img_auth.php.
	 */
	public function testUnauthorizedUser_ConfidentialFile_OriginalPath_Returns403(): void {
		$filename = self::getTestFileNames()['confidential'];
		$url = self::getImgAuthUrl( $filename );
		$response = self::httpGet( $url, self::getTestUserCookies() );

		self::assertSame(
			403,
			$response['status'],
			"LEAK-01: TestUser (no confidential grant) requesting $url "
			. "should get 403, got {$response['status']}"
		);
	}

	// =========================================================================
	// LEAK-02: Unauthorized user denied confidential thumbnail
	// =========================================================================

	/**
	 * TestUser is denied access to the confidential file's thumbnail path
	 * via img_auth.php.
	 */
	public function testUnauthorizedUser_ConfidentialFile_ThumbnailPath_Returns403(): void {
		$filename = self::getTestFileNames()['confidential'];
		$thumbName = '120px-' . $filename;
		$url = self::getImgAuthThumbUrl( $filename, $thumbName );
		$response = self::httpGet( $url, self::getTestUserCookies() );

		self::assertSame(
			403,
			$response['status'],
			"LEAK-02: TestUser (no confidential grant) requesting thumbnail $url "
			. "should get 403, got {$response['status']}"
		);
	}

	// =========================================================================
	// Anonymous access denial (private wiki)
	// =========================================================================

	/**
	 * Anonymous user (no cookies) is denied access to the public file
	 * via img_auth.php on a private wiki.
	 */
	public function testAnonymousUser_PublicFile_OriginalPath_DeniedOnPrivateWiki(): void {
		$filename = self::getTestFileNames()['public'];
		$url = self::getImgAuthUrl( $filename );
		$response = self::httpGet( $url );

		self::assertSame(
			403,
			$response['status'],
			"Anonymous user requesting public file $url on private wiki "
			. "should get 403, got {$response['status']}"
		);
	}

	/**
	 * Anonymous user (no cookies) is denied access to the confidential file
	 * via img_auth.php on a private wiki.
	 */
	public function testAnonymousUser_ConfidentialFile_OriginalPath_DeniedOnPrivateWiki(): void {
		$filename = self::getTestFileNames()['confidential'];
		$url = self::getImgAuthUrl( $filename );
		$response = self::httpGet( $url );

		self::assertSame(
			403,
			$response['status'],
			"Anonymous user requesting confidential file $url on private wiki "
			. "should get 403, got {$response['status']}"
		);
	}

	// =========================================================================
	// LEAK-05: Authorized users can download at granted levels
	// =========================================================================

	/**
	 * TestUser (public+internal grants) can access the public file
	 * via img_auth.php.
	 */
	public function testAuthorizedUser_PublicFile_OriginalPath_Returns200(): void {
		$filename = self::getTestFileNames()['public'];
		$url = self::getImgAuthUrl( $filename );
		$response = self::httpGet( $url, self::getTestUserCookies() );

		self::assertSame(
			200,
			$response['status'],
			"LEAK-05: TestUser (has public grant) requesting $url "
			. "should get 200, got {$response['status']}"
		);
		self::assertGreaterThan(
			0,
			strlen( $response['body'] ),
			"LEAK-05: Response body should contain file bytes, got empty body"
		);
	}

	/**
	 * TestUser (public+internal grants) can access the internal file
	 * via img_auth.php.
	 */
	public function testAuthorizedUser_InternalFile_OriginalPath_Returns200(): void {
		$filename = self::getTestFileNames()['internal'];
		$url = self::getImgAuthUrl( $filename );
		$response = self::httpGet( $url, self::getTestUserCookies() );

		self::assertSame(
			200,
			$response['status'],
			"LEAK-05: TestUser (has internal grant) requesting $url "
			. "should get 200, got {$response['status']}"
		);
		self::assertGreaterThan(
			0,
			strlen( $response['body'] ),
			"LEAK-05: Response body should contain file bytes, got empty body"
		);
	}

	/**
	 * Admin (sysop with wildcard access) can access the confidential file
	 * via img_auth.php.
	 */
	public function testAdmin_ConfidentialFile_OriginalPath_Returns200(): void {
		$filename = self::getTestFileNames()['confidential'];
		$url = self::getImgAuthUrl( $filename );
		$response = self::httpGet( $url, self::getAdminCookies() );

		self::assertSame(
			200,
			$response['status'],
			"LEAK-05: Admin (sysop, wildcard grant) requesting $url "
			. "should get 200, got {$response['status']}"
		);
		self::assertGreaterThan(
			0,
			strlen( $response['body'] ),
			"LEAK-05: Response body should contain file bytes, got empty body"
		);
	}

	// =========================================================================
	// LEAK-06: Public files accessible to all authenticated users
	// =========================================================================

	/**
	 * Both Admin and TestUser can access the public file via img_auth.php.
	 */
	public function testPublicFile_AccessibleToAllAuthenticated(): void {
		$filename = self::getTestFileNames()['public'];
		$url = self::getImgAuthUrl( $filename );

		// Admin access
		$adminResponse = self::httpGet( $url, self::getAdminCookies() );
		self::assertSame(
			200,
			$adminResponse['status'],
			"LEAK-06: Admin requesting public file $url "
			. "should get 200, got {$adminResponse['status']}"
		);

		// TestUser access
		$testUserResponse = self::httpGet( $url, self::getTestUserCookies() );
		self::assertSame(
			200,
			$testUserResponse['status'],
			"LEAK-06: TestUser requesting public file $url "
			. "should get 200, got {$testUserResponse['status']}"
		);
	}
}
