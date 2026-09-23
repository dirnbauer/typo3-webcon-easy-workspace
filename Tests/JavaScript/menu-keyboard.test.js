import { beforeEach, describe, expect, it } from 'vitest';
import { onListFocusIn, onListKeydown, setActiveRow } from '@webconsulting/webcon-easy-workspace/menu-keyboard.js';

function row(key, actions = ['edit', 'diff', 'discard']) {
  const li = document.createElement('li');
  li.setAttribute('data-wew-row', '');
  li.setAttribute('data-wew-key', key);
  li.tabIndex = -1;
  for (const action of actions) {
    const button = document.createElement('button');
    button.setAttribute('data-wew-action', action);
    button.tabIndex = -1;
    li.append(button);
  }
  return li;
}

function press(target, key) {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
  target.dispatchEvent(event);
  return event;
}

describe('menu-keyboard', () => {
  let host;
  let rows;

  beforeEach(() => {
    document.body.innerHTML = '';
    host = document.createElement('div');
    const list = document.createElement('ul');
    rows = [row('tt_content:1'), row('tt_content:2'), row('tt_content:3', ['edit'])];
    list.append(...rows);
    host.append(list);
    document.body.append(host);
    list.addEventListener('keydown', (event) => onListKeydown(host, event));
    list.addEventListener('focusin', (event) => onListFocusIn(host, event));
  });

  const tabbable = () => Array.from(host.querySelectorAll('[tabindex="0"]'));

  it('makes exactly one row and its own action buttons tabbable', () => {
    setActiveRow(host, rows[1]);

    expect(tabbable()).toEqual([rows[1], ...rows[1].querySelectorAll('[data-wew-action]')]);
  });

  it('follows focus, so Tab from a row walks into that row\'s actions', () => {
    rows[2].focus();

    expect(tabbable()).toEqual([rows[2], rows[2].querySelector('[data-wew-action]')]);
  });

  it('moves between rows with the arrow keys, Home and End', () => {
    rows[0].focus();
    press(rows[0], 'ArrowDown');
    expect(document.activeElement).toBe(rows[1]);
    press(rows[1], 'End');
    expect(document.activeElement).toBe(rows[2]);
    press(rows[2], 'Home');
    expect(document.activeElement).toBe(rows[0]);
  });

  it('steps into and out of a row\'s actions with ArrowRight and ArrowLeft', () => {
    rows[0].focus();
    const [edit, diff] = rows[0].querySelectorAll('[data-wew-action]');

    press(rows[0], 'ArrowRight');
    expect(document.activeElement).toBe(edit);
    press(edit, 'ArrowRight');
    expect(document.activeElement).toBe(diff);
    press(diff, 'ArrowLeft');
    expect(document.activeElement).toBe(edit);
    press(edit, 'ArrowLeft');
    expect(document.activeElement).toBe(rows[0]);
  });

  it('skips disabled actions', () => {
    const [edit, diff] = rows[0].querySelectorAll('[data-wew-action]');
    diff.disabled = true;
    rows[0].focus();

    press(rows[0], 'ArrowRight');
    press(edit, 'ArrowRight');

    expect(document.activeElement.getAttribute('data-wew-action')).toBe('discard');
  });
});
