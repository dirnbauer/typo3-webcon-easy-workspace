import { key, toggle } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { findItemByKey } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

/**
 * Roving tabindex for the row list: exactly one row is tabbable, arrow
 * keys move focus, Space toggles selection, Enter opens the editor.
 */
export function setActiveRow(host, activeRow) {
  for (const row of host.querySelectorAll('[data-wew-row]')) {
    row.setAttribute('tabindex', row === activeRow ? '0' : '-1');
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
  const move = (target) => {
    if (!target) return;
    event.preventDefault();
    setActiveRow(host, target);
    target.focus();
  };
  switch (event.key) {
    case 'ArrowDown': move(rows[index + 1]); break;
    case 'ArrowUp': move(rows[index - 1]); break;
    case 'Home': move(rows[0]); break;
    case 'End': move(rows.at(-1)); break;
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
