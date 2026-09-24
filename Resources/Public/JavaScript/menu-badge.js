import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import {
  ENDPOINTS,
  CHANNEL_NAME,
  BADGE_DEBOUNCE_MS,
  BADGE_NAVIGATION_SETTLE_MS,
  CHANGE_EVENTS,
  NAVIGATION_EVENTS,
  MODULE_IFRAME_SELECTOR,
} from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import { detectContext, label } from '@webconsulting/webcon-easy-workspace/menu-context.js';

/**
 * BadgeSync — the ONLY writer of `host.badgeCount` and `host.contextCount`,
 * and the only place that decides when the toolbar talks to the server.
 *
 * The server (`/badge`, and the `badge` block of items, publish and discard
 * responses) is the single source of truth: `contextCount` for the page or
 * news article the editor is on (what the badge shows), `changedCount` for
 * the whole workspace, `records` for the changed rows of the page, and a
 * `stamp` that moves with every write that can change a count.
 *
 * Event-driven, never periodic. A request goes out only when
 *  - the element connects,
 *  - something was saved: Core's DataHandler/page-tree/workspace events, a
 *    FormEngine save, a Visual Editor save (`wew-refresh`, bridged by
 *    visual-editor-decline-button.js from VE's `ve_saveEnded`), a module
 *    finishing to load (the only trace a classic FormEngine save leaves),
 *  - the editor moved to another page or news article,
 *  - another tab of this browser announced a change it could not hand over,
 *  - the toolbar itself needs its list (dropdown open, refresh button, after
 *    its own publish/discard/edit).
 * Focus, visibility and time never cause one. Changes by other editors show
 * up with the next navigation or save.
 *
 * Every trigger funnels through one debounce, and at most one request is in
 * flight; triggers arriving meanwhile collapse into a single follow-up. Each
 * run fetches either `/badge` or — when the list is wanted — the list, whose
 * response carries the same badge block, so a burst of signals (a VE save
 * emits several) costs exactly one request.
 */
export class BadgeSync {
  constructor(host, options = {}) {
    this.host = host;
    this.win = options.window ?? window;
    this.doc = options.document ?? this.win.document;
    this.topDoc = options.topDocument ?? safeTopDocument(this.win);
    this.topWin = options.topWindow ?? safeTopWindow(this.win);
    this.fetchBadge = options.fetchBadge ?? fetchBadgePayload;
    this.fetchList = options.fetchList ?? null;
    this.createChannel = options.createChannel ?? createBroadcastChannel;
    this.instanceId = options.instanceId ?? randomId();
    this.debounceMs = options.debounceMs ?? BADGE_DEBOUNCE_MS;
    this.settleMs = options.settleMs ?? BADGE_NAVIGATION_SETTLE_MS;

    this.count = 0;
    this.contextCount = null;
    this.records = null;
    this.workspaceId = Math.max(0, Number(options.initialWorkspaceId) || 0);
    this.workspaceTitle = '';
    this.stamp = '';
    this.byState = { new: 0, changed: 0, deleted: 0, moved: 0 };
    this.byTable = {};
    this.latestChangeAt = 0;

    this.requestId = 0;
    this.errorStreak = 0;
    this.pending = null; // { reason, list, stamp, waiters } — collected by the debounce
    this.followUp = null; // same shape — collected while a request is in flight
    this.inFlight = null;
    this.debounceTimer = null;
    this.settleTimer = null;
    this.lastContextKey = null;
    this.controller = null;
    this.frameController = null;
    this.channel = null;
    this.started = false;
  }

  /**
   * Adopt the count the server rendered into the toolbar markup.
   *
   * Without this the first paint of every backend page would blank a
   * correct badge until the first response arrives — and keep it blank (and
   * the toolbar item hidden) when that request fails.
   */
  seed() {
    const badge = toolbarBadgeElement(this.host);
    if (!badge) return;
    const count = parseInt(badge.getAttribute('data-wew-count') ?? '', 10);
    const workspaceId = parseInt(badge.getAttribute('data-wew-workspace') ?? '', 10);
    // The server renders the page-scoped count (it only knows a page when
    // the backend URL carries ?id=), so seed the context count from it and
    // leave the workspace total to the first response.
    if (Number.isFinite(count) && count >= 0) {
      this.count = count;
      this.contextCount = count > 0 ? count : null;
    }
    if (Number.isFinite(workspaceId) && workspaceId >= 0) this.workspaceId = workspaceId;
    this.host.badgeCount = this.count;
    this.host.contextCount = this.contextCount;
    this.host.workspaceId = this.workspaceId;
  }

