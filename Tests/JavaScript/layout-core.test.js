import { describe, expect, it, vi } from 'vitest';
import { render } from 'lit';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import core, { copyRecordTotal, confirmDiscardCopies } from '@webconsulting/webcon-easy-workspace/layouts/core.js';
import { layoutFor } from '@webconsulting/webcon-easy-workspace/layouts/index.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { renderGroup } from '@webconsulting/webcon-easy-workspace/templates/group.js';
import { groupRows } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { actionsOf, stopsOf, syncRovingTabindex } from '@webconsulting/webcon-easy-workspace/menu-keyboard.js';
import { WebconEasyWorkspaceMenu } from '@webconsulting/webcon-easy-workspace/components/wew-toolbar-menu.js';
import { change, companion, copy, host, GERMAN } from './fixtures/items.js';

function mount(template) {
  const container = document.createElement('div');
  render(template, container);
  return container;
}

/** A host rendering through the "TYPO3 Core" profile. */
function coreHost(items, options = {}) {
  return host(items, { layoutProfile: core, ...options });
}

function hero(extra = {}) {
  return change(161266, {
    title: 'Everything a TYPO3 client site needs',
    locateTable: 'tt_content', locateLiveUid: 161166, locateWorkspaceUid: 161266,
    latestChangeAt: Math.floor(Date.now() / 1000) - 120, latestChangeUserUid: 1,
    companions: [companion(1), companion(2), companion(3)],
    ...extra,
  });
}

function leftover(uid = 161380) {
  return copy(uid, { title: 'Everything a TYPO3 client site needs', companions: [companion(1), companion(2), companion(3)] });
}

describe('"TYPO3 Core" layout profile', () => {
  it('is the profile the registry hands out for "core", with the shared flags', () => {
    expect(layoutFor({ _config: { layout: 'core' } })).toBe(core);
    expect(core.name).toBe('core');
    expect(core.flags).toEqual({
      selectAllMin: 2,
      otherLanguagesOpenUpTo: 0,
      cleanupOpen: 'alone',
      keyboardHintKey: 'toolbar.keyboardHint',
      copiesSummaryKey: 'toolbar.summary.drafts',
      copyEditKey: 'toolbar.cleanup.editDraft',
      cleanupSuccessKey: 'toolbar.cleanup.successDrafts',
      cleanupSkippedKey: 'toolbar.cleanup.skippedDrafts',
    });
    for (const hook of ['renderRow', 'renderCleanup', 'renderCopy', 'beforeDiscardCopies']) {
      expect(typeof core[hook], hook).toBe('function');
    }
    expect(Object.isFrozen(core)).toBe(true);
  });
});

