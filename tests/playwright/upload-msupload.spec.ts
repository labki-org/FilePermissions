/**
 * E2E tests for MsUpload integration in the source editor.
 *
 * Verifies the FilePermissions dropdown is injected into MsUpload's toolbar,
 * contains all configured levels, pre-selects the first level, and that
 * uploads via MsUpload store the selected permission level.
 */

import { test, expect } from './fixtures/auth';
import { queryFilePermLevel, deletePage } from './fixtures/wiki-api';
import { createTestPng } from './fixtures/test-files';

const MSUPLOAD_DIV = '#msupload-div';
const CONTROLS_SELECTOR = '#fileperm-msupload-controls';
const SELECT_SELECTOR = '#fileperm-msupload-select';

test.describe('MsUpload source editor integration', () => {
  test('dropdown injected into MsUpload toolbar', async ({ adminPage }) => {
    await adminPage.goto('/index.php?title=PW_MsUpload_Test&action=edit');

    // Wait for MsUpload div to appear
    await adminPage.waitForSelector(MSUPLOAD_DIV, { timeout: 30_000 });

    // Assert controls are visible
    const controls = adminPage.locator(CONTROLS_SELECTOR);
    await expect(controls).toBeVisible();

    // Assert it's a select element
    const select = adminPage.locator(SELECT_SELECTOR);
    await expect(select).toBeVisible();
    const tagName = await select.evaluate((el) => el.tagName.toLowerCase());
    expect(tagName).toBe('select');
  });

  test('dropdown contains all configured levels', async ({ adminPage }) => {
    await adminPage.goto('/index.php?title=PW_MsUpload_Test&action=edit');
    await adminPage.waitForSelector(MSUPLOAD_DIV, { timeout: 30_000 });

    const options = adminPage.locator(`${SELECT_SELECTOR} option`);
    const values: string[] = [];
    const count = await options.count();
    for (let i = 0; i < count; i++) {
      values.push(await options.nth(i).getAttribute('value') ?? '');
    }
    expect(values).toEqual(['public', 'internal', 'confidential']);
  });

  test('first level pre-selected when no namespace default', async ({
    adminPage,
  }) => {
    await adminPage.goto('/index.php?title=PW_MsUpload_Test&action=edit');
    await adminPage.waitForSelector(MSUPLOAD_DIV, { timeout: 30_000 });

    const select = adminPage.locator(SELECT_SELECTOR);
    await expect(select).toHaveValue('public');
  });

  test('upload via MsUpload stores selected permission level', async ({
    adminPage,
    adminContext,
  }) => {
    const testFilename = 'PW_MsUpload_Level_Test.png';

    await adminPage.goto('/index.php?title=PW_MsUpload_Test&action=edit');
    await adminPage.waitForSelector(MSUPLOAD_DIV, { timeout: 30_000 });

    // Select "internal" from the FilePermissions dropdown
    await adminPage.selectOption(SELECT_SELECTOR, 'internal');

    // Trigger file upload via MsUpload's file input
    const fileInput = adminPage.locator(`${MSUPLOAD_DIV} input[type="file"]`);
    const png = createTestPng();
    await fileInput.setInputFiles({
      name: testFilename,
      mimeType: 'image/png',
      buffer: png,
    });

    // Wait for upload completion — MsUpload shows status in #msupload-list
    await adminPage.waitForSelector('#msupload-list .green, #msupload-list li.successful', {
      timeout: 30_000,
    }).catch(() => {
      // Fallback: wait for any completion indicator
    });

    // Wait for DeferredUpdates to store permission
    await adminPage.waitForTimeout(3000);

    // Verify via API
    const apiContext = adminContext.request;
    const level = await queryFilePermLevel(apiContext, testFilename);
    expect(level).toBe('internal');

    // Clean up
    await deletePage(apiContext, `File:${testFilename}`);
  });

  test('dropdown is disabled during upload and re-enabled after', async ({
    adminPage,
  }) => {
    const testFilename = 'PW_MsUpload_Disabled_Test.png';

    await adminPage.goto('/index.php?title=PW_MsUpload_Test&action=edit');
    await adminPage.waitForSelector(MSUPLOAD_DIV, { timeout: 30_000 });

    // Start an upload
    const fileInput = adminPage.locator(`${MSUPLOAD_DIV} input[type="file"]`);
    const png = createTestPng();

    // Set up a promise to check disabled state during upload
    const checkDisabled = adminPage.evaluate((selectSel) => {
      return new Promise<boolean>((resolve) => {
        const observer = new MutationObserver(() => {
          const el = document.querySelector(selectSel) as HTMLSelectElement;
          if (el && el.disabled) {
            observer.disconnect();
            resolve(true);
          }
        });
        const target = document.querySelector(selectSel);
        if (target) {
          observer.observe(target, { attributes: true });
        }
        // Timeout fallback
        setTimeout(() => {
          observer.disconnect();
          const el = document.querySelector(selectSel) as HTMLSelectElement;
          resolve(el?.disabled ?? false);
        }, 5000);
      });
    }, SELECT_SELECTOR);

    await fileInput.setInputFiles({
      name: testFilename,
      mimeType: 'image/png',
      buffer: png,
    });

    // Check that dropdown was disabled during upload
    const wasDisabled = await checkDisabled;
    expect(wasDisabled).toBe(true);

    // Wait for upload completion
    await adminPage.waitForTimeout(3000);

    // After upload, dropdown should be re-enabled
    const select = adminPage.locator(SELECT_SELECTOR);
    await expect(select).toBeEnabled({ timeout: 10_000 });

    // Clean up
    await deletePage(
      adminPage.context().request,
      `File:${testFilename}`
    );
  });
});