  start() {
    if (this.started) return;
    this.started = true;
    this.controller = new AbortController();
    const options = { signal: this.controller.signal };

    // 1. Core document events (top frame + own frame, deduplicated) — and
    //    the same events inside the module iframe, re-attached every time a
    //    module finishes loading.
    for (const targetDocument of new Set([this.doc, this.topDoc].filter(Boolean))) {
      this.listen(targetDocument, options);
      try {
        targetDocument.addEventListener('typo3-module-loaded', () => this.attachFrame(), options);
      } catch { /* cross-origin top document */ }
    }
    this.attachFrame();

    // 2. Save signals delivered as window messages (FormEngine modals post
    //    to the top window; our Visual Editor script bridges VE's save end
    //    as `wew-refresh`).
    for (const targetWindow of new Set([this.win, this.topWin].filter(Boolean))) {
      try {
        targetWindow.addEventListener('message', (event) => this.onWindowMessage(event), options);
      } catch { /* cross-origin */ }
    }

    // 3. Same-origin BroadcastChannel: other tabs of this browser.
    this.channel = this.createChannel(CHANNEL_NAME);
    if (this.channel) {
      this.channel.onmessage = (event) => this.onChannelMessage(event);
    }

    this.seed();
    this.render();
    this.syncVisibility();
    this.request('connect');
  }

  /**
   * Attach the change and navigation events to one document.
   */
  listen(targetDocument, options) {
    for (const eventName of CHANGE_EVENTS) {
      try {
        targetDocument.addEventListener(eventName, () => this.request(eventName), options);
      } catch { /* cross-origin document */ }
    }
    for (const eventName of NAVIGATION_EVENTS) {
      try {
        targetDocument.addEventListener(eventName, () => this.navigate(eventName), options);
      } catch { /* cross-origin document */ }
    }
  }

  /**
   * (Re-)attach to the module iframe's document. Core's modules dispatch
   * their events on their own `document`; without this the toolbar never
   * sees a publish or discard done inside the Workspaces module.
   */
  attachFrame() {
    this.frameController?.abort();
    this.frameController = null;
    if (!this.started) return;

    let frameDocument = null;
    try {
      const iframe = (this.topDoc ?? this.doc)?.querySelector?.(MODULE_IFRAME_SELECTOR);
      frameDocument = iframe?.contentDocument ?? null;
    } catch { /* foreign origin */ }
    if (!frameDocument || frameDocument === this.doc) return;

    this.frameController = new AbortController();
    this.listen(frameDocument, { signal: this.frameController.signal });
  }

  stop() {
    this.started = false;
    this.controller?.abort();
    this.controller = null;
    this.frameController?.abort();
    this.frameController = null;
    this.cancelDebounce();
    this.cancelSettle();
    try { this.channel?.close(); } catch { /* already closed */ }
    this.channel = null;
    this.requestId += 1; // invalidate the in-flight response
    for (const batch of [this.pending, this.followUp]) {
      batch?.waiters.forEach((resolve) => resolve());
    }
    this.pending = null;
    this.followUp = null;
  }

  /**
   * Debounced entry point for every trigger. Resolves once the request that
   * covers this trigger has been applied (or failed).
   *
   * @param {string} reason
   * @param {{list?: boolean, stamp?: string}} options `list` asks for the
   *        dropdown list as well; `stamp` is the workspace stamp a channel
   *        message announced (a follow-up is dropped when the response
   *        already carries it).
   * @returns {Promise<void>}
   */
  request(reason = 'manual', { list = false, stamp = '' } = {}) {
    if (!this.started) return Promise.resolve();
    this.cancelSettle();
    return new Promise((resolve) => {
      if (this.inFlight) {
        this.followUp = mergeBatch(this.followUp, reason, list, stamp, resolve);
        return;
      }
      this.pending = mergeBatch(this.pending, reason, list, stamp, resolve);
      this.cancelDebounce();
      this.debounceTimer = this.win.setTimeout(() => {
        this.debounceTimer = null;
        const batch = this.pending;
        this.pending = null;
        if (batch) void this.run(batch);
      }, this.debounceMs);
    });
  }

