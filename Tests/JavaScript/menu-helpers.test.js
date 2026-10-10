import { describe, expect, it } from 'vitest';
import {
  changeType,
  relativeTime,
  groupRows,
  changeRowCount,
  copyRecordCount,
  companionGroups,
  hasContentChange,
  isChangeRow,
  isCopyRow,
  isInView,
  metaParts,
  rowLanguage,
  primaryViewLanguage,
  summaryText,
} from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { change, companion, copy, host } from './fixtures/items.js';

describe('roles', () => {
  it('prefers the server role and falls back to the content flag without one', () => {
    expect(isChangeRow(change(1))).toBe(true);
    expect(isChangeRow(copy(2))).toBe(false);
    expect(isCopyRow(copy(2))).toBe(true);
    // Role wins over the 1.10 flag.
    expect(isChangeRow({ isChanged: true, contentChanged: false, role: 'change' })).toBe(true);
    // Older payloads: every version counts unless it says it carries no content change.
    expect(hasContentChange({ isChanged: true })).toBe(true);
    expect(hasContentChange({ isChanged: true, contentChanged: false })).toBe(false);
    expect(hasContentChange({ isChanged: false, contentChanged: true })).toBe(false);
    expect(isCopyRow({ isChanged: true, contentChanged: false })).toBe(false);
  });

  it('counts edits only, and copy records with the translations folded into them', () => {
    const items = [change(1, { companions: [companion(1), companion(2)] }), change(2), copy(3, { companions: [companion(1), companion(2), companion(3)] })];
    expect(changeRowCount(items)).toBe(2);
    expect(copyRecordCount(items)).toBe(4);
    expect(changeRowCount(null)).toBe(0);
    expect(copyRecordCount(undefined)).toBe(0);
  });
});

describe('formatting', () => {
  it('maps kind keys onto the four change types', () => {
    expect(changeType({ kindKey: 'new' })).toBe('new');
    expect(changeType({ kindKey: 'delete' })).toBe('deleted');
    expect(changeType({ kindKey: 'move' })).toBe('moved');
    expect(changeType({ kindKey: 'modified' })).toBe('changed');
    expect(changeType({})).toBe('changed');
  });

  it('formats coarse relative times', () => {
    const now = 1_700_000_000_000;
    const h = host();
    expect(relativeTime(h, 0, now)).toBe('');
    expect(relativeTime(h, 1_700_000_000 - 20, now)).toBe('gerade eben');
    expect(relativeTime(h, 1_700_000_000 - 300, now)).toBe('vor 5 Min.');
    expect(relativeTime(h, 1_700_000_000 - 7200, now)).toBe('vor 2 Std.');
    expect(relativeTime(h, 1_700_000_000 - 86400, now)).toBe('vor 1 Tag');
    expect(relativeTime(h, 1_700_000_000 - 3 * 86400, now)).toBe('vor 3 Tagen');
  });

  it('orders the meta line type · column · part-of · time · author, and names the editor "von Ihnen"', () => {
    const at = Math.floor(Date.now() / 1000) - 120;
    const h = host();
    const own = change(1, { latestChangeAt: at, latestChangeUser: 'Kurt Dirnbauer (admin)', latestChangeUserUid: 1, parent: { title: 'Drei Schritte' } });
    expect(metaParts(h, own)).toEqual(['Hero-Bereich', 'Normal', 'in Drei Schritte', 'vor 2 Min.', 'von Ihnen']);
    const other = { ...own, latestChangeUserUid: 7, latestChangeUser: 'Anna Berger' };
    expect(metaParts(h, other).at(-1)).toBe('von Anna Berger');
    // Without the acting user's id nobody is "you".
    expect(metaParts({ ...h, userUid: 0 }, own).at(-1)).toBe('von Kurt Dirnbauer (admin)');
  });

  it('gives every language a short code, also when the server sends none', () => {
    const h = host();
    expect(rowLanguage(h, change(1, { languageUid: 1 }))).toMatchObject({ code: 'DE', hreflang: 'de-AT', title: 'German' });
    h.languages = { 1: { uid: 1, title: 'German', flag: 'flags-de' } };
    expect(rowLanguage(h, change(1, { languageUid: 1 })).code).toBe('GE');
    expect(rowLanguage(h, change(1, { languageUid: 7 }))).toBeNull();
  });

  it('groups translations carried along by kind, chips ordered by language', () => {
    const item = change(1, { companions: [companion(3), companion(1, 'synced', ['Veröffentlichungsdatum']), companion(2), companion(2, 'deleted')] });
    const groups = companionGroups(host(), item);
    expect(groups.map((group) => [group.kind, group.labelKey, group.editable, group.chips.map((chip) => chip.language.code)])).toEqual([
      ['identical', 'toolbar.companions.identical', true, ['ZH', 'HU']],
      ['synced', 'toolbar.companions.synced', true, ['DE']],
      ['deleted', 'toolbar.companions.deleted', false, ['ZH']],
    ]);
    expect(groups[1].fields).toBe('Veröffentlichungsdatum');
    expect(companionGroups(host(), change(2))).toEqual([]);
  });
});

