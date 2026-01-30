/**
 * Playwright test fixtures for authenticated MediaWiki sessions.
 *
 * Provides `adminPage`, `testUserPage`, `adminContext`, and `testUserContext`
 * fixtures that reuse browser storage states saved during global setup.
 */

import { test as base, Page, BrowserContext } from '@playwright/test';
import path from 'path';

/** Paths to saved storage states, relative to project root. */
const ADMIN_STATE = path.resolve(__dirname, '../../../.auth/admin-state.json');
const TEST_USER_STATE = path.resolve(__dirname, '../../../.auth/testuser-state.json');

type AuthFixtures = {
  /** Page authenticated as Admin (sysop). */
  adminPage: Page;
  /** Page authenticated as TestUser (user group). */
  testUserPage: Page;
  /** Browser context authenticated as Admin. */
  adminContext: BrowserContext;
  /** Browser context authenticated as TestUser. */
  testUserContext: BrowserContext;
};

/**
 * Extended test with authentication fixtures.
 *
 * Usage:
 *   import { test } from './fixtures/auth';
 *   test('my test', async ({ adminPage }) => { ... });
 */
export const test = base.extend<AuthFixtures>({
  adminContext: async ({ browser }, use) => {
    const context = await browser.newContext({ storageState: ADMIN_STATE });
    await use(context);
    await context.close();
  },

  testUserContext: async ({ browser }, use) => {
    const context = await browser.newContext({ storageState: TEST_USER_STATE });
    await use(context);
    await context.close();
  },

  adminPage: async ({ adminContext }, use) => {
    const page = await adminContext.newPage();
    await use(page);
    await page.close();
  },

  testUserPage: async ({ testUserContext }, use) => {
    const page = await testUserContext.newPage();
    await use(page);
    await page.close();
  },
});

export { expect } from '@playwright/test';
