<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use FilePermissions\PermissionService;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\ImageBeforeProduceHTMLHook;
use MediaWiki\Hook\ImgAuthBeforeStreamHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;

/**
 * Enforcement hooks that deny unauthorized access to protected files.
 *
 * Implements three hook interfaces to cover all file access paths:
 * - File: description pages (getUserPermissionsErrors)
 * - Raw file/thumbnail access via img_auth.php (ImgAuthBeforeStream)
 * - Embedded images in wiki pages (ImageBeforeProduceHTML)
 */
class EnforcementHooks implements
	GetUserPermissionsErrorsHook,
	ImgAuthBeforeStreamHook,
	ImageBeforeProduceHTMLHook
{
	private PermissionService $permissionService;

	public function __construct( PermissionService $permissionService ) {
		$this->permissionService = $permissionService;
	}

	/**
	 * Block File: page access for unauthorized users.
	 * ENFC-01: getUserPermissionsErrors hook denies File: page access
	 *
	 * @param \MediaWiki\Title\Title $title
	 * @param \MediaWiki\User\User $user
	 * @param string $action
	 * @param array|string|MessageSpecifier &$result
	 * @return bool
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		// Only apply to File: namespace
		if ( $title->getNamespace() !== NS_FILE ) {
			return true;
		}

		// Only check 'read' action
		if ( $action !== 'read' ) {
			return true;
		}

		// Check permission via service
		if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
			// Generic error - does not reveal required level
			$result = [ 'filepermissions-access-denied' ];
			return false;
		}

		return true;
	}

	/**
	 * Block raw file and thumbnail access via img_auth.php.
	 * ENFC-02: ImgAuthBeforeStream hook denies raw file downloads
	 * ENFC-03: Thumbnail access denied via ImgAuthBeforeStream
	 *
	 * The $title is already resolved from thumbnail/archive paths by MediaWiki.
	 *
	 * @param \MediaWiki\Title\Title &$title
	 * @param string &$path
	 * @param string &$name
	 * @param array &$result
	 * @return bool
	 */
	public function onImgAuthBeforeStream( &$title, &$path, &$name, &$result ) {
		$user = RequestContext::getMain()->getUser();

		if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
			// Return 403 with MediaWiki standard messages
			// $result[0] = header message key (used for response header)
			// $result[1] = body message key (used if $wgImgAuthDetails = true)
			$result = [
				'img-auth-accessdenied',
				'filepermissions-img-denied'
			];
			return false;
		}

		return true;
	}

	/**
	 * Replace embedded images with placeholder for unauthorized users.
	 * ENFC-04: Embedded images fail to render for unauthorized users
	 *
	 * @param \Skin|null $unused
	 * @param \MediaWiki\Title\Title &$title
	 * @param \File|false &$file
	 * @param array &$frameParams
	 * @param array &$handlerParams
	 * @param string|false &$time
	 * @param string|null &$res
	 * @param \Parser $parser
	 * @param string &$query
	 * @param int|null &$widthOption
	 * @return bool
	 */
	public function onImageBeforeProduceHTML(
		$unused,
		&$title,
		&$file,
		&$frameParams,
		&$handlerParams,
		&$time,
		&$res,
		$parser,
		&$query,
		&$widthOption
	) {
		// Check if this file has a permission level (explicit or default)
		$level = $this->permissionService->getEffectiveLevel( $title );

		// If the file is protected, disable parser cache for this page.
		// The parser cache stores ONE version for all users, but different
		// users may have different file access levels. Without this, the
		// first user to trigger a parse determines what all users see.
		if ( $level !== null && $parser && $parser->getOutput() ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}

		$user = RequestContext::getMain()->getUser();

		if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
			// Use requested dimensions or fallback to default thumbnail size
			$width = $handlerParams['width'] ?? 220;
			$height = $handlerParams['height'] ?? $width;

			// Generate placeholder HTML and skip default rendering
			$res = $this->generatePlaceholderHtml( (int)$width, (int)$height );
			return false;
		}

		return true;
	}

	/**
	 * Generate placeholder HTML with lock icon SVG.
	 *
	 * Creates a non-clickable placeholder that:
	 * - Matches requested dimensions to preserve page layout
	 * - Shows a minimal grayscale lock icon
	 * - Uses inline SVG data URI to avoid extra HTTP request
	 *
	 * @param int $width Width in pixels
	 * @param int $height Height in pixels
	 * @return string HTML for the placeholder
	 */
	private function generatePlaceholderHtml( int $width, int $height ): string {
		// Lock icon SVG - minimal grayscale design
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#999">'
			. '<path d="M12 17a2 2 0 002-2v-2a2 2 0 00-4 0v2a2 2 0 002 2zm6-7V8a6 6 0 10-12 0v2'
			. 'a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2v-6a2 2 0 00-2-2z"/></svg>';

		// Base64-encode SVG to avoid HTML attribute escaping issues
		$dataUri = 'data:image/svg+xml;base64,' . base64_encode( $svg );

		// Non-clickable placeholder - no link wrapper, dead end
		return '<span class="fileperm-placeholder" style="display:inline-block;'
			. 'width:' . htmlspecialchars( (string)$width ) . 'px;'
			. 'height:' . htmlspecialchars( (string)$height ) . 'px;'
			. 'background:url(' . htmlspecialchars( $dataUri ) . ') center/50% no-repeat #f5f5f5;'
			. 'border:1px solid #ddd;"></span>';
	}
}
