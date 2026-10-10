import { describe, expect, it } from 'vitest';
import {
  key,
  toggle,
  toggleMany,
  selectAll,
  resetSelection,
  syncSelectionWithItems,
  publishRecordsForItem,
  discardTargetForItem,
} from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { footerState } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { change, companion, copy } from './fixtures/items.js';

function host(items) {
  return { items, selection: new Set(), _selectionContextKey: '', _selectionTouched: false, requestSelectionUpdate() { this.updates = (this.updates || 0) + 1; } };
}

describe('menu-selection', () => {
  it('preselects every edit for a new context, never a copy, and drops rows that disappeared', () => {
    const h = host([change(1), change(2), copy(3), { table: 'tt_content', workspaceUid: 4, isChanged: false }]);
    syncSelectionWithItems(h, '4:page:2228');
    expect([...h.selection]).toEqual(['tt_content:1', 'tt_content:2']);

    h.items = [change(2), change(5)];
    syncSelectionWithItems(h, '4:page:2228');
    expect([...h.selection]).toEqual(['tt_content:2', 'tt_content:5']);
  });

  it('keeps an explicit selection across refreshes but resets it on context change', () => {
    const h = host([change(1), change(2)]);
    syncSelectionWithItems(h, '4:page:2228');
    toggle(h, change(1), false);
    expect([...h.selection]).toEqual(['tt_content:2']);
    expect(h.updates).toBe(1);

    h.items = [change(1), change(2), change(3)];
    syncSelectionWithItems(h, '4:page:2228');
    expect([...h.selection]).toEqual(['tt_content:2']);

    syncSelectionWithItems(h, '4:page:7');
    expect([...h.selection]).toEqual(['tt_content:1', 'tt_content:2', 'tt_content:3']);
  });

  it('selectAll covers edits only and marks the selection as touched', () => {
    const h = host([change(1), change(2), copy(3)]);
    selectAll(h, true);
    expect([...h.selection]).toEqual(['tt_content:1', 'tt_content:2']);
    expect(h._selectionTouched).toBe(true);
    selectAll(h, false);
    expect(h.selection.size).toBe(0);
    resetSelection(h);
    expect(h._selectionTouched).toBe(false);
  });

  it('toggleMany selects or clears a whole section in one step, skipping copies', () => {
    const h = host([change(1), change(2), change(3), copy(4)]);
    toggleMany(h, [change(2), change(3), copy(4)], true);
    expect([...h.selection]).toEqual(['tt_content:2', 'tt_content:3']);
    expect(h._selectionTouched).toBe(true);
    expect(h.updates).toBe(1);
    toggleMany(h, [change(2), change(3)], false);
    expect(h.selection.size).toBe(0);
  });

  it('the footer counts edits only', () => {
    const h = host([change(1, { companions: [companion(1), companion(2), companion(3)] }), copy(2)]);
    syncSelectionWithItems(h, 'ctx');
    expect(footerState(h)).toEqual({ total: 1, selectedCount: 1, allChecked: true, someChecked: false });
  });

  it('publishes the row and its nested records, never the translations TYPO3 copied along', () => {
    const hero = change(161266, {
      publishRecords: [
        { table: 'tt_content', workspaceUid: 161266 },
        { table: 'sys_file_reference', workspaceUid: 161270 },
        { table: 'sys_file_reference', workspaceUid: '161270' },
        { table: '', workspaceUid: 5 },
      ],
      companions: [companion(1), companion(2), companion(3)],
    });
    expect(publishRecordsForItem({}, hero)).toEqual([
      { table: 'tt_content', workspaceUid: 161266 },
      { table: 'sys_file_reference', workspaceUid: 161270 },
    ]);
    expect(publishRecordsForItem({}, { table: 'tt_content', workspaceUid: 3, isChanged: false })).toEqual([]);
    expect(key({}, hero)).toBe('tt_content:161266');
  });

  it('discards the row\'s own record only: the server takes its nested records and translations along', () => {
    const hero = change(161266, { companions: [companion(1)], publishRecords: [{ table: 'sys_file_reference', workspaceUid: 161270 }] });
    expect(discardTargetForItem(hero)).toEqual({ table: 'tt_content', workspaceUid: 161266 });
  });
});
