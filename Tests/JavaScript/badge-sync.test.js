import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BadgeSync, normalizeBadgePayload } from '@webconsulting/webcon-easy-workspace/menu-badge.js';
import { CHANGE_EVENTS, DEFAULT_CONFIG, NAVIGATION_EVENTS } from '@webconsulting/webcon-easy-workspace/menu-constants.js';

function payload(overrides = {}) {
  return {
    context: 'page',
    workspaceId: 1,
    workspaceTitle: 'Staging',
    contextCount: 2,
    records: [{ table: 'tt_content', liveUid: 10, workspaceUid: 11 }],
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

function createHost({ items = changedItems(5), workspaceId = 1, withDom = false, pageUid = 7 } = {}) {
  const host = withDom ? document.createElement('webcon-easy-workspace-menu-v2') : {};
  Object.assign(host, {
    items,
    badgeCount: 0,
    workspaceId,
    workspaceTitle: '',
    _config: { ...DEFAULT_CONFIG, activeWorkspaceId: workspaceId, pageUid, labels: { ...DEFAULT_CONFIG.labels, 'toolbar.badge.pending': '{count, plural, one {# pending} other {# pending}}' } },
    requestUpdate: vi.fn(),
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
  const fetchList = vi.fn(async () => payload());
  const sync = new BadgeSync(host, {
    window,
    document: doc,
    topDocument: null,
    topWindow: null,
    fetchBadge,
    fetchList,
    createChannel: () => channel,
    initialWorkspaceId: host.workspaceId,
    ...overrides,
  });
  return { sync, doc, channel, fetchBadge, fetchList };
}

/**
 * Started sync whose connect request is already done.
 */
async function startedSync(host = createHost(), overrides = {}) {
  const context = createSync(host, overrides);
  context.sync.start();
  await vi.advanceTimersByTimeAsync(200);
  context.fetchBadge.mockClear();
  context.fetchList.mockClear();
  return { ...context, host };
}

function requests(context) {
  return context.fetchBadge.mock.calls.length + context.fetchList.mock.calls.length;
}

function postWindowMessage(data, origin = window.location.origin) {
  window.dispatchEvent(new MessageEvent('message', { data, origin }));
}

describe('BadgeSync trigger matrix', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('asks once when it connects', async () => {
    const { sync, fetchBadge } = createSync(createHost());
    sync.start();
    await vi.advanceTimersByTimeAsync(200);

    expect(fetchBadge).toHaveBeenCalledTimes(1);
    expect(fetchBadge.mock.calls[0][0]).toMatchObject({ pageUid: 7 });
    sync.stop();
  });

  it.each(CHANGE_EVENTS)('asks once after the Core event %s', async (eventName) => {
    const context = await startedSync();

    context.doc.dispatch(eventName);
    await vi.advanceTimersByTimeAsync(200);

    expect(requests(context)).toBe(1);
    context.sync.stop();
  });

  it.each([
    ['a Visual Editor save (wew-refresh, any origin)', { type: 'wew-refresh', reason: 've-save' }, 'https://frontend.example'],
    ['a FormEngine save', { actionName: 'typo3:editform:saved' }, undefined],
    ['a ve_saveEnded message', { command: 've_saveEnded' }, undefined],
  ])('asks once after %s', async (_name, data, origin) => {
    const context = await startedSync();

    postWindowMessage(data, origin ?? window.location.origin);
    await vi.advanceTimersByTimeAsync(200);

    expect(requests(context)).toBe(1);
    context.sync.stop();
  });

  it('asks once after another tab announced a change it cannot hand over', async () => {
    const context = await startedSync();

    context.channel.onmessage({ data: { type: 'refresh', reason: 'publish', workspaceId: 1, stamp: 'other', instanceId: 'another-tab', contextKey: 'page:99' } });
    await vi.advanceTimersByTimeAsync(200);

    expect(requests(context)).toBe(1);
    context.sync.stop();
  });

  it('never asks while the editor is idle — no poll', async () => {
    const context = await startedSync();

    await vi.advanceTimersByTimeAsync(30 * 60 * 1000);

    expect(requests(context)).toBe(0);
    context.sync.stop();
  });

  it('never asks on focus, blur or clicks', async () => {
    const context = await startedSync();

    for (let i = 0; i < 20; i++) {
      window.dispatchEvent(new Event('focus'));
      window.dispatchEvent(new Event('blur'));
      document.dispatchEvent(new Event('click'));
      await vi.advanceTimersByTimeAsync(3500);
    }

    expect(requests(context)).toBe(0);
    context.sync.stop();
  });

  it('never asks when the tab is hidden and shown again', async () => {
    const context = await startedSync();

    context.doc.hidden = true;
    context.doc.dispatch('visibilitychange');
    await vi.advanceTimersByTimeAsync(10 * 60 * 1000);
    context.doc.hidden = false;
    context.doc.dispatch('visibilitychange');
    await vi.advanceTimersByTimeAsync(1000);

    expect(requests(context)).toBe(0);
    context.sync.stop();
  });

  it.each([
    ['a save message from a foreign origin', { actionName: 'typo3:editform:saved' }, 'https://evil.example'],
    ['an unrelated message', { hello: 'world' }, undefined],
  ])('ignores %s', async (_name, data, origin) => {
    const context = await startedSync();

    postWindowMessage(data, origin ?? window.location.origin);
    await vi.advanceTimersByTimeAsync(200);

    expect(requests(context)).toBe(0);
    context.sync.stop();
  });

  it('ignores its own channel messages and those carrying the stamp it already has', async () => {
    const context = await startedSync();

    context.channel.onmessage({ data: { type: 'refresh', reason: 'publish', stamp: 'x', instanceId: context.sync.instanceId } });
    context.channel.onmessage({ data: { type: 'refresh', reason: 'publish', stamp: 'stamp-a', instanceId: 'another-tab' } });
    await vi.advanceTimersByTimeAsync(200);

    expect(requests(context)).toBe(0);
    context.sync.stop();
  });

  it.each(NAVIGATION_EVENTS)('ignores %s while the page stays the same', async (eventName) => {
    const context = await startedSync();

    context.doc.dispatch(eventName);
    await vi.advanceTimersByTimeAsync(5000);

    expect(requests(context)).toBe(0);
    context.sync.stop();
  });

  it('asks once per navigation: the module load after a page-tree click joins it', async () => {
    const context = await startedSync();

    context.host._config.pageUid = 8;
    context.doc.dispatch('typo3:module-state-storage:update:web');
    context.doc.dispatch('typo3:module-state-storage:update-with-tree-identifier:web');
    await vi.advanceTimersByTimeAsync(600); // the module is still loading
    expect(requests(context)).toBe(0);
    context.doc.dispatch('typo3-module-loaded');
    await vi.advanceTimersByTimeAsync(5000);

    expect(context.fetchBadge).toHaveBeenCalledTimes(1);
    expect(context.fetchBadge.mock.calls[0][0]).toMatchObject({ pageUid: 8 });
    context.sync.stop();
  });

  it('asks after a pause when a navigation loads no module (Visual Editor page change)', async () => {
    const context = await startedSync(createHost(), { settleMs: 1500 });

    context.host._config.pageUid = 9;
    context.doc.dispatch('typo3:module-state-storage:update:web');
    await vi.advanceTimersByTimeAsync(1400);
    expect(requests(context)).toBe(0);
    await vi.advanceTimersByTimeAsync(300);

    expect(context.fetchBadge).toHaveBeenCalledTimes(1);
    expect(context.fetchBadge.mock.calls[0][0]).toMatchObject({ pageUid: 9 });
    context.sync.stop();
  });

  it('asks when a preview reloads on another page, not when it reloads the same one', async () => {
    const context = await startedSync();

    context.sync.checkContext('preview');
    await vi.advanceTimersByTimeAsync(200);
    expect(requests(context)).toBe(0);

    context.host._config.pageUid = 12;
    context.sync.checkContext('preview');
    await vi.advanceTimersByTimeAsync(200);
    expect(requests(context)).toBe(1);
    context.sync.stop();
  });
});

describe('BadgeSync requests', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('turns the signal burst of one Visual Editor save into exactly one request', async () => {
    const context = await startedSync();

    postWindowMessage({ type: 'wew-refresh', reason: 've-save' }, 'https://frontend.example');
    postWindowMessage({ command: 've_saveEnded' });
    context.doc.dispatch('typo3:pagetree:refresh');
    context.doc.dispatch('typo3:datahandler:process');
    context.channel.onmessage({ data: { type: 'refresh', reason: 've-save', stamp: '', instanceId: 've-preview' } });
    await vi.advanceTimersByTimeAsync(5000);

    expect(requests(context)).toBe(1);
    context.sync.stop();
  });

  it('shows the new count well within a second of a save', async () => {
    document.body.innerHTML = `
      <li id="typo3-cms-backend-backend-toolbaritems-easyworkspacetoolbaritem" class="toolbar-item">
        <button class="dropdown-toggle"><span class="toolbar-item-badge badge" data-wew-workspace-badge data-wew-count="2" data-wew-workspace="1">2</span></button>
        <div class="dropdown-menu"></div>
      </li>`;
    const host = createHost({ withDom: true });
    document.querySelector('.dropdown-menu').append(host);
    const context = await startedSync(host);
    context.fetchBadge.mockImplementation(async () => {
      await new Promise((resolve) => setTimeout(resolve, 300)); // server time
      return payload({ contextCount: 3, stamp: 'after-save' });
    });

    postWindowMessage({ type: 'wew-refresh', reason: 've-save' });
    await vi.advanceTimersByTimeAsync(1000);

    expect(document.querySelector('[data-wew-workspace-badge]').textContent).toBe('3');
    context.sync.stop();
    document.body.innerHTML = '';
  });

  it('keeps one request in flight; triggers meanwhile collapse into one follow-up whose answer wins', async () => {
    const context = await startedSync();
    const resolvers = [];
    context.fetchBadge.mockImplementation(() => new Promise((resolve) => { resolvers.push(resolve); }));

    context.doc.dispatch('typo3:datahandler:process');
    await vi.advanceTimersByTimeAsync(200);
    expect(context.fetchBadge).toHaveBeenCalledTimes(1);

    // Three more saves while the first answer is outstanding …
    context.doc.dispatch('typo3:datahandler:process');
    postWindowMessage({ type: 'wew-refresh' });
    context.doc.dispatch('typo3-module-loaded');
    await vi.advanceTimersByTimeAsync(2000);
    expect(context.fetchBadge).toHaveBeenCalledTimes(1);

    // … become exactly one follow-up once it lands.
    resolvers[0](payload({ contextCount: 9, stamp: 'first' }));
    await vi.advanceTimersByTimeAsync(0);
    expect(context.fetchBadge).toHaveBeenCalledTimes(2);
    resolvers[1](payload({ contextCount: 4, stamp: 'second' }));
    await vi.advanceTimersByTimeAsync(2000);

    expect(context.fetchBadge).toHaveBeenCalledTimes(2);
    expect(context.host.contextCount).toBe(4);
    expect(context.sync.stamp).toBe('second');
    context.sync.stop();
  });

  it('drops a follow-up that a channel message asked for when the answer already carries its stamp', async () => {
    const context = await startedSync();
    let resolveFirst;
    context.fetchBadge.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }));

    context.doc.dispatch('typo3:datahandler:process');
    await vi.advanceTimersByTimeAsync(200);
    context.channel.onmessage({ data: { type: 'refresh', reason: 've-save', stamp: 'shared', instanceId: 'another-tab', contextKey: 'page:99' } });
    resolveFirst(payload({ stamp: 'shared' }));
    await vi.advanceTimersByTimeAsync(2000);

    expect(context.fetchBadge).toHaveBeenCalledTimes(1);
    context.sync.stop();
  });

  it('discards the answer of a request that was in flight when it stopped', async () => {
    const context = await startedSync();
    let resolveFirst;
    context.fetchBadge.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }));

    context.doc.dispatch('typo3:datahandler:process');
    await vi.advanceTimersByTimeAsync(200);
    context.sync.stop();
    resolveFirst(payload({ contextCount: 42, stamp: 'late' }));
    await vi.advanceTimersByTimeAsync(0);

    expect(context.host.contextCount).not.toBe(42);
  });

  it('does not retry a failed request on its own', async () => {
    const context = await startedSync();
    context.fetchBadge.mockRejectedValue(new Error('offline'));
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

    context.doc.dispatch('typo3:datahandler:process');
    await vi.advanceTimersByTimeAsync(10 * 60 * 1000);

    expect(context.fetchBadge).toHaveBeenCalledTimes(1);
    expect(context.sync.errorStreak).toBe(1);
    warn.mockRestore();
    context.sync.stop();
  });

  it('fetches the list instead of /badge when the list is wanted — one request for both', async () => {
    const context = await startedSync();

    await Promise.all([
      context.sync.request('open', { list: true }),
      vi.advanceTimersByTimeAsync(200),
    ]);
    expect(context.fetchList).toHaveBeenCalledTimes(1);
    expect(context.fetchBadge).not.toHaveBeenCalled();

    // While the dropdown is open, a save refreshes the open list — and the badge with it.
    context.host._isDropdownOpen.mockReturnValue(true);
    context.doc.dispatch('typo3:datahandler:process');
    await vi.advanceTimersByTimeAsync(200);
    expect(context.fetchList).toHaveBeenCalledTimes(2);
    expect(context.fetchBadge).not.toHaveBeenCalled();
    context.sync.stop();
  });

  it('resolves request() once the covering answer is applied', async () => {
    const context = await startedSync();
    context.fetchList.mockResolvedValue(payload({ contextCount: 6, stamp: 'list' }));

    const done = context.sync.request('edit-saved', { list: true });
    await vi.advanceTimersByTimeAsync(200);
    await done;

    expect(context.host.contextCount).toBe(6);
    context.sync.stop();
  });
});

