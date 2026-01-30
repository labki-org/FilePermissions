/**
 * E2E tests for File: description page display.
 *
 * Verifies permission badges, edit controls visibility based on user role,
 * level editing workflow, and access denial for unauthorized users.
 */

import { test, expect } from './fixtures/auth';
import { TEST_FILES } from './fixtures/test-files';
import { setFilePermLevel, queryFilePermLevel } from './fixtures/wiki-api';

test.describe('File: page display', () => {
  test('shows permission badge for protected file', async ({ adminPage }) => {
    await adminPage.goto(`/index.php/File:${TEST_FILES.internal}`);

    const indicator = adminPage.locator('div.fileperm-indicator');
    await expect(indicator).toBeVisible();

    const badge = adminPage.locator('#fileperm-level-badge');
    await expect(badge).toHaveText('internal');
  });

  test('shows edit controls for sysop user', async ({ adminPage }) => {
    await adminPage.goto(`/index.php/File:${TEST_FILES.internal}`);

    const dropdown = adminPage.locator('#fileperm-edit-dropdown');
    await expect(dropdown).toBeVisible();

    const saveButton = adminPage.locator('#fileperm-edit-save');
    await expect(saveButton).toBeVisible();
  });

  test('edit dropdown lists all configured levels', async ({ adminPage }) => {
    await adminPage.goto(`/index.php/File:${TEST_FILES.internal}`);

    // Wait for OOUI to load
    await adminPage.waitForSelector('#fileperm-edit-dropdown', { timeout: 10_000 });

    // Read OOUI dropdown options (the underlying select inside OOUI widget)
    const options = await adminPage.evaluate(() => {
      const dropdown = document.querySelector('#fileperm-edit-dropdown');
      if (!dropdown) return { values: [] as string[], currentValue: '' };
      const select = dropdown.querySelector('select');
      if (!select) return { values: [] as string[], currentValue: '' };
      return {
        values: Array.from(select.options).map((opt) => opt.value),
        currentValue: select.value,
      };
    });

    expect(options.values).toEqual(['public', 'internal', 'confidential']);
    expect(options.currentValue).toBe('internal');
  });

  test('does NOT show edit controls for non-sysop user', async ({
    testUserPage,
  }) => {
    await testUserPage.goto(`/index.php/File:${TEST_FILES.internal}`);

    // Edit controls should NOT be in the DOM
    const editControls = testUserPage.locator('#fileperm-edit-controls');
    await expect(editControls).toHaveCount(0);

    // But badge SHOULD be visible (TestUser has access to "internal")
    const badge = testUserPage.locator('#fileperm-level-badge');
    await expect(badge).toBeVisible();
  });

  test('saving level change updates badge and persists', async ({
    adminPage,
    adminContext,
  }) => {
    await adminPage.goto(`/index.php/File:${TEST_FILES.internal}`);
    await adminPage.waitForSelector('#fileperm-edit-dropdown', { timeout: 10_000 });

    // Change level to "public" via OOUI dropdown
    await adminPage.evaluate(() => {
      const dropdown = document.querySelector('#fileperm-edit-dropdown');
      if (!dropdown) return;
      const select = dropdown.querySelector('select');
      if (select) {
        select.value = 'public';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });

    // Click save button via OOUI infusion
    await adminPage.evaluate(() => {
      const btn = document.querySelector('#fileperm-edit-save');
      if (btn) btn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    // Wait for save to complete — look for success notification
    await adminPage.waitForSelector('.mw-notification-type-success, .mw-notification', {
      timeout: 10_000,
    }).catch(() => {});
    await adminPage.waitForTimeout(2000);

    // Assert badge changed
    const badge = adminPage.locator('#fileperm-level-badge');
    await expect(badge).toHaveText('public');

    // Reload and verify persistence
    await adminPage.reload();
    await expect(adminPage.locator('#fileperm-level-badge')).toHaveText('public');

    // Verify via API
    const apiContext = adminContext.request;
    const level = await queryFilePermLevel(apiContext, TEST_FILES.internal);
    expect(level).toBe('public');

    // Restore to "internal"
    await setFilePermLevel(apiContext, TEST_FILES.internal, 'internal');
  });

  test('no indicator for file without permission level', async ({
    adminPage,
  }) => {
    await adminPage.goto(`/index.php/File:${TEST_FILES.noLevel}`);

    const section = adminPage.locator('div.fileperm-section');
    await expect(section).toHaveCount(0);
  });

  test('access denied page for unauthorized user', async ({
    testUserPage,
  }) => {
    await testUserPage.goto(`/index.php/File:${TEST_FILES.confidential}`);

    // TestUser cannot access "confidential" — should see a permission error
    const errorContent = testUserPage.locator(
      '.permissions-errors, .mw-permissionerrors, #mw-content-text'
    );
    const errorText = await errorContent.first().textContent();
    expect(errorText).toContain('permission');

    // The file image should NOT be displayed
    const fileImg = testUserPage.locator('#file img');
    await expect(fileImg).toHaveCount(0);
  });
});