describe('groupRows', () => {
  it('lists edits by view language, folds translations into their row and groups leftover copies', () => {
    const h = host([
      change(10, { companions: [companion(1), companion(2), companion(3)] }),
      change(11, { languageUid: 1 }),
      change(12, { languageUid: 2 }),
      change(13),
      copy(20, { languageUid: 1, copyGroup: 'tt_content:158035' }),
      copy(21, { languageUid: 0, copyGroup: 'tt_content:158035', title: 'Everything a TYPO3 client site needs', companions: [companion(3)] }),
      copy(22, { copyGroup: 'tt_content:158036', title: 'What is in the package' }),
    ]);
    const [group, ...rest] = groupRows(h);
    expect(rest).toEqual([]);
    expect(group.rows.map((row) => row.workspaceUid)).toEqual([10, 13]);
    // Companions never become rows or language sections.
    expect(group.otherLanguages.map((entry) => [entry.language.code, entry.rows.map((row) => row.workspaceUid)])).toEqual([
      ['DE', [11]], ['ZH', [12]],
    ]);
    expect(group.copies.map((entry) => entry.key)).toEqual(['tt_content:158035', 'tt_content:158036']);
    const hero = group.copies[0];
    // The member with the lowest language names the line; members are sorted by language.
    expect(hero.title).toBe('Everything a TYPO3 client site needs');
    expect(hero.members.map((member) => member.languageUid)).toEqual([0, 1, 3]);
    // Only the copy items are posted; the server adds their translations.
    expect(hero.records).toEqual([{ table: 'tt_content', workspaceUid: 21 }, { table: 'tt_content', workspaceUid: 20 }]);
  });

  it('puts file metadata, edits and copies alike, into the workspace files group', () => {
    const h = host([
      change(1, { table: 'sys_file_metadata', iconIdentifier: 'mimetypes-media-image' }),
      copy(2, { table: 'sys_file_metadata', copyGroup: 'sys_file_metadata:5' }),
    ]);
    const groups = groupRows(h);
    expect(groups.map((group) => [group.key, group.title, group.rows.length, group.copies.length])).toEqual([
      ['files', 'Dateien in diesem Workspace', 1, 1],
    ]);
    expect(groups[0].icon).toBe('mimetypes-media-image');
    expect(groupRows(host())).toEqual([]);
  });

  it('builds a group for copies alone', () => {
    const groups = groupRows(host([copy(1)]));
    expect(groups.map((group) => [group.key, group.title, group.rows.length, group.copies.length])).toEqual([['context', 'Product', 0, 1]]);
  });

  it('treats every row as in view when the module shows no particular language, or several', () => {
    const h = host();
    h.viewLanguages = null;
    const german = change(11, { languageUid: 1 });
    expect(isInView(h, german)).toBe(true);
    expect(primaryViewLanguage(h)).toBe(0);
    h.viewLanguages = [0, 1];
    expect(isInView(h, german)).toBe(true);
    expect(isInView(h, { ...german, languageUid: 2 })).toBe(false);
    expect(primaryViewLanguage(h)).toBe(0);
    h.viewLanguages = [2];
    expect(isInView(h, german)).toBe(false);
    expect(primaryViewLanguage(h)).toBe(2);
    expect(isInView(h, { languageUid: 7 })).toBe(true);
  });
});

describe('summaryText', () => {
  it('says "keine" instead of 0, and never counts while the first list is loading', () => {
    expect(summaryText(host([], { badgeCount: 0 }))).toBe('Keine Änderungen auf dieser Seite');
    expect(summaryText(host([], { newsUid: 4, badgeCount: 0 }))).toBe('Keine Änderungen in diesem Artikel');
    expect(summaryText(host([], { state: 'loading' }))).toBe('Ausstehende Änderungen werden geladen...');
    expect(summaryText(host([change(1)], { state: 'error' }))).toBe('');
    expect(summaryText(host([], { state: 'no-context', badgeCount: 4 }))).toBe('4 ausstehend in diesem Workspace');
  });

  it('names the edits, the share in other languages, leftover copies and what waits elsewhere', () => {
    const items = [
      change(1, { companions: [companion(1), companion(2), companion(3)] }),
      change(2, { languageUid: 1 }),
      copy(3, { companions: [companion(1)] }),
    ];
    expect(summaryText(host(items, { badgeCount: 5 })))
      .toBe('2 Änderungen auf dieser Seite, 1 davon in einer anderen Sprache · 2 unveränderte Kopien · 3 weitere im Workspace');
    // The screenshot case: one edit, three translation copies — reads 1.
    expect(summaryText(host([items[0]], { badgeCount: 1 }))).toBe('1 Änderung auf dieser Seite');
  });
});