  /**
   * A navigation event: only a different page or news article needs a new
   * count. The module that shows it is usually still loading, and its
   * `typo3-module-loaded` would ask again — so wait for that (it cancels
   * this timer through request()) and fall back to asking after a pause
   * for navigation that loads no module (the Visual Editor changing pages
   * inside its own frame).
   */
  navigate(reason = 'navigation') {
    if (!this.started || this.settleTimer !== null) return;
    if (this.contextKey() === this.lastContextKey) return;
    this.settleTimer = this.win.setTimeout(() => {
      this.settleTimer = null;
      void this.request(reason);
    }, this.settleMs);
  }

  /**
   * Ask now if the page or news article changed since the last request —
   * for signals that arrive when the new context is already known (a
   * preview frame finished loading).
   */
  checkContext(reason = 'context') {
    if (this.started && this.contextKey() !== this.lastContextKey) {
      void this.request(reason);
    }
  }

  /**
   * Fetch for one batch of triggers. Never more than one at a time.
   */
  async run(batch) {
    if (this.inFlight) {
      this.followUp = mergeBatches(this.followUp, batch);
      return this.inFlight;
    }
    const requestId = ++this.requestId;
    const contextKey = this.contextKey();
    this.lastContextKey = contextKey;
    const useList = batch.list || this.isDropdownOpen();
    const task = (async () => {
      try {
        const payload = useList && this.fetchList
          ? await this.fetchList({ reason: batch.reason })
          : await this.fetchBadge(this.query());
        if (requestId !== this.requestId) return; // stopped meanwhile
        this.errorStreak = 0;
        // A list response already is the fresh list.
        if (payload) this.apply(payload, { reason: batch.reason, refreshList: false, contextKey });
      } catch (error) {
        if (requestId !== this.requestId) return;
        this.errorStreak += 1;
        console.warn('[easy-workspace] badge request failed', error);
      }
    })();
    this.inFlight = task;
    try {
      await task;
    } finally {
      this.inFlight = null;
      batch.waiters.forEach((resolve) => resolve());
      const followUp = this.followUp;
      this.followUp = null;
      if (followUp && this.started) {
        const coveredByResponse = followUp.stamp !== '' && followUp.stamp === this.stamp && !followUp.list;
        if (coveredByResponse) {
          followUp.waiters.forEach((resolve) => resolve());
        } else {
          void this.run(followUp);
        }
      } else {
        followUp?.waiters.forEach((resolve) => resolve());
      }
    }
    return undefined;
  }

  /**
   * Apply a server payload (from /badge, a list response, a channel message
   * or embedded in publish/discard responses). Writes host.badgeCount,
   * updates the DOM badge and the toolbar visibility, and — when the stamp
   * moved — refreshes an open list and tells the other tabs.
   */
  apply(payload, { reason = 'response', refreshList = true, contextKey = null } = {}) {
    const next = normalizeBadgePayload(payload);
    if (!next) return;
    const previousCount = this.badgeNumber();
    const previousStamp = this.stamp;

    this.count = next.changedCount;
    this.contextCount = next.contextCount;
    this.records = next.records;
    this.workspaceId = next.workspaceId;
    this.stamp = next.stamp;
    this.byState = next.byState;
    this.byTable = next.byTable;
    this.latestChangeAt = next.latestChangeAt;
    if (next.workspaceTitle) this.workspaceTitle = next.workspaceTitle;
    // The page this answer belongs to: the one asked for, or — for answers
    // that come with a response of the toolbar's own action or from another
    // tab on the same page — the current one.
    this.lastContextKey = contextKey ?? this.contextKey();

    this.host.badgeCount = this.count;
    this.host.contextCount = this.contextCount;
    this.host.changedRecords = this.records;
    this.host.workspaceId = this.workspaceId;
    if (next.workspaceTitle) this.host.workspaceTitle = next.workspaceTitle;

    this.render({ pulse: this.badgeNumber() > previousCount && reason !== 'connect' });
    this.syncVisibility();

    const stampChanged = previousStamp !== '' && previousStamp !== this.stamp;
    if (stampChanged && refreshList && this.isDropdownOpen()) {
      void this.request('list-stale', { list: true });
    }
    // Whoever noticed a change first tells the other tabs — with the payload,
    // so a tab on the same page adopts it without asking the server. A tab
    // that already holds this stamp ignores the message, so this cannot
    // bounce back and forth.
    if (stampChanged && !String(reason).startsWith('channel:')) {
      this.broadcast(reason, next);
    }
    this.host.requestUpdate?.();
    this.host.dispatchEvent?.(new CustomEvent('wew:badge', { detail: { ...next, reason } }));
  }

