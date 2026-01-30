<?php

declare( strict_types=1 );

namespace FilePermissions\Tests\E2E;

/**
 * E2E permission matrix test: 3 levels x 3 users x 2 vectors = 18 scenarios.
 *
 * Covers LEAK-07: exhaustive verification of the complete security surface
 * through img_auth.php. Every combination of permission level, user role,
 * and access vector (original/thumbnail) is tested.
 *
 * Expected results:
 *
 *                   | public | internal | confidential
 *   Admin (sysop)   | 200    | 200      | 200
 *   TestUser (user)  | 200    | 200      | 403
 *   Anonymous        | 403    | 403      | 403
 *
 * Both original and thumbnail paths follow the same expected pattern.
 *
 * @group e2e
 */
class PermissionMatrixTest extends E2ETestBase {

	/** @var array<string, array{status: int, level: string, vector: string}> Results for summary */
	private static array $matrixResults = [];

	/**
	 * Data provider yielding all 18 permission matrix scenarios.
	 *
	 * @return array<string, array{string, string, string, int}>
	 *   Keys: "userType-level-vector"
	 *   Values: [userType, level, vector, expectedStatus]
	 */
	public static function permissionMatrixProvider(): array {
		// Expected status matrix:
		//                  | public | internal | confidential
		// Admin (sysop)    | 200    | 200      | 200
		// TestUser (user)  | 200    | 200      | 403
		// Anonymous        | 403    | 403      | 403

		$matrix = [
			'admin'     => [ 'public' => 200, 'internal' => 200, 'confidential' => 200 ],
			'testuser'  => [ 'public' => 200, 'internal' => 200, 'confidential' => 403 ],
			'anonymous' => [ 'public' => 403, 'internal' => 403, 'confidential' => 403 ],
		];

		$vectors = [ 'original', 'thumbnail' ];
		$cases = [];

		foreach ( $matrix as $userType => $levels ) {
			foreach ( $levels as $level => $expectedStatus ) {
				foreach ( $vectors as $vector ) {
					$key = "{$userType}-{$level}-{$vector}";
					$cases[$key] = [ $userType, $level, $vector, $expectedStatus ];
				}
			}
		}

		return $cases;
	}

	/**
	 * Test a single cell in the permission matrix.
	 *
	 * @dataProvider permissionMatrixProvider
	 */
	public function testPermissionMatrix(
		string $userType,
		string $level,
		string $vector,
		int $expectedStatus
	): void {
		$cookies = $this->getCookiesForUser( $userType );
		$filename = self::getTestFileNames()[$level];
		$url = $this->getUrlForVector( $filename, $vector );

		$response = self::httpGet( $url, $cookies );

		// Record result for summary output
		$key = "{$userType}-{$level}-{$vector}";
		self::$matrixResults[$key] = [
			'status' => $response['status'],
			'level' => $level,
			'vector' => $vector,
		];

		self::assertSame(
			$expectedStatus,
			$response['status'],
			"[{$userType}] [{$level}] [{$vector}] expected {$expectedStatus}, "
			. "got {$response['status']}"
		);
	}

	/**
	 * Print human-readable permission matrix summary after all tests.
	 */
	public static function tearDownAfterClass(): void {
		if ( !empty( self::$matrixResults ) ) {
			self::printMatrixSummary();
		}

		self::$matrixResults = [];

		parent::tearDownAfterClass();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get cookies for a given user type.
	 *
	 * @param string $userType 'admin', 'testuser', or 'anonymous'
	 * @return array<string, string> Session cookies (empty for anonymous)
	 */
	private function getCookiesForUser( string $userType ): array {
		switch ( $userType ) {
			case 'admin':
				return self::getAdminCookies();
			case 'testuser':
				return self::getTestUserCookies();
			case 'anonymous':
				return [];
			default:
				self::fail( "Unknown user type: $userType" );
		}
	}

	/**
	 * Get URL for a given file and access vector.
	 *
	 * @param string $filename File name
	 * @param string $vector 'original' or 'thumbnail'
	 * @return string Full URL via img_auth.php
	 */
	private function getUrlForVector( string $filename, string $vector ): string {
		switch ( $vector ) {
			case 'original':
				return self::getImgAuthUrl( $filename );
			case 'thumbnail':
				$thumbName = '120px-' . $filename;
				return self::getImgAuthThumbUrl( $filename, $thumbName );
			default:
				self::fail( "Unknown vector: $vector" );
		}
	}

	/**
	 * Print a formatted permission matrix summary to stdout.
	 */
	private static function printMatrixSummary(): void {
		$users = [ 'admin' => 'Admin', 'testuser' => 'TestUser', 'anonymous' => 'Anonymous' ];
		$levels = [ 'public', 'internal', 'confidential' ];

		$output = "\n";
		$output .= "=== FilePermissions E2E Permission Matrix ===\n\n";
		$output .= sprintf( "%-14s | %-8s | %-10s | %-14s\n", '', 'public', 'internal', 'confidential' );
		$output .= str_repeat( '-', 56 ) . "\n";

		foreach ( $users as $userKey => $userLabel ) {
			$row = sprintf( "  %-12s |", $userLabel );

			foreach ( $levels as $level ) {
				// Check both vectors; report combined status
				$origKey = "{$userKey}-{$level}-original";
				$thumbKey = "{$userKey}-{$level}-thumbnail";
				$origStatus = self::$matrixResults[$origKey]['status'] ?? '?';
				$thumbStatus = self::$matrixResults[$thumbKey]['status'] ?? '?';

				if ( $origStatus === $thumbStatus ) {
					$cell = "OK {$origStatus}";
				} else {
					$cell = "MISMATCH {$origStatus}/{$thumbStatus}";
				}

				$width = $level === 'confidential' ? 14 : ( $level === 'internal' ? 10 : 8 );
				$row .= sprintf( " %-{$width}s |", $cell );
			}

			$output .= $row . "\n";
		}

		$output .= "\n";
		$output .= "Legend: OK = both vectors matched expected status\n";
		$output .= "Vectors tested: original, thumbnail (both via img_auth.php)\n";
		$output .= "Total scenarios: 18 (3 levels x 3 users x 2 vectors)\n";
		$output .= "\n";

		fwrite( STDOUT, $output );
	}
}
