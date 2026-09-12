import { describe, expect, it } from 'vitest';
import {
  key,
  toggle,
  selectAll,
  resetSelection,
  syncSelectionWithItems,
  publishRecordsForItem,
  discardRecordsForItem,
} from '@webconsulting/webcon-easy-workspace/menu-selection.js';

function item(uid, extra = {}) {
  return { table: 'tt_content', workspaceUid: uid, liveUid: uid - 100, isChanged: true, ...extra };
}

function host(items) {
  return { items, selection: new Set(), _selectionContextKey: '', _selectionTouched: false, requestSelectionUpdate() { this.updates = (this.updates || 0) + 1; } };
}

describe('menu-selection', () => {
  it('preselects every changed row for a new context and drops rows that disappeared', () => {
    const h = host([item(1), item(2), item(3, { isChanged: false })]);
    syncSelectionWithItems(h, '1:page:7');
    expect([...h.selection]).toEqual(['tt_content:1', 'tt_content:2']);

    h.items = [item(2), item(4)];
    syncSelectionWithItems(h, '1:page:7');
    expect([...h.selection]).toEqual(['tt_content:2', 'tt_content:4']);
  });

  it('keeps an explicit selection across refreshes but resets it on context change', () => {
    const h = host([item(1), item(2)]);
    syncSelectionWithItems(h, '1:page:7');
    toggle(h, item(1), false);
    expect([...h.selection]).toEqual(['tt_content:2']);
    expect(h.updates).toBe(1);

    h.items = [item(1), item(2), item(3)];
    syncSelectionWithItems(h, '1:page:7');
    expect([...h.selection]).toEqual(['tt_content:2']);

    syncSelectionWithItems(h, '1:page:8');
    expect([...h.selection]).toEqual(['tt_content:1', 'tt_content:2', 'tt_content:3']);
  });

  it('selectAll toggles every changed row and marks the selection as touched', () => {
    const h = host([item(1), item(2), item(3, { isChanged: false })]);
    selectAll(h, true);
    expect(h.selection.size).toBe(2);
    expect(h._selectionTouched).toBe(true);
    selectAll(h, false);
    expect(h.selection.size).toBe(0);
    resetSelection(h);
    expect(h._selectionTouched).toBe(false);
  });

  it('collects unique publish records and discards the record itself last', () => {
    const parent = item(9, {
      publishRecords: [
        { table: 'tt_content', workspaceUid: 9 },
        { table: 'sys_file_reference', workspaceUid: 40 },
        { table: 'sys_file_reference', workspaceUid: '40' },
        { table: '', workspaceUid: 5 },
      ],
    });
    expect(publishRecordsForItem({}, parent)).toEqual([
      { table: 'tt_content', workspaceUid: 9 },
      { table: 'sys_file_reference', workspaceUid: 40 },
    ]);
    expect(discardRecordsForItem({}, parent)).toEqual([
      { table: 'sys_file_reference', workspaceUid: 40 },
      { table: 'tt_content', workspaceUid: 9 },
    ]);
    expect(publishRecordsForItem({}, item(3, { isChanged: false }))).toEqual([]);
    expect(key({}, item(3))).toBe('tt_content:3');
  });
});
