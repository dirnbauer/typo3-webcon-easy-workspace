import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { key } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { hasContentChange, isChangeRow, isCopyRow } from '@webconsulting/webcon-easy-workspace/menu-roles.js';
import { layoutFlag } from '@webconsulting/webcon-easy-workspace/menu-layout.js';

export { hasContentChange, isChangeRow, isCopyRow };

/** Number of edit rows in a list. */
export function changeRowCount(items) {
  return Array.isArray(items) ? items.filter(isChangeRow).length : 0;
}

/**
 * Number of records the cleanup section would discard: every copy item plus
 * the translations folded into it.
 */
export function copyRecordCount(items) {
  if (!Array.isArray(items)) return 0;
  return items.filter(isCopyRow).reduce((sum, item) => sum + 1 + (Array.isArray(item.companions) ? item.companions.length : 0), 0);
}

export function footerState(host) {
  const total = changeRowCount(host.items);
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
 * A site language by id, with the short code the chips show. A server that
 * sends no `code` gets the first two letters of the title.
 *
 * @returns {{uid: number, title: string, flag: string, code: string, hreflang: string}|null}
 */
export function languageInfo(host, uid) {
  if (!Number.isInteger(uid) || uid < 0) return null;
  const language = host.languages?.[uid];
  if (!language) return null;
  const title = String(language.title || '');
  return {
    uid,
    title,
    flag: String(language.flag || ''),
    code: String(language.code || title.slice(0, 2)).toUpperCase(),
    hreflang: String(language.hreflang || ''),
  };
}

/**
 * The site language of a row, or null for a record without a language
 * field (or one of a language the site no longer knows).
 *
 * @returns {{uid: number, title: string, flag: string, code: string, hreflang: string}|null}
 */
export function rowLanguage(host, item) {
  return languageInfo(host, item?.languageUid);
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

/** The one language the view shows, or null for a view of several or none. */
export function singleViewLanguage(host) {
  const view = viewLanguages(host);
  return view !== null && view.length === 1 ? (host.languages?.[view[0]] || null) : null;
}

const COMPANION_KINDS = ['identical', 'synced', 'moved', 'deleted'];

/**
 * The translations TYPO3 carried along with an edit, grouped by what
 * happened to them: identical copies, copies that only took over a shared
 * field (`fields` names it), translations moved or deleted along. Chips are
 * ordered by language id; a language the site no longer lists keeps its id.
 *
 * @returns {Array<{kind: string, labelKey: string, fields: string, editable: boolean, chips: Array<{companion: object, language: object}>}>}
 */
export function companionGroups(host, item) {
  const companions = Array.isArray(item?.companions) ? item.companions : [];
  const groups = [];
  for (const kind of COMPANION_KINDS) {
    const members = companions
      .filter((companion) => (COMPANION_KINDS.includes(companion?.kind) ? companion.kind : 'identical') === kind)
      .sort((a, b) => (Number(a.languageUid) || 0) - (Number(b.languageUid) || 0));
    if (members.length === 0) continue;
    const fields = [...new Set(members.flatMap((companion) => (Array.isArray(companion.fields) ? companion.fields : [])))];
    groups.push({
      kind,
      labelKey: `toolbar.companions.${kind}`,
      fields: fields.join(', '),
      editable: kind === 'identical' || kind === 'synced',
      chips: members.map((companion) => ({ companion, language: memberLanguage(host, companion.languageUid) })),
    });
  }
  return groups;
}

function memberLanguage(host, uid) {
  const id = Number(uid);
  return languageInfo(host, id) || { uid: id, title: String(id), flag: '', code: String(id), hreflang: '' };
}

/**
 * Language titles of companions or dependents, each language once, for the
 * confirm and notification texts.
 */
export function companionLanguageTitles(host, companions) {
  const titles = (Array.isArray(companions) ? companions : [])
    .slice()
    .sort((a, b) => (Number(a.languageUid) || 0) - (Number(b.languageUid) || 0))
    .map((companion) => memberLanguage(host, companion.languageUid).title);
  return [...new Set(titles)].join(', ');
}

/**
 * The line below the title: what the record is, where it sits (its
 * column, or the element it is part of), when it was changed and by whom.
 * Time comes before the author so it survives the ellipsis.
 */
export function metaParts(host, item) {
  const parts = [];
  if (configBool(host, 'enableTypeLabels', true)) {
    parts.push(item.typeLabel && item.typeLabel !== item.tableLabel ? item.typeLabel : item.tableLabel);
  }
  if (item.colPosLabel) parts.push(item.colPosLabel);
  if (item.parent?.title) parts.push(label(host, 'toolbar.row.partOf', { title: item.parent.title }));
  const when = relativeTime(host, item.latestChangeAt || item.tstamp);
  if (when) parts.push(when);
  const userUid = Number(host.userUid) || 0;
  if (userUid > 0 && Number(item.latestChangeUserUid) === userUid) {
    parts.push(label(host, 'toolbar.row.byYou'));
  } else if (item.latestChangeUser) {
    parts.push(label(host, 'toolbar.row.by', { name: item.latestChangeUser }));
  }
  return parts.filter(Boolean);
}

/**
 * Leftover copies grouped as the cleanup section lists them: one line per
 * element, holding the copy items of that element and the translations
 * folded into them.
 */
function copyGroups(copyItems) {
  const byGroup = new Map();
  for (const item of copyItems) {
    const groupKey = item.copyGroup || `${item.table}:${item.liveUid}`;
    if (!byGroup.has(groupKey)) byGroup.set(groupKey, []);
    byGroup.get(groupKey).push(item);
  }
  return Array.from(byGroup.entries()).map(([groupKey, items]) => {
    const sorted = items.slice().sort((a, b) => (Number(a.languageUid) || 0) - (Number(b.languageUid) || 0));
    const lead = sorted[0];
    const members = sorted.flatMap((item) => [item, ...(Array.isArray(item.companions) ? item.companions : [])])
      .map((member) => ({
        table: member.table,
        workspaceUid: member.workspaceUid,
        liveUid: member.liveUid,
        languageUid: Number.isInteger(member.languageUid) ? member.languageUid : 0,
        editUrl: member.editUrl || null,
        contextualEditUrl: member.contextualEditUrl || null,
      }))
      .sort((a, b) => a.languageUid - b.languageUid);
    return {
      key: groupKey,
      title: lead.title || '',
      iconIdentifier: lead.iconIdentifier || 'mimetypes-other-other',
      typeLabel: lead.typeLabel || lead.tableLabel || '',
      members,
      records: sorted.map((item) => ({ table: item.table, workspaceUid: item.workspaceUid })),
    };
  });
}

/**
 * Group the list for rendering. Per group (the page or news record the list
 * is scoped to, then workspace-wide file metadata):
 *  - `rows`: edits the current view shows;
 *  - `otherLanguages`: edits in languages the view does not show, one entry
 *    per language;
 *  - `copies`: leftover copies for the cleanup section, one entry per element.
 * Translations folded into an edit (`companions`) produce no rows here.
 *
 * @returns {Array<{key: string, icon: string, title: string, path: string, rows: object[], otherLanguages: Array<{key: string, language: object, rows: object[]}>, copies: object[]}>}
 */
export function groupRows(host) {
  const items = (host.items || []).filter(Boolean);
  const isFile = (item) => item.table === 'sys_file_metadata';
  const groups = [];

  const contextChanges = items.filter((item) => isChangeRow(item) && !isFile(item));
  const contextCopies = items.filter((item) => isCopyRow(item) && !isFile(item));
  if (contextChanges.length > 0 || contextCopies.length > 0) {
    const record = host.contextRecord || {};
    const isNews = host.newsUid > 0;
    groups.push({
      key: 'context',
      icon: record.iconIdentifier || (isNews ? 'content-news' : 'apps-pagetree-page-default'),
      title: record.title || label(host, isNews ? 'context.news' : 'context.page', { uid: isNews ? host.newsUid : host.pageUid }),
      path: record.path || '',
      ...splitByView(host, contextChanges),
      copies: copyGroups(contextCopies),
    });
  }

  const fileChanges = items.filter((item) => isChangeRow(item) && isFile(item));
  const fileCopies = items.filter((item) => isCopyRow(item) && isFile(item));
  if (fileChanges.length > 0 || fileCopies.length > 0) {
    groups.push({
      key: 'files',
      icon: (fileChanges[0] || fileCopies[0]).iconIdentifier || 'mimetypes-other-other',
      title: label(host, 'toolbar.group.files'),
      path: '',
      rows: fileChanges,
      otherLanguages: [],
      copies: copyGroups(fileCopies),
    });
  }
  return groups;
}

function splitByView(host, rows) {
  const byLanguage = new Map();
  for (const row of rows.filter((entry) => !isInView(host, entry))) {
    const language = rowLanguage(host, row);
    if (!byLanguage.has(language.uid)) {
      byLanguage.set(language.uid, { key: `language:${language.uid}`, language, rows: [] });
    }
    byLanguage.get(language.uid).rows.push(row);
  }
  return {
    rows: rows.filter((row) => isInView(host, row)),
    otherLanguages: Array.from(byLanguage.values()).sort((a, b) => a.language.uid - b.language.uid),
  };
}

/** Edit rows of a group, in view and in the other languages. */
export function groupChangeRows(group) {
  return [...(group.rows || []), ...(group.otherLanguages || []).flatMap((entry) => entry.rows)];
}

/** Records the cleanup section of a group lists (copy items + their translations). */
export function groupCopyRecordCount(group) {
  return (group.copies || []).reduce((sum, copy) => sum + copy.members.length, 0);
}

/**
 * One sentence about the numbers: the edits here (and how many of them the
 * view does not show), the leftover copies, and how much more waits
 * elsewhere in the workspace.
 */
export function summaryText(host) {
  const total = Math.max(0, Number(host.badgeCount) || 0);
  if (host.state === 'no-context') {
    return total > 0 ? label(host, 'toolbar.count.workspace', { count: total }) : label(host, 'toolbar.count.none');
  }
  const items = Array.isArray(host.items) ? host.items : [];
  if (host.state === 'loading' && items.length === 0) return label(host, 'toolbar.loading');
  if (host.state === 'error') return '';
  const onPage = changeRowCount(items);
  const isNews = host.newsUid > 0;
  let first = onPage > 0
    ? label(host, isNews ? 'toolbar.summary.news' : 'toolbar.summary.page', { count: onPage })
    : label(host, isNews ? 'toolbar.summary.noneNews' : 'toolbar.summary.nonePage');
  const otherLanguages = items.filter((item) => isChangeRow(item) && !isInView(host, item)).length;
  if (otherLanguages > 0) first += `, ${label(host, 'toolbar.summary.otherLanguages', { count: otherLanguages })}`;
  const parts = [first];
  const copies = copyRecordCount(items);
  if (copies > 0) parts.push(label(host, String(layoutFlag(host, 'copiesSummaryKey') || 'toolbar.summary.copies'), { count: copies }));
  const elsewhere = Math.max(0, total - onPage);
  if (elsewhere > 0) parts.push(label(host, 'toolbar.summary.elsewhere', { count: elsewhere }));
  return parts.join(' · ');
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
  return configBool(host, 'enableRevert') && Boolean(item?.isChanged);
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

/**
 * Keys for repeat(): a chip stays the chip of its translation across
 * renders, so a focused one never turns into another language's.
 */
export const chipKey = (chip) => `${chip.companion?.table}:${chip.companion?.workspaceUid}`;
export const memberKey = (member) => `${member?.table}:${member?.workspaceUid}`;
