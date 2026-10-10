import { describe, expect, it } from 'vitest';
import { render } from 'lit';
import { renderFooter } from '@webconsulting/webcon-easy-workspace/templates/footer.js';
import { renderHeader } from '@webconsulting/webcon-easy-workspace/templates/header.js';
import { renderGroup } from '@webconsulting/webcon-easy-workspace/templates/group.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { renderEmpty, renderLoading, renderNoContext } from '@webconsulting/webcon-easy-workspace/templates/states.js';
import { groupRows } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { WebconEasyWorkspaceMenu } from '@webconsulting/webcon-easy-workspace/components/wew-toolbar-menu.js';
import { change, companion, copy, GERMAN, host } from './fixtures/items.js';

function mount(template) {
  const container = document.createElement('div');
  render(template, container);
  return container;
}

const text = (node) => node?.textContent.replace(/\s+/g, ' ').trim();
const hero = (extra = {}) => change(161266, {
  title: 'Everything a TYPO3 client site needs',
  companions: [companion(1), companion(2), companion(3)],
  ...extra,
});

describe('row template', () => {
  it('gives the title its own line and starts line 2 with the change badge', () => {
    const h = host([hero({ isHidden: true })]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');

    expect(text(row.querySelector('.wew-row__title'))).toBe('Everything a TYPO3 client site needs');
    expect(row.querySelector('.wew-row__title .badge')).toBeNull();
    const meta = row.querySelector('.wew-row__meta');
    expect(meta.firstElementChild.hasAttribute('data-wew-change-badge')).toBe(true);
    expect(meta.firstElementChild.className).toContain('badge-info');
    expect(text(meta.firstElementChild)).toBe('Geändert');
    expect(text(meta.children[1])).toBe('Verborgen');
  });

  it('tags the title with its language and describes the checkbox with the meta and translation lines', () => {
    const h = host([hero(), change(5, { languageUid: 1 })], { viewLanguages: null });
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    const checkbox = row.querySelector('[data-wew-row-check]');

    expect(row.querySelector('.wew-row__title').getAttribute('lang')).toBe('en-US');
    expect(checkbox.getAttribute('aria-describedby')).toBe('wew-row-tt_content-161266-meta wew-row-tt_content-161266-companions');
    expect(checkbox.hasAttribute('data-wew-stop')).toBe(true);
    expect(checkbox.getAttribute('tabindex')).toBe('-1');
    expect(checkbox.closest('.form-check')).not.toBeNull();
    expect(row.querySelector('#wew-row-tt_content-161266-meta')).not.toBeNull();

    const german = mount(renderRow(h, h.items[1])).querySelector('[data-wew-row]');
    expect(german.querySelector('[data-wew-row-check]').getAttribute('aria-describedby')).toBe('wew-row-tt_content-5-meta');
    // A view of several languages: the row says which one it is, as a code.
    expect(text(german.querySelector('[data-wew-row-language] [aria-hidden="true"]:not(typo3-backend-icon)'))).toBe('DE');
    expect(german.querySelector('.wew-row__title').getAttribute('lang')).toBe('de-AT');
  });

  it('orders the actions edit, compare, locate, discard — discard last', () => {
    const h = host([hero({ locateTable: 'tt_content', locateLiveUid: 158035 })]);
    const actions = Array.from(mount(renderRow(h, h.items[0])).querySelectorAll('[data-wew-action]'));

    expect(actions.map((action) => action.getAttribute('data-wew-action'))).toEqual(['edit', 'diff', 'preview', 'discard']);
    expect(actions.at(-1).className).toContain('wew-row__action--danger');
    for (const action of actions) {
      expect(action.getAttribute('tabindex')).toBe('-1');
      expect(action.className).toContain('btn-borderless');
      expect(action.getAttribute('aria-label')).not.toBe('');
    }
  });

  it('keeps discard visible but disabled when reverting is switched off', () => {
    const h = host([hero()], { config: { enableRevert: false } });
    const discard = mount(renderRow(h, h.items[0])).querySelector('[data-wew-action="discard"]');
    expect(discard.disabled).toBe(true);
    expect(discard.getAttribute('title')).toBe('Verwerfen ist per Konfiguration deaktiviert (enableRevert = 0)');
  });

  it('folds the translations TYPO3 copied along into one line of chips', () => {
    const h = host([hero()]);
    const line = mount(renderRow(h, h.items[0])).querySelector('[data-wew-companions]');

    expect(text(line.querySelector('.wew-row__companions-label'))).toBe('Übersetzungen unverändert:');
    const chips = Array.from(line.querySelectorAll('button[data-wew-companion]'));
    expect(chips.map((chip) => chip.getAttribute('aria-label'))).toEqual([
      'DE: Übersetzung „German“ bearbeiten',
      'ZH: Übersetzung „Chinese (Simplified)“ bearbeiten',
      'HU: Übersetzung „Hungarian“ bearbeiten',
    ]);
    expect(chips.map((chip) => text(chip))).toEqual(['DE', 'ZH', 'HU']);
    expect(chips.every((chip) => chip.getAttribute('tabindex') === '-1')).toBe(true);
    expect(text(line.querySelector('.visually-hidden'))).toContain('Beim Speichern legt TYPO3 auch Arbeitskopien');
  });

  it('names a shared field taken over, and shows moved or deleted translations as plain codes', () => {
    const h = host([
      hero({ companions: [companion(1, 'synced', ['Veröffentlichungsdatum']), companion(2, 'synced', ['Veröffentlichungsdatum'])] }),
      hero({ workspaceUid: 7, kindKey: 'delete', companions: [companion(1, 'deleted'), companion(3, 'deleted')] }),
      hero({ workspaceUid: 8, kindKey: 'move', companions: [companion(1, 'moved')] }),
    ]);
    const synced = mount(renderRow(h, h.items[0])).querySelector('[data-wew-companions]');
    expect(text(synced.querySelector('.wew-row__companions-label'))).toBe('Gilt auch für die Übersetzungen (Veröffentlichungsdatum):');
    expect(synced.querySelectorAll('button[data-wew-companion]')).toHaveLength(2);

    const deleted = mount(renderRow(h, h.items[1]));
    expect(text(deleted.querySelector('.wew-row__companions-label'))).toBe('Übersetzungen werden mitgelöscht:');
    expect(deleted.querySelectorAll('button[data-wew-companion]')).toHaveLength(0);
    expect(Array.from(deleted.querySelectorAll('.wew-companion--static')).map(text)).toEqual(['DE', 'HU']);
    expect(deleted.querySelector('[data-wew-row]').getAttribute('data-change')).toBe('deleted');

    expect(text(mount(renderRow(h, h.items[2])).querySelector('.wew-row__companions-label'))).toBe('Übersetzungen werden mitverschoben:');
  });

  it('marks a row the view does not show and offers no preview for it', () => {
    const h = host([change(1, { languageUid: 1, locateTable: 'tt_content', locateLiveUid: 3 })]);
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');

    expect(row.getAttribute('data-wew-in-view')).toBe('0');
    expect(row.getAttribute('title')).toBe('Auf der Seite nur in der Sprachansicht German zu sehen');
    expect(row.querySelector('[data-wew-row-language]')).toBeNull();
    expect(row.querySelector('[data-wew-action="preview"]')).toBeNull();
    expect(row.querySelector('[data-wew-row-check]')).not.toBeNull();
  });

  it('names related changes on one line when the user setting asks for them', () => {
    const item = change(1, { childChanges: [
      { table: 'tx_item', title: '3 Tage', kindKey: 'modified', contentChanged: true },
      { table: 'sys_file_reference', title: 'hero.jpg', kindKey: 'new' },
      { table: 'sys_file_reference', title: 'copy.jpg', kindKey: 'modified', contentChanged: false },
    ] });
    const on = host([item], { config: { showSubelementsInToolbar: true } });
    const children = mount(renderRow(on, item)).querySelector('[data-wew-children]');
    expect(text(children)).toBe('2 zugehörige Änderungen: 3 Tage · hero.jpg (Neu)');
    expect(children.getAttribute('title')).toBe('3 Tage: Geändert\nhero.jpg: Neu');
    expect(mount(renderRow(host([item]), item)).querySelector('[data-wew-children]')).toBeNull();
  });
});

describe('group template', () => {
  it('heads a group with an h3 and shows a count only when there are two groups', () => {
    const h = host([hero(), change(2, { table: 'sys_file_metadata' })]);
    const [context, files] = groupRows(h);
    const single = mount(renderGroup(h, context, { groupCount: 1 }));
    expect(text(single.querySelector('h3.wew-group__title'))).toBe('Product');
    expect(single.querySelector('section.wew-group').getAttribute('aria-labelledby')).toBe(single.querySelector('h3').id);
    expect(single.querySelector('.wew-group__count')).toBeNull();

    const counted = mount(renderGroup(h, context, { groupCount: 2 }));
    expect(text(counted.querySelector('.wew-group__count'))).toBe('1');
    expect(counted.querySelector('.wew-group__count').getAttribute('title')).toBe('1 Änderung');
    expect(text(mount(renderGroup(h, files, { groupCount: 2 })).querySelector('h3'))).toBe('Dateien in diesem Workspace');
  });

  it('folds the other languages under a heading with a tri-state checkbox', () => {
    const h = host([hero(), change(2, { languageUid: 1 }), change(3, { languageUid: 2 })], { selected: ['tt_content:161266', 'tt_content:2'] });
    let list = mount(renderGroup(h, groupRows(h)[0]));

    expect(Array.from(list.querySelectorAll('[data-wew-section]')).map((node) => node.getAttribute('data-wew-section'))).toEqual(['in-view', 'other-languages']);
    expect(text(list.querySelector('h4.wew-section--in-view'))).toBe('English · in dieser Ansicht 1');
    const toggle = list.querySelector('[data-wew-section-toggle]');
    expect(toggle.closest('h4')).not.toBeNull();
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(toggle.hasAttribute('data-wew-stop')).toBe(true);
    expect(text(toggle.querySelector('.wew-section__title'))).toBe('Übersetzungen');
    expect(text(toggle.querySelector('.wew-section__count'))).toBe('2 Änderungen');
    expect(text(toggle.querySelector('.wew-section__meta'))).toBe('in English nicht sichtbar');
    const check = list.querySelector('[data-wew-section-check]');
    expect(check.indeterminate).toBe(true);
    expect(check.checked).toBe(false);
    expect(check.getAttribute('aria-label')).toBe('Alle Änderungen in „Übersetzungen“ auswählen');
    expect(check.getAttribute('title')).toBe('1 von 2 ausgewählt');
    expect(Array.from(list.querySelectorAll('[data-wew-row]')).map((row) => row.getAttribute('data-wew-key'))).toEqual(['tt_content:161266']);

    toggle.click();
    list = mount(renderGroup(h, groupRows(h)[0]));
    const opened = list.querySelector('[data-wew-section-toggle]');
    expect(opened.getAttribute('aria-expanded')).toBe('true');
    expect(list.querySelector(`#${opened.getAttribute('aria-controls')}`)).not.toBeNull();
    expect(Array.from(list.querySelectorAll('h5.wew-language')).map(text)).toEqual(['German 1', 'Chinese (Simplified) 1']);
    expect(Array.from(list.querySelectorAll('[data-wew-row]')).map((row) => row.getAttribute('data-wew-key'))).toEqual([
      'tt_content:161266', 'tt_content:2', 'tt_content:3',
    ]);
  });

  it('opens the other languages by itself when nothing of the page is in view', () => {
    const h = host([change(2, { languageUid: 1 })]);
    const list = mount(renderGroup(h, groupRows(h)[0]));
    expect(list.querySelector('[data-wew-section="in-view"]')).toBeNull();
    expect(list.querySelector('[data-wew-section-toggle]').getAttribute('aria-expanded')).toBe('true');
    expect(list.querySelector('[data-wew-section-check]').checked).toBe(true);
  });

  it('lists leftover copies in a cleanup section with one discard action', () => {
    const h = host([hero(), copy(9, { title: 'What is in the package', companions: [companion(1), companion(2)] })]);
    let list = mount(renderGroup(h, groupRows(h)[0]));
    const cleanup = list.querySelector('[data-wew-cleanup]');
    // Folded while the page has edits.
    expect(cleanup.querySelector('[data-wew-section-toggle]').getAttribute('aria-expanded')).toBe('false');
    expect(text(cleanup.querySelector('.wew-section__title'))).toBe('Unveränderte Kopien');
    expect(cleanup.querySelector('.wew-section__count').getAttribute('title')).toBe('3 unveränderte Kopien');
    const discard = cleanup.querySelector('[data-wew-discard-copies]');
    expect(text(discard)).toBe('Kopien verwerfen');
    expect(discard.disabled).toBe(false);
    expect(discard.getAttribute('tabindex')).toBe('-1');
    expect(list.querySelector(`#${discard.getAttribute('aria-describedby')}`).className).toContain('visually-hidden');
    expect(cleanup.querySelector('[data-wew-copy-group]')).toBeNull();

    const disabled = host(h.items, { config: { enableRevert: false } });
    expect(mount(renderGroup(disabled, groupRows(disabled)[0])).querySelector('[data-wew-discard-copies]').disabled).toBe(true);

    const busy = host(h.items, { cleaning: true });
    expect(text(mount(renderGroup(busy, groupRows(busy)[0])).querySelector('[data-wew-discard-copies]'))).toBe('Wird verworfen…');

    cleanup.querySelector('[data-wew-section-toggle]').click();
    list = mount(renderGroup(h, groupRows(h)[0]));
    const line = list.querySelector('[data-wew-copy-group]');
    expect(text(line.querySelector('.wew-copy__title'))).toBe('What is in the package');
    const chips = Array.from(line.querySelectorAll('[data-wew-companion]'));
    expect(chips.map(text)).toEqual(['EN', 'DE', 'ZH']);
    expect(chips.map((chip) => chip.hasAttribute('data-wew-stop'))).toEqual([true, false, false]);
    expect(chips[1].getAttribute('aria-label')).toBe('DE: Kopie „German“ bearbeiten');
  });

  it('opens the cleanup section when copies are all the page has', () => {
    const h = host([copy(9)]);
    const list = mount(renderGroup(h, groupRows(h)[0]));
    expect(list.querySelector('[data-wew-cleanup] [data-wew-section-toggle]').getAttribute('aria-expanded')).toBe('true');
    expect(text(list.querySelector('.wew-section__help'))).toBe('Von TYPO3 angelegt, Inhalt wie live. Verwerfen ändert nichts an der Website.');
    expect(list.querySelector('.wew-section__help').className).not.toContain('visually-hidden');
    expect(list.querySelector('[data-wew-row]')).toBeNull();
  });
});

describe('footer template', () => {
  it('shows select-all from two edits on: "Alle (n)" or "x von n"', () => {
    const one = mount(renderFooter(host([hero()])));
    expect(one.querySelector('[data-wew-select-all]')).toBeNull();
    expect(text(one.querySelector('[data-wew-publish]'))).toBe('1 Änderung veröffentlichen');

    const all = mount(renderFooter(host([hero(), change(2)])));
    const input = all.querySelector('[data-wew-select-all]');
    expect(input.closest('.form-check')).not.toBeNull();
    expect(all.querySelector(`label.form-check-label[for="${input.id}"]`)).not.toBeNull();
    expect(text(all.querySelector('[data-wew-selection-count]'))).toBe('Alle (2)');
    expect(input.checked).toBe(true);

    const some = mount(renderFooter(host([hero(), change(2), change(3)], { selected: ['tt_content:2'] })));
    expect(text(some.querySelector('[data-wew-selection-count]'))).toBe('1 von 3');
    expect(some.querySelector('[data-wew-select-all]').indeterminate).toBe(true);
    expect(some.querySelector('[data-wew-select-all]').getAttribute('aria-label')).toBe('Alle Änderungen auswählen (1 von 3 ausgewählt)');
    expect(text(some.querySelector('[data-wew-publish]'))).toBe('1 Änderung veröffentlichen');
  });

  it('says why publishing is disabled when nothing is selected', () => {
    const footer = mount(renderFooter(host([hero(), change(2)], { selected: [] })));
    const publish = footer.querySelector('[data-wew-publish]');
    expect(publish.disabled).toBe(true);
    expect(text(publish)).toBe('Live veröffentlichen');
    expect(text(footer.querySelector(`#${publish.getAttribute('aria-describedby')}`))).toBe('Wählen Sie mindestens eine Änderung aus.');
  });

  it('has a status region and leaves the selection out when there is nothing to publish', () => {
    const footer = mount(renderFooter(host([copy(1)], { statusMessage: 'Liste aktualisiert' })));
    expect(footer.querySelector('[data-wew-select-all]')).toBeNull();
    expect(footer.querySelector('[data-wew-publish]')).toBeNull();
    expect(footer.querySelector('[data-wew-preview-open]').getAttribute('aria-label')).toBe('Vorschau');
    const status = footer.querySelector('[data-wew-status]');
    expect(status.getAttribute('role')).toBe('status');
    expect(text(status)).toBe('Liste aktualisiert');
  });
});

describe('header template', () => {
  it('names the workspace, its stage with a hidden prefix, and the numbers without a live region', () => {
    const h = host([hero(), copy(2)], { badgeCount: 4 });
    const header = mount(renderHeader(h));

    expect(text(header.querySelector('.wew-menu__title'))).toBe('AI Review Test');
    const stage = header.querySelector('[data-wew-stage]');
    expect(text(stage)).toBe('Stufe: Bearbeitung');
    expect(text(stage.querySelector('.visually-hidden'))).toBe('Stufe:');
    expect(stage.hasAttribute('aria-label')).toBe(false);
    const summary = header.querySelector('[data-wew-count-chip]');
    expect(summary.hasAttribute('aria-live')).toBe(false);
    expect(summary.id).toBe('wew-summary-test');
    expect(text(summary)).toBe('1 Änderung auf dieser Seite · 1 unveränderte Kopie · 3 weitere im Workspace');
    expect(header.querySelector('[data-wew-open-module]')).not.toBeNull();
  });

  it('hides the summary line on error', () => {
    const header = mount(renderHeader(host([], { state: 'error' })));
    expect(header.querySelector('[data-wew-count-chip]').hidden).toBe(true);
  });
});

describe('state templates', () => {
  it('offers the module from the empty state when changes wait elsewhere', () => {
    const empty = mount(renderEmpty(host([], { badgeCount: 3 })));
    const title = empty.querySelector('[data-wew-empty-title]');
    expect(title.tagName).toBe('H3');
    expect(title.getAttribute('tabindex')).toBe('-1');
    expect(text(empty.querySelector('[data-wew-empty-elsewhere]'))).toBe('3 Änderungen an anderer Stelle ansehen');
    expect(mount(renderEmpty(host([], { badgeCount: 0 }))).querySelector('[data-wew-empty-elsewhere]')).toBeNull();
  });

  it('draws the skeleton on the row grid and gives the no-context state a module button', () => {
    const loading = mount(renderLoading(host()));
    expect(loading.querySelector('[data-wew-loading]').getAttribute('aria-busy')).toBe('true');
    expect(loading.querySelectorAll('.wew-skeleton__row')).toHaveLength(3);
    expect(loading.querySelectorAll('.wew-skeleton__badge')).toHaveLength(3);
    expect(loading.querySelectorAll('.wew-skeleton__actions > span')).toHaveLength(9);
    expect(text(mount(renderNoContext(host())).querySelector('[data-wew-no-context-module]'))).toBe('Modul öffnen');
  });
});

describe('discard confirmation', () => {
  it('names the translations discarded along and the new translations removed with a new element', () => {
    const h = host();
    const content = WebconEasyWorkspaceMenu.prototype.discardConfirmContent.call(h, hero({
      kindKey: 'new',
      companions: [companion(2), companion(1, 'synced', ['Spalte'])],
      dependents: [{ table: 'tt_content', workspaceUid: 9, languageUid: 3 }],
    }));
    const paragraphs = Array.from(content.querySelectorAll('p')).map((paragraph) => paragraph.textContent);
    expect(paragraphs).toHaveLength(4);
    expect(paragraphs[1]).toBe('Die unveränderten Übersetzungskopien (Chinese (Simplified)) werden mit verworfen. Die veröffentlichten Übersetzungen bleiben, wie sie sind.');
    expect(paragraphs[2]).toBe('Die Übersetzungen (German) behalten ihre veröffentlichten Werte.');
    expect(paragraphs[3]).toBe('Neue Übersetzungen dieses Elements werden ebenfalls entfernt: Hungarian.');
    expect(content.querySelectorAll('*')).toHaveLength(4);
  });

  it('names the translations Core discards with a move, their own edits included, apart from new ones', () => {
    const h = host();
    const content = WebconEasyWorkspaceMenu.prototype.discardConfirmContent.call(h, hero({
      kindKey: 'move',
      companions: [companion(2, 'moved')],
      dependents: [
        { table: 'tt_content', workspaceUid: 12, languageUid: 3, edited: true },
        { table: 'tt_content', workspaceUid: 11, languageUid: 1, edited: true },
        { table: 'tx_easyws_item', workspaceUid: 13, languageUid: 1, edited: true },
      ],
    }));
    const paragraphs = Array.from(content.querySelectorAll('p')).map((paragraph) => paragraph.textContent);

    expect(paragraphs).toHaveLength(3);
    expect(paragraphs[0]).toBe(GERMAN['discard.modal.message.move'].replace('{title}', 'Everything a TYPO3 client site needs').replace('{table}', 'Seiteninhalt'));
    expect(paragraphs[1]).toBe('Auch die Übersetzungen (Chinese (Simplified)) bleiben an ihrer Stelle.');
    expect(paragraphs[2]).toBe('TYPO3 verwirft dabei auch diese Übersetzungen, samt ihren eigenen Änderungen: German, Hungarian.');
  });

  it('falls back to the plain text where the payload does not know the dependents', () => {
    const h = host();
    const plain = WebconEasyWorkspaceMenu.prototype.discardConfirmContent.call(h, { table: 'tt_content', workspaceUid: 7, liveUid: 7, title: 'tt_content #7', tableLabel: 'Seiteninhalt', isChanged: true });
    expect(plain.querySelectorAll('p')).toHaveLength(1);

    // A dependent of an earlier payload is a new translation.
    const earlier = WebconEasyWorkspaceMenu.prototype.discardConfirmContent.call(h, hero({
      kindKey: 'new',
      companions: [],
      dependents: [{ table: 'tt_content', workspaceUid: 9, languageUid: 1 }],
    }));
    expect(Array.from(earlier.querySelectorAll('p'), (paragraph) => paragraph.textContent).slice(1)).toEqual([
      'Neue Übersetzungen dieses Elements werden ebenfalls entfernt: German.',
    ]);
  });
});
