import { describe, expect, it, vi } from 'vitest';
import { render } from 'lit';
import efficiency, {
  exactTimeFormatter,
  isApplePlatform,
  keyboardHint,
  onKeydown,
  pressPublish,
  publishShortcut,
  publishShortcutText,
  selectAllHint,
  shortcutKeys,
} from '@webconsulting/webcon-easy-workspace/layouts/efficiency.js';
import { LAYOUTS } from '@webconsulting/webcon-easy-workspace/layouts/index.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { renderGroup } from '@webconsulting/webcon-easy-workspace/templates/group.js';
import { renderFooter } from '@webconsulting/webcon-easy-workspace/templates/footer.js';
import { groupRows } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { WebconEasyWorkspaceMenu } from '@webconsulting/webcon-easy-workspace/components/wew-toolbar-menu.js';
import { change, companion, copy, host } from './fixtures/items.js';

function mount(template) {
  const container = document.createElement('div');
  render(template, container);
  return container;
}

const text = (node) => node?.textContent.replace(/\s+/g, ' ').trim();
const fresh = () => Math.floor(Date.now() / 1000) - 300;
const hero = (extra = {}) => change(161266, {
  title: 'Everything a TYPO3 client site needs',
  latestChangeAt: fresh(),
  latestChangeUserUid: 1,
  companions: [companion(1), companion(2), companion(3)],
  ...extra,
});
const page = (extra = {}) => change(161300, { table: 'pages', title: 'Product', isPrimary: true, typeLabel: 'Standard', latestChangeAt: fresh(), ...extra });

/** A host rendering through the efficiency profile. */
const eff = (items, options = {}) => host(items, { layoutProfile: efficiency, ...options });

function keydown(keyName, init = {}) {
  return new KeyboardEvent('keydown', { key: keyName, cancelable: true, bubbles: true, ...init });
}

describe('efficiency profile', () => {
  it('is registered and opens short translation sections, keeps the cleanup folded, names its shortcuts', () => {
    expect(LAYOUTS.efficiency).toBe(efficiency);
    expect(efficiency.name).toBe('efficiency');
    expect(efficiency.flags).toMatchObject({
      selectAllMin: 2,
      otherLanguagesOpenUpTo: 5,
      cleanupOpen: 'never',
      keyboardHintKey: 'toolbar.keyboardHint.efficiency',
    });
    for (const hook of ['renderRow', 'rowClasses', 'renderCompanions', 'renderSection', 'renderCleanup', 'renderCopy', 'footerExtras', 'onKeydown', 'keyboardHint']) {
      expect(typeof efficiency[hook], hook).toBe('function');
    }
    expect(Object.isFrozen(efficiency)).toBe(true);
  });
});

