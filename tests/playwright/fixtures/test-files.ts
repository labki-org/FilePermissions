/**
 * Test file utilities for Playwright E2E tests.
 *
 * Provides a minimal 1x1 PNG buffer and test filename constants
 * used across global setup/teardown and test specs.
 */

/** Minimal 1x1 pixel PNG as base64. */
const PNG_BASE64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

/** Returns a Buffer containing a valid 1x1 PNG image. */
export function createTestPng(): Buffer {
  return Buffer.from(PNG_BASE64, 'base64');
}

/** Filenames for seeded test data (without File: prefix). */
export const TEST_FILES = {
  public: 'PW_Test_Public.png',
  internal: 'PW_Test_Internal.png',
  confidential: 'PW_Test_Confidential.png',
  noLevel: 'PW_Test_NoLevel.png',
} as const;

/** Wiki page that embeds all test images. */
export const EMBED_PAGE_TITLE = 'PW_Embed_Test';

/** Wikitext content for the embed test page. */
export const EMBED_PAGE_WIKITEXT = `[[File:${TEST_FILES.public}]]
[[File:${TEST_FILES.internal}|thumb|200px|Internal image]]
[[File:${TEST_FILES.confidential}|thumb|200px|Confidential image]]
[[File:${TEST_FILES.noLevel}|thumb|200px|No level image]]`;
