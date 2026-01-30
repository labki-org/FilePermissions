<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * Abstract base class for E2E HTTP tests.
 *
 * Provides cookie-based MW API authentication, test file seeding at each
 * permission level, and HTTP client wrappers. E2E tests run as external
 * HTTP clients against the wiki -- they do NOT extend MediaWikiTestCase.
 *
 * Bootstrap checks verify the wiki is reachable, img_auth.php is active,
 * and private wiki mode is configured before any tests execute.
 *
 * @group e2e
 */
abstract class E2ETestBase extends TestCase {

	/** @var string Wiki base URL (docker-compose port mapping) */
	protected const WIKI_URL = 'http://localhost:8888';

	/** @var string Admin username (sysop, wildcard access) */
	protected const ADMIN_USER = 'Admin';

	/** @var string Admin password */
	protected const ADMIN_PASS = 'dockerpass';

	/** @var string Test user username (user group, public+internal only) */
	protected const TEST_USER = 'TestUser';

	/** @var string Test user password */
	protected const TEST_PASS = 'testpass123';

	/** @var string Minimal 1x1 pixel PNG as base64 */
	private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

	/** @var array<string, string> Cached admin session cookies */
	protected static array $adminCookies = [];

	/** @var array<string, string> Cached test user session cookies */
	protected static array $testUserCookies = [];

	/** @var array<string, string> Map of permission level => test filename */
	protected static array $testFiles = [
		'public' => 'E2E_Test_Public.png',
		'internal' => 'E2E_Test_Internal.png',
		'confidential' => 'E2E_Test_Confidential.png',
	];

	/** @var bool Whether test data was seeded successfully */
	protected static bool $seeded = false;

	/**
	 * Bootstrap verification and test data seeding.
	 *
	 * Runs once before the first test in any subclass. Verifies wiki
	 * reachability, img_auth.php configuration, and private wiki mode.
	 * Then authenticates admin and test users and seeds test files.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// Bootstrap check 1: Wiki reachable
		$response = self::httpGet(
			self::WIKI_URL . '/api.php?action=query&meta=siteinfo&format=json'
		);
		if ( $response['status'] !== 200 ) {
			self::markTestSkipped(
				'E2E prerequisite not met: wiki not reachable at ' . self::WIKI_URL
				. ' (HTTP ' . $response['status'] . ')'
			);
		}

		// Bootstrap check 2: img_auth.php is the active upload path
		$response = self::httpGet(
			self::WIKI_URL . '/api.php?action=query&meta=siteinfo&siprop=general&format=json'
		);
		if ( $response['status'] !== 200 ) {
			self::markTestSkipped(
				'E2E prerequisite not met: cannot query siteinfo'
			);
		}
		$siteInfo = json_decode( $response['body'], true );
		$uploadPath = $siteInfo['query']['general']['uploadpath'] ?? '';
		if ( strpos( $uploadPath, 'img_auth.php' ) === false ) {
			self::markTestSkipped(
				'E2E prerequisite not met: img_auth.php not active.'
				. ' uploadpath=' . $uploadPath
			);
		}

		// Bootstrap check 3: Private wiki mode (anonymous cannot read File: pages)
		$anonResponse = self::httpGet(
			self::WIKI_URL . '/api.php?action=query&titles=File:Test.png&format=json'
		);
		$anonData = json_decode( $anonResponse['body'], true );
		$hasError = isset( $anonData['error'] )
			&& $anonData['error']['code'] === 'readapidenied';
		if ( !$hasError ) {
			self::markTestSkipped(
				'E2E prerequisite not met: private wiki mode not configured.'
				. ' Anonymous API query did not return readapidenied.'
			);
		}

		// Authenticate admin user
		self::$adminCookies = self::loginUser( self::ADMIN_USER, self::ADMIN_PASS );

		// Authenticate test user (skip if not found)
		try {
			self::$testUserCookies = self::loginUser( self::TEST_USER, self::TEST_PASS );
		} catch ( \RuntimeException $e ) {
			self::markTestSkipped(
				'E2E prerequisite not met: TestUser not found -- run reinstall_test_env.sh. '
				. $e->getMessage()
			);
		}

		// Seed test files
		self::seedTestFiles();
	}

	/**
	 * Clean up test files after all tests complete.
	 */
	public static function tearDownAfterClass(): void {
		if ( self::$seeded && !empty( self::$adminCookies ) ) {
			self::cleanupTestFiles();
		}
		self::$adminCookies = [];
		self::$testUserCookies = [];
		self::$seeded = false;

		parent::tearDownAfterClass();
	}

