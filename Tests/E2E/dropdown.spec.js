import { expect, test } from '@playwright/test';
import {
  badgeCount,
  badgeOfTestPage,
  currentWorkspaceId,
  discardPendingOnTestPage,
  env,
  itemsOfTestPage,
  goto,
  openBackend,
  requireEnvironment,
  saveContentHeaderInIframe,
  selectors,
  switchWorkspace,
  waitForModuleFrame,
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
  // Give the dropdown something to show.
  await saveContentHeaderInIframe(page, env.contentUid, `Dropdown E2E ${Date.now()}`);
});

test.afterAll(async () => {
  if (!page) return;
  await discardPendingOnTestPage(page).catch(() => {});
  await switchWorkspace(page, originalWorkspaceId).catch(() => {});
  await context.close();
});

async function openDropdown(target) {
  await goto(target, env.recordsModule);
  // The module iframe carries the page context the list is scoped to.
  await waitForModuleFrame(target, '/records');
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

  // The dropdown is a labelled dialog, described by its summary. The summary
  // is no live region (it would re-announce every refresh); a status region
  // in the footer speaks instead.
  await expect(menu.locator('[role="dialog"]').or(menu)).toBeVisible();
  const dialog = page.locator('.wew-menu');
  await expect(dialog).toHaveAttribute('role', 'dialog');
  await expect(dialog).toHaveAttribute('data-wew-layout', /^(clarity|efficiency|core)$/);
  const labelledBy = await dialog.getAttribute('aria-labelledby');
  expect(labelledBy).toBeTruthy();
  await expect(page.locator(`#${labelledBy}`)).toHaveCount(1);
  const describedBy = await dialog.getAttribute('aria-describedby');
  await expect(page.locator(`#${describedBy}`)).toHaveAttribute('data-wew-count-chip', '');
  await expect(page.locator('[data-wew-count-chip]')).not.toHaveAttribute('aria-live', /.+/);
  await expect(page.locator('[data-wew-status]')).toHaveAttribute('role', 'status');

  // No icon-only control without an accessible name.
  const unnamed = await page.locator('.wew-menu button').evaluateAll((buttons) => buttons
    .filter((button) => !(button.getAttribute('aria-label') || button.textContent || '').trim())
    .map((button) => button.className));
  expect(unnamed).toEqual([]);

  // Keyboard: the row checkboxes are the stops, exactly one is tabbable,
  // and the arrow keys walk them.
  const checks = page.locator(selectors.rowCheck);
  if (await checks.count() > 0) {
    await expect(page.locator('.wew-list [data-wew-stop][tabindex="0"]')).toHaveCount(1);
  }
  if (await checks.count() > 1) {
    await checks.first().focus();
    await page.keyboard.press('ArrowDown');
    await expect.poll(() => page.evaluate(() => document.activeElement?.closest('[data-wew-row]')?.getAttribute('data-wew-key'))).toBe(
      await page.locator(selectors.rows).nth(1).getAttribute('data-wew-key'),
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

  const emptyItems = JSON.stringify({ context: 'page', items: [], languages: [], viewLanguages: null, workspaceId: env.workspaceId });

  // Loading — hold the items response back long enough to capture it.
  await page.route('**/webcon-easy-workspace/items*', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 5_000));
    await route.fulfill({ status: 200, contentType: 'application/json', body: emptyItems }).catch(() => {});
  });
  await page.locator(selectors.toggle).click();
  await page.locator('[data-wew-loading]').waitFor({ timeout: 15_000 });
  await page.locator(selectors.menu).screenshot({ path: `${shotDir}/state-loading.png` });
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await page.keyboard.press('Escape');

  // Error — the endpoint fails, the dropdown offers a retry.
  await page.route('**/webcon-easy-workspace/items*', (route) => route.fulfill({ status: 500, body: '{}' }));
  await page.locator(selectors.toggle).click();
  await page.locator('[data-wew-error]').waitFor({ timeout: 10_000 });
  await expect(page.locator('[data-wew-error]')).toHaveAttribute('role', 'alert');
  await expect(page.locator('[data-wew-retry]')).toBeVisible();
  await page.locator(selectors.menu).screenshot({ path: `${shotDir}/state-error.png` });
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await page.keyboard.press('Escape');

  // Empty — no pending rows for this context.
  await page.route('**/webcon-easy-workspace/items*', (route) => route.fulfill({
    status: 200, contentType: 'application/json', body: emptyItems,
  }));
  await page.locator(selectors.toggle).click();
  await page.locator('[data-wew-empty]').waitFor({ timeout: 15_000 });
  await page.locator(selectors.menu).screenshot({ path: `${shotDir}/state-empty.png` });
  await page.unrouteAll({ behavior: 'ignoreErrors' });
});