describe('core row', () => {
  it('puts the title and its change badge on line 1, the badge out of the meta line', () => {
    const h = coreHost([hero()]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    const head = row.querySelector('.wew-row__head');
    expect(head.querySelector('.wew-row__title').textContent).toBe('Everything a TYPO3 client site needs');
    expect(head.querySelector('.wew-row__title').getAttribute('lang')).toBe('en-US');
    const badge = head.querySelector('[data-wew-change-badge]');
    expect(badge.className).toContain('badge-info');
    expect(badge.textContent).toBe(GERMAN['change.changed']);
    expect(badge.id).toBe('wew-row-tt_content-161266-badge');
    expect(row.querySelector('.wew-row__meta [data-wew-change-badge]')).toBeNull();
  });

  it('keeps the shell contract: data attributes, the checkbox as stop', () => {
    const h = coreHost([hero()]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    expect(row.getAttribute('data-wew-key')).toBe('tt_content:161266');
    expect(row.getAttribute('data-change')).toBe('changed');
    const check = row.querySelector('input[type="checkbox"][data-wew-row-check][data-wew-stop]');
    expect(check).not.toBeNull();
    expect(check.checked).toBe(true);
    expect(check.getAttribute('tabindex')).toBe('-1');
    expect(check.getAttribute('aria-label')).toBe(GERMAN['toolbar.row.select'].replace('{title}', 'Everything a TYPO3 client site needs'));
  });

  it('describes the checkbox with the badge, the meta line and the translations, in reading order', () => {
    const h = coreHost([hero()]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    const describedBy = row.querySelector('[data-wew-row-check]').getAttribute('aria-describedby').split(' ');
    expect(describedBy).toEqual(['wew-row-tt_content-161266-badge', 'wew-row-tt_content-161266-meta', 'wew-row-tt_content-161266-companions']);
    for (const id of describedBy) expect(row.querySelector(`#${id}`), id).not.toBeNull();

    const plain = coreHost([change(5)]);
    const lone = mount(renderRow(plain, plain.items[0])).querySelector('[data-wew-row-check]');
    expect(lone.getAttribute('aria-describedby')).toBe('wew-row-tt_content-5-badge wew-row-tt_content-5-meta');
  });

  it('starts line 2 with hidden and language, then the meta as whole parts', () => {
    const h = coreHost([hero({ isHidden: true })], { viewLanguages: [0, 1] });
    h.items.push(change(7, { languageUid: 1, title: 'Alles, was eine TYPO3-Kundenseite braucht' }));
    const meta = mount(renderRow(h, h.items[1])).querySelector('.wew-row__meta');
    expect(meta.firstElementChild.hasAttribute('data-wew-row-language')).toBe(true);
    const hidden = mount(renderRow(h, h.items[0])).querySelector('.wew-row__meta');
    expect(hidden.firstElementChild.hasAttribute('data-wew-hidden-badge')).toBe(true);
    const parts = Array.from(hidden.querySelectorAll('.wew-row__part'), (part) => part.textContent);
    expect(parts[0]).toBe('Hero-Bereich');
    expect(parts).toContain('Normal');
    expect(parts.at(-1)).toBe(GERMAN['toolbar.row.byYou']);
    // Separators are drawn by CSS, so a part and its "·" leave together.
    expect(hidden.querySelector('.wew-row__sep')).toBeNull();
    expect(hidden.querySelector('.wew-row__meta-text').getAttribute('title')).toBe(parts.join(' · '));
  });

  it('keeps the action order edit, compare, locate, discard last', () => {
    const h = coreHost([hero()]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    expect(Array.from(row.querySelectorAll('[data-wew-action]'), (button) => button.getAttribute('data-wew-action'))).toEqual(['edit', 'diff', 'preview', 'discard']);
    expect(row.querySelector('[data-wew-action="discard"]').className).toContain('wew-row__action--danger');
  });

  it('marks the translations line with the translate glyph and keeps the chips editable', () => {
    const h = coreHost([hero()]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    expect(row.querySelector('.wew-row__glyph typo3-backend-icon').getAttribute('identifier')).toBe('actions-translate');
    expect(row.querySelector('.wew-row__glyph').getAttribute('aria-hidden')).toBe('true');
    const chips = row.querySelectorAll('[data-wew-companions] button[data-wew-companion]');
    expect(Array.from(chips, (chip) => chip.textContent.trim())).toEqual(['DE', 'ZH', 'HU']);
    expect(chips[0].getAttribute('aria-label')).toBe('DE: Übersetzung „German“ bearbeiten');

    const moved = coreHost([hero({ kindKey: 'move', companions: [companion(1, 'moved')] })]);
    const staticRow = mount(renderRow(moved, moved.items[0])).querySelector('[data-wew-row]');
    expect(staticRow.querySelector('button[data-wew-companion]')).toBeNull();
    expect(staticRow.querySelector('.wew-companion--static').textContent.trim()).toBe('DE');

    const plain = coreHost([change(5)]);
    expect(mount(renderRow(plain, plain.items[0])).querySelector('.wew-row__glyph')).toBeNull();
  });

  it('leaves actions and chips in DOM order for the keyboard: actions first, then the translations', () => {
    const h = coreHost([hero()]);
    const container = mount(renderRow(h, h.items[0]));
    const check = container.querySelector('[data-wew-row-check]');
    expect(actionsOf(check).map((node) => node.getAttribute('data-wew-action') || node.textContent.trim()))
      .toEqual(['edit', 'diff', 'preview', 'discard', 'DE', 'ZH', 'HU']);
  });
});

describe('core cleanup: "Entwürfe ohne Änderung"', () => {
  it('names the section, counts its records and offers one discard for it', () => {
    const h = coreHost([leftover()]);
    const group = groupRows(h)[0];
    const section = mount(renderGroup(h, group)).querySelector('[data-wew-cleanup]');
    const toggle = section.querySelector('[data-wew-section-toggle][data-wew-stop]');
    expect(toggle.querySelector('.wew-section__title').textContent).toBe(GERMAN['toolbar.cleanup.titleDrafts']);
    expect(toggle.querySelector('.wew-section__count').textContent).toContain('4');
    // Only copies on the page: open by default, the explanation visible.
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    const help = section.querySelector('.wew-section__help');
    expect(help.textContent).toBe(GERMAN['toolbar.cleanup.draftsHelp']);
    expect(help.className).not.toContain('visually-hidden');

    const discard = section.querySelector('[data-wew-discard-copies]');
    expect(discard.textContent.trim()).toBe(GERMAN['toolbar.cleanup.confirm.button']);
    // The name starts with the visible text (WCAG 2.5.3).
    expect(discard.getAttribute('aria-label')).toBe(`${GERMAN['toolbar.cleanup.confirm.button']}: ${GERMAN['toolbar.cleanup.titleDrafts']}`);
    expect(discard.getAttribute('aria-describedby')).toBe(help.id);
    expect(discard.disabled).toBe(false);
    const spy = vi.fn();
    h.handleDiscardCopies = spy;
    discard.click();
    expect(spy).toHaveBeenCalledWith(group);
  });

  it('folds behind edits and disables discarding without the revert permission', () => {
    const h = coreHost([hero(), leftover()], { config: { enableRevert: false } });
    const section = mount(renderGroup(h, groupRows(h)[0])).querySelector('[data-wew-cleanup]');
    expect(section.querySelector('[data-wew-section-toggle]').getAttribute('aria-expanded')).toBe('false');
    expect(section.querySelector('.wew-copies')).toBeNull();
    expect(section.querySelector('.wew-section__help').className).toContain('visually-hidden');
    const discard = section.querySelector('[data-wew-discard-copies]');
    expect(discard.disabled).toBe(true);
    expect(discard.getAttribute('title')).toBe(GERMAN['discard.button.disabledTitle']);
  });

  it('lists each element with its type, its language chips and a discard of its own', () => {
    const h = coreHost([leftover()]);
    const group = groupRows(h)[0];
    const line = mount(renderGroup(h, group)).querySelector('[data-wew-copy-group]');
    expect(line.getAttribute('data-wew-copy-group')).toBe('tt_content:158035');
    expect(line.querySelector('.wew-copy__title').textContent).toBe('Everything a TYPO3 client site needs');
    expect(line.querySelector('.wew-copy__title').getAttribute('lang')).toBe('en-US');
    expect(line.querySelector('.wew-copy__meta').textContent).toBe(`Hero-Bereich · ${GERMAN['toolbar.cleanup.draftsShort']}`);
    const chips = line.querySelectorAll('button[data-wew-companion]');
    expect(Array.from(chips, (chip) => chip.textContent.trim())).toEqual(['EN', 'DE', 'ZH', 'HU']);
    // The same word as the section: a draft, not a copy.
    expect(chips[1].getAttribute('aria-label')).toBe('DE: Entwurf „German“ bearbeiten');
    expect(chips[0].hasAttribute('data-wew-stop')).toBe(true);
    expect(chips[1].hasAttribute('data-wew-stop')).toBe(false);

    const discard = line.querySelector('[data-wew-action="discard-copy"]');
    expect(discard.getAttribute('aria-label')).toBe('Unveränderten Entwurf „Everything a TYPO3 client site needs“ verwerfen');
    const spy = vi.fn();
    h.handleDiscardCopies = spy;
    discard.click();
    expect(spy).toHaveBeenCalledWith(group, [group.copies[0]]);
  });

  it('lets the keyboard reach the per-element discard from the line\'s first chip', () => {
    const h = coreHost([leftover()]);
    const container = mount(renderGroup(h, groupRows(h)[0]));
    const firstChip = container.querySelector('[data-wew-copy-group] [data-wew-stop]');
    // The chips first, the discard last — in arrow and in Tab order.
    expect(actionsOf(firstChip).map((node) => node.getAttribute('data-wew-action') || node.textContent.trim()))
      .toEqual(['DE', 'ZH', 'HU', 'discard-copy']);
    expect(container.querySelector('[data-wew-copy-group]').lastElementChild.className).toContain('wew-copy__actions');
    syncRovingTabindex(Object.assign(container, { _activeStop: null }));
    expect(stopsOf(container).filter((stop) => stop.getAttribute('tabindex') === '0')).toHaveLength(1);
  });

  it('disables the per-element discard while a cleanup runs', () => {
    const h = coreHost([leftover()], { cleaning: true });
    const container = mount(renderGroup(h, groupRows(h)[0]));
    expect(container.querySelector('[data-wew-action="discard-copy"]').disabled).toBe(true);
    const discard = container.querySelector('[data-wew-discard-copies]');
    expect(discard.disabled).toBe(true);
    expect(discard.getAttribute('aria-busy')).toBe('true');
    expect(discard.textContent.trim()).toBe(GERMAN['toolbar.cleanup.discarding']);
  });
});

describe('core confirm before discarding drafts', () => {
  function spyConfirm() {
    const calls = [];
    vi.spyOn(Modal, 'confirm').mockImplementation((title, message, severity, buttons) => {
      const modal = document.createElement('div');
      modal.hideModal = vi.fn(() => modal.dispatchEvent(new Event('typo3-modal-hidden')));
      calls.push({ title, message, severity, buttons, modal });
      return modal;
    });
    return calls;
  }

  it('counts every record of the given lines', () => {
    expect(copyRecordTotal([{ members: [1, 2, 3, 4] }, { records: [1] }, {}])).toBe(5);
    expect(copyRecordTotal(null)).toBe(0);
  });

  it('asks with Core\'s confirm modal and resolves true only for "Verwerfen"', async () => {
    const calls = spyConfirm();
    const h = coreHost([leftover()]);
    const group = groupRows(h)[0];
    const answer = confirmDiscardCopies(h, group, group.copies);
    expect(calls).toHaveLength(1);
    const [{ title, message, severity, buttons, modal }] = calls;
    expect(title).toBe(GERMAN['toolbar.cleanup.confirm.title']);
    expect(message).toBe('4 Entwürfe entsprechen dem Live-Stand. Verwerfen ändert nichts auf der Website.');
    expect(severity).toBe(SeverityEnum.info);
    expect(buttons.map((button) => button.name)).toEqual(['cancel', 'discard']);
    expect(buttons[1].text).toBe(GERMAN['toolbar.cleanup.confirm.button']);
    expect(buttons[1].active).toBe(true);
    buttons[1].trigger();
    await expect(answer).resolves.toBe(true);
    expect(modal.hideModal).toHaveBeenCalled();
  });

  it('resolves false on cancel and when the modal is closed', async () => {
    const calls = spyConfirm();
    const h = coreHost([leftover()]);
    const group = groupRows(h)[0];
    const cancelled = confirmDiscardCopies(h, group, group.copies);
    calls[0].buttons[0].trigger();
    await expect(cancelled).resolves.toBe(false);
    const closed = confirmDiscardCopies(h, group, group.copies);
    calls[1].modal.dispatchEvent(new Event('typo3-modal-hidden'));
    await expect(closed).resolves.toBe(false);
  });

  it('keeps the component from discarding when the editor cancels', async () => {
    const calls = spyConfirm();
    const element = new WebconEasyWorkspaceMenu();
    element.layoutProfile = core;
    element._config = { ...element._config, labels: GERMAN };
    const group = { key: 'context', copies: [{ key: 'tt_content:158035', members: [{}, {}], records: [{ table: 'tt_content', workspaceUid: 161380 }] }] };
    const pending = element.handleDiscardCopies(group);
    await Promise.resolve();
    expect(calls).toHaveLength(1);
    expect(calls[0].message).toContain('2 Entwürfe');
    calls[0].buttons[0].trigger();
    await pending;
    expect(element.cleaning).toBe(false);
  });
});
