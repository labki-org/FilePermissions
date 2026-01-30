/**
 * MediaWiki API helpers for Playwright E2E tests.
 *
 * Provides authenticated API calls for uploading files, setting permission
 * levels, deleting files, editing pages, and querying file permissions.
 */

import { APIRequestContext } from '@playwright/test';

const BASE_URL = process.env.MW_BASE_URL || 'http://localhost:8888';

/**
 * Get a CSRF token from the wiki API.
 */
export async function getCsrfToken(request: APIRequestContext): Promise<string> {
  const resp = await request.get(`${BASE_URL}/api.php`, {
    params: {
      action: 'query',
      meta: 'tokens',
      format: 'json',
    },
  });
  const data = await resp.json();
  return data.query.tokens.csrftoken;
}

/**
 * Upload a file via the MW API.
 */
export async function uploadFile(
  request: APIRequestContext,
  filename: string,
  fileBuffer: Buffer,
  options?: { permLevel?: string; ignoreWarnings?: boolean; comment?: string }
): Promise<{ result: string; filename?: string }> {
  const token = await getCsrfToken(request);
  const formData: Record<string, string | { name: string; mimeType: string; buffer: Buffer }> = {
    action: 'upload',
    filename,
    token,
    ignorewarnings: options?.ignoreWarnings !== false ? '1' : '0',
    format: 'json',
    comment: options?.comment || 'Playwright test upload',
    file: {
      name: filename,
      mimeType: 'image/png',
      buffer: fileBuffer,
    },
  };
  if (options?.permLevel) {
    formData.wpFilePermLevel = options.permLevel;
  }

  const resp = await request.post(`${BASE_URL}/api.php`, {
    multipart: formData,
  });
  const data = await resp.json();
  if (data.error) {
    throw new Error(`Upload failed for ${filename}: ${data.error.info}`);
  }
  return data.upload;
}

/**
 * Set a file's permission level via the fileperm-set-level API.
 */
export async function setFilePermLevel(
  request: APIRequestContext,
  fileTitle: string,
  level: string
): Promise<void> {
  const token = await getCsrfToken(request);
  const resp = await request.post(`${BASE_URL}/api.php`, {
    form: {
      action: 'fileperm-set-level',
      title: fileTitle.startsWith('File:') ? fileTitle : `File:${fileTitle}`,
      level,
      token,
      format: 'json',
    },
  });
  const data = await resp.json();
  if (data.error) {
    throw new Error(`Set level failed for ${fileTitle}: ${data.error.info}`);
  }
}

/**
 * Query a file's permission level via the fileperm prop module.
 */
export async function queryFilePermLevel(
  request: APIRequestContext,
  fileTitle: string
): Promise<string | null> {
  const title = fileTitle.startsWith('File:') ? fileTitle : `File:${fileTitle}`;
  const resp = await request.get(`${BASE_URL}/api.php`, {
    params: {
      action: 'query',
      titles: title,
      prop: 'fileperm',
      format: 'json',
    },
  });
  const data = await resp.json();
  const pages = data.query?.pages;
  if (!pages) return null;
  const pageId = Object.keys(pages)[0];
  return pages[pageId]?.fileperm_level ?? null;
}

/**
 * Delete a file (or any page) via the MW API.
 */
export async function deletePage(
  request: APIRequestContext,
  title: string
): Promise<void> {
  const token = await getCsrfToken(request);
  const resp = await request.post(`${BASE_URL}/api.php`, {
    form: {
      action: 'delete',
      title,
      token,
      reason: 'Playwright test cleanup',
      format: 'json',
    },
  });
  const data = await resp.json();
  // Ignore errors (page may not exist)
  if (data.error && data.error.code !== 'missingtitle') {
    console.warn(`Delete warning for ${title}: ${data.error.info}`);
  }
}

/**
 * Edit (create or update) a wiki page.
 */
export async function editPage(
  request: APIRequestContext,
  title: string,
  content: string
): Promise<void> {
  const token = await getCsrfToken(request);
  const resp = await request.post(`${BASE_URL}/api.php`, {
    form: {
      action: 'edit',
      title,
      text: content,
      token,
      summary: 'Playwright test setup',
      format: 'json',
    },
  });
  const data = await resp.json();
  if (data.error) {
    throw new Error(`Edit failed for ${title}: ${data.error.info}`);
  }
}

/**
 * Query installed extensions on the wiki.
 */
export async function queryInstalledExtensions(
  request: APIRequestContext
): Promise<string[]> {
  const resp = await request.get(`${BASE_URL}/api.php`, {
    params: {
      action: 'query',
      meta: 'siteinfo',
      siprop: 'extensions',
      format: 'json',
    },
  });
  const data = await resp.json();
  return (data.query?.extensions ?? []).map((ext: { name: string }) => ext.name);
}

/**
 * Check if a specific extension is loaded.
 */
export async function isExtensionLoaded(
  request: APIRequestContext,
  extensionName: string
): Promise<boolean> {
  const extensions = await queryInstalledExtensions(request);
  return extensions.includes(extensionName);
}
