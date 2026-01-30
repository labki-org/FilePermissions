/**
 * E2E tests for direct URL access (img_auth.php and /images/).
 *
 * Verifies that img_auth.php correctly serves files to authorized users,
 * denies access to unauthorized users and anonymous users, and that
 * direct /images/ paths are blocked by Apache.
 */

import { test, expect } from './fixtures/auth';
import { TEST_FILES } from './fixtures/test-files';
import { queryFileUrl } from './fixtures/wiki-api';

test.describe('Direct URL access', () => {
  test('img_auth.php serves file to authorized user', async ({
    adminPage,
    adminContext,
  }) => {
    // Get the actual file URL with correct hash path from the API
    const fileUrl = await queryFileUrl(
      adminContext.request,
      TEST_FILES.confidential
    );
    expect(fileUrl).not.toBeNull();

    // Extract the relative path (img_auth.php/...)
    const url = new URL(fileUrl!);
    const response = await adminPage.goto(url.pathname);
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);
  });

  test('img_auth.php denies file to unauthorized user', async ({
    testUserPage,
    testUserContext,
  }) => {
    // Get the actual file URL with correct hash path
    const fileUrl = await queryFileUrl(
      testUserContext.request,
      TEST_FILES.confidential
    );

    if (fileUrl) {
      const url = new URL(fileUrl);
      const response = await testUserPage.goto(url.pathname);
      expect(response).not.toBeNull();

      const status = response!.status();
      if (status === 403) {
        expect(status).toBe(403);
      } else {
        // MW may render an HTML error page with 200 status
        const body = await testUserPage.textContent('body');
        expect(body).toContain('permission');
      }
    } else {
      // File URL not available via API (testUser may lack access to query it)
      // Fall back to the simple path format
      const response = await testUserPage.goto(
        `/img_auth.php/${TEST_FILES.confidential}`
      );
      expect(response).not.toBeNull();
      // Any non-200 is acceptable for an unauthorized user
      expect(response!.status()).not.toBe(200);
    }
  });

  test('img_auth.php serves public file to all logged-in users', async ({
    testUserPage,
    testUserContext,
  }) => {
    // Get the actual file URL with correct hash path
    const fileUrl = await queryFileUrl(
      testUserContext.request,
      TEST_FILES.public
    );
    expect(fileUrl).not.toBeNull();

    const url = new URL(fileUrl!);
    const response = await testUserPage.goto(url.pathname);
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);
  });

  test('img_auth.php denies all files to anonymous users', async ({
    browser,
    adminContext,
  }) => {
    // Get the actual file URL from an authenticated context
    const fileUrl = await queryFileUrl(
      adminContext.request,
      TEST_FILES.public
    );
    expect(fileUrl).not.toBeNull();

    const url = new URL(fileUrl!);

    // Fresh context with no login
    const anonContext = await browser.newContext();
    const anonPage = await anonContext.newPage();

    const response = await anonPage.goto(
      `http://localhost:8888${url.pathname}`
    );
    expect(response).not.toBeNull();

    // Should redirect to login or return 403
    const status = response!.status();
    const pageUrl = anonPage.url();

    const isBlocked =
      status === 403 ||
      pageUrl.includes('UserLogin') ||
      (await anonPage.textContent('body')).includes('not authorised');

    expect(isBlocked).toBe(true);

    await anonPage.close();
    await anonContext.close();
  });

  test('direct /images/ path blocked by Apache for all users', async ({
    adminPage,
  }) => {
    // Admin tries to access the direct /images/ path (bypassing img_auth.php)
    // Apache should block this with 403
    const response = await adminPage.goto('/images/', {
      waitUntil: 'domcontentloaded',
    });
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(403);
  });

  test('img_auth.php thumbnail access follows same rules', async ({
    testUserPage,
    testUserContext,
    adminContext,
  }) => {
    // TestUser should be DENIED access to confidential thumbnail
    // Get confidential thumbnail URL from admin context (testUser may not be able to query it)
    const confThumbUrl = await queryFileUrl(
      adminContext.request,
      TEST_FILES.confidential,
      120
    );

    if (confThumbUrl) {
      const confUrl = new URL(confThumbUrl);
      const confThumbResponse = await testUserPage.goto(confUrl.pathname);
      expect(confThumbResponse).not.toBeNull();
      const confStatus = confThumbResponse!.status();
      if (confStatus !== 403) {
        // May render HTML error
        const body = await testUserPage.textContent('body');
        expect(body).toContain('permission');
      }
    }

    // TestUser should be ALLOWED access to internal thumbnail
    const intThumbUrl = await queryFileUrl(
      testUserContext.request,
      TEST_FILES.internal,
      120
    );
    expect(intThumbUrl).not.toBeNull();

    const intUrl = new URL(intThumbUrl!);
    const intThumbResponse = await testUserPage.goto(intUrl.pathname);
    expect(intThumbResponse).not.toBeNull();
    const intStatus = intThumbResponse!.status();
    // 200 = thumbnail served, 404 = thumbnail not yet generated (but access allowed)
    expect([200, 404]).toContain(intStatus);
  });
});