	// =========================================================================
	// HTTP Client Methods
	// =========================================================================

	/**
	 * Perform an HTTP GET request using curl.
	 *
	 * @param string $url Full URL to request
	 * @param array<string, string> $cookies Cookies to send
	 * @return array{status: int, body: string, headers: array<string, string>}
	 */
	protected static function httpGet( string $url, array $cookies = [] ): array {
		return self::httpRequest( 'GET', $url, [], $cookies );
	}

	/**
	 * Perform an HTTP POST request using curl.
	 *
	 * @param string $url Full URL to request
	 * @param array $data POST data (key => value)
	 * @param array<string, string> $cookies Cookies to send
	 * @return array{status: int, body: string, headers: array<string, string>}
	 */
	protected static function httpPost( string $url, array $data, array $cookies = [] ): array {
		return self::httpRequest( 'POST', $url, $data, $cookies );
	}

	/**
	 * Perform an HTTP request using curl.
	 *
	 * @param string $method HTTP method (GET or POST)
	 * @param string $url Full URL to request
	 * @param array $data POST data
	 * @param array<string, string> $cookies Cookies to send
	 * @return array{status: int, body: string, headers: array<string, string>}
	 */
	private static function httpRequest(
		string $method,
		string $url,
		array $data = [],
		array $cookies = []
	): array {
		$ch = curl_init();

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $ch, CURLOPT_HEADER, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );

		// Capture response headers
		$responseHeaders = [];
		curl_setopt( $ch, CURLOPT_HEADERFUNCTION,
			function ( $curl, $headerLine ) use ( &$responseHeaders ) {
				$len = strlen( $headerLine );
				$parts = explode( ':', $headerLine, 2 );
				if ( count( $parts ) === 2 ) {
					$name = strtolower( trim( $parts[0] ) );
					$value = trim( $parts[1] );
					// Accumulate set-cookie headers as array
					if ( $name === 'set-cookie' ) {
						$responseHeaders['set-cookie'][] = $value;
					} else {
						$responseHeaders[$name] = $value;
					}
				}
				return $len;
			}
		);

		// Set cookies
		if ( !empty( $cookies ) ) {
			$cookiePairs = [];
			foreach ( $cookies as $name => $value ) {
				$cookiePairs[] = $name . '=' . $value;
			}
			curl_setopt( $ch, CURLOPT_COOKIE, implode( '; ', $cookiePairs ) );
		}

