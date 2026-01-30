/**
 * E2E tests for Special:Upload form integration.
 *
 * Verifies the FilePermissions dropdown appears on the standard upload form,
 * contains all configured levels with group names, validates selection,
 * and stores the permission on upload.
 */

import { test, expect } from './fixtures/auth';
import { createTestPng } from './fixtures/test-files';
import {
  queryFilePermLevel,
  deletePage,
  setFilePermLevel,
  uploadFile,
  getCsrfToken,
} from './fixtures/wiki-api';

const DROPDOWN_SELECTOR = 'select[name="wpFilePermLevel"]';

test.describe('Special:Upload form', () => {
  test('dropdown is visible on upload form', async ({ adminPage }) => {
    await adminPage.goto('/index.php/Special:Upload');
    const dropdown = adminPage.locator(DROPDOWN_SELECTOR);
    await expect(dropdown).toBeVisible();

    // Verify label exists
    const label = adminPage.locator('label[for="wpFilePermLevel"]');
    await expect(label).toBeVisible();
  });

  test('dropdown has placeholder and all configured levels', async ({ adminPage }) => {
    await adminPage.goto('/index.php/Special:Upload');
    const options = adminPage.locator(`${DROPDOWN_SELECTOR} option`);

    // First option is placeholder with empty value
    const firstOption = options.nth(0);
    await expect(firstOption).toHaveAttribute('value', '');

    // Remaining options are the 3 configured levels
    const values: string[] = [];
    const count = await options.count();
    for (let i = 1; i < count; i++) {
      values.push(await options.nth(i).getAttribute('value') ?? '');
    }
    expect(values).toEqual(['public', 'internal', 'confidential']);
  });

  test('option labels include granted group names', async ({ adminPage }) => {
    await adminPage.goto('/index.php/Special:Upload');
    const options = adminPage.locator(`${DROPDOWN_SELECTOR} option`);

    const count = await options.count();
    const labels: string[] = [];
    for (let i = 0; i < count; i++) {
      labels.push(await options.nth(i).textContent() ?? '');
    }

    // "public" should show both sysop and user groups
    const publicLabel = labels.find((l) => l.includes('public'));
    expect(publicLabel).toBeDefined();
    expect(publicLabel).toContain('sysop');
    expect(publicLabel).toContain('user');

    // "confidential" should show only sysop
    const confLabel = labels.find((l) => l.includes('confidential'));
    expect(confLabel).toBeDefined();
    expect(confLabel).toContain('sysop');
  });

  test('uploading with selected level stores the permission', async ({
    adminPage,
    adminContext,
  }) => {
    const testFilename = 'PW_Upload_Special_Test.png';

    await adminPage.goto('/index.php/Special:Upload');

    // Set file input
    const fileInput = adminPage.locator('input[name="wpUploadFile"]');
    const png = createTestPng();
    await fileInput.setInputFiles({
      name: testFilename,
      mimeType: 'image/png',
      buffer: png,
    });

    // Set destination filename
    await adminPage.fill('input[name="wpDestFile"]', testFilename);

    // Select "internal" level
    await adminPage.selectOption(DROPDOWN_SELECTOR, 'internal');

    // Check "Ignore any warnings" if present
    const ignoreWarnings = adminPage.locator('input[name="wpIgnoreWarning"]');
    if (await ignoreWarnings.isVisible()) {
      await ignoreWarnings.check();
    }

    // Submit form
    await adminPage.click('input[name="wpUpload"]');

    // Wait for redirect to File: page
    await adminPage.waitForURL(/File:/, { timeout: 30_000 });

    // Verify badge shows "internal"
    const badge = adminPage.locator('#fileperm-level-badge');
    await expect(badge).toHaveText('internal');

    // Verify via API
    const apiContext = adminContext.request;
    const level = await queryFilePermLevel(apiContext, testFilename);
    expect(level).toBe('internal');

    // Clean up
    await deletePage(apiContext, `File:${testFilename}`);
  });

  test('upload without selecting level shows validation error', async ({
    adminPage,
  }) => {
    const testFilename = 'PW_Upload_NoLevel_Test.png';

    await adminPage.goto('/index.php/Special:Upload');

    // Set file input
    const fileInput = adminPage.locator('input[name="wpUploadFile"]');
    const png = createTestPng();
    await fileInput.setInputFiles({
      name: testFilename,
      mimeType: 'image/png',
      buffer: png,
    });

    await adminPage.fill('input[name="wpDestFile"]', testFilename);

    // Leave dropdown on placeholder (empty value) — do not select a level

    // Check "Ignore any warnings" if present
    const ignoreWarnings = adminPage.locator('input[name="wpIgnoreWarning"]');
    if (await ignoreWarnings.isVisible()) {
      await ignoreWarnings.check();
    }

    // Submit form
    await adminPage.click('input[name="wpUpload"]');

    // Should show error — the page should contain an error message
    const errorText = adminPage.locator('.error, .errorbox, .mw-message-box-error');
    await expect(errorText.first()).toBeVisible({ timeout: 15_000 });
  });

  test('re-upload pre-selects existing permission level', async ({
    adminContext,
    adminPage,
  }) => {
    const testFilename = 'PW_Reupload_PreSelect_Test.png';
    const apiContext = adminContext.request;

    // Seed a file with "confidential" level via API
    const png = createTestPng();
    await uploadFile(apiContext, testFilename, png, {
      permLevel: 'confidential',
      ignoreWarnings: true,
    });
    await setFilePermLevel(apiContext, testFilename, 'confidential');
    // Wait for DeferredUpdates
    await new Promise((r) => setTimeout(r, 2000));

    // Navigate to upload form with pre-filled destination
    await adminPage.goto(
      `/index.php/Special:Upload?wpDestFile=${testFilename}`
    );

    // Assert dropdown value is "confidential"
    const dropdown = adminPage.locator(DROPDOWN_SELECTOR);
    await expect(dropdown).toHaveValue('confidential');

    // Clean up
    await deletePage(apiContext, `File:${testFilename}`);
  });
});