  /**
   * Tell other tabs/frames that this instance saw the workspace change.
   */
  broadcast(reason = 'change', payload = null) {
    try {
      this.channel?.postMessage({
        type: 'refresh',
        reason,
        workspaceId: this.workspaceId,
        stamp: this.stamp,
        instanceId: this.instanceId,
        contextKey: this.contextKey(),
        payload,
      });
    } catch { /* channel closed */ }
  }

  onChannelMessage(event) {
    const message = event?.data;
    if (!message || message.type !== 'refresh' || message.instanceId === this.instanceId) return;
    const stamp = typeof message.stamp === 'string' ? message.stamp : '';
    if (stamp !== '' && stamp === this.stamp) return;
    const reason = `channel:${message.reason || 'unknown'}`;
    // Same page, same workspace: the other tab's answer is ours as well.
    if (message.payload && typeof message.contextKey === 'string'
      && message.contextKey === this.contextKey()
      && Number(message.workspaceId) === this.workspaceId
    ) {
      this.apply(message.payload, { reason });
      return;
    }
    void this.request(reason, { stamp });
  }

  onWindowMessage(event) {
    const data = event?.data;
    if (!data || typeof data !== 'object') return;
    if (data.type === 'wew-refresh') {
      void this.request(`message:${data.reason || 'refresh'}`);
      return;
    }
    if (!isSameOrigin(event, this.win)) return;
    if (data.actionName === 'typo3:editform:saved' || data.command === 've_saveEnded') {
      void this.request(`message:${data.actionName || data.command}`);
    }
  }

  cancelDebounce() {
    if (this.debounceTimer !== null) {
      this.win.clearTimeout(this.debounceTimer);
      this.debounceTimer = null;
    }
  }

  cancelSettle() {
    if (this.settleTimer !== null) {
      this.win.clearTimeout(this.settleTimer);
      this.settleTimer = null;
    }
  }

  query() {
    const { pageUid, newsUid } = detectContext(this.host);
    const query = { _: Date.now() };
    if (newsUid > 0) query.newsUid = newsUid;
    else if (pageUid > 0) query.pageUid = pageUid;
    return query;
  }

  /**
   * Identity of the page or news article the editor is on.
   */
  contextKey() {
    const { pageUid, newsUid } = detectContext(this.host);
    if (newsUid > 0) return `news:${newsUid}`;
    return pageUid > 0 ? `page:${pageUid}` : 'none';
  }

  isDropdownOpen() {
    return Boolean(this.host._isDropdownOpen?.());
  }

  /**
   * What the toolbar badge shows: the changes of the page or news article
   * the editor is on. Without a resolvable context (a module outside the
   * Web group) there is no page to count, so the workspace total stands in.
   */
  badgeNumber() {
    return this.contextCount === null ? this.count : this.contextCount;
  }

  render({ pulse = false } = {}) {
    const badge = toolbarBadgeElement(this.host);
    if (!badge) return;
    const count = this.workspaceId > 0 ? this.badgeNumber() : 0;
    badge.textContent = count > 0 ? String(count) : '';
    badge.hidden = count <= 0;
    badge.classList.toggle('hidden', count <= 0);
    if (count > 0) {
      badge.setAttribute('aria-label', label(
        this.host,
        this.contextCount === null ? 'toolbar.badge.pending' : 'toolbar.badge.pendingHere',
        { count },
      ));
    } else {
      badge.removeAttribute('aria-label');
    }
    if (pulse && count > 0) {
      badge.classList.remove('wew-badge--pulse');
      void badge.offsetWidth; // restart the animation
      badge.classList.add('wew-badge--pulse');
      badge.addEventListener('animationend', () => badge.classList.remove('wew-badge--pulse'), { once: true });
    }
  }

  syncVisibility() {
    const toolbarHost = toolbarHostElement(this.host);
    if (!toolbarHost) return;
    const live = this.workspaceId <= 0;
    toolbarHost.hidden = live;
    // The server ships the same class so the item is never visible in Live
    // before this script runs; keep the two in sync from here on.
    toolbarHost.classList.toggle('webcon-easy-workspace-toolbar--live', live);
  }
}

/**
 * Collect triggers into one batch: the last reason names it, the list is
 * wanted when any trigger wanted it, and a channel stamp only survives when
 * every trigger carried the same one.
 */
