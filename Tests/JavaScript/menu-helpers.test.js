import { describe, expect, it } from 'vitest';
import { DEFAULT_CONFIG } from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import {
  changeType,
  relativeTime,
  groupRows,
  changedItemCount,
  isInView,
  rowLanguage,
  primaryViewLanguage,
} from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

const labels = {
  'time.now': 'just now',
  'time.minutes': '{count, plural, one {# min ago} other {# min ago}}',
  'time.hours': '{count, plural, one {# h ago} other {# h ago}}',
  'time.days': '{count, plural, one {# day ago} other {# days ago}}',
  'toolbar.group.files': 'Files in this workspace',
  'context.page': 'Page {uid}',
};
const languages = {
  0: { uid: 0, title: 'English', flag: 'flags-us' },
  1: { uid: 1, title: 'German', flag: 'flags-de' },
  2: { uid: 2, title: 'Chinese', flag: 'flags-cn' },
};
const host = () => ({
  _config: { ...DEFAULT_CONFIG, labels },
  pageUid: 7,
  newsUid: 0,
  items: [],
  languages,
  viewLanguages: null,
  contextRecord: null,
});

describe('menu-toolbar-helpers', () => {
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
    expect(relativeTime(h, 1_700_000_000 - 20, now)).toBe('just now');
    expect(relativeTime(h, 1_700_000_000 - 300, now)).toBe('5 min ago');
    expect(relativeTime(h, 1_700_000_000 - 7200, now)).toBe('2 h ago');
    expect(relativeTime(h, 1_700_000_000 - 86400, now)).toBe('1 day ago');
    expect(relativeTime(h, 1_700_000_000 - 3 * 86400, now)).toBe('3 days ago');
  });

  it('groups rows under the context record and workspace files', () => {
    const h = host();
    h.contextRecord = { title: 'Start', path: 'Root / Start', iconIdentifier: 'apps-pagetree-page-default' };
    h.items = [
      { table: 'pages', workspaceUid: 1, isChanged: true, languageUid: 0 },
      { table: 'tt_content', workspaceUid: 2, isChanged: true, languageUid: 0 },
      { table: 'tt_content', workspaceUid: 9, isChanged: false, languageUid: 0 },
      { table: 'sys_file_metadata', workspaceUid: 3, isChanged: true, iconIdentifier: 'mimetypes-pdf' },
    ];
    const groups = groupRows(h);
    expect(groups.map((group) => [group.key, group.title, group.rows.length, group.otherLanguages.length])).toEqual([
      ['context', 'Start', 2, 0],
      ['files', 'Files in this workspace', 1, 0],
    ]);
    expect(groups[0].path).toBe('Root / Start');
    expect(groups[1].icon).toBe('mimetypes-pdf');
    expect(groupRows(host())).toEqual([]);
  });

  it('sets the rows of languages the module does not show apart, one group per language', () => {
    const h = host();
    h.viewLanguages = [1];
    h.items = [
      { table: 'tt_content', workspaceUid: 10, isChanged: true, languageUid: 0 },
      { table: 'tt_content', workspaceUid: 11, isChanged: true, languageUid: 1 },
      { table: 'tt_content', workspaceUid: 12, isChanged: true, languageUid: 2 },
      { table: 'tt_content', workspaceUid: 13, isChanged: true, languageUid: 0 },
      { table: 'tx_easyws_item', workspaceUid: 14, isChanged: true, languageUid: null },
    ];
    const [group] = groupRows(h);
    expect(group.rows.map((row) => row.workspaceUid)).toEqual([11, 14]);
    expect(group.otherLanguages.map((entry) => [entry.key, entry.language.title, entry.rows.map((row) => row.workspaceUid)])).toEqual([
      ['language:0', 'English', [10, 13]],
      ['language:2', 'Chinese', [12]],
    ]);
  });

  it('treats every row as in view when the module shows no particular language, or several', () => {
    const h = host();
    const german = { table: 'tt_content', workspaceUid: 11, isChanged: true, languageUid: 1 };
    expect(isInView(h, german)).toBe(true);
    expect(primaryViewLanguage(h)).toBe(0);
    h.viewLanguages = [0, 1];
    expect(isInView(h, german)).toBe(true);
    expect(isInView(h, { ...german, languageUid: 2 })).toBe(false);
    expect(primaryViewLanguage(h)).toBe(0);
    h.viewLanguages = [2];
    expect(isInView(h, german)).toBe(false);
    expect(primaryViewLanguage(h)).toBe(2);
    // A language the site does not list cannot be told apart.
    expect(rowLanguage(h, { languageUid: 7 })).toBeNull();
    expect(isInView(h, { languageUid: 7 })).toBe(true);
  });

  it('counts changed items for the header only', () => {
    expect(changedItemCount([{ isChanged: true }, { isChanged: false }, { isChanged: true }])).toBe(2);
    expect(changedItemCount(null)).toBe(0);
  });
});
