import { expect } from '@playwright/test';

/**
 * Shared helpers for the browser scenario. Everything that depends on the
 * target installation is read from the environment (see Documentation/Testing.rst).
 */
export const env = {
  username: process.env.WEW_E2E_USERNAME || 'admin',
  password: process.env.WEW_E2E_PASSWORD || '',
  loginProvider: process.env.WEW_E2E_LOGIN_PROVIDER || '1433416747',
  workspaceId: Number(process.env.WEW_E2E_WORKSPACE_ID || 0),
  pageUid: Number(process.env.WEW_E2E_PAGE_UID || 0),
  contentUid: Number(process.env.WEW_E2E_CONTENT_UID || 0),
  // The badge poll interval plus jitter; the "external actor" case has to wait for it.
  pollMs: Number(process.env.WEW_E2E_POLL_MS || 45_000),
  // Publishing writes to Live. Opt in explicitly before running that case
  // against an installation whose content you care about.
  allowPublish: process.env.WEW_E2E_ALLOW_PUBLISH === '1',
};

/** Module used as the iframe host for the FormEngine cases. */
env.recordsModule = process.env.WEW_E2E_RECORDS_MODULE
  || `/typo3/module/content/records?id=${env.pageUid}`;

export const selectors = {
  toolbarItem: '.webcon-easy-workspace-toolbar',
  badge: '[data-wew-workspace-badge]',
  toggle: '.webcon-easy-workspace-toolbar .dropdown-toggle',
  menu: '.wew-menu',
  rows: '[data-wew-row]',
  publish: '[data-wew-publish]',
  selectAll: '[data-wew-select-all]',
  contentIframe: '#typo3-contentIframe',
};

/**
 * Navigate the top window. The backend keeps long-lived connections open,
 * so `load` may never settle — `domcontentloaded` plus an explicit wait for
 * what the case needs is both faster and more reliable.
 */
export async function goto(page, path) {
  await page.goto(path, { waitUntil: 'domcontentloaded' });
}

export function requireEnvironment(test) {
  test.skip(
    env.password === '' || env.workspaceId <= 0 || env.pageUid <= 0 || env.contentUid <= 0,
    'Set WEW_E2E_BASE_URL, WEW_E2E_PASSWORD, WEW_E2E_WORKSPACE_ID, WEW_E2E_PAGE_UID and WEW_E2E_CONTENT_UID to run the browser scenario.',
  );
}

/**
 * Log in and land in the backend shell.
 *
 * The login form only posts the password once its own script has taken it
 * over, so submit from the field itself and make sure the value is really
 * there — submitting early logs an "empty password" attempt and burns one
 * slot of Core's login rate limiter.
 */
export async function login(page, attempt = 0) {
  await goto(page, `/typo3/login?loginProvider=${env.loginProvider}`);
  await page.locator('#t3-login-submit').waitFor({ state: 'visible', timeout: 60_000 });
  await page.fill('#t3-username', env.username);
  await page.fill('#t3-password', env.password);
  await page.waitForFunction(
    (expected) => document.querySelector('#t3-password')?.value === expected,
    env.password,
    { timeout: 10_000 },
  );
  // Don't let Playwright's implicit navigation wait swallow a slow POST.
  await page.locator('#t3-login-submit').click({ noWaitAfter: true });

  // The backend lands on whatever module the user last used, so wait for
  // the shell rather than for a particular URL.
  try {
    await page.locator('typo3-backend-module-router').waitFor({ state: 'attached', timeout: 90_000 });
  } catch (error) {
    if (attempt > 0) throw error;
    return login(page, attempt + 1);
  }
  await page.locator(selectors.toolbarItem).waitFor({ state: 'attached', timeout: 30_000 });
}

/**
 * Open a browser context that is signed in to the backend.
 *
 * The session is cached on disk and reused: Core rate-limits logins, and a
 * spec that re-authenticates on every run locks itself out.
 */
