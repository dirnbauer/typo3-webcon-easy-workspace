import { describe, expect, it } from 'vitest';
import { DEFAULT_CONFIG } from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import { changeType, relativeTime, groupRows, changedItemCount } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

const labels = {
  'time.now': 'just now',
  'time.minutes': '{count, plural, one {# min ago} other {# min ago}}',
  'time.hours': '{count, plural, one {# h ago} other {# h ago}}',
  'time.days': '{count, plural, one {# day ago} other {# days ago}}',
  'toolbar.group.files': 'Files in this workspace',
  'context.page': 'Page {uid}',
};
const host = () => ({ _config: { ...DEFAULT_CONFIG, labels }, pageUid: 7, newsUid: 0, items: [], changedItemGroups: [], contextRecord: null });

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
    h.changedItemGroups = [
      { key: 'records', items: [{ table: 'pages', workspaceUid: 1, isChanged: true }] },
      { key: 'column:0', items: [{ table: 'tt_content', workspaceUid: 2, isChanged: true }, { table: 'sys_file_metadata', workspaceUid: 3, isChanged: true, iconIdentifier: 'mimetypes-pdf' }] },
    ];
    const groups = groupRows(h);
    expect(groups.map((group) => [group.key, group.title, group.rows.length])).toEqual([
      ['context', 'Start', 2],
      ['files', 'Files in this workspace', 1],
    ]);
    expect(groups[0].path).toBe('Root / Start');
    expect(groups[1].icon).toBe('mimetypes-pdf');
    expect(groupRows(host())).toEqual([]);
  });

  it('counts changed items for the header only', () => {
    expect(changedItemCount([{ isChanged: true }, { isChanged: false }, { isChanged: true }])).toBe(2);
    expect(changedItemCount(null)).toBe(0);
  });
});
