/**
 * Playwright global setup.
 *
 * 1. Health-checks the wiki
 * 2. Logs in Admin and TestUser via Special:UserLogin, saves storage states
 * 3. Seeds test files at all 3 permission levels + one with no level
 * 4. Creates the embed test page
 * 5. Queries installed extensions for diagnostics
 */

import { chromium, FullConfig } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { createTestPng, TEST_FILES, EMBED_PAGE_TITLE, EMBED_PAGE_WIKITEXT } from './fixtures/test-files';
import {
  uploadFile,
  setFilePermLevel,
  editPage,
  queryInstalledExtensions,
} from './fixtures/wiki-api';

const BASE_URL = process.env.MW_BASE_URL || 'http://localhost:8888';
const AUTH_DIR = path.resolve(__dirname, '../../.auth');
const ADMIN_STATE = path.join(AUTH_DIR, 'admin-state.json');
const TEST_USER_STATE = path.join(AUTH_DIR, 'testuser-state.json');

async function globalSetup(_config: FullConfig) {
  // Ensure .auth directory exists
  fs.mkdirSync(AUTH_DIR, { recursive: true });

  // --- Health check ---
  console.log('Checking wiki health...');
  const healthResp = await fetch(
    `${BASE_URL}/api.php?action=query&meta=siteinfo&format=json`
  );
  if (!healthResp.ok) {
    throw new Error(`Wiki not reachable at ${BASE_URL} (HTTP ${healthResp.status})`);
  }
  console.log('Wiki is healthy.');

  // --- Login Admin ---
  console.log('Logging in Admin...');
  const browser = await chromium.launch();
  const adminContext = await browser.newContext();
  const adminPage = await adminContext.newPage();
  await loginViaForm(adminPage, 'Admin', 'dockerpass');
  await adminContext.storageState({ path: ADMIN_STATE });
  await adminPage.close();
  await adminContext.close();

  // --- Login TestUser ---
  console.log('Logging in TestUser...');
  const testUserContext = await browser.newContext();
  const testUserPage = await testUserContext.newPage();
  await loginViaForm(testUserPage, 'TestUser', 'testpass123');
  await testUserContext.storageState({ path: TEST_USER_STATE });
  await testUserPage.close();
  await testUserContext.close();

  await browser.close();

  // --- Seed test files via API (using Admin state) ---
  console.log('Seeding test files...');
  const { request } = await import('@playwright/test');
  const adminApiContext = await request.newContext({
    baseURL: BASE_URL,
    storageState: ADMIN_STATE,
  });

  const png = createTestPng();

  // Upload all test files
  for (const [level, filename] of Object.entries(TEST_FILES)) {
    console.log(`  Uploading ${filename}...`);
    await uploadFile(adminApiContext, filename, png, { ignoreWarnings: true });

    if (level !== 'noLevel') {
      console.log(`  Setting ${filename} to level "${level}"...`);
      await setFilePermLevel(adminApiContext, filename, level);
    }
  }

  // Wait for DeferredUpdates
  await new Promise((resolve) => setTimeout(resolve, 2000));

  // --- Create embed test page ---
  console.log('Creating embed test page...');
  await editPage(adminApiContext, EMBED_PAGE_TITLE, EMBED_PAGE_WIKITEXT);

  // --- Query installed extensions ---
  console.log('Querying installed extensions...');
  const extensions = await queryInstalledExtensions(adminApiContext);
  console.log(`  Installed: ${extensions.join(', ')}`);

  await adminApiContext.dispose();
  console.log('Global setup complete.');
}

/**
 * Log in via Special:UserLogin form and wait for redirect.
 */
async function loginViaForm(page: any, username: string, password: string) {
  await page.goto(`${BASE_URL}/index.php?title=Special:UserLogin`);
  await page.fill('#wpName1', username);
  await page.fill('#wpPassword1', password);
  await page.click('#wpLoginAttempt');

  // Wait for redirect away from login page
  await page.waitForURL((url: URL) => !url.pathname.includes('UserLogin'), {
    timeout: 15_000,
  });
  console.log(`  Logged in as ${username}.`);
}

export default globalSetup;