describe('efficiency row', () => {
  it('keeps the row contract: key, checkbox stop, describing meta and translation lines', () => {
    const h = eff([hero()]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    expect(row.getAttribute('data-wew-key')).toBe('tt_content:161266');
    const check = row.querySelector('[data-wew-row-check][data-wew-stop]');
    expect(check).not.toBeNull();
    for (const id of check.getAttribute('aria-describedby').split(' ')) {
      expect(row.querySelector(`#${id}`), id).not.toBeNull();
    }
    expect(row.className).toContain('wew-row--dense');
    expect(row.className).toContain('wew-row--locatable');
    expect(row.className).not.toContain('wew-row--unselected');
  });

  it('puts locate on the type icon and leaves three actions: edit, compare, discard', () => {
    const highlight = vi.fn();
    const actions = [];
    const h = eff([hero()], {
      _highlightInIframe: highlight,
      handleRowAction: (event, action) => actions.push(action),
    });
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');

    const locate = row.querySelector('.wew-row__locate');
    expect(locate.tagName).toBe('BUTTON');
    expect(locate.getAttribute('data-wew-action')).toBe('preview');
    expect(locate.getAttribute('aria-label')).toBe('In der Vorschau anzeigen');
    expect(locate.getAttribute('tabindex')).toBe('-1');
    expect(locate.querySelector('.wew-row__eye typo3-backend-icon').getAttribute('identifier')).toBe('wew-preview');
    // Locate comes first, so ArrowRight from the checkbox reaches it first.
    expect(Array.from(row.querySelectorAll('[data-wew-action]'), (el) => el.getAttribute('data-wew-action'))).toEqual(['preview', 'edit', 'diff', 'discard']);
    expect(row.querySelector('.wew-row__actions [data-wew-action="preview"]')).toBeNull();

    locate.dispatchEvent(new MouseEvent('mouseenter'));
    expect(highlight).toHaveBeenCalledWith(h.items[0]);
    locate.click();
    expect(actions).toEqual(['preview']);
  });

  it('keeps a plain icon for records that cannot be located', () => {
    const h = eff([page()]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    expect(row.querySelector('.wew-row__locate')).toBeNull();
    expect(row.querySelector('.wew-row__type-tile').getAttribute('aria-hidden')).toBe('true');
    expect(row.querySelector('[data-wew-action="preview"]')).toBeNull();
    expect(row.className).not.toContain('wew-row--locatable');
  });

  it('names the Delete key on discard, and says why when discarding is off', () => {
    const on = eff([hero()]);
    const discard = mount(renderRow(on, on.items[0])).querySelector('[data-wew-action="discard"]');
    expect(discard.getAttribute('title')).toBe('Diese Änderung verwerfen (Entf)');
    expect(discard.disabled).toBe(false);

    const off = eff([hero()], { config: { enableRevert: false } });
    const disabled = mount(renderRow(off, off.items[0])).querySelector('[data-wew-action="discard"]');
    expect(disabled.disabled).toBe(true);
    expect(disabled.getAttribute('title')).toContain('enableRevert');
  });

  it('gives the title line 1 alone and puts the time before the author on line 2', () => {
    const h = eff([hero()]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    expect(row.querySelector('.wew-row__title').nextElementSibling.className).toBe('wew-row__actions');
    const meta = row.querySelector('.wew-row__meta');
    expect(meta.firstElementChild.hasAttribute('data-wew-change-badge')).toBe(true);
    expect(text(meta.querySelector('.wew-row__meta-text'))).toBe('Hero-Bereich · Normal · vor 5 Min. · von Ihnen');
    // Later parts carry their separator, so the ellipsis drops whole parts.
    expect(Array.from(meta.querySelectorAll('.wew-row__part'), (part) => [text(part), part.classList.contains('wew-row__part--unit')]))
      .toEqual([['Hero-Bereich', false], ['· Normal', true], ['· vor 5 Min.', true], ['· von Ihnen', true]]);
    const time = meta.querySelector('.wew-row__part--time');
    expect(text(time)).toBe('· vor 5 Min.');
    expect(text(meta.querySelector('.wew-row__part--column'))).toBe('· Normal');
    // The exact date on hover.
    expect(time.getAttribute('title')).toMatch(/\d/);
  });

  it('marks unselected rows, which mute their title', () => {
    const h = eff([hero(), change(7)], { selected: ['tt_content:7'] });
    const rows = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    expect(rows.className).toContain('wew-row--unselected');
    const selected = mount(renderRow(h, h.items[1])).querySelector('[data-wew-row]');
    expect(selected.className).not.toContain('wew-row--unselected');
  });

  it('renders editable translation chips with a pencil, plain codes for deleted ones', () => {
    const edits = [];
    const h = eff([hero({ companions: [companion(1), companion(2, 'deleted')] })], {
      handleCompanionEdit: (item, member) => edits.push(member.languageUid),
      handleRowClick: () => edits.push('row'),
    });
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    const line = row.querySelector('[data-wew-companions]');
    expect(line.id).toBe(`${row.querySelector('[data-wew-row-check]').getAttribute('aria-describedby').split(' ')[1]}`);
    expect(text(line.querySelector('[data-wew-companion-kind="identical"] .wew-row__companions-label'))).toBe('Übersetzungen unverändert:');

    const chip = line.querySelector('button[data-wew-companion]');
    expect(chip.getAttribute('aria-label')).toBe('DE: Übersetzung „German“ bearbeiten');
    expect(chip.querySelector('.wew-companion__pen typo3-backend-icon').getAttribute('identifier')).toBe('actions-open');
    chip.click();
    expect(edits).toEqual([1]);

    const deleted = line.querySelector('[data-wew-companion-kind="deleted"] .wew-companion--static');
    expect(deleted.tagName).toBe('SPAN');
    expect(deleted.querySelector('.wew-companion__pen')).toBeNull();
    expect(text(deleted)).toBe('ZH');
  });
});

describe('efficiency sections', () => {
  it('opens a short translation section by itself and says where its rows are', () => {
    const h = eff([hero(), change(5, { languageUid: 1, title: 'Alles, was …' })]);
    const group = mount(renderGroup(h, groupRows(h)[0]));
    const toggle = group.querySelector('[data-wew-section="other-languages"] [data-wew-section-toggle][data-wew-stop]');
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(text(toggle.querySelector('.wew-section__meta'))).toBe('in English nicht sichtbar');
    expect(group.querySelector('[data-wew-section-body] h5[data-wew-language="1"]')).not.toBeNull();
    expect(group.querySelectorAll('[data-wew-section-body] [data-wew-row]')).toHaveLength(1);
    expect(group.querySelector('[data-wew-section-check]').checked).toBe(true);
  });

  it('says how many folded rows go live, with a tri-state checkbox', () => {
    const rows = [1, 2, 3, 4, 5, 6].map((n) => change(10 + n, { languageUid: 1 }));
    const h = eff([hero(), ...rows], { selected: ['tt_content:161266', 'tt_content:11', 'tt_content:12'] });
    const group = mount(renderGroup(h, groupRows(h)[0]));
    const section = group.querySelector('[data-wew-section="other-languages"]');
    const toggle = section.querySelector('[data-wew-section-toggle]');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(text(section.querySelector('.wew-section__meta--selection'))).toBe('2 von 6 ausgewählt');
    expect(text(toggle.querySelector('.visually-hidden'))).toContain('2 von 6 ausgewählt');
    expect(section.querySelector('[data-wew-section-check]').indeterminate).toBe(true);
    expect(section.querySelector('[data-wew-section-body]')).toBeNull();
  });

  it('keeps the cleanup folded even when alone and discards it in one click', () => {
    const discarded = [];
    const h = eff([copy(3, { companions: [companion(1)] })], { handleDiscardCopies: (group) => discarded.push(group.key) });
    const group = mount(renderGroup(h, groupRows(h)[0]));
    const cleanup = group.querySelector('[data-wew-cleanup]');
    expect(cleanup.querySelector('[data-wew-section-toggle][data-wew-stop]').getAttribute('aria-expanded')).toBe('false');
    expect(text(cleanup.querySelector('.wew-section__count'))).toBe('2');
    expect(text(cleanup.querySelector('.wew-section__meta'))).toBe('Inhalt wie live');
    const button = cleanup.querySelector('[data-wew-discard-copies]');
    expect(text(button)).toBe('Verwerfen');
    expect(button.getAttribute('aria-label')).toBe('Kopien verwerfen');
    expect(button.getAttribute('title')).toBe('Kopien verwerfen (2 unveränderte Kopien)');
    button.click();
    expect(discarded).toEqual(['context']);
    expect(cleanup.querySelector('.wew-section__help').className).toContain('visually-hidden');
  });

  it('lists the copies when opened: one line per element, the first chip is the stop', () => {
    const h = eff([copy(3, { title: 'Hero', companions: [companion(1), companion(2)] })]);
    h.sectionState = new Map([['context:copies', true]]);
    const group = mount(renderGroup(h, groupRows(h)[0]));
    const line = group.querySelector('[data-wew-copy-group]');
    expect(text(line.querySelector('.wew-copy__title'))).toBe('Hero');
    expect(text(line.querySelector('.wew-copy__type'))).toBe('Hero-Bereich');
    const chips = line.querySelectorAll('[data-wew-companion]');
    expect(chips).toHaveLength(3);
    expect(chips[0].hasAttribute('data-wew-stop')).toBe(true);
    expect(chips[1].hasAttribute('data-wew-stop')).toBe(false);
    expect(chips[0].querySelector('.wew-companion__pen')).not.toBeNull();
    expect(group.querySelector('[data-wew-cleanup] .wew-section__meta')).toBeNull();
    expect(group.querySelector('[data-wew-cleanup] .wew-section__help').className).not.toContain('visually-hidden');
  });
});

describe('efficiency footer', () => {
  it('names the shortcuts in quiet <kbd> hints, only while there is something to publish', () => {
    const h = eff([hero(), change(7)]);
    const footer = mount(renderFooter(h));
    const hints = footer.querySelector('[data-wew-shortcuts]');
    expect(hints.getAttribute('aria-hidden')).toBe('true');
    expect(hints.querySelectorAll('kbd.wew-kbd').length).toBeGreaterThanOrEqual(4);
    const hint = (name) => Array.from(hints.querySelector(`.wew-shortcut--${name}`).children, text);
    expect(hint('publish')).toEqual(['Strg', 'Enter', 'veröffentlichen']);
    expect(hints.querySelector('.wew-shortcut--publish kbd').getAttribute('title')).toBe('Steuerungstaste');
    expect(hint('discard')).toEqual(['Entf', 'verwerfen']);
    expect(hint('all')).toEqual(['A', 'alle']);
    expect(hint('move')).toEqual(['↑ ↓', 'bewegen']);
    // The screen-reader help names Delete and Ctrl/Cmd+Enter.
    expect(text(footer)).toContain('Strg+Enter oder Cmd+Enter veröffentlicht');

    expect(mount(renderFooter(eff([hero()]))).querySelector('.wew-shortcut--all')).toBeNull();
    expect(mount(renderFooter(eff([hero()], { config: { enableRevert: false } }))).querySelector('.wew-shortcut--discard')).toBeNull();
    expect(mount(renderFooter(eff([])))).not.toBeNull();
    expect(mount(renderFooter(eff([]))).querySelector('[data-wew-shortcuts]')).toBeNull();
  });

  it('names the Delete key in the screen-reader help only while discarding is on', () => {
    expect(keyboardHint(eff([hero()]))).toBe('toolbar.keyboardHint.efficiency');
    expect(keyboardHint(eff([hero()], { config: { enableRevert: false } }))).toBe('toolbar.keyboardHint.efficiencyNoDiscard');

    const off = mount(renderFooter(eff([hero()], { config: { enableRevert: false } })));
    const help = Array.from(off.querySelectorAll('.visually-hidden'), text).join(' ');
    expect(help).toContain('Strg+Enter oder Cmd+Enter veröffentlicht');
    expect(help).not.toMatch(/Entf|Delete/);
  });

  it('builds the exact-time formatter once per backend language', () => {
    const spy = vi.spyOn(Intl, 'DateTimeFormat');
    document.documentElement.lang = 'de-AT-x-formatter-test';
    const h = eff([hero(), change(7, { latestChangeAt: fresh() }), change(8, { latestChangeAt: fresh() })]);
    const group = groupRows(h)[0];
    mount(renderGroup(h, group));
    mount(renderGroup(h, group));
    expect(spy.mock.calls.filter(([locale]) => locale === 'de-AT-x-formatter-test')).toHaveLength(1);
    expect(exactTimeFormatter()).toBe(exactTimeFormatter());
    document.documentElement.lang = '';
  });

  it('shows ⌘ on a Mac and Strg elsewhere', () => {
    const h = eff([hero()]);
    expect(shortcutKeys(h, true).mod.text).toBe('⌘');
    expect(shortcutKeys(h, false).mod).toEqual({ text: 'Strg', title: 'Steuerungstaste' });
    expect(publishShortcutText(h, false)).toBe('Strg+Enter');
    expect(publishShortcutText(h, true)).toBe('⌘+Enter');
    expect(publishShortcut(h).keys).toBe('Control+Enter Meta+Enter');
    expect(efficiency.publishShortcut).toBe(publishShortcut);
    expect(isApplePlatform({ userAgentData: { platform: 'macOS' } })).toBe(true);
    expect(isApplePlatform({ platform: 'iPad' })).toBe(true);
    expect(isApplePlatform({ platform: 'Win32' })).toBe(false);
    expect(isApplePlatform(undefined)).toBe(false);
    expect(selectAllHint(h)).toBe('alle');
  });
});

describe('efficiency shortcuts', () => {
  function shortcutHost(items, { publishDisabled = false, ...options } = {}) {
    const button = document.createElement('button');
    button.setAttribute('data-wew-publish', '');
    button.disabled = publishDisabled;
    const h = eff(items, options);
    h.statuses = [];
    h.setStatus = (message) => h.statuses.push(message);
    h.querySelector = (selector) => (selector === '[data-wew-publish]' ? button : null);
    return { h, button };
  }

  it('presses publish on Ctrl+Enter and Cmd+Enter, from anywhere in the dialog', () => {
    const { h, button } = shortcutHost([hero()]);
    const clicks = vi.fn();
    button.addEventListener('click', clicks);
    expect(onKeydown(h, keydown('Enter', { ctrlKey: true }), {})).toBe(true);
    expect(button.hasAttribute('data-wew-pressed')).toBe(true);
    expect(onKeydown(h, keydown('Enter', { metaKey: true }), {})).toBe(true);
    expect(clicks).toHaveBeenCalledTimes(2);
    // Plain Enter stays the list's (edit); Shift/Alt combinations are not ours.
    expect(onKeydown(h, keydown('Enter'), {})).toBe(false);
    expect(onKeydown(h, keydown('Enter', { ctrlKey: true, shiftKey: true }), {})).toBe(false);
  });

  it('explains instead of publishing when nothing is selected, and ignores the key while publishing', () => {
    const { h, button } = shortcutHost([hero()], { publishDisabled: true });
    const clicks = vi.fn();
    button.addEventListener('click', clicks);
    expect(pressPublish(h)).toBe(true);
    expect(h.statuses).toEqual(['Wählen Sie mindestens eine Änderung aus.']);
    h.publishing = true;
    expect(pressPublish(h)).toBe(true);
    expect(clicks).not.toHaveBeenCalled();
    expect(pressPublish({ ...h, publishing: false, querySelector: () => null })).toBe(false);
  });

  it('opens the discard confirmation on Delete or Backspace in a row, never discarding by itself', () => {
    const calls = [];
    const { h } = shortcutHost([hero()], { handleRowAction: (event, action, item) => calls.push([action, item.workspaceUid]) });
    const row = document.createElement('li');
    expect(onKeydown(h, keydown('Delete'), { item: h.items[0], row })).toBe(true);
    expect(onKeydown(h, keydown('Backspace'), { item: h.items[0], row })).toBe(true);
    expect(calls).toEqual([['discard', 161266], ['discard', 161266]]);
    // A held key does not stack confirmations.
    expect(onKeydown(h, keydown('Delete', { repeat: true }), { item: h.items[0], row })).toBe(true);
    expect(calls).toHaveLength(2);
    // Outside a row (section toggle, footer) Delete does nothing.
    expect(onKeydown(h, keydown('Delete'), { item: null, row: null })).toBe(false);
  });

  it('leaves Delete alone where discarding is off', () => {
    const calls = [];
    const { h } = shortcutHost([hero()], { config: { enableRevert: false }, handleRowAction: () => calls.push(1) });
    expect(onKeydown(h, keydown('Delete'), { item: h.items[0], row: document.createElement('li') })).toBe(false);
    expect(calls).toEqual([]);
  });

  it('toggles every edit with "a" and says the new count', () => {
    const { h } = shortcutHost([hero(), change(7)]);
    h.requestSelectionUpdate = vi.fn();
    expect(onKeydown(h, keydown('a'), {})).toBe(true);
    expect(h.selection.size).toBe(0);
    expect(onKeydown(h, keydown('A'), {})).toBe(true);
    expect(Array.from(h.selection)).toEqual(['tt_content:161266', 'tt_content:7']);
    expect(h.statuses).toEqual(['0 von 2 ausgewählt', '2 von 2 ausgewählt']);
    expect(h.requestSelectionUpdate).toHaveBeenCalledTimes(2);
    expect(onKeydown(h, keydown('a', { repeat: true }), {})).toBe(true);
    expect(h.selection.size).toBe(2);
    expect(onKeydown(shortcutHost([]).h, keydown('a'), {})).toBe(false);
  });

  it('stays out of the way: modifiers, text fields and the preview menu', () => {
    const { h } = shortcutHost([hero(), change(7)]);
    expect(onKeydown(h, keydown('a', { ctrlKey: true }), {})).toBe(false);
    expect(onKeydown(h, keydown('a', { altKey: true }), {})).toBe(false);
    const field = document.createElement('input');
    field.type = 'search';
    document.body.append(field);
    const typed = keydown('a');
    field.dispatchEvent(typed);
    expect(onKeydown(h, typed, {})).toBe(false);
    const menu = document.createElement('ul');
    menu.setAttribute('role', 'menu');
    const item = document.createElement('button');
    menu.append(item);
    document.body.append(menu);
    const inMenu = keydown('a');
    item.dispatchEvent(inMenu);
    expect(onKeydown(h, inMenu, {})).toBe(false);
    expect(h.selection.size).toBe(2);
    field.remove();
    menu.remove();
  });

  it('runs inside the dialog before Escape: a handled key is prevented', () => {
    const element = new WebconEasyWorkspaceMenu();
    element.layoutProfile = efficiency;
    element.items = [hero(), change(7)];
    element.selection = new Set(['tt_content:161266', 'tt_content:7']);
    element.setStatus = () => {};
    const event = keydown('a');
    element.handleDialogKeydown(event);
    expect(event.defaultPrevented).toBe(true);
    expect(element.selection.size).toBe(0);
  });
});
