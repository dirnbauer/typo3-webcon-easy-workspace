import { isChangeRow } from '@webconsulting/webcon-easy-workspace/menu-roles.js';

export function key(host, item) {
  return `${item.table}:${item.workspaceUid}`;
}

/**
 * The records a selected row publishes: its own version and its nested
 * records (collection items, file references). The translations TYPO3
 * copied along are not part of it; the server adds them after checking
 * they are still unchanged.
 */
export function publishRecordsForItem(host, item) {
  const records = Array.isArray(item.publishRecords) && item.publishRecords.length > 0
    ? item.publishRecords
    : (item.isChanged ? [{ table: item.table, workspaceUid: item.workspaceUid }] : []);
  const unique = new Map();
  for (const record of records) {
    const table = String(record?.table || '');
    const workspaceUid = parseInt(record?.workspaceUid || 0, 10);
    if (!table || workspaceUid <= 0) continue;
    unique.set(`${table}:${workspaceUid}`, { table, workspaceUid });
  }
  return Array.from(unique.values());
}

/**
 * The one record a row discard posts. The server discards its nested
 * records and the translations folded into it in the same request.
 */
export function discardTargetForItem(item) {
  return { table: item.table, workspaceUid: item.workspaceUid };
}

export function syncSelectionWithItems(host, contextKey) {
  const items = Array.isArray(host.items) ? host.items : [];
  const changedKeys = new Set(items.filter(isChangeRow).map((i) => key(host, i)));
  const contextChanged = host._selectionContextKey !== contextKey;

  if (contextChanged) {
    host._selectionContextKey = contextKey;
    host._selectionTouched = false;
  }

  if (!host._selectionTouched) {
    host.selection = changedKeys;
    return;
  }

  host.selection = new Set(
    Array.from(host.selection || []).filter((itemKey) => changedKeys.has(itemKey)),
  );
}

export function resetSelection(host) {
  host.selection = new Set();
  host._selectionContextKey = '';
  host._selectionTouched = false;
}

export function toggle(host, item, checked) {
  toggleMany(host, [item], checked);
}

/**
 * Select or clear several rows at once (a section's checkbox). Only edit
 * rows can be selected.
 */
export function toggleMany(host, items, checked) {
  const next = new Set(host.selection);
  for (const item of items) {
    if (!isChangeRow(item)) continue;
    const itemKey = key(host, item);
    if (checked) {
      next.add(itemKey);
    } else {
      next.delete(itemKey);
    }
  }
  host.selection = next;
  host._selectionTouched = true;
  host.requestSelectionUpdate?.();
}

export function selectAll(host, value) {
  host._selectionTouched = true;
  if (!value) {
    host.selection = new Set();
    host.requestSelectionUpdate?.();
    return;
  }
  host.selection = new Set((host.items || []).filter(isChangeRow).map((i) => key(host, i)));
  host.requestSelectionUpdate?.();
}
