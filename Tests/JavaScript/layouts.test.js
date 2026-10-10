import { describe, expect, it } from 'vitest';
import { html, render } from 'lit';
import { LAYOUTS, DEFAULT_LAYOUT, layoutFor, isLayoutName } from '@webconsulting/webcon-easy-workspace/layouts/index.js';
import { DEFAULT_FLAGS, layoutFlag, layoutName } from '@webconsulting/webcon-easy-workspace/menu-layout.js';
import { formatIcu } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { renderGroup } from '@webconsulting/webcon-easy-workspace/templates/group.js';
import { renderFooter } from '@webconsulting/webcon-easy-workspace/templates/footer.js';
import { groupRows } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { WebconEasyWorkspaceMenu } from '@webconsulting/webcon-easy-workspace/components/wew-toolbar-menu.js';
import { change, companion, copy, host } from './fixtures/items.js';
import { javaScriptLabelKeys, xliffLabels } from './fixtures/labels.js';

function mount(template) {
  const container = document.createElement('div');
  render(template, container);
  return container;
}

describe('layout registry', () => {
  it('knows clarity, efficiency and core, and falls back to clarity', () => {
    expect(Object.keys(LAYOUTS)).toEqual(['clarity', 'efficiency', 'core']);
    expect(DEFAULT_LAYOUT).toBe('clarity');
    for (const name of Object.keys(LAYOUTS)) {
      expect(layoutFor({ _config: { layout: name } }).name).toBe(name);
      expect(isLayoutName(name)).toBe(true);
    }
    expect(layoutFor({ _config: { layout: 'Core ' } }).name).toBe('core');
    expect(layoutFor({ _config: { layout: 'fancy' } }).name).toBe('clarity');
    expect(layoutFor({ _config: {} }).name).toBe('clarity');
    expect(layoutFor(null).name).toBe('clarity');
    expect(isLayoutName('toString')).toBe(false);
  });

  it('gives every profile the shared flags', () => {
    for (const profile of Object.values(LAYOUTS)) {
      for (const flag of Object.keys(DEFAULT_FLAGS)) {
        expect(layoutFlag({ layoutProfile: profile }, flag)).toBeDefined();
      }
    }
    expect(layoutFlag({}, 'selectAllMin')).toBe(2);
    expect(layoutName({})).toBe('clarity');
  });

  it('marks the dialog with the editor\'s layout', () => {
    const element = new WebconEasyWorkspaceMenu();
    element.setAttribute('config', JSON.stringify({ layout: 'efficiency' }));
    element._config = element._readConfig();
    element.layoutProfile = layoutFor(element);
    element.state = 'empty';
    const container = document.createElement('div');
    render(element.render(), container);
    expect(container.querySelector('.wew-menu').getAttribute('data-wew-layout')).toBe('efficiency');
    expect(container.querySelector('.wew-menu').getAttribute('aria-describedby')).toBe(element.summaryId);
  });
});

