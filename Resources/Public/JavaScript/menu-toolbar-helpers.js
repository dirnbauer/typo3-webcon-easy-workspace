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
 * The site language of a row, or null for a record without a language
 * field (or one of a language the site no longer knows).
 *
 * @returns {{uid: number, title: string, flag: string}|null}
 */
export function rowLanguage(host, item) {
  const uid = item?.languageUid;
  if (!Number.isInteger(uid) || uid < 0) return null;
  return host.languages?.[uid] || null;
}

/**
 * The languages the editor's module shows, or null when the module shows
 * no particular language.
 */
export function viewLanguages(host) {
  return Array.isArray(host.viewLanguages) && host.viewLanguages.length > 0 ? host.viewLanguages : null;
}

/**
 * Whether the page, as the editor currently sees it, shows this row. A row
 * without a language, or a module without a language, is always in view.
 */
export function isInView(host, item) {
  const view = viewLanguages(host);
  const language = rowLanguage(host, item);
  return view === null || language === null || view.includes(language.uid);
}

/**
 * The language a row is measured against: the one language of the view,
 * or the default language when the view shows several or none.
 */
export function primaryViewLanguage(host) {
  const view = viewLanguages(host);
  return view !== null && view.length === 1 ? view[0] : 0;
}

/**
 * Group the changed rows for rendering: the page or news record the list
 * is scoped to — its rows in the current view first, then the rows of
 * other languages, one group per language — and, when present, the
 * workspace-wide file metadata rows that have no page.
 *
 * @returns {Array<{key: string, icon: string, title: string, path: string, rows: object[], otherLanguages: Array<{key: string, language: {uid: number, title: string, flag: string}, rows: object[]}>}>}
 */
export function groupRows(host) {
  const rows = (host.items || []).filter((item) => item?.isChanged);
  const standalone = rows.filter((row) => row.table === 'sys_file_metadata');
  const contextRows = rows.filter((row) => row.table !== 'sys_file_metadata');
  const groups = [];
  if (contextRows.length > 0) {
    const record = host.contextRecord || {};
    const isNews = host.newsUid > 0;
    const byLanguage = new Map();
    for (const row of contextRows.filter((row) => !isInView(host, row))) {
      const language = rowLanguage(host, row);
      if (!byLanguage.has(language.uid)) {
        byLanguage.set(language.uid, { key: `language:${language.uid}`, language, rows: [] });
      }
      byLanguage.get(language.uid).rows.push(row);
    }
    groups.push({
      key: 'context',
      icon: record.iconIdentifier || (isNews ? 'content-news' : 'apps-pagetree-page-default'),
      title: record.title || label(host, isNews ? 'context.news' : 'context.page', { uid: isNews ? host.newsUid : host.pageUid }),
      path: record.path || '',
      rows: contextRows.filter((row) => isInView(host, row)),
      otherLanguages: Array.from(byLanguage.values()).sort((a, b) => a.language.uid - b.language.uid),
    });
  }
  if (standalone.length > 0) {
    groups.push({
      key: 'files',
      icon: standalone[0].iconIdentifier || 'mimetypes-other-other',
      title: label(host, 'toolbar.group.files'),
      path: '',
      rows: standalone,
      otherLanguages: [],
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
