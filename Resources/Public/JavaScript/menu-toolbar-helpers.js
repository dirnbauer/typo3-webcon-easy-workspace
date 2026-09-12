import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { key } from '@webconsulting/webcon-easy-workspace/menu-selection.js';

/**
 * Number of changed rows in the current (context-scoped) list. Used for
 * the header label only — the toolbar badge is fed by the server.
 */
export function changedItemCount(items) {
  return Array.isArray(items) ? items.filter((item) => item.isChanged).length : 0;
}

export function footerState(host) {
  const changeable = (host.items || []).filter((item) => item.isChanged);
  const total = changeable.length;
  const selectedCount = host.selection?.size ?? 0;
  const allChecked = total > 0 && selectedCount === total;
  const someChecked = selectedCount > 0 && selectedCount < total;
  return { total, selectedCount, allChecked, someChecked };
}

/**
 * Normalised change type of a row: new | changed | deleted | moved.
 */
export function changeType(item) {
  switch (String(item?.kindKey || '').toLowerCase()) {
    case 'new':
    case 'created':
      return 'new';
    case 'delete':
    case 'deleted':
    case 'removed':
      return 'deleted';
    case 'move':
    case 'moved':
      return 'moved';
    default:
      return 'changed';
  }
}

/**
 * Coarse relative time ("just now", "5 min ago", "3 h ago", "2 days ago").
 *
 * @param {number} timestampSeconds Unix timestamp in seconds
 * @param {number} nowMs Reference time in milliseconds
 */
export function relativeTime(host, timestampSeconds, nowMs = Date.now()) {
  const timestamp = Number(timestampSeconds) || 0;
  if (timestamp <= 0) return '';
  const diff = Math.max(0, Math.round(nowMs / 1000 - timestamp));
  if (diff < 60) return label(host, 'time.now');
  if (diff < 3600) return label(host, 'time.minutes', { count: Math.round(diff / 60) });
  if (diff < 86400) return label(host, 'time.hours', { count: Math.round(diff / 3600) });
  return label(host, 'time.days', { count: Math.round(diff / 86400) });
}

/**
 * Group the changed rows for rendering: one group for the page or news
 * record the list is scoped to, plus one for workspace-wide file
 * metadata rows that have no page.
 *
 * @returns {Array<{key: string, icon: string, title: string, path: string, rows: object[]}>}
 */
export function groupRows(host) {
  const rows = (host.changedItemGroups || []).flatMap((group) => (Array.isArray(group.items) ? group.items : []));
  const standalone = rows.filter((row) => row.table === 'sys_file_metadata');
  const contextRows = rows.filter((row) => row.table !== 'sys_file_metadata');
  const groups = [];
  if (contextRows.length > 0) {
    const record = host.contextRecord || {};
    const isNews = host.newsUid > 0;
    groups.push({
      key: 'context',
      icon: record.iconIdentifier || (isNews ? 'content-news' : 'apps-pagetree-page-default'),
      title: record.title || label(host, isNews ? 'context.news' : 'context.page', { uid: isNews ? host.newsUid : host.pageUid }),
      path: record.path || '',
      rows: contextRows,
    });
  }
  if (standalone.length > 0) {
    groups.push({
      key: 'files',
      icon: standalone[0].iconIdentifier || 'mimetypes-other-other',
      title: label(host, 'toolbar.group.files'),
      path: '',
      rows: standalone,
    });
  }
  return groups;
}

export function diffTitle(host, item) {
  if (item.kindKey === 'new' && item.historyDiffCount > 0) {
    return label(host, 'diff.title.newWithChanges');
  }
  if (item.kindKey === 'new') {
    return label(host, 'diff.title.newDetails');
  }
  if (item.kindKey === 'delete') {
    return label(host, 'diff.title.removal');
  }
  if (item.kindKey === 'move') {
    return label(host, 'diff.title.move');
  }
  if (item.kindKey === 'modified') {
    return label(host, 'diff.title.changed');
  }
  return label(host, 'diff.title.history');
}

export function canRevert(host, item) {
  return configBool(host, 'enableRevert') && item.isChanged;
}

export function editUrl(item) {
  return item.contextualEditUrl || item.editUrl || '';
}

export function findItem(host, table, workspaceUid) {
  return (host.items || []).find(
    (entry) => entry.table === table && entry.workspaceUid === workspaceUid,
  ) || null;
}

export function findItemByKey(host, itemKey) {
  return (host.items || []).find((item) => key(host, item) === itemKey) || null;
}
