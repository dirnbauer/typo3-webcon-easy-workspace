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

export function toggle(host, item, checked) {
  const next = new Set(host.selection);
  const itemKey = key(host, item);
  if (checked) {
    next.add(itemKey);
  } else {
    next.delete(itemKey);
  }
  host.selection = next;
}

export function selectAll(host, value) {
  if (!value) {
    host.selection = new Set();
    return;
  }
  host.selection = new Set(host.items.filter((i) => i.isChanged).map((i) => key(host, i)));
}
