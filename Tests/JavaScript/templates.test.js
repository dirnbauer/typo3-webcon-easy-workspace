import { describe, expect, it } from 'vitest';
import { render } from 'lit';
import { renderFooter } from '@webconsulting/webcon-easy-workspace/templates/footer.js';
import { renderHeader, summaryText } from '@webconsulting/webcon-easy-workspace/templates/header.js';
import { renderGroup } from '@webconsulting/webcon-easy-workspace/templates/group.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { groupRows } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { DEFAULT_CONFIG } from '@webconsulting/webcon-easy-workspace/menu-constants.js';

const labels = {
  'toolbar.title': 'Workspace',
  'toolbar.count.none': 'Nothing pending',
  'toolbar.count.workspace': '{count, plural, one {# pending in this workspace} other {# pending in this workspace}}',
  'toolbar.summary.page': '{count, plural, one {# change on this page} other {# changes on this page}}',
  'toolbar.summary.elsewhere': '{count, plural, one {# more elsewhere} other {# more elsewhere}}',
  'toolbar.stage.label': 'Stage: {stage}',
  'toolbar.selection.count': '{selected} of {total} selected',
  'toolbar.languages.inView': '{language} · shown in this view',
  'toolbar.languages.other': 'Other languages',
  'toolbar.languages.otherHint': 'Not visible while the page is shown in {language}.',
  'toolbar.languages.count': '{count, plural, one {# change} other {# changes}}',
  'toolbar.row.language': 'Language: {language}',
  'toolbar.row.partOf': 'in {title}',
  'toolbar.row.notInView': 'Only visible on the page in the {language} view',
  'change.changed': 'Changed',
  'change.new': 'New',
};
const languages = {
  0: { uid: 0, title: 'English', flag: 'flags-us' },
  1: { uid: 1, title: 'German', flag: 'flags-de' },
};

function host(items, selected = [], overrides = {}) {
  return {
    items,
    languages,
    viewLanguages: null,
    selection: new Set(selected),
    publishing: false,
    splitOpen: false,
    pageUid: 7,
    newsUid: 0,
    titleId: 'wew-title-test',
    state: items.length > 0 ? 'loaded' : 'empty',
    badgeCount: 0,
    workspaceTitle: 'Staging',
    stage: null,
    contextRecord: { title: 'Start', path: '', iconIdentifier: 'apps-pagetree-page-default' },
    _config: { ...DEFAULT_CONFIG, enableRevert: true, enablePreviewLink: true, moduleUrl: '#module', labels },
    _highlightInIframe() {},
    _clearIframeHighlight() {},
    _previewDiscard() {},
    ...overrides,
  };
}

function changed(uid, extra = {}) {
  return { table: 'tt_content', workspaceUid: uid, liveUid: uid, title: `Element ${uid}`, kindKey: 'modified', isChanged: true, editUrl: '#', historyUrl: '#', languageUid: 0, ...extra };
}

function mount(template) {
  const container = document.createElement('div');
  render(template, container);
  return container;
}

describe('footer template', () => {
  it('draws the select-all checkbox as a Core .form-check with a label', () => {
    const footer = mount(renderFooter(host([changed(1), changed(2)], ['tt_content:1'])));
    const input = footer.querySelector('[data-wew-select-all]');

    expect(input.closest('.form-check')).not.toBeNull();
    expect(footer.querySelector(`label.form-check-label[for="${input.id}"]`)).not.toBeNull();
    expect(input.indeterminate).toBe(true);
    expect(footer.querySelector('[data-wew-selection-count]').textContent.trim()).toBe('1 of 2 selected');
    expect(footer.querySelector('[data-wew-publish]').disabled).toBe(false);
  });

  it('leaves the selection controls out when there is nothing to select', () => {
    const footer = mount(renderFooter(host([])));

    expect(footer.querySelector('[data-wew-select-all]')).toBeNull();
    expect(footer.querySelector('[data-wew-publish]')).toBeNull();
    expect(footer.querySelector('[data-wew-open-module]')).not.toBeNull();
  });
});

describe('row template', () => {
  it('wraps the row checkbox in .form-check, which defines Core\'s checkbox tokens', () => {
    const h = host([changed(1)], ['tt_content:1']);
    const list = mount(renderRow(h, h.items[0], 0));
    const checkbox = list.querySelector('[data-wew-row-check]');

    expect(checkbox.closest('.form-check')).not.toBeNull();
    expect(checkbox.checked).toBe(true);
  });

  it('keeps the action buttons out of the tab order until their row is active', () => {
    const h = host([changed(1)]);
    const list = mount(renderRow(h, h.items[0], 0));
    const actions = list.querySelectorAll('[data-wew-action]');

    expect(actions.length).toBeGreaterThan(0);
    for (const action of actions) {
      expect(action.getAttribute('tabindex')).toBe('-1');
      expect(action.getAttribute('aria-label')).not.toBe('');
    }
  });
});

