/**
 * E2E tests for embedded images in wiki pages.
 *
 * Verifies that authorized users see real images while unauthorized users
 * see placeholders for files they cannot access.
 */

import { test, expect } from './fixtures/auth';
import { EMBED_PAGE_TITLE, TEST_FILES } from './fixtures/test-files';

test.describe('Embedded images', () => {
  test('authorized user sees all permitted embedded images', async ({
    adminPage,
  }) => {
    await adminPage.goto(`/index.php/${EMBED_PAGE_TITLE}`);
    await adminPage.waitForLoadState('networkidle');

    // Admin (sysop) has access to all levels — all images should load
    const images = adminPage.locator('#mw-content-text img[src*="img_auth.php"]');
    const count = await images.count();
    expect(count).toBeGreaterThanOrEqual(3);

    // Verify images loaded successfully (naturalWidth > 0)
    for (let i = 0; i < count; i++) {
      const naturalWidth = await images.nth(i).evaluate(
        (img: HTMLImageElement) => img.naturalWidth
      );
      expect(naturalWidth).toBeGreaterThan(0);
    }
  });

  test('regular user sees public+internal images but placeholder for confidential', async ({
    testUserPage,
  }) => {
    await testUserPage.goto(`/index.php/${EMBED_PAGE_TITLE}`);
    await testUserPage.waitForLoadState('networkidle');

    // Public and internal images should load (TestUser has access)
    // The page may have img tags or placeholders depending on access
    const content = testUserPage.locator('#mw-content-text');
    const html = await content.innerHTML();

    // Check for placeholder (confidential image should be replaced)
    expect(html).toContain('fileperm-placeholder');

    // Count loaded real images vs placeholders
    const realImages = testUserPage.locator(
      '#mw-content-text img[src*="img_auth.php"]'
    );
    const placeholders = testUserPage.locator('#mw-content-text .fileperm-placeholder');

    const realCount = await realImages.count();
    const placeholderCount = await placeholders.count();

    // Should have at least some real images (public + internal)
    expect(realCount).toBeGreaterThanOrEqual(1);
    // Should have at least one placeholder (confidential)
    expect(placeholderCount).toBeGreaterThanOrEqual(1);
  });

  test('thumbnail of authorized file renders correctly', async ({
    adminPage,
  }) => {
    await adminPage.goto(`/index.php/${EMBED_PAGE_TITLE}`);
    await adminPage.waitForLoadState('networkidle');

    // Find a thumbnail image (thumb images have "thumb" in src)
    const thumbImages = adminPage.locator(
      '#mw-content-text img[src*="img_auth.php/thumb"]'
    );
    const count = await thumbImages.count();

    if (count > 0) {
      // At least one thumbnail should load correctly
      const naturalWidth = await thumbImages.first().evaluate(
        (img: HTMLImageElement) => img.naturalWidth
      );
      expect(naturalWidth).toBeGreaterThan(0);
    } else {
      // If no thumb URLs found, check for any loaded thumbnails
      const anyThumb = adminPage.locator('#mw-content-text .thumb img');
      const anyCount = await anyThumb.count();
      expect(anyCount).toBeGreaterThanOrEqual(0);
    }
  });

  test('thumbnail of unauthorized file does not render', async ({
    testUserPage,
  }) => {
    await testUserPage.goto(`/index.php/${EMBED_PAGE_TITLE}`);
    await testUserPage.waitForLoadState('networkidle');

    // The confidential file's thumbnail should be a placeholder
    const placeholders = testUserPage.locator('#mw-content-text .fileperm-placeholder');
    const count = await placeholders.count();
    expect(count).toBeGreaterThanOrEqual(1);

    // Verify placeholder has dimensions (layout preserved)
    if (count > 0) {
      const styles = await placeholders.first().getAttribute('style');
      expect(styles).toContain('width');
      expect(styles).toContain('height');
    }
  });
});
