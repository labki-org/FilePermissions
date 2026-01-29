<?php
// =============================================================================
// FilePermissions Test Environment Configuration
// =============================================================================

// Server Config
$wgServer = 'http://localhost:8888';

// =============================================================================
// CRITICAL: Enable img_auth.php for file access control
// =============================================================================
// This routes all file requests through img_auth.php, enabling ImgAuthBeforeStream hook
$wgUploadPath = "$wgScriptPath/img_auth.php";

// Enable image authorization
$wgImgAuthDetails = true;

// =============================================================================
// CRITICAL: Make img_auth.php enforce permission hooks
// =============================================================================
// MediaWiki's img_auth.php skips ALL permission hooks on "public" wikis
// (where anonymous users have 'read' permission). To enable per-file
// permission enforcement, we must remove anonymous read access.
//
// This means anonymous users cannot read wiki pages directly — they must
// log in. This matches the intended deployment: a private wiki with
// fine-grained file permissions.
$wgGroupPermissions['*']['read'] = false;

// Allow all logged-in users to read wiki pages
$wgGroupPermissions['user']['read'] = true;

// Allow anonymous access to login/create account pages and all main namespace pages
$wgWhitelistRead = [ 'Special:UserLogin', 'Special:CreateAccount', 'Main Page' ];
$wgWhitelistReadRegexp = [ '/^[^:]*$/' ];

// =============================================================================
// Load Extensions
// =============================================================================
wfLoadExtension( 'WikiEditor' );
wfLoadExtension( 'VisualEditor' );
$wgDefaultUserOptions['visualeditor-editor'] = 'visualeditor';

wfLoadExtension( 'MsUpload' );

wfLoadExtension( 'FilePermissions', '/mw-user-extensions/FilePermissions/extension.json' );

// =============================================================================
// FilePermissions Configuration
// =============================================================================
// Permission levels available for files
$wgFilePermLevels = [ 'public', 'internal', 'confidential' ];

// Group-to-level grants
// sysop: can access all levels (wildcard)
// user: can access public and internal only
// * (anonymous): no file access grants configured
$wgFilePermGroupGrants = [
	'sysop' => [ '*' ],
	'user' => [ 'public', 'internal' ],
];

// Default permission level (null = require explicit selection)
$wgFilePermDefaultLevel = null;

// Namespace defaults (empty = use global default)
$wgFilePermNamespaceDefaults = [];

// =============================================================================
// Debugging Configuration
// =============================================================================
$wgDebugLogGroups['filepermissions'] = '/var/log/mediawiki/filepermissions.log';
$wgShowExceptionDetails = true;
$wgDebugDumpSql = false;

// =============================================================================
// Test User Setup
// =============================================================================
// Admin user: Admin / dockerpass (created by labki-platform)
// - Has sysop group, can access ALL permission levels
//
// Test user: Create via Special:CreateAccount or maintenance script:
//   docker compose exec wiki php maintenance/run.php createAndPromote TestUser testpass123
// - Has 'user' group only, can access 'public' and 'internal' but NOT 'confidential'

// =============================================================================
// Skin Configuration
// =============================================================================
wfLoadSkin( 'Citizen' );
wfLoadSkin( 'Vector' );
$wgDefaultSkin = 'vector';

// =============================================================================
// Cache Configuration
// =============================================================================
$wgCacheDirectory = "$IP/cache-filepermissions";

// =============================================================================
// Testing Notes
// =============================================================================
// To test FilePermissions:
//
// 1. Upload a file as Admin:
//    - Go to Special:Upload
//    - Upload TestProtectedFile.png
//
// 2. Set permission level on the file:
//    a) Via the File page UI: navigate to File:TestProtectedFile.png, use the
//       permission level dropdown and Save button (requires edit-fileperm right).
//    b) Via the API:
//       curl -b cookies.txt -d "action=fileperm-set-level&title=File:TestProtectedFile.png&level=confidential&token=..." http://localhost:8888/api.php
//    c) Via direct SQL (useful for automated testing):
//       docker compose exec db mysql -u labki -plabki_pass labki -e "
//         INSERT INTO page_props (pp_page, pp_propname, pp_value)
//         SELECT page_id, 'fileperm_level', 'confidential'
//         FROM page WHERE page_title = 'TestProtectedFile.png' AND page_namespace = 6;
//       "
//
// 3. Test as unauthorized user:
//    - Log out or log in as TestUser (no confidential access)
//    - Navigate to File:TestProtectedFile.png - should see permission error
//    - Try direct URL /img_auth.php/... - should get 403
//
// 4. Test as authorized user:
//    - Log in as Admin (sysop)
//    - Navigate to File:TestProtectedFile.png - should see file page
//    - Direct URL should work