export async function openBackend(browser) {
  const { existsSync, statSync, mkdirSync } = await import('node:fs');
  const { dirname } = await import('node:path');
  const statePath = process.env.WEW_E2E_STATE || '.Build/playwright/state.json';
  const fresh = existsSync(statePath) && Date.now() - statSync(statePath).mtimeMs < 30 * 60_000;

  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 900 },
    ...(fresh ? { storageState: statePath } : {}),
  });
  const page = await context.newPage();
  await goto(page, '/typo3/module/dashboard');
  if (await page.locator(selectors.toolbarItem).count() === 0) {
    await login(page);
  }
  mkdirSync(dirname(statePath), { recursive: true });
  await context.storageState({ path: statePath });

  return { context, page };
}

/** Current workspace id of the backend user, read through Core's workspace_info route. */
export async function currentWorkspaceId(page) {
  return page.evaluate(async () => {
    const response = await fetch(TYPO3.settings.ajaxUrls.workspace_info, { credentials: 'same-origin' });
    return Number((await response.json()).current?.id ?? 0);
  });
}

/**
 * Switch the workspace without reloading the page, through the same Core
 * client API the sidebar selector calls (`WorkspaceState.switchWorkspace`),
 * so the `typo3:workspace:changed` event path is exercised verbatim.
 */
export async function switchWorkspace(page, workspaceId) {
  if ((await currentWorkspaceId(page)) === workspaceId) return;
  await page.evaluate(async (id) => {
    await top.TYPO3.WorkspaceState.switchWorkspace(id);
  }, workspaceId);
  await expect.poll(() => currentWorkspaceId(page), { timeout: 30_000 }).toBe(workspaceId);
}

/** Badge text as a number (0 when hidden or empty). */
export async function badgeCount(page) {
  return page.evaluate((selector) => {
    const badge = document.querySelector(selector);
    if (!badge || badge.hidden) return 0;
    return Number.parseInt(badge.textContent || '0', 10) || 0;
  }, selectors.badge);
}

/** Number of pending changes reported by the server for the active workspace. */
export async function serverCount(page) {
  return page.evaluate(async () => {
    const url = new URL(TYPO3.settings.ajaxUrls.webcon_easy_workspace_badge, window.location.href);
    url.searchParams.set('_', String(Date.now()));
    const response = await fetch(url.toString(), { credentials: 'same-origin' });
    return Number((await response.json()).changedCount ?? 0);
  });
}

/** Badge count rendered into the toolbar markup by the server, before any script ran. */
export async function serverRenderedBadge(page) {
  const html = await page.content();
  const match = html.match(/data-wew-workspace-badge[^>]*>([^<]*)</);
  return match ? Number.parseInt(match[1].trim() || '0', 10) || 0 : null;
}

/**
 * Resolve once the module iframe holds a fully loaded document whose URL
 * contains `marker`. Waiting for the URL matters: the `typo3-iframe-module`
 * element owns `src` and re-applies it, so navigating the frame before its
 * own load settled is silently undone.
 */
export async function waitForModuleFrame(page, marker = '') {
  await page.locator(selectors.contentIframe).waitFor({ timeout: 60_000 });
  await page.waitForFunction(
    (expected) => {
      const iframe = document.querySelector('#typo3-contentIframe');
      try {
        const href = iframe?.contentWindow?.location?.href ?? '';
        return iframe?.contentDocument?.readyState === 'complete'
          && href !== 'about:blank'
          && href.includes(expected);
      } catch {
        return false;
      }
    },
    marker,
    { timeout: 60_000 },
  );
}

/**
 * Open a FormEngine edit form for a content element inside the module iframe
 * and save a new header — the classic editor flow.
 */