		// POST data
		if ( $method === 'POST' ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			// Check if any value is a CURLFile (multipart upload)
			$hasFile = false;
			foreach ( $data as $v ) {
				if ( $v instanceof \CURLFile ) {
					$hasFile = true;
					break;
				}
			}
			if ( $hasFile ) {
				// multipart/form-data for file uploads
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
			} else {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $data ) );
			}
		}

		$body = curl_exec( $ch );
		$status = (int)curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( $body === false ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return [
				'status' => 0,
				'body' => 'curl error: ' . $error,
				'headers' => $responseHeaders,
			];
		}

		curl_close( $ch );

		return [
			'status' => $status,
			'body' => (string)$body,
			'headers' => $responseHeaders,
		];
	}

	// =========================================================================
	// Authentication
	// =========================================================================

	/**
	 * Log in a user via MW API clientlogin flow.
	 *
	 * Performs the two-step login:
	 * 1. Fetch login token (captures session cookies)
	 * 2. POST clientlogin with credentials (captures auth cookies)
	 *
	 * @param string $username
	 * @param string $password
	 * @return array<string, string> Session cookies for authenticated requests
	 * @throws \RuntimeException If login fails
	 */
	protected static function loginUser( string $username, string $password ): array {
		$cookies = [];

		// Step 1: Get login token
		$tokenUrl = self::WIKI_URL
			. '/api.php?action=query&meta=tokens&type=login&format=json';
		$tokenResponse = self::httpGet( $tokenUrl );

		if ( $tokenResponse['status'] !== 200 ) {
			throw new \RuntimeException(
				"Failed to fetch login token for $username: HTTP "
				. $tokenResponse['status']
			);
		}

		// Accumulate cookies from token response
		$cookies = self::parseCookies( $tokenResponse['headers'], $cookies );

		$tokenData = json_decode( $tokenResponse['body'], true );
		$loginToken = $tokenData['query']['tokens']['logintoken'] ?? null;

		if ( $loginToken === null ) {
			throw new \RuntimeException(
				"No login token in response for $username: "
				. $tokenResponse['body']
			);
		}

		// Step 2: POST clientlogin
		$loginResponse = self::httpPost(
			self::WIKI_URL . '/api.php',
			[
				'action' => 'clientlogin',
				'username' => $username,
				'password' => $password,
				'logintoken' => $loginToken,
				'loginreturnurl' => self::WIKI_URL,
				'format' => 'json',
			],
			$cookies
		);

		if ( $loginResponse['status'] !== 200 ) {
			throw new \RuntimeException(
				"Login HTTP error for $username: HTTP "
				. $loginResponse['status']
			);
		}

		// Accumulate cookies from login response
		$cookies = self::parseCookies( $loginResponse['headers'], $cookies );

		$loginData = json_decode( $loginResponse['body'], true );
		$loginStatus = $loginData['clientlogin']['status'] ?? 'UNKNOWN';

		if ( $loginStatus !== 'PASS' ) {
			throw new \RuntimeException(
				"Login failed for $username: status=$loginStatus. "
				. 'Response: ' . $loginResponse['body']
			);
		}

		return $cookies;
	}

	/**
	 * Parse Set-Cookie headers and merge into existing cookie array.
	 *
	 * @param array $headers Response headers
	 * @param array<string, string> $existing Existing cookies to merge with
	 * @return array<string, string> Updated cookie array
	 */
	private static function parseCookies( array $headers, array $existing = [] ): array {
		if ( !isset( $headers['set-cookie'] ) ) {
			return $existing;
		}

		$setCookies = $headers['set-cookie'];
		if ( !is_array( $setCookies ) ) {
			$setCookies = [ $setCookies ];
		}

		foreach ( $setCookies as $cookieStr ) {
			// Extract name=value from "name=value; path=/; ..."
			$parts = explode( ';', $cookieStr );
			$nameValue = explode( '=', trim( $parts[0] ), 2 );
			if ( count( $nameValue ) === 2 ) {
				$existing[trim( $nameValue[0] )] = trim( $nameValue[1] );
			}
		}

		return $existing;
	}

	// =========================================================================
	// Test Data Seeding
	// =========================================================================

	/**
	 * Seed test files at each permission level.
	 *
	 * Uploads 3 test PNG files via MW API and sets their permission levels
	 * using the fileperm-set-level API action.
	 */
	private static function seedTestFiles(): void {
		$csrfToken = self::getCsrfToken( self::$adminCookies );

		foreach ( self::$testFiles as $level => $filename ) {
			self::uploadTestFile( $filename, $csrfToken );
			self::setFilePermissionLevel( $filename, $level, $csrfToken );
		}

		self::$seeded = true;
	}

	/**
	 * Upload a test PNG file via MW API.
	 *
	 * @param string $filename Target filename
	 * @param string $csrfToken CSRF token
	 */
	private static function uploadTestFile( string $filename, string $csrfToken ): void {
		$pngData = base64_decode( self::PNG_BASE64 );

		// Write PNG to temp file for CURLFile
		$tmpFile = tempnam( sys_get_temp_dir(), 'e2e_png_' );
		file_put_contents( $tmpFile, $pngData );

		try {
			$response = self::httpPost(
				self::WIKI_URL . '/api.php',
				[
					'action' => 'upload',
					'filename' => $filename,
					'token' => $csrfToken,
					'ignorewarnings' => '1',
					'format' => 'json',
					'file' => new \CURLFile( $tmpFile, 'image/png', $filename ),
				],
				self::$adminCookies
			);

			$data = json_decode( $response['body'], true );
			$result = $data['upload']['result'] ?? 'UNKNOWN';

			if ( $result !== 'Success' ) {
				self::fail(
					"Failed to upload $filename: result=$result. "
					. 'Response: ' . $response['body']
				);
			}
		} finally {
			@unlink( $tmpFile );
		}
	}

	/**
	 * Set the permission level for a file via the fileperm-set-level API.
	 *
	 * @param string $filename File title (without namespace prefix)
	 * @param string $level Permission level
	 * @param string $csrfToken CSRF token
	 */
	private static function setFilePermissionLevel(
		string $filename,
		string $level,
		string $csrfToken
	): void {
		$response = self::httpPost(
			self::WIKI_URL . '/api.php',
			[
				'action' => 'fileperm-set-level',
				'title' => 'File:' . $filename,
				'level' => $level,
				'token' => $csrfToken,
				'format' => 'json',
			],
			self::$adminCookies
		);

		$data = json_decode( $response['body'], true );

		if ( isset( $data['error'] ) ) {
			self::fail(
				"Failed to set permission level $level on $filename: "
				. ( $data['error']['info'] ?? 'unknown error' )
				. '. Response: ' . $response['body']
			);
		}
	}

	/**
	 * Get a CSRF token for the current admin session.
	 *
	 * @param array<string, string> $cookies Session cookies
	 * @return string CSRF token
	 */
	private static function getCsrfToken( array $cookies ): string {
		$response = self::httpGet(
			self::WIKI_URL . '/api.php?action=query&meta=tokens&format=json',
			$cookies
		);

		if ( $response['status'] !== 200 ) {
			self::fail(
				'Failed to get CSRF token: HTTP ' . $response['status']
			);
		}

		$data = json_decode( $response['body'], true );
		$token = $data['query']['tokens']['csrftoken'] ?? null;

		if ( $token === null ) {
			self::fail(
				'No CSRF token in response: ' . $response['body']
			);
		}

		return $token;
	}

	/**
	 * Delete test files via MW API.
	 */
	private static function cleanupTestFiles(): void {
		$csrfToken = self::getCsrfToken( self::$adminCookies );

		foreach ( self::$testFiles as $level => $filename ) {
			self::httpPost(
				self::WIKI_URL . '/api.php',
				[
					'action' => 'delete',
					'title' => 'File:' . $filename,
					'token' => $csrfToken,
					'reason' => 'E2E cleanup',
					'format' => 'json',
				],
				self::$adminCookies
			);

			// Ignore errors during cleanup (file may not exist)
		}
	}

	// =========================================================================
	// Helper Methods for Subclasses
	// =========================================================================

	/**
	 * Get cached admin session cookies.
	 *
	 * @return array<string, string> Admin cookies
	 */
	protected static function getAdminCookies(): array {
		return self::$adminCookies;
	}

	/**
	 * Get cached TestUser session cookies.
	 *
	 * @return array<string, string> TestUser cookies
	 */
	protected static function getTestUserCookies(): array {
		return self::$testUserCookies;
	}

	/**
	 * Get img_auth.php URL for a file (original image).
	 *
	 * @param string $filename File name (e.g., 'E2E_Test_Public.png')
	 * @return string Full URL via img_auth.php
	 */
	protected static function getImgAuthUrl( string $filename ): string {
		return self::WIKI_URL . '/img_auth.php/' . rawurlencode( $filename );
	}

	/**
	 * Get img_auth.php URL for a thumbnail.
	 *
	 * @param string $filename Original file name
	 * @param string $thumbName Thumbnail name (e.g., '120px-E2E_Test_Public.png')
	 * @return string Full URL via img_auth.php
	 */
	protected static function getImgAuthThumbUrl( string $filename, string $thumbName ): string {
		return self::WIKI_URL . '/img_auth.php/thumb/'
			. rawurlencode( $filename ) . '/' . rawurlencode( $thumbName );
	}

	/**
	 * Get direct Apache path URL for a file (bypassing img_auth.php).
	 *
	 * Computes the MD5 hash path used by MediaWiki's default file storage:
	 * first char / first two chars / filename (e.g., a/ab/File.png).
	 *
	 * @param string $filename File name
	 * @return string Direct /images/ URL
	 */
	protected static function getDirectImageUrl( string $filename ): string {
		$hash = md5( $filename );
		$hashPath = $hash[0] . '/' . substr( $hash, 0, 2 ) . '/';
		return self::WIKI_URL . '/images/' . $hashPath . rawurlencode( $filename );
	}

	/**
	 * Get direct Apache path URL for a thumbnail (bypassing img_auth.php).
	 *
	 * @param string $filename Original file name
	 * @param string $thumbName Thumbnail name
	 * @return string Direct /images/thumb/ URL
	 */
	protected static function getDirectThumbUrl( string $filename, string $thumbName ): string {
		$hash = md5( $filename );
		$hashPath = $hash[0] . '/' . substr( $hash, 0, 2 ) . '/';
		return self::WIKI_URL . '/images/thumb/' . $hashPath
			. rawurlencode( $filename ) . '/' . rawurlencode( $thumbName );
	}

	/**
	 * Get the map of permission level => test filename.
	 *
	 * @return array<string, string> Level => filename
	 */
	public static function getTestFileNames(): array {
		return self::$testFiles;
	}
}
