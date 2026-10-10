import { afterEach, describe, expect, it, vi } from 'vitest';
import { __calls, __reset, __respond } from '@typo3/core/ajax/ajax-request.js';
import { __notifications } from '@typo3/backend/notification.js';
import { refresh, publish, discardItem, discardCopies } from '@webconsulting/webcon-easy-workspace/menu-actions.js';
import { change, companion, host as menuHost } from './fixtures/items.js';

function host(pageUid = 2228, extra = {}) {
  return menuHost([], {
    _config: { pageUid, labels: menuHost()._config.labels },
    state: 'idle',
    badge: { apply: vi.fn() },
    focusAfterRender: vi.fn(),
    setStatus: vi.fn(),
    ...extra,
  });
}

const badge = { contextCount: 0, changedCount: 0, stamp: 'b' };

describe('menu-actions refresh', () => {
  afterEach(() => { __reset(); __notifications.length = 0; });

  it('keeps the sections an editor toggled while the page stays, resets them on another page', async () => {
    __respond(async () => ({ context: 'page', workspaceId: 4, userUid: 1, items: [change(1)] }));
    const h = host(2228, { badge: null });
    await refresh(h);
    h.sectionState = new Map([['context:other', true]]);

    await refresh(h);
    expect([...h.sectionState]).toEqual([['context:other', true]]);

    h._config.pageUid = 8;
    await refresh(h);
    expect(h.sectionState.size).toBe(0);
  });

  it('keeps the acting user and the language codes', async () => {
    __respond(async () => ({
      context: 'page', workspaceId: 4, userUid: 9, items: [change(1)],
      languages: [{ uid: 1, title: 'German', flag: 'flags-de', code: 'DE', hreflang: 'de-AT' }, { uid: 2, title: 'Chinese', flag: 'flags-cn' }],
    }));
    const h = host(2228, { badge: null });
    await refresh(h);
    expect(h.userUid).toBe(9);
    expect(h.languages[1]).toEqual({ uid: 1, title: 'German', flag: 'flags-de', code: 'DE', hreflang: 'de-AT' });
    expect(h.languages[2].code).toBe('');
  });
});

describe('menu-actions writes', () => {
  afterEach(() => { __reset(); __notifications.length = 0; });

  it('discards a row with exactly one request for its own record', async () => {
    __respond(async () => ({ success: true, discarded: 1, companionsDiscarded: 3, dependentsDiscarded: 1, warnings: [], errors: [], badge }));
    const h = host();
    const hero = change(161266, { companions: [companion(1), companion(2), companion(3)], publishRecords: [{ table: 'sys_file_reference', workspaceUid: 161270 }] });
    const result = await discardItem(h, hero);

    expect(__calls).toHaveLength(1);
    expect(__calls[0].url).toContain('/discard?');
    expect(__calls[0].body).toEqual({ table: 'tt_content', workspaceUid: 161266, pageUid: 2228 });
    expect(result).toEqual({ success: true, errors: [], warnings: [], companionsDiscarded: 3, dependentsDiscarded: 1 });
    expect(h.badge.apply).toHaveBeenCalledWith(badge, { reason: 'discard', refreshList: false });
  });

  it('reads no dependents discarded from an older server', async () => {
    __respond(async () => ({ success: true, discarded: 1, companionsDiscarded: 0, warnings: [], errors: [] }));
    const result = await discardItem(host(), change(1));
    expect(result.dependentsDiscarded).toBe(0);
  });

  it('reports a failed discard with the server\'s errors', async () => {
    __respond(async () => ({ success: false, errors: ['Kein Zugriff'], warnings: [] }));
    const result = await discardItem(host(), change(1));
    expect(result.success).toBe(false);
    expect(result.errors).toEqual(['Kein Zugriff']);
  });

  it('posts the cleanup section\'s copy records with the context and applies the badge', async () => {
    __respond(async (request) => (request.url.includes('/discard-copies')
      ? { success: true, discarded: 2, skipped: 1, errors: [], badge }
      : { context: 'page', items: [] }));
    const h = host();
    const records = [{ table: 'tt_content', workspaceUid: 161380 }, { table: 'tt_content', workspaceUid: 161390 }];
    const result = await discardCopies(h, records);

    expect(__calls[0].url).toContain('/discard-copies?');
    expect(__calls[0].body).toEqual({ records, pageUid: 2228 });
    expect(h.badge.apply).toHaveBeenCalledWith(badge, { reason: 'discard', refreshList: false });
    expect(result).toMatchObject({ success: true, discarded: 2, skipped: 1 });
    expect(__notifications.map((n) => [n.severity, n.title, n.message])).toEqual([
      ['success', 'Aufgeräumt', '2 unveränderte Kopien verworfen.'],
      ['info', 'Aufgeräumt', '1 Kopie wurde inzwischen bearbeitet und bleibt erhalten.'],
    ]);
  });

  it('names the cleaned-up records as the layout\'s cleanup section does', async () => {
    const { default: core } = await import('@webconsulting/webcon-easy-workspace/layouts/core.js');
    __respond(async (request) => (request.url.includes('/discard-copies')
      ? { success: true, discarded: 3, skipped: 1, errors: [], badge }
      : { context: 'page', items: [] }));
    await discardCopies(host(2228, { layoutProfile: core }), [{ table: 'tt_content', workspaceUid: 161380 }]);

    expect(__notifications.map((n) => n.message)).toEqual([
      '3 Entwürfe ohne Änderung verworfen.',
      '1 Entwurf wurde inzwischen bearbeitet und bleibt erhalten.',
    ]);
  });

  it('counts the selected edits in the success message, not the records the server published', async () => {
    __respond(async (request) => (request.url.includes('/publish')
      ? { success: true, published: 5, companions: 3, skippedCompanions: [], warnings: [], errors: [], badge }
      : { context: 'page', items: [] }));
    const hero = change(161266, { companions: [companion(1), companion(2), companion(3)] });
    const h = host(2228, { items: [hero], selection: new Set(['tt_content:161266']) });
    await publish(h);

    expect(__calls[0].body).toEqual({ selections: [{ table: 'tt_content', workspaceUid: 161266 }], pageUid: 2228 });
    expect(__notifications).toEqual([expect.objectContaining({ severity: 'success', message: '1 Änderung veröffentlicht.' })]);
    expect(h.focusAfterRender).toHaveBeenCalledWith({ index: 0 });
    expect(h.publishing).toBe(false);
  });

  it('says which translations were not published along, and passes warnings on', async () => {
    __respond(async (request) => (request.url.includes('/publish')
      ? {
        success: true, published: 1, companions: 1,
        skippedCompanions: [{ table: 'tt_content', workspaceUid: 161268, languageUid: 2 }, { table: 'tt_content', workspaceUid: 161267, languageUid: 1 }],
        warnings: ['Stufe übersprungen'], errors: [], badge,
      }
      : { context: 'page', items: [] }));
    const h = host(2228, { items: [change(161266)], selection: new Set(['tt_content:161266']) });
    await publish(h);

    expect(__notifications.map((n) => n.severity)).toEqual(['success', 'info', 'warning']);
    expect(__notifications[1].message).toBe('Nicht mitveröffentlicht, weil inzwischen bearbeitet: German, Chinese (Simplified). Diese Übersetzungen stehen jetzt als eigene Änderung in der Liste.');
    expect(__notifications[2].message).toBe('Stufe übersprungen');
  });
});