describe('layout hooks', () => {
  it('lets a profile rebuild a row from the shared parts and add classes', () => {
    const profile = {
      name: 'test',
      flags: {},
      rowClasses: () => ['wew-row--test'],
      renderRow: (h, item, ctx) => ctx.shell(html`${ctx.parts.check(h, item, ctx.row)}${ctx.parts.title(h, item, ctx.row)}<em>${ctx.row.type}</em>`),
    };
    const h = host([change(1, { kindKey: 'new' })], { layoutProfile: profile });
    const row = mount(renderRow(h, h.items[0])).querySelector('[data-wew-row]');
    expect(row.className).toContain('wew-row--test');
    expect(row.getAttribute('data-wew-key')).toBe('tt_content:1');
    expect(row.querySelector('[data-wew-row-check][data-wew-stop]')).not.toBeNull();
    expect(row.querySelector('em').textContent).toBe('new');
    expect(row.querySelector('[data-wew-action]')).toBeNull();
  });

  it('hands every renderer hook the default output to wrap', () => {
    const wrap = (name) => (...args) => html`<div data-hook=${name}>${args.at(-1).render()}</div>`;
    const profile = {
      name: 'test',
      flags: { selectAllMin: 1, cleanupOpen: 'always' },
      renderCompanions: wrap('companions'),
      renderGroupHead: wrap('head'),
      renderGroupExtras: () => html`<p data-hook="extras"></p>`,
      renderSection: wrap('section'),
      renderCleanup: wrap('cleanup'),
      renderCopy: wrap('copy'),
      footerExtras: (h, state) => html`<p data-hook="footer">${state.total}</p>`,
    };
    const h = host([change(1, { companions: [companion(1)] }), change(2, { languageUid: 1 }), copy(3)], { layoutProfile: profile });
    const group = mount(renderGroup(h, groupRows(h)[0]));
    for (const name of ['companions', 'head', 'section', 'cleanup', 'copy']) {
      expect(group.querySelector(`[data-hook="${name}"]`)?.children.length, name).toBeGreaterThan(0);
    }
    expect(group.querySelector('[data-hook="extras"]')).not.toBeNull();
    const footer = mount(renderFooter(h));
    expect(footer.querySelector('[data-hook="footer"]').textContent).toBe('2');
  });

  it('applies the flags: select-all from one edit, cleanup always open', () => {
    const profile = { name: 'test', flags: { selectAllMin: 1, cleanupOpen: 'always', otherLanguagesOpenUpTo: 5 } };
    const h = host([change(1), change(2, { languageUid: 1 }), copy(3)], { layoutProfile: profile });
    expect(mount(renderFooter(host([change(1)], { layoutProfile: profile }))).querySelector('[data-wew-select-all]')).not.toBeNull();
    const group = mount(renderGroup(h, groupRows(h)[0]));
    expect(group.querySelector('[data-wew-cleanup] [data-wew-section-toggle]').getAttribute('aria-expanded')).toBe('true');
    expect(group.querySelector('[data-wew-section="other-languages"] [data-wew-section-toggle]').getAttribute('aria-expanded')).toBe('true');
  });

  it('runs a profile\'s shortcuts before Escape and list navigation', () => {
    const seen = [];
    const element = new WebconEasyWorkspaceMenu();
    element.layoutProfile = {
      name: 'test',
      flags: {},
      onKeydown: (h, event, ctx) => { seen.push([event.key, ctx.inList]); return event.key === 'Delete'; },
    };
    element._closeDropdown = () => seen.push(['closed']);
    const del = new KeyboardEvent('keydown', { key: 'Delete', cancelable: true });
    element.handleDialogKeydown(del);
    expect(del.defaultPrevented).toBe(true);
    element.handleDialogKeydown(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(seen).toEqual([['Delete', false], ['Escape', false], ['closed']]);
  });

  it('asks a profile before discarding copies', async () => {
    const asked = [];
    const element = new WebconEasyWorkspaceMenu();
    element.layoutProfile = { name: 'test', flags: {}, beforeDiscardCopies: (h, group, copies) => { asked.push(copies.length); return false; } };
    const group = { key: 'context', copies: [{ records: [{ table: 'tt_content', workspaceUid: 3 }] }] };
    await element.handleDiscardCopies(group);
    expect(asked).toEqual([1]);
    expect(element.cleaning).toBe(false);
  });
});

describe('labels', () => {
  const keys = javaScriptLabelKeys();

  it('ships every toolbar label in English and German, removed ones gone', () => {
    const en = xliffLabels('en');
    const de = xliffLabels('de');
    expect(keys.filter((key) => !(key in en) || !(key in de))).toEqual([]);
    for (const removed of ['toolbar.summary.unchanged', 'toolbar.section.unchanged', 'toolbar.section.unchangedHint',
      'toolbar.row.unchanged', 'toolbar.row.unchangedTitle', 'toolbar.languages.changedCount',
      'toolbar.shortcut.hint', 'toolbar.shortcut.edit', 'toolbar.shortcut.select', 'toolbar.kbd.space', 'toolbar.kbd.spaceTitle',
      'toolbar.stage.title']) {
      expect(keys).not.toContain(removed);
      expect(en[removed]).toBeUndefined();
      expect(de[removed]).toBeUndefined();
    }
    for (const id of ['userSettings.layout', 'userSettings.layout.default', 'userSettings.layout.clarity', 'userSettings.layout.efficiency', 'userSettings.layout.core']) {
      expect(de[id]).toBeTruthy();
    }
  });

  it('keeps plurals within what the toolbar formatter understands', () => {
    for (const language of ['en', 'de']) {
      const labels = xliffLabels(language);
      for (const key of keys) {
        const message = labels[key] || '';
        if (!message.includes('plural')) continue;
        const formatted = formatIcu(message, { count: 2, selected: 1, total: 2, language: 'X', languages: 'X', fields: 'X', code: 'X', name: 'X', title: 'X', section: 'X', stage: 'X', shortcut: 'X' });
        expect(formatted, `${language}:${key}`).not.toMatch(/plural|\{\w+,|[{}]\s*[{}]/);
      }
    }
  });
});

describe('shared extension points the layouts asked for', () => {
  it('names the cleanup records with the layout\'s own word in the summary', async () => {
    const { summaryText } = await import('@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js');
    const items = [change(1), copy(2, { companions: [companion(1), companion(2)] })];
    expect(summaryText(host(items, { layoutProfile: LAYOUTS.clarity }))).toContain('3 unveränderte Kopien');
    expect(summaryText(host(items, { layoutProfile: LAYOUTS.core }))).toContain('3 Entwürfe ohne Änderung');
  });

  it('keeps every number of the header summary on the line of its noun', async () => {
    const { bindNumbers, renderHeader } = await import('@webconsulting/webcon-easy-workspace/templates/header.js');
    expect(bindNumbers('8 Änderungen auf dieser Seite · 4 unveränderte Kopien')).toBe('8 Änderungen auf dieser Seite · 4 unveränderte Kopien');
    const h = host([change(1), copy(2)], { layoutProfile: LAYOUTS.efficiency });
    const summary = mount(renderHeader(h)).querySelector('[data-wew-count-chip]');
    expect(summary.textContent).toBe('1 Änderung auf dieser Seite · 1 unveränderte Kopie');
  });

  it('names a layout\'s publish shortcut on the button, and only there', () => {
    const items = [change(1), change(2)];
    const efficient = mount(renderFooter(host(items, { layoutProfile: LAYOUTS.efficiency }))).querySelector('[data-wew-publish]');
    expect(efficient.getAttribute('aria-keyshortcuts')).toBe('Control+Enter Meta+Enter');
    expect(efficient.getAttribute('title')).toMatch(/\((Strg|Ctrl|⌘)\+Enter\)$/);
    for (const name of ['clarity', 'core']) {
      const button = mount(renderFooter(host(items, { layoutProfile: LAYOUTS[name] }))).querySelector('[data-wew-publish]');
      expect(button.hasAttribute('aria-keyshortcuts')).toBe(false);
      expect(button.hasAttribute('title')).toBe(false);
    }
  });

  it('shows a row again that survived a refresh after its exit animation', () => {
    const element = new WebconEasyWorkspaceMenu();
    const row = document.createElement('li');
    row.className = 'wew-row wew-row--leaving';
    element.append(row);
    element._clearLeavingRows();
    expect(row.className).toBe('wew-row');
  });
});
