import { afterEach, describe, expect, it } from 'vitest';
import { __reset, __respond } from '@typo3/core/ajax/ajax-request.js';
import { refresh } from '@webconsulting/webcon-easy-workspace/menu-actions.js';

function host(pageUid) {
  return { _config: { pageUid }, items: [], selection: new Set(), openSections: new Set(), state: 'idle' };
}

describe('menu-actions refresh', () => {
  afterEach(() => __reset());

  it('keeps the sections an editor opened while the page stays, folds them again on another page', async () => {
    __respond(async () => ({ context: 'page', workspaceId: 4, items: [{ table: 'tt_content', workspaceUid: 1, isChanged: true }] }));
    const h = host(7);
    await refresh(h);
    h.openSections = new Set(['context:other']);

    await refresh(h);
    expect([...h.openSections]).toEqual(['context:other']);

    h._config.pageUid = 8;
    await refresh(h);
    expect(h.openSections.size).toBe(0);
  });
});