function mergeBatch(batch, reason, list, stamp, resolve) {
  if (!batch) {
    return { reason, list: Boolean(list), stamp: stamp || '', waiters: [resolve] };
  }
  batch.reason = reason;
  batch.list = batch.list || Boolean(list);
  batch.stamp = batch.stamp !== '' && batch.stamp === stamp ? stamp : '';
  batch.waiters.push(resolve);
  return batch;
}

function mergeBatches(target, batch) {
  if (!target) return batch;
  target.reason = batch.reason;
  target.list = target.list || batch.list;
  target.stamp = target.stamp !== '' && target.stamp === batch.stamp ? target.stamp : '';
  target.waiters.push(...batch.waiters);
  return target;
}

export function normalizeBadgePayload(data) {
  if (!data || typeof data !== 'object') return null;
  const changedCount = Math.max(0, parseInt(String(data.changedCount ?? '0'), 10) || 0);
  const workspaceId = Math.max(0, parseInt(String(data.workspaceId ?? '0'), 10) || 0);
  const rawContextCount = parseInt(String(data.contextCount ?? ''), 10);
  const contextCount = Number.isFinite(rawContextCount) && rawContextCount >= 0 ? rawContextCount : null;
  const rawState = data.byState && typeof data.byState === 'object' ? data.byState : {};
  return {
    workspaceId,
    workspaceTitle: typeof data.workspaceTitle === 'string' ? data.workspaceTitle : '',
    changedCount,
    contextCount,
    records: Array.isArray(data.records)
      ? data.records
        .filter((record) => record && typeof record === 'object' && typeof record.table === 'string')
        .map((record) => ({
          table: record.table,
          liveUid: parseInt(String(record.liveUid ?? '0'), 10) || 0,
          workspaceUid: parseInt(String(record.workspaceUid ?? '0'), 10) || 0,
        }))
      : null,
    stamp: typeof data.stamp === 'string' ? data.stamp : '',
    latestChangeAt: parseInt(String(data.latestChangeAt ?? '0'), 10) || 0,
    byTable: data.byTable && typeof data.byTable === 'object' && !Array.isArray(data.byTable) ? { ...data.byTable } : {},
    byState: {
      new: parseInt(String(rawState.new ?? '0'), 10) || 0,
      changed: parseInt(String(rawState.changed ?? '0'), 10) || 0,
      deleted: parseInt(String(rawState.deleted ?? '0'), 10) || 0,
      moved: parseInt(String(rawState.moved ?? '0'), 10) || 0,
    },
  };
}

export async function fetchBadgePayload(query) {
  if (!ENDPOINTS.badge) {
    throw new Error('badge endpoint missing');
  }
  const response = await new AjaxRequest(ENDPOINTS.badge).withQueryArguments(query).get();
  return response.resolve();
}

export function createBroadcastChannel(name) {
  try {
    return typeof BroadcastChannel === 'function' ? new BroadcastChannel(name) : null;
  } catch {
    return null;
  }
}

export function toolbarHostElement(host) {
  const localHost = host.closest?.('[id^="typo3-cms-backend-backend-toolbaritems"]')
    || host.closest?.('.toolbar-item');
  if (localHost?.querySelector?.('[data-wew-workspace-badge]')) {
    return localHost;
  }
  const badge = toolbarBadgeElement(host);
  return badge?.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
    || badge?.closest('.toolbar-item')
    || localHost
    || safeTopDocument(window)?.querySelector('[id*="easyworkspacetoolbaritem"]')
    || null;
}

export function toolbarBadgeElement(host) {
  const roots = [
    host.closest?.('[id^="typo3-cms-backend-backend-toolbaritems"]'),
    host.closest?.('.toolbar-item'),
    host.ownerDocument ?? document,
    safeTopDocument(window),
  ];
  for (const root of roots) {
    const badge = root?.querySelector?.('[data-wew-workspace-badge]');
    if (badge) return badge;
  }
  return null;
}

function isSameOrigin(event, win) {
  if (!event.origin) return true;
  if (event.origin === win.location.origin) return true;
  try {
    return event.origin === win.top?.location?.origin;
  } catch {
    return false;
  }
}

function safeTopDocument(win) {
  try {
    return win.top?.document || win.parent?.document || null;
  } catch {
    return null;
  }
}

function safeTopWindow(win) {
  try {
    return win.top && win.top !== win ? win.top : null;
  } catch {
    return null;
  }
}

function randomId() {
  return Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
}
