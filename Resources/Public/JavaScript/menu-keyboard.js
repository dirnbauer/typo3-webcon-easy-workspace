import { key, toggle } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { findItemByKey } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

const ACTION_SELECTOR = '[data-wew-action]:not(:disabled)';

/**
 * Roving tabindex for the row list: exactly one row is tabbable, and so are
 * the action buttons of that row - Tab walks from the row into its edit,
 * changes, discard and preview buttons, then on to the footer. Arrow keys
 * move between rows (ArrowRight/ArrowLeft step into and out of a row's
 * actions), Space toggles the selection, Enter opens the editor.
 */
export function setActiveRow(host, activeRow) {
  for (const row of host.querySelectorAll('[data-wew-row]')) {
    const active = row === activeRow;
    row.setAttribute('tabindex', active ? '0' : '-1');
    for (const button of row.querySelectorAll('[data-wew-action]')) {
      button.setAttribute('tabindex', active ? '0' : '-1');
    }
  }
}

export function onListFocusIn(host, event) {
  const row = event.target.closest?.('[data-wew-row]');
  if (row) setActiveRow(host, row);
}

export function onListKeydown(host, event) {
  const row = event.target.closest?.('[data-wew-row]');
  if (!row) return;
  const rows = Array.from(host.querySelectorAll('[data-wew-row]'));
  const index = rows.indexOf(row);
  const actions = Array.from(row.querySelectorAll(ACTION_SELECTOR));
  const actionIndex = actions.indexOf(event.target);
  const move = (target) => {
    if (!target) return;
    event.preventDefault();
    setActiveRow(host, target);
    target.focus();
  };
  const focusAction = (target) => {
    if (!target) return;
    event.preventDefault();
    target.focus();
  };
  switch (event.key) {
    case 'ArrowDown': move(rows[index + 1]); break;
    case 'ArrowUp': move(rows[index - 1]); break;
    case 'Home': move(rows[0]); break;
    case 'End': move(rows.at(-1)); break;
    case 'ArrowRight': focusAction(actionIndex < 0 ? actions[0] : actions[actionIndex + 1]); break;
    case 'ArrowLeft': {
      if (actionIndex < 0) return;
      if (actionIndex === 0) {
        move(row);
      } else {
        focusAction(actions[actionIndex - 1]);
      }
      break;
    }
    case ' ': {
      if (event.target !== row) return;
      event.preventDefault();
      const item = findItemByKey(host, row.getAttribute('data-wew-key') || '');
      if (item?.isChanged) toggle(host, item, !host.selection.has(key(host, item)));
      break;
    }
    case 'Enter': {
      if (event.target !== row) return;
      const item = findItemByKey(host, row.getAttribute('data-wew-key') || '');
      if (item) host.handleRowAction(event, 'edit', item);
      break;
    }
    default: break;
  }
}