describe('BadgeSync across tabs', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('adopts the payload of a tab on the same page instead of asking the server', async () => {
    const context = await startedSync();

    context.channel.onmessage({
      data: {
        type: 'refresh',
        reason: 've-save',
        workspaceId: 1,
        stamp: 'from-other-tab',
        instanceId: 'another-tab',
        contextKey: 'page:7',
        payload: payload({ contextCount: 5, stamp: 'from-other-tab' }),
      },
    });
    await vi.advanceTimersByTimeAsync(2000);

    expect(requests(context)).toBe(0);
    expect(context.host.contextCount).toBe(5);
    // Learned from another tab: not echoed back.
    expect(context.channel.postMessage).not.toHaveBeenCalled();
    context.sync.stop();
  });

  it('asks for its own count when the announcing tab is on another page', async () => {
    const context = await startedSync();

    context.channel.onmessage({
      data: {
        type: 'refresh',
        reason: 've-save',
        workspaceId: 1,
        stamp: 'from-other-tab',
        instanceId: 'another-tab',
        contextKey: 'page:8',
        payload: payload({ contextCount: 5, stamp: 'from-other-tab' }),
      },
    });
    await vi.advanceTimersByTimeAsync(200);

    expect(context.fetchBadge).toHaveBeenCalledTimes(1);
    context.sync.stop();
  });

  it('tells the other tabs when it noticed a change — with the payload — without bouncing it back', async () => {
    const host = createHost();
    const { sync, channel } = createSync(host);
    sync.start(); // opens the channel
    sync.apply(payload({ changedCount: 1, stamp: 'one' }), { reason: 'connect' });
    channel.postMessage.mockClear();

    // A change this tab noticed itself is announced once, with its answer.
    sync.apply(payload({ changedCount: 2, stamp: 'two' }), { reason: 'typo3-module-loaded' });
    expect(channel.postMessage).toHaveBeenCalledTimes(1);
    expect(channel.postMessage.mock.calls[0][0]).toMatchObject({
      type: 'refresh',
      stamp: 'two',
      contextKey: 'page:7',
      payload: expect.objectContaining({ changedCount: 2, stamp: 'two' }),
    });

    // A change learned from another tab is not echoed back.
    channel.postMessage.mockClear();
    sync.apply(payload({ changedCount: 3, stamp: 'three' }), { reason: 'channel:discard' });
    expect(channel.postMessage).not.toHaveBeenCalled();

    // Neither is a response that changed nothing.
    sync.apply(payload({ changedCount: 3, stamp: 'three' }), { reason: 'typo3:datahandler:process' });
    expect(channel.postMessage).not.toHaveBeenCalled();
    sync.stop();
  });
});

