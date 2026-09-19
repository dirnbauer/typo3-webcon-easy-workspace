import { expect, test } from '@playwright/test';
import {
  badgeCount,
  currentWorkspaceId,
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
  workspaceCount,
} from './backend.js';

/**
 * The toolbar badge must always show the server count for the page the
 * editor is on (the whole workspace where there is no page context) —
 * without a reload, in every tab, in every module.
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

test('renders the page count into the toolbar markup, before any script runs', async () => {
  // Only a backend URL that names a page (?id=) lets the server pre-fill the
  // badge; the Records module is opened with one.
  await goto(page, env.recordsModule);
  const expected = await serverCount(page);
  expect(await serverRenderedBadge(page)).toBe(expected);
  await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(expected);
});

test('shows the page count, not the whole workspace', async () => {
  await goto(page, env.recordsModule);
  await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(await serverCount(page));
  expect(await serverCount(page)).toBeLessThanOrEqual(await workspaceCount(page));
});

test('updates after a FormEngine save inside the module iframe, in this and in a second tab', async () => {
  const secondTab = await context.newPage();
  await goto(secondTab, '/typo3/module/dashboard');
  await expect.poll(() => badgeCount(secondTab), { timeout: 15_000 }).toBe(baseline);

  await saveContentHeaderInIframe(page, env.contentUid, `Badge E2E ${Date.now()}`);
  const expected = await serverCount(page);
  expect(expected).toBeGreaterThan(0);
  expect(expected).toBeGreaterThanOrEqual(baseline);

  // Event-driven, and that is the point: a classic FormEngine save emits no
  // DataHandler event, so before the fix nothing but the 45 s poll moved the
  // badge. Fifteen seconds is a third of that.
  await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(expected);
  await expect.poll(() => badgeCount(secondTab), { timeout: 15_000 }).toBe(expected);
  await secondTab.close();
});

test('keeps a count on the dashboard, in Records and in the file list', async () => {
  for (const path of ['/typo3/module/dashboard', env.recordsModule, '/typo3/module/file/list']) {
    await goto(page, path);
    await page.locator(selectors.toolbarItem).waitFor({ state: 'attached' });
    // Re-read per module: the scope follows the page context each one has.
    await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(await serverCount(page));
  }
});

test('keeps the count while navigating modules without a page reload', async () => {
  await goto(page, '/typo3/module/dashboard');
  await waitForModuleFrame(page, '/dashboard');
  for (const path of [env.recordsModule, '/typo3/module/file/list', '/typo3/module/dashboard']) {
    await page.evaluate((url) => { document.querySelector('#typo3-contentIframe').contentWindow.location.assign(url); }, path);
    await waitForModuleFrame(page, new URL(path, 'https://x').pathname);
    await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(await serverCount(page));
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

test('follows an event a module raises only inside its own frame', async () => {
  // Core's own modules — the Workspaces module's publish and discard, for
  // instance — dispatch on their own `document`, which never reaches the
  // toolbar in the top frame. Change a record without any client-side
  // signal, then raise the event where such a module raises it.
  await goto(page, env.recordsModule);
  await waitForModuleFrame(page, '/records');
  expect(await externalEdit(page, env.contentUid, `In-frame E2E ${Date.now()}`)).toBeLessThan(400);
  const expected = await serverCount(page);
  expect(expected).toBeGreaterThan(0);

  await page.evaluate(() => {
    document.querySelector('#typo3-contentIframe').contentDocument
      .dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
  });
  await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(expected);
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
  await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(expected);
  await expect.poll(() => badgeCount(secondTab), { timeout: 15_000 }).toBe(expected);
  await secondTab.close();
});
