import { describe, expect, it } from 'vitest';
import { render } from 'lit';
import { renderFooter } from '@webconsulting/webcon-easy-workspace/templates/footer.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { DEFAULT_CONFIG } from '@webconsulting/webcon-easy-workspace/menu-constants.js';

function host(items, selected = []) {
  return {
    items,
    selection: new Set(selected),
    publishing: false,
    splitOpen: false,
    pageUid: 7,
    newsUid: 0,
    titleId: 'wew-title-test',
    state: items.length > 0 ? 'loaded' : 'empty',
    _config: { ...DEFAULT_CONFIG, enableRevert: true, enablePreviewLink: true, moduleUrl: '#module' },
  };
}

function changed(uid) {
  return { table: 'tt_content', workspaceUid: uid, liveUid: uid, title: `Element ${uid}`, kindKey: 'modified', isChanged: true, editUrl: '#', historyUrl: '#' };
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