/**
 * 1.11: editing a default-language element makes TYPO3 version its
 * translations unchanged. One edit must read as one everywhere, the copies
 * ride on the edited row and go with it. Needs WEW_E2E_TRANSLATION_UID (a
 * translation of WEW_E2E_CONTENT_UID; the lab: page 2228, hero 158035,
 * German 158633). Never publishes: that path is covered functionally.
 */
test.describe('translation copies', () => {
  test.beforeEach(async () => {
    test.skip(env.translationUid <= 0, 'Set WEW_E2E_TRANSLATION_UID to run the translation-copy cases.');
    await discardPendingOnTestPage(page);
  });

  test('one header edit reads as one change, with the translations as chips', async () => {
    await saveContentHeaderInIframe(page, env.contentUid, `Copies E2E ${Date.now()}`);
    const badge = await badgeOfTestPage(page);
    expect(badge.contextCount).toBe(1);
    await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(1);

    await openDropdown(page);
    await expect(page.locator(selectors.rows)).toHaveCount(1);
    // One edit: no select-all, the publish label carries the count.
    await expect(page.locator(selectors.selectAll)).toHaveCount(0);
    await expect(page.locator(selectors.publish)).toHaveText(/^\s*1\b/);
    const chips = page.locator(selectors.companionChip);
    expect(await chips.count()).toBeGreaterThan(0);
    for (const chip of await chips.all()) {
      await expect(chip).toHaveAttribute('aria-label', /^[A-Z]{2}(-[A-Z]{2})?: /);
    }
    await page.locator(selectors.menu).screenshot({ path: `${shotDir}/copies-one-edit.png` });
  });

  test('a translation chip opens that translation in the edit modal', async () => {
    await saveContentHeaderInIframe(page, env.contentUid, `Chip E2E ${Date.now()}`);
    await openDropdown(page);
    await page.locator(selectors.companionChip).first().click();
    const modal = page.locator('typo3-backend-modal, .wew-edit-modal-shell').first();
    await expect(modal).toBeVisible({ timeout: 20_000 });
    await page.keyboard.press('Escape');
  });

  test('discarding the edit names the copies and takes them along', async () => {
    await saveContentHeaderInIframe(page, env.contentUid, `Discard E2E ${Date.now()}`);
    await openDropdown(page);
    await page.locator(`${selectors.rows} [data-wew-action="discard"]`).first().click();
    const confirm = page.locator('.wew-confirm');
    await expect(confirm).toBeVisible({ timeout: 10_000 });
    // The change itself, then one paragraph about the copies.
    expect(await confirm.locator('p').count()).toBeGreaterThanOrEqual(2);
    await page.locator('typo3-backend-modal button[name="discard"]').click();
    await expect.poll(async () => (await itemsOfTestPage(page)).items.length, { timeout: 20_000 }).toBe(0);
  });

  test('a save without changes leaves only copies, cleaned up in one click', async () => {
    await saveContentHeaderInIframe(page, env.contentUid, null);
    const badge = await badgeOfTestPage(page);
    expect(badge.contextCount).toBe(0);
    expect(badge.copies).toBeGreaterThan(0);
    await expect.poll(() => badgeCount(page), { timeout: 15_000 }).toBe(0);

    await openDropdown(page);
    const cleanup = page.locator(selectors.cleanup);
    await expect(cleanup.locator('[data-wew-section-toggle]')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator(selectors.publish)).toHaveCount(0);
    await page.locator(selectors.menu).screenshot({ path: `${shotDir}/copies-cleanup.png` });
    await page.locator(selectors.discardCopies).click();
    await page.locator(selectors.empty).waitFor({ timeout: 20_000 });
    expect((await itemsOfTestPage(page)).items).toEqual([]);
  });

  test('keyboard: arrows reach the actions and chips, Enter edits', async () => {
    await saveContentHeaderInIframe(page, env.contentUid, `Keyboard E2E ${Date.now()}`);
    await openDropdown(page);
    const check = page.locator(selectors.rowCheck).first();
    await check.focus();
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => page.evaluate(() => document.activeElement?.getAttribute('data-wew-action'))).toBe('edit');
    await page.keyboard.press('ArrowLeft');
    await expect.poll(() => page.evaluate(() => document.activeElement?.hasAttribute('data-wew-row-check'))).toBe(true);
    await page.keyboard.press('Enter');
    await expect(page.locator('typo3-backend-modal, .wew-edit-modal-shell').first()).toBeVisible({ timeout: 20_000 });
    await page.keyboard.press('Escape');
  });
});
