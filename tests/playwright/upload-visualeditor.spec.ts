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

/**
 * Select a file in the VE upload dialog to trigger the info form rendering.
 * The FilePermissions dropdown is injected into the info form by the
 * monkey-patched BookletLayout#renderInfoForm.
 */
async function selectFileInVEDialog(page: any, filename: string) {
  const fileInput = page.locator('.ve-ui-mwMediaDialog input[type="file"]');
  if (await fileInput.isVisible()) {
    const png = createTestPng();
    await fileInput.setInputFiles({
      name: filename,
      mimeType: 'image/png',
      buffer: png,
    });
    // Wait for stash upload to complete and info form to render
    await page.waitForTimeout(5000);
  }
}

test.describe('VisualEditor upload dialog', () => {
  test('dropdown appears in VE upload dialog', async ({ adminPage }) => {
    await openVEUploadDialog(adminPage);
    await selectFileInVEDialog(adminPage, 'PW_VE_Dropdown_Test.png');

    // The dropdown is injected by monkey-patched renderInfoForm.
    // It may be in a non-active OOUI panel (hidden), so check DOM attachment
    // rather than visual visibility.
    const dropdown = adminPage.locator(VE_DROPDOWN_SELECTOR);
    await expect(dropdown).toBeAttached({ timeout: 15_000 });
  });

  test('dropdown contains all configured levels', async ({ adminPage }) => {
    await openVEUploadDialog(adminPage);
    await selectFileInVEDialog(adminPage, 'PW_VE_Levels_Test.png');

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
    await selectFileInVEDialog(adminPage, 'PW_VE_Default_Test.png');

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
    const apiContext = adminContext.request;

    // Clean up from any previous run
    await deletePage(apiContext, `File:${testFilename}`);

    await openVEUploadDialog(adminPage);
    await selectFileInVEDialog(adminPage, testFilename);

    // Wait for the FilePermissions dropdown to appear (injected by
    // the monkey-patched renderInfoForm when the info form panel renders)
    const dropdown = adminPage.locator(VE_DROPDOWN_SELECTOR);
    await expect(dropdown).toBeAttached({ timeout: 15_000 });

    // Select "confidential" from the FilePermissions dropdown via the
    // underlying <select> element (OOUI DropdownInputWidget wraps one)
    await adminPage.evaluate((sel) => {
      var container = document.querySelector(sel);
      if (!container) return;
      var select = container.querySelector('select');
      if (select) {
        select.value = 'confidential';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, VE_DROPDOWN_SELECTOR);

    // Perform a programmatic stash-then-publish upload via raw XHR.
    // VE's native stash upload (triggered by selectFileInVEDialog) is
    // unreliable in headless test environments, so we do it explicitly.
    // Phase 2 (publish-from-stash) goes through the patched
    // XMLHttpRequest.prototype.send. The VE bridge's onUploadSend callback
    // detects the filekey param in the FormData and appends wpFilePermLevel
    // from the activeDropdown set by renderInfoForm above.
    const uploadResult: any = await adminPage.evaluate((filename) => {
      return new Promise(function (resolve) {
        new mw.Api().getToken('csrf').then(function (token) {
          var b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';
          var binary = atob(b64);
          var bytes = new Uint8Array(binary.length);
          for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
          var file = new File([bytes], filename, { type: 'image/png' });

          // Phase 1: Upload to stash
          var stashData = new FormData();
          stashData.append('action', 'upload');
          stashData.append('filename', filename);
          stashData.append('file', file);
          stashData.append('stash', '1');
          stashData.append('ignorewarnings', '1');
          stashData.append('token', token);
          stashData.append('format', 'json');

          var stashXhr = new XMLHttpRequest();
          stashXhr.open('POST', '/api.php');
          stashXhr.onload = function () {
            try {
              var data = JSON.parse(stashXhr.responseText);
              var filekey = data.upload && data.upload.filekey;
              if (!filekey) {
                resolve({ success: false, error: 'no-filekey', response: stashXhr.responseText.substring(0, 200) });
                return;
              }

              // Phase 2: Publish from stash — the VE bridge's XHR callback
              // injects wpFilePermLevel into this FormData
              var publishData = new FormData();
              publishData.append('action', 'upload');
              publishData.append('filekey', filekey);
              publishData.append('filename', filename);
              publishData.append('comment', 'Playwright VE upload flow test');
              publishData.append('ignorewarnings', '1');
              publishData.append('token', token);
              publishData.append('format', 'json');

              var publishXhr = new XMLHttpRequest();
              publishXhr.open('POST', '/api.php');
              publishXhr.onload = function () {
                resolve({ success: true });
              };
              publishXhr.onerror = function () {
                resolve({ success: false, error: 'publish-xhr:' + publishXhr.status });
              };
              publishXhr.send(publishData);
            } catch (e) {
              resolve({ success: false, error: 'stash-parse' });
            }
          };
          stashXhr.onerror = function () {
            resolve({ success: false, error: 'stash-xhr:' + stashXhr.status });
          };
          stashXhr.send(stashData);
        }, function (e) {
          resolve({ success: false, error: 'token:' + String(e) });
        });
      });
    }, testFilename);

    expect(uploadResult.success).toBe(true);

    // Wait for DeferredUpdates to store permission
    await adminPage.waitForTimeout(5000);

    // Verify via API
    const level = await queryFilePermLevel(apiContext, testFilename);
    expect(level).toBe('confidential');

    // Clean up
    await deletePage(apiContext, `File:${testFilename}`);
  });

  test('dropdown resets when dialog is reused', async ({ adminPage }) => {
    await openVEUploadDialog(adminPage);
    await selectFileInVEDialog(adminPage, 'PW_VE_Reset_Test.png');

    // Select "confidential" via the underlying select element
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
    await selectFileInVEDialog(adminPage, 'PW_VE_Reset_Test2.png');

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
