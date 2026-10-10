import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  onListFocusIn,
  onListKeydown,
  setActiveStop,
  stopsOf,
  syncRovingTabindex,
} from '@webconsulting/webcon-easy-workspace/menu-keyboard.js';

function element(tag, attributes = {}, children = []) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value);
  node.append(...children);
  return node;
}

function row(key, { actions = ['edit', 'diff', 'discard'], chips = [] } = {}) {
  return element('li', { 'data-wew-row': '', 'data-wew-key': key }, [
    element('input', { type: 'checkbox', 'data-wew-row-check': '', 'data-wew-stop': '', tabindex: '-1' }),
    ...actions.map((action) => element('button', { 'data-wew-action': action, tabindex: '-1' })),
    ...chips.map((code) => element('button', { 'data-wew-companion': `tt_content:${code}`, tabindex: '-1' })),
  ]);
}

function section(id, expanded, rows = []) {
  return element('div', { 'data-wew-section': 'other-languages' }, [
    element('div', { 'data-wew-section-head': '' }, [
      element('input', { type: 'checkbox', 'data-wew-section-check': '', tabindex: '-1' }),
      element('button', { 'data-wew-section-toggle': id, 'data-wew-stop': '', 'aria-expanded': expanded ? 'true' : 'false', tabindex: '-1' }),
    ]),
    ...(expanded ? [element('div', { 'data-wew-section-body': '' }, [element('ul', {}, rows)])] : []),
  ]);
}

function copyLine(key, chips) {
  return element('li', { 'data-wew-copy-group': key }, chips.map((code, index) => element('button', {
    'data-wew-companion': `tt_content:${code}`, tabindex: '-1', ...(index === 0 ? { 'data-wew-stop': '' } : {}),
  })));
}

function press(target, key, init = {}) {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init });
  target.dispatchEvent(event);
  return event;
}

describe('menu-keyboard', () => {
  let host;
  let rows;

  beforeEach(() => {
    document.body.innerHTML = '';
    host = document.createElement('div');
    host.items = [{ table: 'tt_content', workspaceUid: 1 }, { table: 'tt_content', workspaceUid: 2 }, { table: 'tt_content', workspaceUid: 3 }];
    host.toggleSection = vi.fn();
    host.handleRowAction = vi.fn();
    const list = element('div', { class: 'wew-list' });
    rows = [row('tt_content:1', { chips: [161267, 161268] }), row('tt_content:2'), row('tt_content:3', { actions: ['edit'] })];
    list.append(
      element('ul', {}, [rows[0], rows[1]]),
      section('context:other', true, [rows[2]]),
      section('context:copies', false),
      element('ul', {}, [copyLine('tt_content:158035', [161380, 161381])]),
    );
    host.append(list);
    document.body.append(host);
    list.addEventListener('keydown', (event) => onListKeydown(host, event));
    list.addEventListener('focusin', (event) => onListFocusIn(host, event));
  });

  const tabbable = () => Array.from(host.querySelectorAll('[tabindex="0"]'));
  const check = (index) => rows[index].querySelector('[data-wew-row-check]');
  const toggle = (id) => host.querySelector(`[data-wew-section-toggle="${id}"]`);

  it('has checkboxes, section toggles and the first copy chip as stops', () => {
    expect(stopsOf(host).map((stop) => stop.getAttribute('data-wew-section-toggle') || stop.closest('[data-wew-key], [data-wew-copy-group]').getAttribute('data-wew-key') || 'copy')).toEqual([
      'tt_content:1', 'tt_content:2', 'context:other', 'tt_content:3', 'context:copies', 'copy',
    ]);
  });

  it('makes exactly one stop tabbable, with its own actions and translation chips', () => {
    syncRovingTabindex(host);
    expect(tabbable()).toEqual([check(0), ...rows[0].querySelectorAll('[data-wew-action], [data-wew-companion]')]);

    setActiveStop(host, toggle('context:other'));
    // A section toggle brings its checkbox along into the Tab sequence.
    expect(tabbable()).toEqual([host.querySelector('[data-wew-section-check]'), toggle('context:other')]);
    expect(tabbable().filter((node) => node.hasAttribute('data-wew-stop'))).toHaveLength(1);
  });

  it('follows focus, so Tab from a stop walks into what belongs to it', () => {
    rows[2].querySelector('[data-wew-action]').focus();
    expect(tabbable()).toEqual([check(2), rows[2].querySelector('[data-wew-action]')]);
  });

  it('moves between stops with the arrow keys, Home and End', () => {
    check(0).focus();
    press(check(0), 'ArrowDown');
    expect(document.activeElement).toBe(check(1));
    press(check(1), 'ArrowDown');
    expect(document.activeElement).toBe(toggle('context:other'));
    press(document.activeElement, 'ArrowUp');
    expect(document.activeElement).toBe(check(1));
    press(check(1), 'End');
    expect(document.activeElement.closest('[data-wew-copy-group]')).not.toBeNull();
    press(document.activeElement, 'Home');
    expect(document.activeElement).toBe(check(0));
  });

  it('steps into the actions and translation chips with ArrowRight and back with ArrowLeft', () => {
    check(0).focus();
    const [edit, diff, discard, chip] = rows[0].querySelectorAll('[data-wew-action], [data-wew-companion]');
    press(check(0), 'ArrowRight');
    expect(document.activeElement).toBe(edit);
    press(edit, 'ArrowRight');
    press(diff, 'ArrowRight');
    press(discard, 'ArrowRight');
    expect(document.activeElement).toBe(chip);
    press(chip, 'ArrowLeft');
    expect(document.activeElement).toBe(discard);
    press(discard, 'ArrowLeft');
    press(diff, 'ArrowLeft');
    press(edit, 'ArrowLeft');
    expect(document.activeElement).toBe(check(0));
  });

  it('skips disabled actions', () => {
    const [edit, diff] = rows[0].querySelectorAll('[data-wew-action]');
    diff.disabled = true;
    check(0).focus();
    press(check(0), 'ArrowRight');
    press(edit, 'ArrowRight');
    expect(document.activeElement.getAttribute('data-wew-action')).toBe('discard');
  });

  it('opens a section with ArrowRight, closes it with ArrowLeft, and jumps from its rows to its toggle', () => {
    toggle('context:copies').focus();
    press(toggle('context:copies'), 'ArrowRight');
    expect(host.toggleSection).toHaveBeenCalledWith('context:copies');
    press(toggle('context:copies'), 'ArrowLeft');
    // Already closed: nothing to do.
    expect(host.toggleSection).toHaveBeenCalledTimes(1);

    toggle('context:other').focus();
    press(toggle('context:other'), 'ArrowRight');
    expect(host.toggleSection).toHaveBeenCalledTimes(1);
    press(toggle('context:other'), 'ArrowLeft');
    expect(host.toggleSection).toHaveBeenLastCalledWith('context:other');

    check(2).focus();
    press(check(2), 'ArrowLeft');
    expect(document.activeElement).toBe(toggle('context:other'));
  });

  it('opens the editor with Enter on a row checkbox and leaves Space to the checkbox', () => {
    check(1).focus();
    press(check(1), 'Enter');
    expect(host.handleRowAction).toHaveBeenCalledWith(expect.any(KeyboardEvent), 'edit', host.items[1]);
    const space = press(check(1), ' ');
    expect(space.defaultPrevented).toBe(false);
    // Modified keys belong to the layout's shortcuts.
    press(check(1), 'Enter', { ctrlKey: true });
    expect(host.handleRowAction).toHaveBeenCalledTimes(1);
  });
});
