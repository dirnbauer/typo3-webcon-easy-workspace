import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BadgeSync } from '@webconsulting/webcon-easy-workspace/menu-badge.js';
import { DEFAULT_CONFIG } from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import { playEditingSession } from './fixtures/editing-session.js';

/**
 * Request budget of a whole editing session. 1.7.2 asked the server 98
 * times in these five minutes — 91 badge and 7 list requests, one every
 * three seconds, because focus, poll, visibility and navigation each fetched
 * on their own (at 2.2 s of server time each on production). The toolbar
 * now asks only when something was saved or the page changed.
 */
describe('A five-minute Visual Editor session', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('costs one request per save, per page change and per dropdown check', async () => {
    let revision = 0;
    const answer = () => ({ workspaceId: 1, contextCount: revision, changedCount: revision, stamp: `rev-${revision}` });
    const fetchBadge = vi.fn(async () => answer());
    const fetchList = vi.fn(async () => answer());
    const listeners = {};
    const doc = {
      hidden: false,
      addEventListener(name, fn, options = {}) {
        (listeners[name] ??= []).push(fn);
        options?.signal?.addEventListener?.('abort', () => { listeners[name] = listeners[name].filter((f) => f !== fn); });
      },
      dispatch(name) { for (const fn of [...(listeners[name] || [])]) fn(new Event(name)); },
    };
    let open = false;
    const host = {
      closest: () => null,
      dispatchEvent: vi.fn(),
      requestUpdate: vi.fn(),
      _config: { ...DEFAULT_CONFIG, pageUid: 1149 },
      _isDropdownOpen: () => open,
    };
    const channel = { postMessage: vi.fn(), close: vi.fn(), onmessage: null };
    const sync = new BadgeSync(host, {
      window,
      document: doc,
      topDocument: null,
      topWindow: null,
      fetchBadge,
      fetchList,
      createChannel: () => channel,
      initialWorkspaceId: 1,
    });

    sync.start();
    await playEditingSession({
      advance: (ms) => vi.advanceTimersByTimeAsync(ms),
      focus: () => window.dispatchEvent(new Event('focus')),
      visibility: (hidden) => { doc.hidden = hidden; doc.dispatch('visibilitychange'); },
      docEvent: (name) => doc.dispatch(name),
      veSave: (pageTree) => {
        revision += 1;
        // visual-editor-decline-button.js bridges VE's ve_saveEnded.
        window.dispatchEvent(new MessageEvent('message', { data: { type: 'wew-refresh', reason: 've-save' }, origin: 'https://frontend.example' }));
        if (pageTree) doc.dispatch('typo3:pagetree:refresh');
      },
      navigate: async (pageUid) => {
        host._config.pageUid = pageUid;
        doc.dispatch('typo3:module-state-storage:update:web');
        doc.dispatch('typo3:module-state-storage:update-with-tree-identifier:web');
        await vi.advanceTimersByTimeAsync(700);
        doc.dispatch('typo3-module-loaded');
      },
      openDropdown: () => { open = true; void sync.request('open', { list: true }); },
      closeDropdown: () => { open = false; },
    });
    sync.stop();

    // connect 1 + 9 saves + 2 page changes + 1 dropdown check = 13 requests.
    // The save at 2:31 falls into the dropdown check, so it fetched the open
    // list — whose response carries the badge — instead of /badge.
    expect(fetchBadge).toHaveBeenCalledTimes(11);
    expect(fetchList).toHaveBeenCalledTimes(2);
    expect(fetchBadge.mock.calls.length + fetchList.mock.calls.length).toBe(13);
  });
});
