import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BadgeSync, normalizeBadgePayload } from '@webconsulting/webcon-easy-workspace/menu-badge.js';
import { DEFAULT_CONFIG } from '@webconsulting/webcon-easy-workspace/menu-constants.js';

function payload(overrides = {}) {
  return {
    context: 'page',
    workspaceId: 1,
    workspaceTitle: 'Staging',
    changedCount: 3,
    byTable: { tt_content: 3 },
    byState: { new: 1, changed: 2, deleted: 0, moved: 0 },
    latestChangeAt: 1700000000,
    stamp: 'stamp-a',
    ...overrides,
  };
}

function changedItems(count) {
  return Array.from({ length: count }, (_, index) => ({
    table: 'tt_content',
    workspaceUid: 100 + index,
    liveUid: 10 + index,
    isChanged: true,
    title: `Element ${index}`,
  }));
}

function createHost({ items = changedItems(5), workspaceId = 1, withDom = false } = {}) {
  const host = withDom ? document.createElement('webcon-easy-workspace-menu-v2') : {};
  Object.assign(host, {
    items,
    badgeCount: 0,
    workspaceId,
    workspaceTitle: '',
    _config: { ...DEFAULT_CONFIG, activeWorkspaceId: workspaceId, pageUid: 7, labels: { ...DEFAULT_CONFIG.labels, 'toolbar.badge.pending': '{count, plural, one {# pending} other {# pending}}' } },
    requestUpdate: vi.fn(),
    _refresh: vi.fn(async () => {}),
    _isDropdownOpen: vi.fn(() => false),
  });
  if (!withDom) {
    host.closest = () => null;
    host.dispatchEvent = vi.fn();
  }
  return host;
}

/**
 * Minimal document stub. It honours `options.signal`, because BadgeSync
 * relies on AbortController to detach the iframe listeners again.
 */
function createDoc() {
  return {
    hidden: false,
    listeners: {},
    addEventListener(name, fn, options = {}) {
      const bucket = (this.listeners[name] ??= []);
      bucket.push(fn);
      options?.signal?.addEventListener?.('abort', () => {
        const index = bucket.indexOf(fn);
        if (index >= 0) bucket.splice(index, 1);
      });
    },
    removeEventListener(name, fn) {
      const bucket = this.listeners[name] || [];
      const index = bucket.indexOf(fn);
      if (index >= 0) bucket.splice(index, 1);
    },
    dispatch(name) { for (const fn of [...(this.listeners[name] || [])]) fn(new Event(name)); },
  };
}

function fakeChannel() {
  return { postMessage: vi.fn(), close: vi.fn(), onmessage: null };
}

function createSync(host, overrides = {}) {
  const doc = createDoc();
  const channel = fakeChannel();
  const fetchBadge = vi.fn(async () => payload());
  const sync = new BadgeSync(host, {
    window,
    document: doc,
    topDocument: null,
    topWindow: null,
    fetchBadge,
    createChannel: () => channel,
    random: () => 0,
    initialWorkspaceId: host.workspaceId,
    ...overrides,
  });
  return { sync, doc, channel, fetchBadge };
}

