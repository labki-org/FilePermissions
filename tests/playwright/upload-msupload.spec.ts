/**
 * E2E tests for MsUpload integration in the source editor.
 *
 * Verifies the FilePermissions dropdown is injected into MsUpload's toolbar,
 * contains all configured levels, pre-selects the first level, and that
 * uploads via MsUpload store the selected permission level.
 */

import { test, expect } from './fixtures/auth';
import { queryFilePermLevel, deletePage } from './fixtures/wiki-api';

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
    const apiContext = adminContext.request;

    // Clean up from any previous run
    await deletePage(apiContext, `File:${testFilename}`);

    await adminPage.goto('/index.php?title=PW_MsUpload_Test&action=edit');
    await adminPage.waitForSelector(MSUPLOAD_DIV, { timeout: 30_000 });

    // Select "internal" from the FilePermissions dropdown
    await adminPage.selectOption(SELECT_SELECTOR, 'internal');

    // Upload via raw XHR on the edit page. The XHR goes through the patched
    // XMLHttpRequest.prototype.send (ext.FilePermissions.shared). The MsUpload
    // bridge's onUploadSend callback detects the FormData body with
    // action=upload and appends wpFilePermLevel from #fileperm-msupload-select.
    // We use raw XHR because plupload's moxie runtime ignores programmatic
    // file input changes and the mediawiki.api.upload module is unavailable.
    await adminPage.evaluate((filename) => {
      return new Promise((resolve) => {
        new mw.Api().getToken('csrf').then(function (token) {
          var b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';
          var binary = atob(b64);
          var bytes = new Uint8Array(binary.length);
          for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
          var file = new File([bytes], filename, { type: 'image/png' });

          var formData = new FormData();
          formData.append('action', 'upload');
          formData.append('filename', filename);
          formData.append('file', file);
          formData.append('comment', 'Playwright MsUpload test');
          formData.append('ignorewarnings', '1');
          formData.append('token', token);
          formData.append('format', 'json');

          var xhr = new XMLHttpRequest();
          xhr.open('POST', '/api.php');
          xhr.onload = function () {
            resolve('done:' + xhr.status);
          };
          xhr.onerror = function () {
            resolve('error:' + xhr.status);
          };
          xhr.send(formData);
        }, function (e) {
          resolve('token-error:' + String(e));
        });
      });
    }, testFilename);

    // Wait for DeferredUpdates to store permission
    await adminPage.waitForTimeout(5000);

    // Verify via API
    const level = await queryFilePermLevel(apiContext, testFilename);
    expect(level).toBe('internal');

    // Clean up
    await deletePage(apiContext, `File:${testFilename}`);
  });

  test('dropdown is disabled during upload and re-enabled after', async ({
    adminPage,
    adminContext,
  }) => {
    const testFilename = 'PW_MsUpload_Disabled_Test.png';

    // Clean up from any previous run
    await deletePage(adminContext.request, `File:${testFilename}`);

    await adminPage.goto('/index.php?title=PW_MsUpload_Test&action=edit');
    await adminPage.waitForSelector(MSUPLOAD_DIV, { timeout: 30_000 });

    // Use a single evaluate that sets up a MutationObserver, then triggers
    // the upload via raw XHR. The MsUpload bridge's onUploadSend callback
    // disables #fileperm-msupload-select synchronously when xhr.send fires.
    const wasDisabled = await adminPage.evaluate(([filename, selectSel]) => {
      return new Promise((resolve) => {
        var el = document.querySelector(selectSel);
        if (!el) { resolve(false); return; }

        // Observe disabled attribute changes
        var observer = new MutationObserver(function () {
          if (el.disabled) {
            observer.disconnect();
            resolve(true);
          }
        });
        observer.observe(el, { attributes: true, attributeFilter: ['disabled'] });

        // Get CSRF token and trigger upload via raw XHR
        new mw.Api().getToken('csrf').then(function (token) {
          var b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';
          var binary = atob(b64);
          var bytes = new Uint8Array(binary.length);
          for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
          var file = new File([bytes], filename, { type: 'image/png' });

          var formData = new FormData();
          formData.append('action', 'upload');
          formData.append('filename', filename);
          formData.append('file', file);
          formData.append('comment', 'Playwright disabled test');
          formData.append('ignorewarnings', '1');
          formData.append('token', token);
          formData.append('format', 'json');

          var xhr = new XMLHttpRequest();
          xhr.open('POST', '/api.php');
          xhr.send(formData);
        });

        // Timeout fallback
        setTimeout(function () {
          observer.disconnect();
          resolve(el.disabled);
        }, 15000);
      });
    }, [testFilename, SELECT_SELECTOR]);

    expect(wasDisabled).toBe(true);

    // After upload completes, dropdown should be re-enabled
    const select = adminPage.locator(SELECT_SELECTOR);
    await expect(select).toBeEnabled({ timeout: 15_000 });

    // Clean up
    await deletePage(adminContext.request, `File:${testFilename}`);
  });
});
