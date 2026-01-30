/**
 * E2E tests for VisualEditor upload dialog integration.
 *
 * Verifies the FilePermissions OOUI dropdown appears in VE's upload dialog,
 * contains all configured levels, defaults correctly, stores permissions
 * on upload, and resets when the dialog is reused.
 */

import { test, expect } from './fixtures/auth';
import { queryFilePermLevel, deletePage } from './fixtures/wiki-api';
import { createTestPng } from './fixtures/test-files';

const VE_DROPDOWN_SELECTOR = '.fileperm-ve-dropdown';

/**
 * Open VE and navigate to the upload dialog.
 * Returns when the upload tab/form is visible.
 */
async function openVEUploadDialog(page: any) {
  await page.goto('/index.php?title=PW_VE_Test&veaction=edit');

  // Wait for VE to fully load
  await page.waitForSelector('.ve-ui-surface', { timeout: 30_000 });

  // Open Insert menu and click Media
  const insertMenu = page.locator('.ve-ui-toolbar-group-insert');
  await insertMenu.click();

  // Look for "Media" or "Images and media" in the insert menu
  const mediaItem = page.locator(
    '.ve-ui-toolbar-group-insert .oo-ui-tool-name-media a, ' +
    '.oo-ui-tool-name-media .oo-ui-tool-link'
  );
  await mediaItem.first().click();

  // Wait for media dialog
  await page.waitForSelector('.ve-ui-mwMediaDialog', { timeout: 15_000 });

  // Click the Upload tab
  const uploadTab = page.locator(
    '.oo-ui-indexLayout-tabPanels .oo-ui-tabOptionWidget:has-text("Upload"), ' +
    '.oo-ui-menuLayout-menu .oo-ui-optionWidget:has-text("Upload")'
  );
  if (await uploadTab.first().isVisible()) {
    await uploadTab.first().click();
  }

  // Wait for upload form to be ready
  await page.waitForTimeout(1000);
}

