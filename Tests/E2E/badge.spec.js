import { expect, test } from '@playwright/test';
import {
  badgeCount,
  currentWorkspaceId,
  discardAllInWorkspacesModule,
  discardPendingOnTestPage,
  env,
  externalEdit,
  goto,
  openBackend,
  publishAllThroughDropdown,
  requireEnvironment,
  saveContentHeaderInIframe,
  selectors,
  serverCount,
  serverRenderedBadge,
  switchWorkspace,
  waitForModuleFrame,
} from './backend.js';

/**
 * The toolbar badge must always show the server count of the active
 * workspace — without a reload, in every tab, in every module.
 *
 * Every case below is a stale-count report reproduced as a test. See
 * Documentation/Testing.rst for the environment variables.
 */
test.describe.configure({ mode: 'serial' });

let context;
let page;
let originalWorkspaceId = 0;
let baseline = 0;

test.beforeAll(async ({ browser }) => {
  requireEnvironment(test);
  ({ context, page } = await openBackend(browser));
  originalWorkspaceId = await currentWorkspaceId(page);
  await switchWorkspace(page, env.workspaceId);
  await discardPendingOnTestPage(page);
  baseline = await serverCount(page);
});

test.afterAll(async () => {
  if (!page) return;
  await discardPendingOnTestPage(page).catch(() => {});
  await switchWorkspace(page, originalWorkspaceId).catch(() => {});
  await context.close();
});

test('renders the server count into the toolbar markup, before any script runs', async () => {
  await goto(page, '/typo3/module/dashboard');
  const expected = await serverCount(page);
  expect(await serverRenderedBadge(page)).toBe(expected);
  await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(expected);
});

test('updates after a FormEngine save inside the module iframe, in this and in a second tab', async () => {
  const secondTab = await context.newPage();
  await goto(secondTab, '/typo3/module/dashboard');
  await expect.poll(() => badgeCount(secondTab), { timeout: 15_000 }).toBe(baseline);

  await saveContentHeaderInIframe(page, env.contentUid, `Badge E2E ${Date.now()}`);
  const expected = await serverCount(page);
  expect(expected).toBe(baseline + 1);

  // Event-driven: well below the poll interval.
  await expect.poll(() => badgeCount(page), { timeout: 8_000 }).toBe(expected);
  await expect.poll(() => badgeCount(secondTab), { timeout: 8_000 }).toBe(expected);
  await secondTab.close();
});

test('keeps the workspace-wide count on the dashboard, in Records and in the file list', async () => {
  const expected = await serverCount(page);
  for (const path of ['/typo3/module/dashboard', env.recordsModule, '/typo3/module/file/list']) {
    await goto(page, path);
    await page.locator(selectors.toolbarItem).waitFor({ state: 'attached' });
    await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(expected);
  }
});

test('keeps the count while navigating modules without a page reload', async () => {
  const expected = await serverCount(page);
  await goto(page, '/typo3/module/dashboard');
  await waitForModuleFrame(page, '/dashboard');
  for (const path of [env.recordsModule, '/typo3/module/file/list', '/typo3/module/dashboard']) {
    await page.evaluate((url) => { document.querySelector('#typo3-contentIframe').contentWindow.location.assign(url); }, path);
    await waitForModuleFrame(page, new URL(path, 'https://x').pathname);
    await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(expected);
  }
});

test('hides the toolbar item in Live and restores the count when switching back', async () => {
  const expected = await serverCount(page);
  await switchWorkspace(page, 0);
  await expect.poll(() => page.evaluate((s) => document.querySelector(s)?.hidden === true, selectors.toolbarItem), { timeout: 10_000 }).toBe(true);
  await switchWorkspace(page, env.workspaceId);
  await expect.poll(() => page.evaluate((s) => document.querySelector(s)?.hidden === true, selectors.toolbarItem), { timeout: 10_000 }).toBe(false);
  await expect.poll(() => badgeCount(page), { timeout: 10_000 }).toBe(expected);
});

test('follows a change made by another actor within one poll interval', async () => {
  const before = await serverCount(page);
  const status = await externalEdit(page, env.contentUid, `External E2E ${Date.now()}`);
  expect(status).toBeLessThan(400);
  const expected = await serverCount(page);
  expect(expected).toBeGreaterThanOrEqual(before);
  await expect.poll(() => badgeCount(page), { timeout: env.pollMs + 15_000 }).toBe(expected);
});

test('decrements after discarding in Core\'s Workspaces module', async () => {
  const before = await serverCount(page);
  test.skip(before === 0, 'Nothing pending to discard.');
  await discardAllInWorkspacesModule(page);
  await expect.poll(() => serverCount(page), { timeout: 30_000 }).toBeLessThan(before);
  const expected = await serverCount(page);
  await expect.poll(() => badgeCount(page), { timeout: 10_000 }).toBe(expected);
});

test('decrements after publishing from the dropdown, in this and in a second tab', async () => {
  test.skip(!env.allowPublish, 'Publishing writes to Live. Set WEW_E2E_ALLOW_PUBLISH=1 to run this case.');
  await saveContentHeaderInIframe(page, env.contentUid, `Publish E2E ${Date.now()}`);
  const secondTab = await context.newPage();
  await goto(secondTab, '/typo3/module/dashboard');
  const before = await serverCount(page);
  await expect.poll(() => badgeCount(secondTab), { timeout: 15_000 }).toBe(before);

  await goto(page, env.recordsModule);
  await publishAllThroughDropdown(page);
  await expect.poll(() => serverCount(page), { timeout: 20_000 }).toBeLessThan(before);
  const expected = await serverCount(page);
  await expect.poll(() => badgeCount(page), { timeout: 8_000 }).toBe(expected);
  await expect.poll(() => badgeCount(secondTab), { timeout: 8_000 }).toBe(expected);
  await secondTab.close();
});
