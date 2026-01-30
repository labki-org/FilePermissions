/**
 * Playwright global teardown.
 *
 * Deletes all seeded test files and the embed test page via the MW API.
 */

import path from 'path';
import fs from 'fs';
import { TEST_FILES, EMBED_PAGE_TITLE } from './fixtures/test-files';
import { deletePage } from './fixtures/wiki-api';

const BASE_URL = process.env.MW_BASE_URL || 'http://localhost:8888';
const ADMIN_STATE = path.resolve(__dirname, '../../.auth/admin-state.json');

async function globalTeardown() {
  // Skip if auth state doesn't exist (setup failed)
  if (!fs.existsSync(ADMIN_STATE)) {
    console.log('No admin state found, skipping teardown.');
    return;
  }

  console.log('Cleaning up test data...');
  const { request } = await import('@playwright/test');
  const adminApiContext = await request.newContext({
    baseURL: BASE_URL,
    storageState: ADMIN_STATE,
  });

  // Delete all test files
  for (const filename of Object.values(TEST_FILES)) {
    console.log(`  Deleting File:${filename}...`);
    await deletePage(adminApiContext, `File:${filename}`);
  }

  // Delete embed test page
  console.log(`  Deleting ${EMBED_PAGE_TITLE}...`);
  await deletePage(adminApiContext, EMBED_PAGE_TITLE);

  await adminApiContext.dispose();
  console.log('Teardown complete.');
}

export default globalTeardown;
