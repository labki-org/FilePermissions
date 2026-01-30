/**
 * E2E tests for direct URL access (img_auth.php and /images/).
 *
 * Verifies that img_auth.php correctly serves files to authorized users,
 * denies access to unauthorized users and anonymous users, and that
 * direct /images/ paths are blocked by Apache.
 */

import { test, expect } from './fixtures/auth';
import { TEST_FILES } from './fixtures/test-files';

test.describe('Direct URL access', () => {
  test('img_auth.php serves file to authorized user', async ({
    adminPage,
  }) => {
    // Admin (sysop) has access to all levels including confidential
    const response = await adminPage.goto(
      `/img_auth.php/${TEST_FILES.confidential}`
    );
    expect(response).not.toBeNull();
    // Should be 200 (file served) — not a redirect or error
    expect(response!.status()).toBe(200);
  });

  test('img_auth.php denies file to unauthorized user', async ({
    testUserPage,
  }) => {
    // TestUser does not have access to confidential
    const response = await testUserPage.goto(
      `/img_auth.php/${TEST_FILES.confidential}`
    );
    expect(response).not.toBeNull();

    // Should be 403 (forbidden) or the page should contain an access denied message
    const status = response!.status();
    if (status === 403) {
      expect(status).toBe(403);
    } else {
      // MW may render an HTML error page with 200 status
      const body = await testUserPage.textContent('body');
      expect(body).toContain('permission');
    }
  });

  test('img_auth.php serves public file to all logged-in users', async ({
    testUserPage,
  }) => {
    // TestUser has access to "public" level
    const response = await testUserPage.goto(
      `/img_auth.php/${TEST_FILES.public}`
    );
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);
  });

  test('img_auth.php denies all files to anonymous users', async ({
    browser,
  }) => {
    // Fresh context with no login
    const anonContext = await browser.newContext();
    const anonPage = await anonContext.newPage();

    const response = await anonPage.goto(
      `http://localhost:8888/img_auth.php/${TEST_FILES.public}`
    );
    expect(response).not.toBeNull();

    // Should redirect to login or return 403
    const status = response!.status();
    const url = anonPage.url();

    const isBlocked =
      status === 403 ||
      url.includes('UserLogin') ||
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
  }) => {
    // TestUser should be DENIED access to confidential thumbnail
    const confThumbResponse = await testUserPage.goto(
      `/img_auth.php/thumb/${TEST_FILES.confidential}/120px-${TEST_FILES.confidential}`
    );
    expect(confThumbResponse).not.toBeNull();
    const confStatus = confThumbResponse!.status();
    if (confStatus !== 403) {
      // May render HTML error
      const body = await testUserPage.textContent('body');
      expect(body).toContain('permission');
    }

    // TestUser should be ALLOWED access to internal thumbnail
    const intThumbResponse = await testUserPage.goto(
      `/img_auth.php/thumb/${TEST_FILES.internal}/120px-${TEST_FILES.internal}`
    );
    expect(intThumbResponse).not.toBeNull();
    // Internal level is accessible to TestUser (user group grants public+internal)
    // Should be 200 or at least not 403
    const intStatus = intThumbResponse!.status();
    // 200 = thumbnail served, 404 = thumbnail not generated yet (but access allowed)
    expect([200, 404]).toContain(intStatus);
  });
});
