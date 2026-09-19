import { expect, test } from '@playwright/test';
import {
  currentWorkspaceId,
  env,
  goto,
  openBackend,
  requireEnvironment,
  selectors,
  switchWorkspace,
} from './backend.js';

/**
 * Visual and accessibility pass over the toolbar dropdown.
 *
 * Writes the dropdown screenshots into
 * `Build/Reports/screenshots/`; override the directory with
 * `WEW_E2E_SHOT_DIR`.
 */
test.describe.configure({ mode: 'serial' });

const shotDir = process.env.WEW_E2E_SHOT_DIR || 'Build/Reports/screenshots';

let context;
let page;
let originalWorkspaceId = 0;

test.beforeAll(async ({ browser }) => {
  requireEnvironment(test);
  ({ context, page } = await openBackend(browser));
  originalWorkspaceId = await currentWorkspaceId(page);
  await switchWorkspace(page, env.workspaceId);
});

test.afterAll(async () => {
  if (!page) return;
  await switchWorkspace(page, originalWorkspaceId).catch(() => {});
  await context.close();
});

async function openDropdown(target) {
  await goto(target, env.recordsModule);
  await target.locator(selectors.toggle).waitFor({ timeout: 30_000 });
  await target.locator(selectors.toggle).click();
  await target.locator(selectors.menu).waitFor({ timeout: 20_000 });
  // Let the skeleton resolve into the real list (or an empty state).
  await expect
    .poll(async () => target.locator('[data-wew-loading]').count(), { timeout: 20_000 })
    .toBe(0);
  return target.locator(selectors.menu);
}

for (const scheme of ['light', 'dark']) {
  test(`dropdown looks right in the ${scheme} colour scheme`, async () => {
    await page.emulateMedia({ colorScheme: scheme });
    const menu = await openDropdown(page);
    await menu.screenshot({ path: `${shotDir}/dropdown-${scheme}.png` });
    await page.screenshot({ path: `${shotDir}/toolbar-${scheme}.png`, clip: { x: 900, y: 0, width: 540, height: 640 } });
  });
}

test('every interactive element is reachable and labelled', async () => {
  await page.emulateMedia({ colorScheme: 'light' });
  const menu = await openDropdown(page);

  // The dropdown is a labelled dialog with a live count.
  await expect(menu.locator('[role="dialog"]').or(menu)).toBeVisible();
  const dialog = page.locator('.wew-menu');
  await expect(dialog).toHaveAttribute('role', 'dialog');
  const labelledBy = await dialog.getAttribute('aria-labelledby');
  expect(labelledBy).toBeTruthy();
  await expect(page.locator(`#${labelledBy}`)).toHaveCount(1);
  await expect(page.locator('[data-wew-count-chip]')).toHaveAttribute('aria-live', 'polite');

  // No icon-only control without an accessible name.
  const unnamed = await page.locator('.wew-menu button').evaluateAll((buttons) => buttons
    .filter((button) => !(button.getAttribute('aria-label') || button.textContent || '').trim())
    .map((button) => button.className));
  expect(unnamed).toEqual([]);

  // Keyboard: the list takes focus and the arrow keys walk the rows.
  const rows = page.locator(selectors.rows);
  if (await rows.count() > 1) {
    await rows.first().focus();
    await page.keyboard.press('ArrowDown');
    await expect.poll(() => page.evaluate(() => document.activeElement?.getAttribute('data-wew-key'))).toBe(
      await rows.nth(1).getAttribute('data-wew-key'),
    );
  }

  // Escape closes the dropdown again.
  await page.keyboard.press('Escape');
  await expect.poll(() => page.locator(selectors.menu).isVisible().catch(() => false), { timeout: 5_000 }).toBe(false);
});

test('states render: loading skeleton, empty and error', async () => {
  await page.emulateMedia({ colorScheme: 'light' });
  await goto(page, env.recordsModule);
  await page.locator(selectors.toggle).waitFor({ timeout: 30_000 });

  // Loading — hold the items response open long enough to capture it.
  await page.route('**/webcon-easy-workspace/items*', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 2_500));
    await route.continue();
  });
  await page.locator(selectors.toggle).click();
  await page.locator('[data-wew-loading]').waitFor({ timeout: 10_000 });
  await page.locator(selectors.menu).screenshot({ path: `${shotDir}/state-loading.png` });
  await page.unroute('**/webcon-easy-workspace/items*');
  await page.keyboard.press('Escape');

  // Error — the endpoint fails, the dropdown offers a retry.
  await page.route('**/webcon-easy-workspace/items*', (route) => route.fulfill({ status: 500, body: '{}' }));
  await page.locator(selectors.toggle).click();
  await page.locator('[data-wew-error]').waitFor({ timeout: 10_000 });
  await expect(page.locator('[data-wew-error]')).toHaveAttribute('role', 'alert');
  await expect(page.locator('[data-wew-retry]')).toBeVisible();
  await page.locator(selectors.menu).screenshot({ path: `${shotDir}/state-error.png` });
  await page.unroute('**/webcon-easy-workspace/items*');
  await page.keyboard.press('Escape');

  // Empty — no pending rows for this context.
  await page.route('**/webcon-easy-workspace/items*', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ context: 'page', items: [], itemGroups: [], changedItemGroups: [], workspaceId: env.workspaceId }),
  }));
  await page.locator(selectors.toggle).click();
  await page.locator('[data-wew-empty]').waitFor({ timeout: 10_000 });
  await page.locator(selectors.menu).screenshot({ path: `${shotDir}/state-empty.png` });
  await page.unroute('**/webcon-easy-workspace/items*');
});
