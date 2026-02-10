<?php

declare( strict_types=1 );

namespace FilePermissions\Hooks;

use FilePermissions\PermissionService;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\ImageBeforeProduceHTMLHook;
use MediaWiki\Hook\ImgAuthBeforeStreamHook;
use MediaWiki\Hook\ParserOptionsRegisterHook;
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
	ImageBeforeProduceHTMLHook,
	ParserOptionsRegisterHook
{
	private const DEFAULT_THUMBNAIL_WIDTH = 220;

	private PermissionService $permissionService;
	private bool $hasDisabledCache = false;

	public function __construct( PermissionService $permissionService ) {
		$this->permissionService = $permissionService;
	}

	/**
	 * Register 'fileperm-user' parser option for cache key variation.
	 *
	 * Defense-in-depth: if any intermediate caching layer ignores the zero
	 * expiry set by onImageBeforeProduceHTML, different users still get
	 * different cache keys.
	 *
	 * @param array &$defaults
	 * @param array &$inCacheKey
	 * @param array &$lazyLoad
	 * @return void
	 */
	public function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyLoad ): void {
		$defaults['fileperm-user'] = null;
		$inCacheKey['fileperm-user'] = true;
		$lazyLoad['fileperm-user'] = static function () {
			return (string)RequestContext::getMain()->getUser()->getId();
		};
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
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ): bool {
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
	public function onImgAuthBeforeStream( &$title, &$path, &$name, &$result ): bool {
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
	): bool {
		// Check if this file has a permission level (explicit or default)
		$level = $this->permissionService->getEffectiveLevel( $title );

		// If the file is protected, disable parser cache for this page.
		// The parser cache stores ONE version for all users, but different
		// users may have different file access levels. Without this, the
		// first user to trigger a parse determines what all users see.
		if ( $level !== null && !$this->hasDisabledCache && $parser && $parser->getOutput() ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
			$parser->getOutput()->recordOption( 'fileperm-user' );
			$this->hasDisabledCache = true;
		}

		$user = RequestContext::getMain()->getUser();

		if ( !$this->permissionService->canUserAccessFile( $user, $title ) ) {
			// Use requested dimensions or fallback to default thumbnail size
			$width = $handlerParams['width'] ?? self::DEFAULT_THUMBNAIL_WIDTH;
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
	 * - Shows a refined lock icon with "Access restricted" label
	 * - Uses inline SVG data URI to avoid extra HTTP request
	 * - Scales icon and text proportionally with placeholder size
	 *
	 * Must remain fully inline (no external CSS) because this HTML is
	 * embedded in parser output on arbitrary wiki pages.
	 *
	 * @param int $width Width in pixels
	 * @param int $height Height in pixels
	 * @return string HTML for the placeholder
	 */
	private function generatePlaceholderHtml( int $width, int $height ): string {
		$minDim = min( $width, $height );
		$iconSize = max( 16, (int)( $minDim * 0.3 ) );

		// Lock icon SVG — two-part design (shackle + body), WikimediaUI color
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#72777d">'
			. '<rect x="3" y="11" width="18" height="11" rx="2"/>'
			. '<path d="M7 11V7a5 5 0 0110 0v4" fill="none" stroke="#72777d"'
			. ' stroke-width="2" stroke-linecap="round"/></svg>';

		// Base64-encode SVG to avoid HTML attribute escaping issues
		$dataUri = 'data:image/svg+xml;base64,' . base64_encode( $svg );

		$escapedWidth = htmlspecialchars( (string)$width );
		$escapedHeight = htmlspecialchars( (string)$height );

		// Show text label only when placeholder is large enough
		$textHtml = '';
		if ( $minDim >= 80 ) {
			$fontSize = max( 10, (int)( $minDim * 0.07 ) );
			$textMargin = max( 2, (int)( $iconSize * 0.15 ) );
			$label = htmlspecialchars(
				wfMessage( 'filepermissions-access-denied-short' )->text()
			);
			$textHtml = '<span style="font-size:' . $fontSize . 'px;color:#72777d;'
				. 'margin-top:' . $textMargin . 'px;font-family:sans-serif;">'
				. $label . '</span>';
		}

		// Non-clickable placeholder - no link wrapper, dead end
		return '<span class="fileperm-placeholder" style="display:inline-flex;'
			. 'align-items:center;justify-content:center;flex-direction:column;'
			. 'width:' . $escapedWidth . 'px;'
			. 'height:' . $escapedHeight . 'px;'
			. 'background:radial-gradient(ellipse,#f8f9fa 0%,#eaecf0 100%);'
			. 'border:1px solid #c8ccd1;border-radius:2px;">'
			. '<img src="' . htmlspecialchars( $dataUri )
			. '" alt="" style="width:' . $iconSize . 'px;height:' . $iconSize . 'px;'
			. 'opacity:0.6;" />'
			. $textHtml
			. '</span>';
	}
}