describe('BadgeSync', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('applies the server count instead of the client list count', async () => {
    const host = createHost({ items: changedItems(5) });
    const { sync, fetchBadge } = createSync(host);
    fetchBadge.mockResolvedValue(payload({ changedCount: 3 }));

    await sync.run('connect');

    expect(host.items.filter((item) => item.isChanged)).toHaveLength(5);
    expect(host.badgeCount).toBe(3);
    expect(sync.stamp).toBe('stamp-a');
    expect(host.workspaceTitle).toBe('Staging');
    expect(fetchBadge).toHaveBeenCalledTimes(1);
    expect(fetchBadge.mock.calls[0][0]).toMatchObject({ pageUid: 7 });
  });

  it('ignores a stale response that resolves after a newer request', async () => {
    const host = createHost();
    const { sync, fetchBadge } = createSync(host);
    let resolveFirst;
    fetchBadge
      .mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }))
      .mockImplementationOnce(async () => payload({ changedCount: 2, stamp: 'stamp-b' }));

    const first = sync.run('poll');
    const second = sync.run('manual');
    await second;
    expect(host.badgeCount).toBe(2);

    resolveFirst(payload({ changedCount: 9, stamp: 'stamp-stale' }));
    await first;

    expect(host.badgeCount).toBe(2);
    expect(sync.stamp).toBe('stamp-b');
  });

  it('coalesces bursts of triggers into one request through the debounce', async () => {
    const host = createHost();
    const { sync, fetchBadge } = createSync(host);
    sync.start();
    fetchBadge.mockClear();

    sync.request('typo3:datahandler:process');
    sync.request('typo3:pagetree:refresh');
    sync.request('message:typo3:editform:saved');
    expect(fetchBadge).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(119);
    expect(fetchBadge).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchBadge).toHaveBeenCalledTimes(1);
    sync.stop();
  });

  it('polls while visible, pauses while hidden and catches up on return', async () => {
    const host = createHost();
    const { sync, doc, fetchBadge } = createSync(host, { pollInterval: 1000, pollJitter: 0, debounceMs: 10 });
    sync.start();
    await vi.advanceTimersByTimeAsync(10);
    expect(fetchBadge).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(1010);
    expect(fetchBadge).toHaveBeenCalledTimes(2);

    doc.hidden = true;
    doc.dispatch('visibilitychange');
    await vi.advanceTimersByTimeAsync(5000);
    expect(fetchBadge).toHaveBeenCalledTimes(2);

    doc.hidden = false;
    doc.dispatch('visibilitychange');
    await vi.advanceTimersByTimeAsync(10);
    expect(fetchBadge).toHaveBeenCalledTimes(3);
    sync.stop();
  });

  it('backs off the poll after consecutive errors', async () => {
    const host = createHost();
    const { sync, fetchBadge } = createSync(host, { pollInterval: 1000, pollJitter: 0, pollBackoff: 60000, errorThreshold: 3, debounceMs: 10 });
    fetchBadge.mockRejectedValue(new Error('offline'));
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    sync.start();
    await vi.advanceTimersByTimeAsync(10);
    await vi.advanceTimersByTimeAsync(1010);
    await vi.advanceTimersByTimeAsync(1010);
    expect(fetchBadge).toHaveBeenCalledTimes(3);
    expect(sync.errorStreak).toBe(3);

    await vi.advanceTimersByTimeAsync(1010);
    expect(fetchBadge).toHaveBeenCalledTimes(3);
    await vi.advanceTimersByTimeAsync(60000);
    expect(fetchBadge).toHaveBeenCalledTimes(4);
    warn.mockRestore();
    sync.stop();
  });

  it('refreshes on foreign BroadcastChannel messages and ignores its own', async () => {
    const host = createHost();
    const { sync, channel, fetchBadge } = createSync(host);
    sync.start();
    await vi.advanceTimersByTimeAsync(200);
    fetchBadge.mockClear();

    channel.onmessage({ data: { type: 'refresh', reason: 'publish', workspaceId: 1, stamp: 'other', instanceId: sync.instanceId } });
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchBadge).not.toHaveBeenCalled();

    channel.onmessage({ data: { type: 'refresh', reason: 'publish', workspaceId: 1, stamp: 'other', instanceId: 'another-tab' } });
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchBadge).toHaveBeenCalledTimes(1);

    sync.broadcast('publish');
    expect(channel.postMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'refresh', reason: 'publish', instanceId: sync.instanceId }));
    sync.stop();
  });

  it('re-fetches an open list only when the stamp changed', async () => {
    const host = createHost();
    host._isDropdownOpen.mockReturnValue(true);
    const { sync } = createSync(host);

    sync.apply(payload({ stamp: 'one' }), { reason: 'connect' });
    expect(host._refresh).not.toHaveBeenCalled();
    sync.apply(payload({ stamp: 'one' }));
    expect(host._refresh).not.toHaveBeenCalled();
    sync.apply(payload({ stamp: 'two' }));
    expect(host._refresh).toHaveBeenCalledTimes(1);
    sync.apply(payload({ stamp: 'three' }), { refreshList: false });
    expect(host._refresh).toHaveBeenCalledTimes(1);
  });

  it('renders the count into the toolbar badge and hides the item in live', () => {
    document.body.innerHTML = `
      <li id="typo3-cms-backend-backend-toolbaritems-easyworkspacetoolbaritem" class="toolbar-item">
        <button class="dropdown-toggle"><span class="toolbar-item-badge badge hidden" data-wew-workspace-badge></span></button>
        <div class="dropdown-menu"></div>
      </li>`;
    const host = createHost({ withDom: true });
    document.querySelector('.dropdown-menu').append(host);
    const badge = document.querySelector('[data-wew-workspace-badge]');
    const toolbarItem = document.querySelector('.toolbar-item');
    const { sync } = createSync(host);

    sync.apply(payload({ changedCount: 4 }), { reason: 'connect' });
    expect(badge.textContent).toBe('4');
    expect(badge.hidden).toBe(false);
    expect(badge.getAttribute('aria-label')).toBe('4 pending');
    expect(toolbarItem.hidden).toBe(false);
    expect(badge.classList.contains('wew-badge--pulse')).toBe(false);

    sync.apply(payload({ changedCount: 6, stamp: 'b' }));
    expect(badge.textContent).toBe('6');
    expect(badge.classList.contains('wew-badge--pulse')).toBe(true);

    sync.apply(payload({ changedCount: 0, workspaceId: 0, stamp: 'c' }));
    expect(badge.textContent).toBe('');
    expect(badge.hidden).toBe(true);
    expect(toolbarItem.hidden).toBe(true);
    expect(toolbarItem.classList.contains('webcon-easy-workspace-toolbar--live')).toBe(true);
    document.body.innerHTML = '';
  });

  it('seeds itself from the server-rendered badge and keeps it when the request fails', async () => {
    document.body.innerHTML = `
      <li id="typo3-cms-backend-backend-toolbaritems-easyworkspacetoolbaritem" class="toolbar-item">
        <button class="dropdown-toggle">
          <span class="toolbar-item-badge badge" data-wew-workspace-badge data-wew-count="7" data-wew-workspace="3">7</span>
        </button>
        <div class="dropdown-menu"></div>
      </li>`;
    const host = createHost({ withDom: true, workspaceId: 0 });
    document.querySelector('.dropdown-menu').append(host);
    const badge = document.querySelector('[data-wew-workspace-badge]');
    const toolbarItem = document.querySelector('.toolbar-item');
    const { sync, fetchBadge } = createSync(host);
    fetchBadge.mockRejectedValue(new Error('offline'));

    sync.start();
    // The server value survives the first paint …
    expect(host.badgeCount).toBe(7);
    expect(badge.textContent).toBe('7');
    expect(toolbarItem.hidden).toBe(false);

    // … and a failing request never blanks it or hides the item.
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchBadge).toHaveBeenCalled();
    expect(badge.textContent).toBe('7');
    expect(toolbarItem.hidden).toBe(false);

    sync.stop();
    document.body.innerHTML = '';
  });

  it('tells the other tabs when it noticed a change, without bouncing it back', async () => {
    const host = createHost();
    const { sync, channel } = createSync(host);
    sync.start(); // opens the channel
    sync.apply(payload({ changedCount: 1, stamp: 'one' }), { reason: 'connect' });
    channel.postMessage.mockClear();

    // A change this tab noticed itself is announced once.
    sync.apply(payload({ changedCount: 2, stamp: 'two' }), { reason: 'typo3-module-loaded' });
    expect(channel.postMessage).toHaveBeenCalledTimes(1);
    expect(channel.postMessage.mock.calls[0][0]).toMatchObject({ type: 'refresh', stamp: 'two' });

    // A change learned from another tab is not echoed back.
    channel.postMessage.mockClear();
    sync.apply(payload({ changedCount: 3, stamp: 'three' }), { reason: 'channel:discard' });
    expect(channel.postMessage).not.toHaveBeenCalled();

    // Neither is a response that changed nothing.
    sync.apply(payload({ changedCount: 3, stamp: 'three' }), { reason: 'poll' });
    expect(channel.postMessage).not.toHaveBeenCalled();
  });

  it('refreshes when the module iframe finished loading (classic FormEngine save)', async () => {
    const host = createHost();
    const { sync, doc, fetchBadge } = createSync(host);
    sync.start();
    fetchBadge.mockClear();

    doc.dispatch('typo3-module-loaded');
    await vi.advanceTimersByTimeAsync(200);

    expect(fetchBadge).toHaveBeenCalledTimes(1);
  });

  it('listens inside the module iframe and re-attaches on every module load', async () => {
    const host = createHost();
    const frameDoc = createDoc();
    const topDoc = createDoc();
    topDoc.querySelector = () => ({ contentDocument: frameDoc });
    const { sync, fetchBadge } = createSync(host, { topDocument: topDoc });
    sync.start();
    fetchBadge.mockClear();

    // An event Core dispatches on the module's own document only.
    frameDoc.dispatch('typo3:datahandler:process');
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchBadge).toHaveBeenCalledTimes(1);

    // After a module swap the listeners are attached exactly once, not twice.
    topDoc.dispatch('typo3-module-loaded');
    await vi.advanceTimersByTimeAsync(200);
    fetchBadge.mockClear();
    frameDoc.dispatch('typo3:pagetree:refresh');
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchBadge).toHaveBeenCalledTimes(1);

    sync.stop();
  });
});

