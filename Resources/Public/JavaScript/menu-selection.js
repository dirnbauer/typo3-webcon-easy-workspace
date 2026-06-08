export function key(host, item) {
  return `${item.table}:${item.workspaceUid}`;
}

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

export function discardRecordsForItem(host, item) {
  const records = publishRecordsForItem(host, item);
  const ownKey = `${item.table}:${item.workspaceUid}`;
  return [
    ...records.filter((record) => `${record.table}:${record.workspaceUid}` !== ownKey),
    ...records.filter((record) => `${record.table}:${record.workspaceUid}` === ownKey),
  ];
}

export function syncSelectionWithItems(host, contextKey) {
  const items = Array.isArray(host.items) ? host.items : [];
  const changedKeys = new Set(items.filter((i) => i.isChanged).map((i) => key(host, i)));
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
  const next = new Set(host.selection);
  const itemKey = key(host, item);
  if (checked) {
    next.add(itemKey);
  } else {
    next.delete(itemKey);
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
  host.selection = new Set(host.items.filter((i) => i.isChanged).map((i) => key(host, i)));
  host.requestSelectionUpdate?.();
}