test.describe('VisualEditor upload dialog', () => {
  test('dropdown appears in VE upload dialog', async ({ adminPage }) => {
    await openVEUploadDialog(adminPage);

    // Set a file to trigger the info form rendering
    const fileInput = adminPage.locator('.ve-ui-mwMediaDialog input[type="file"]');
    if (await fileInput.isVisible()) {
      const png = createTestPng();
      await fileInput.setInputFiles({
        name: 'PW_VE_Dropdown_Test.png',
        mimeType: 'image/png',
        buffer: png,
      });
      // Wait for stash upload to complete and info form to render
      await adminPage.waitForTimeout(5000);
    }

    const dropdown = adminPage.locator(VE_DROPDOWN_SELECTOR);
    await expect(dropdown).toBeVisible({ timeout: 15_000 });
  });

  test('dropdown contains all configured levels', async ({ adminPage }) => {
    await openVEUploadDialog(adminPage);

    // Set a file to trigger info form
    const fileInput = adminPage.locator('.ve-ui-mwMediaDialog input[type="file"]');
    if (await fileInput.isVisible()) {
      const png = createTestPng();
      await fileInput.setInputFiles({
        name: 'PW_VE_Levels_Test.png',
        mimeType: 'image/png',
        buffer: png,
      });
      await adminPage.waitForTimeout(5000);
    }

    // Read OOUI dropdown options via JS evaluation
    const options = await adminPage.evaluate((sel) => {
      const widget = document.querySelector(sel);
      if (!widget) return [];
      const select = widget.querySelector('select');
      if (!select) return [];
      return Array.from(select.options).map((opt) => opt.value);
    }, VE_DROPDOWN_SELECTOR);

    expect(options).toEqual(['public', 'internal', 'confidential']);
  });

  test('dropdown defaults to first level', async ({ adminPage }) => {
    await openVEUploadDialog(adminPage);

    const fileInput = adminPage.locator('.ve-ui-mwMediaDialog input[type="file"]');
    if (await fileInput.isVisible()) {
      const png = createTestPng();
      await fileInput.setInputFiles({
        name: 'PW_VE_Default_Test.png',
        mimeType: 'image/png',
        buffer: png,
      });
      await adminPage.waitForTimeout(5000);
    }

    // Read current value from OOUI dropdown
    const value = await adminPage.evaluate((sel) => {
      const widget = document.querySelector(sel);
      if (!widget) return '';
      const select = widget.querySelector('select');
      return select?.value ?? '';
    }, VE_DROPDOWN_SELECTOR);

    expect(value).toBe('public');
  });

  test('full VE upload flow stores permission level', async ({
    adminPage,
    adminContext,
  }) => {
    const testFilename = 'PW_VE_Upload_Flow_Test.png';

    await openVEUploadDialog(adminPage);

    // Upload a file
    const fileInput = adminPage.locator('.ve-ui-mwMediaDialog input[type="file"]');
    const png = createTestPng();
    await fileInput.setInputFiles({
      name: testFilename,
      mimeType: 'image/png',
      buffer: png,
    });

    // Wait for stash upload to complete
    await adminPage.waitForTimeout(5000);

    // Select "confidential" from the FilePermissions dropdown via OOUI
    await adminPage.evaluate((sel) => {
      const widget = document.querySelector(sel);
      if (!widget) return;
      const select = widget.querySelector('select');
      if (select) {
        select.value = 'confidential';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, VE_DROPDOWN_SELECTOR);

    // Fill required description field
    const descField = adminPage.locator(
      '.ve-ui-mwMediaDialog .oo-ui-textInputWidget input, ' +
      '.ve-ui-mwMediaDialog textarea'
    );
    if (await descField.first().isVisible()) {
      await descField.first().fill('Playwright test upload');
    }

    // Click save/upload button
    const saveButton = adminPage.locator(
      '.ve-ui-mwMediaDialog .oo-ui-processDialog-actions-primary .oo-ui-buttonElement-button'
    );
    if (await saveButton.isVisible()) {
      await saveButton.click();
    }

    // Wait for dialog to close and DeferredUpdates
    await adminPage.waitForTimeout(5000);

    // Verify via API
    const apiContext = adminContext.request;
    const level = await queryFilePermLevel(apiContext, testFilename);
    expect(level).toBe('confidential');

    // Clean up
    await deletePage(apiContext, `File:${testFilename}`);
  });

  test('dropdown resets when dialog is reused', async ({ adminPage }) => {
    await openVEUploadDialog(adminPage);

    // Set a file to trigger info form
    const fileInput = adminPage.locator('.ve-ui-mwMediaDialog input[type="file"]');
    if (await fileInput.isVisible()) {
      const png = createTestPng();
      await fileInput.setInputFiles({
        name: 'PW_VE_Reset_Test.png',
        mimeType: 'image/png',
        buffer: png,
      });
      await adminPage.waitForTimeout(5000);
    }

    // Select "confidential"
    await adminPage.evaluate((sel) => {
      const widget = document.querySelector(sel);
      if (!widget) return;
      const select = widget.querySelector('select');
      if (select) {
        select.value = 'confidential';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, VE_DROPDOWN_SELECTOR);

    // Close dialog (cancel)
    const cancelButton = adminPage.locator(
      '.ve-ui-mwMediaDialog .oo-ui-processDialog-actions-safe .oo-ui-buttonElement-button'
    );
    if (await cancelButton.isVisible()) {
      await cancelButton.click();
    }
    await adminPage.waitForTimeout(1000);

    // Re-open dialog
    await openVEUploadDialog(adminPage);

    // Set another file to trigger info form rendering
    const fileInput2 = adminPage.locator('.ve-ui-mwMediaDialog input[type="file"]');
    if (await fileInput2.isVisible()) {
      const png = createTestPng();
      await fileInput2.setInputFiles({
        name: 'PW_VE_Reset_Test2.png',
        mimeType: 'image/png',
        buffer: png,
      });
      await adminPage.waitForTimeout(5000);
    }

    // Assert dropdown value reset to default ("public")
    const value = await adminPage.evaluate((sel) => {
      const widget = document.querySelector(sel);
      if (!widget) return '';
      const select = widget.querySelector('select');
      return select?.value ?? '';
    }, VE_DROPDOWN_SELECTOR);

    expect(value).toBe('public');
  });
});