describe('BadgeSync rendering', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('applies the server count instead of the client list count', async () => {
    const host = createHost({ items: changedItems(5) });
    const { sync, fetchBadge } = createSync(host);
    fetchBadge.mockResolvedValue(payload({ changedCount: 3, contextCount: null }));

    sync.start();
    await vi.advanceTimersByTimeAsync(200);

    expect(host.items.filter((item) => item.isChanged)).toHaveLength(5);
    expect(host.badgeCount).toBe(3);
    expect(sync.stamp).toBe('stamp-a');
    expect(host.workspaceTitle).toBe('Staging');
    expect(host.changedRecords).toEqual([{ table: 'tt_content', liveUid: 10, workspaceUid: 11 }]);
    sync.stop();
  });

  it('refreshes an open list when the stamp moved, never a closed one', async () => {
    const host = createHost();
    const { sync, fetchList } = createSync(host);
    sync.start();
    await vi.advanceTimersByTimeAsync(200);
    fetchList.mockClear();

    sync.apply(payload({ stamp: 'one' }), { reason: 'publish' });
    sync.apply(payload({ stamp: 'two' }), { reason: 'publish' });
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchList).not.toHaveBeenCalled();

    host._isDropdownOpen.mockReturnValue(true);
    sync.apply(payload({ stamp: 'two' }), { reason: 'publish' });
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchList).not.toHaveBeenCalled();
    sync.apply(payload({ stamp: 'three' }), { reason: 'channel:publish' });
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchList).toHaveBeenCalledTimes(1);
    sync.apply(payload({ stamp: 'four' }), { reason: 'publish', refreshList: false });
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchList).toHaveBeenCalledTimes(1);
    sync.stop();
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

    sync.apply(payload({ changedCount: 4, contextCount: null }), { reason: 'connect' });
    expect(badge.textContent).toBe('4');
    expect(badge.hidden).toBe(false);
    expect(badge.getAttribute('aria-label')).toBe('4 pending');
    expect(toolbarItem.hidden).toBe(false);
    expect(badge.classList.contains('wew-badge--pulse')).toBe(false);

    sync.apply(payload({ changedCount: 6, contextCount: null, stamp: 'b' }));
    expect(badge.textContent).toBe('6');
    expect(badge.classList.contains('wew-badge--pulse')).toBe(true);

    sync.apply(payload({ changedCount: 0, contextCount: null, workspaceId: 0, stamp: 'c' }));
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
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

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

    warn.mockRestore();
    sync.stop();
    document.body.innerHTML = '';
  });

  it('listens inside the module iframe and re-attaches on every module load', async () => {
    const host = createHost();
    const frameDoc = createDoc();
    const topDoc = createDoc();
    topDoc.querySelector = () => ({ contentDocument: frameDoc });
    const context = await startedSync(host, { topDocument: topDoc });

    // An event Core dispatches on the module's own document only.
    frameDoc.dispatch('typo3:datahandler:process');
    await vi.advanceTimersByTimeAsync(200);
    expect(context.fetchBadge).toHaveBeenCalledTimes(1);

    // After a module swap the listeners are attached exactly once, not twice.
    topDoc.dispatch('typo3-module-loaded');
    await vi.advanceTimersByTimeAsync(200);
    context.fetchBadge.mockClear();
    frameDoc.dispatch('typo3:pagetree:refresh');
    await vi.advanceTimersByTimeAsync(200);
    expect(context.fetchBadge).toHaveBeenCalledTimes(1);

    context.sync.stop();
  });
});

describe('normalizeBadgePayload', () => {
  it('coerces loose server values into a stable shape', () => {
    expect(normalizeBadgePayload({ changedCount: '4', workspaceId: '2', byState: { new: '1' }, byTable: { pages: 4 } })).toEqual({
      workspaceId: 2,
      workspaceTitle: '',
      changedCount: 4,
      contextCount: null,
      records: null,
      stamp: '',
      latestChangeAt: 0,
      byTable: { pages: 4 },
      byState: { new: 1, changed: 0, deleted: 0, moved: 0 },
    });
    expect(normalizeBadgePayload(null)).toBeNull();
    expect(normalizeBadgePayload({ changedCount: -3 }).changedCount).toBe(0);
    expect(normalizeBadgePayload({ changedCount: 9, contextCount: '2' }).contextCount).toBe(2);
    expect(normalizeBadgePayload({ changedCount: 9, contextCount: 0 }).contextCount).toBe(0);
    expect(normalizeBadgePayload({ records: [{ table: 'pages', liveUid: '3', workspaceUid: 4 }, null, { liveUid: 1 }] }).records)
      .toEqual([{ table: 'pages', liveUid: 3, workspaceUid: 4 }]);
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