describe('row template: languages', () => {
  it('marks a row of a language the module does not show and offers no preview for it', () => {
    const h = host([changed(1, { languageUid: 1 })], [], { viewLanguages: [0] });
    const list = mount(renderRow(h, h.items[0], 0));
    const row = list.querySelector('[data-wew-row]');

    expect(row.getAttribute('data-wew-in-view')).toBe('0');
    expect(row.classList.contains('wew-row--other-language')).toBe(true);
    expect(row.getAttribute('title')).toBe('Only visible on the page in the German view');
    // Its language header names the language; the row itself carries no chip.
    expect(list.querySelector('[data-wew-row-language]')).toBeNull();
    expect(list.querySelector('[data-wew-action="preview"]')).toBeNull();
    // Still selectable and publishable.
    expect(list.querySelector('[data-wew-row-check]')).not.toBeNull();
  });

  it('shows the language chip only where it tells the editor something', () => {
    const inView = host([changed(1, { languageUid: 1 })], [], { viewLanguages: [1] });
    expect(mount(renderRow(inView, inView.items[0], 0)).querySelector('[data-wew-row-language]')).toBeNull();

    const noView = host([changed(1, { languageUid: 1 }), changed(2, { languageUid: 0 })]);
    expect(mount(renderRow(noView, noView.items[0], 0)).querySelector('[data-wew-row-language]')).not.toBeNull();
    expect(mount(renderRow(noView, noView.items[1], 0)).querySelector('[data-wew-row-language]')).toBeNull();
  });

  it('uses Core badges for the change type and names the element a collection item belongs to', () => {
    const h = host([changed(1, { kindKey: 'new', table: 'tx_easyws_item', parent: { table: 'tt_content', uid: 5, title: 'Three steps' } })]);
    const list = mount(renderRow(h, h.items[0], 0));

    expect(list.querySelector('[data-wew-change-badge]').className).toContain('badge-success');
    expect(list.querySelector('.wew-row__meta').textContent).toContain('in Three steps');
    for (const action of list.querySelectorAll('[data-wew-action]')) {
      expect(action.className).toContain('btn-borderless');
    }
  });
});

describe('group template', () => {
  it('renders the current view first and the other languages under their own headers', () => {
    const h = host([changed(1, { languageUid: 0 }), changed(2, { languageUid: 1 }), changed(3, { languageUid: 0 })], [], { viewLanguages: [0] });
    const list = mount(renderGroup(h, groupRows(h)[0], 0));

    const sections = Array.from(list.querySelectorAll('[data-wew-section]')).map((section) => section.getAttribute('data-wew-section'));
    expect(sections).toEqual(['in-view', 'other-languages']);
    expect(list.querySelector('[data-wew-section="other-languages"]').textContent).toContain('Not visible while the page is shown in English.');
    expect(list.querySelector('[data-wew-language="1"]').textContent).toContain('German');
    expect(Array.from(list.querySelectorAll('[data-wew-row]')).map((row) => row.getAttribute('data-wew-key'))).toEqual([
      'tt_content:1', 'tt_content:3', 'tt_content:2',
    ]);
    // Only the first row is tabbable; the others join the roving tabindex.
    expect(Array.from(list.querySelectorAll('[data-wew-row]')).map((row) => row.getAttribute('tabindex'))).toEqual(['0', '-1', '-1']);
  });

  it('needs no section headers when every row is in view', () => {
    const h = host([changed(1), changed(2)], [], { viewLanguages: [0] });
    const list = mount(renderGroup(h, groupRows(h)[0], 0));
    expect(list.querySelector('[data-wew-section]')).toBeNull();
  });
});

describe('header template', () => {
  it('names the workspace and sums up the numbers in one sentence', () => {
    const h = host([changed(1), changed(2)], [], { badgeCount: 5, stage: { id: 0, label: 'Editing' } });
    const header = mount(renderHeader(h));

    expect(header.querySelector('.wew-menu__title').textContent.trim()).toBe('Staging');
    expect(header.querySelector('[data-wew-count-chip]').textContent.trim()).toBe('2 changes on this page · 3 more elsewhere');
    expect(header.querySelector('[data-wew-stage]').textContent.trim()).toBe('Stage: Editing');
    expect(summaryText(host([]), 0, 0)).toBe('Nothing pending');
    expect(summaryText(host([], [], { state: 'no-context' }), 0, 4)).toBe('4 pending in this workspace');
  });
});