describe('normalizeBadgePayload', () => {
  it('coerces loose server values into a stable shape', () => {
    expect(normalizeBadgePayload({ changedCount: '4', workspaceId: '2', byState: { new: '1' }, byTable: { pages: 4 } })).toEqual({
      workspaceId: 2,
      workspaceTitle: '',
      changedCount: 4,
      contextCount: null,
      stamp: '',
      latestChangeAt: 0,
      byTable: { pages: 4 },
      byState: { new: 1, changed: 0, deleted: 0, moved: 0 },
    });
    expect(normalizeBadgePayload(null)).toBeNull();
    expect(normalizeBadgePayload({ changedCount: -3 }).changedCount).toBe(0);
    expect(normalizeBadgePayload({ changedCount: 9, contextCount: '2' }).contextCount).toBe(2);
    expect(normalizeBadgePayload({ changedCount: 9, contextCount: 0 }).contextCount).toBe(0);
  });
});

describe('BadgeSync badge number', () => {
  it('shows the context count and falls back to the workspace total', () => {
    const host = createHost();
    const sync = new BadgeSync(host, { window: window, document, topDocument: document, topWindow: null });

    sync.count = 7;
    sync.contextCount = null;
    expect(sync.badgeNumber()).toBe(7);

    sync.contextCount = 2;
    expect(sync.badgeNumber()).toBe(2);

    sync.contextCount = 0;
    expect(sync.badgeNumber()).toBe(0);
  });
});