export async function saveContentHeaderInIframe(page, contentUid, header) {
  await goto(page, env.recordsModule);
  await waitForModuleFrame(page, new URL(env.recordsModule, 'https://x').pathname);
  const openEditForm = () => page.evaluate(({ uid, returnUrl }) => {
    const url = new URL(TYPO3.settings.FormEngine.moduleUrl, window.location.href);
    url.searchParams.set(`edit[tt_content][${uid}]`, 'edit');
    url.searchParams.set('returnUrl', returnUrl);
    // Navigate the iframe itself, exactly like clicking an edit pencil in
    // the module. (ContentContainer.setUrl() drives the module router and
    // would swap the whole module instead.)
    document.querySelector('#typo3-contentIframe').contentWindow.location.assign(url.toString());
  }, { uid: contentUid, returnUrl: env.recordsModule });

  // In a workspace FormEngine edits the *version* of the record, so the uid
  // in the field name is not the live one. Match the field by its shape.
  const field = '[data-formengine-input-name^="data[tt_content]"][data-formengine-input-name$="[header]"]';
  const frame = page.frameLocator(selectors.contentIframe);
  await openEditForm();
  try {
    await waitForModuleFrame(page, '/record/edit');
  } catch {
    // A saturated instance sometimes drops the first navigation.
    await openEditForm();
    await waitForModuleFrame(page, '/record/edit');
  }
  await frame.locator(field).waitFor({ timeout: 60_000 });
  await frame.locator(field).fill(header);
  // A classic FormEngine save: a full form POST inside the iframe. No
  // DataHandler event, no BroadcastChannel message — only an iframe load.
  // Wait for the POST itself: on a loaded instance the button sits in its
  // spinner state for a long time, and the typed value alone proves nothing.
  const saved = page.waitForResponse(
    (response) => response.request().method() === 'POST' && response.url().includes('/record/edit'),
    { timeout: 120_000 },
  );
  await frame.locator('button[name="_savedok"]').click();
  await saved;
  await waitForModuleFrame(page, '/record/edit');
  await expect
    .poll(() => frame.locator(field).inputValue().catch(() => null), { timeout: 30_000 })
    .toBe(header);
}

/**
 * Change a record like a second actor would: no client-side event of any
 * kind reaches the toolbar, so only the poll can notice it.
 *
 * By default this goes through Core's DataHandler route from a detached
 * fetch. Set `WEW_E2E_EXTERNAL_CMD` to a shell command (it receives the new
 * header in `WEW_HEADER`) to use a real out-of-browser actor — a CLI call,
 * an MCP write — instead.
 */
export async function externalEdit(page, contentUid, header) {
  const command = process.env.WEW_E2E_EXTERNAL_CMD || '';
  if (command !== '') {
    const { execSync } = await import('node:child_process');
    execSync(command, { env: { ...process.env, WEW_HEADER: header, WEW_UID: String(contentUid) }, stdio: 'inherit' });
    return 200;
  }

  return page.evaluate(async ({ uid, value }) => {
    const url = new URL(TYPO3.settings.ajaxUrls.record_process, window.location.href);
    url.searchParams.set(`data[tt_content][${uid}][header]`, value);
    const response = await fetch(url.toString(), { credentials: 'same-origin' });
    return response.status;
  }, { uid: contentUid, value: header });
}

/** Publish every row of the dropdown through the extension's own UI. */
export async function publishAllThroughDropdown(page) {
  await page.click(selectors.toggle);
  await page.locator(selectors.menu).waitFor();
  await page.locator(selectors.rows).first().waitFor({ timeout: 20_000 });
  const selectAll = page.locator(selectors.selectAll);
  if (!(await selectAll.isChecked())) await selectAll.check();
  await page.locator(selectors.publish).click();
}

/**
 * Discard every pending change of the test page so the scenario is
 * repeatable and the live records stay untouched.
 */
export async function discardPendingOnTestPage(page) {
  // Settle on a page of our own first: switching the workspace reloads the
  // frames, and an evaluate that starts during that navigation is lost.
  await goto(page, '/typo3/module/dashboard');
  await page.locator(selectors.toolbarItem).waitFor({ state: 'attached', timeout: 30_000 });
  return page.evaluate(async (pageUid) => {
    const url = new URL(TYPO3.settings.ajaxUrls.webcon_easy_workspace_items, window.location.href);
    url.searchParams.set('pageUid', String(pageUid));
    const items = await (await fetch(url.toString(), { credentials: 'same-origin' })).json();
    for (const item of items.items || []) {
      if (!item.isChanged || !item.workspaceUid) continue;
      await fetch(TYPO3.settings.ajaxUrls.webcon_easy_workspace_discard, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table: item.table, workspaceUid: item.workspaceUid }),
      });
    }
  }, env.pageUid);
}

