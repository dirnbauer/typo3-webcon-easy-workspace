import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import {
  ENDPOINTS,
  CHANNEL_NAME,
  BADGE_DEBOUNCE_MS,
  BADGE_POLL_INTERVAL_MS,
  BADGE_POLL_JITTER_MS,
  BADGE_POLL_BACKOFF_MS,
  BADGE_ERROR_BACKOFF_THRESHOLD,
  REFRESH_EVENTS,
  MODULE_IFRAME_SELECTOR,
} from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import { detectContext, label } from '@webconsulting/webcon-easy-workspace/menu-context.js';

/**
 * BadgeSync — the ONLY writer of `host.badgeCount`.
 *
 * The server (`/badge`, and the `badge` block of publish/discard responses)
 * is the single source of truth for the whole-workspace change count. Every
 * trigger — element connect, navigation, dropdown open, Core DataHandler
 * broadcasts, save messages, BroadcastChannel notifications from other
 * tabs/frames, visibility/focus, and the visible-tab poll — funnels through
 * one debounce. A request-id guard drops stale responses, and the server
 * `stamp` decides whether an open list has to re-fetch.
 */
export class BadgeSync {
  constructor(host, options = {}) {
    this.host = host;
    this.win = options.window ?? window;
    this.doc = options.document ?? this.win.document;
    this.topDoc = options.topDocument ?? safeTopDocument(this.win);
    this.topWin = options.topWindow ?? safeTopWindow(this.win);
    this.fetchBadge = options.fetchBadge ?? fetchBadgePayload;
    this.createChannel = options.createChannel ?? createBroadcastChannel;
    this.random = options.random ?? Math.random;
    this.instanceId = options.instanceId ?? randomId();
    this.debounceMs = options.debounceMs ?? BADGE_DEBOUNCE_MS;
    this.pollInterval = options.pollInterval ?? BADGE_POLL_INTERVAL_MS;
    this.pollJitter = options.pollJitter ?? BADGE_POLL_JITTER_MS;
    this.pollBackoff = options.pollBackoff ?? BADGE_POLL_BACKOFF_MS;
    this.errorThreshold = options.errorThreshold ?? BADGE_ERROR_BACKOFF_THRESHOLD;

    this.count = 0;
    this.workspaceId = Math.max(0, Number(options.initialWorkspaceId) || 0);
    this.workspaceTitle = '';
    this.stamp = '';
    this.byState = { new: 0, changed: 0, deleted: 0, moved: 0 };
    this.byTable = {};
    this.latestChangeAt = 0;
    this.requestId = 0;
    this.errorStreak = 0;
    this.pendingReason = '';
    this.debounceTimer = null;
    this.pollTimer = null;
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
    if (Number.isFinite(count) && count >= 0) this.count = count;
    if (Number.isFinite(workspaceId) && workspaceId >= 0) this.workspaceId = workspaceId;
    this.host.badgeCount = this.count;
    this.host.workspaceId = this.workspaceId;
  }

  start() {
    if (this.started) return;
    this.started = true;
    this.controller = new AbortController();
    const options = { signal: this.controller.signal };

    // 1. Core document events (top frame + own frame, deduplicated).
    for (const targetDocument of new Set([this.doc, this.topDoc].filter(Boolean))) {
      this.listen(targetDocument, options);
    }

    // 1b. …and the same events inside the module iframe, re-attached every
    //     time a module finishes loading.
    for (const targetDocument of new Set([this.doc, this.topDoc].filter(Boolean))) {
      try {
        targetDocument.addEventListener('typo3-module-loaded', () => this.attachFrame(), options);
      } catch { /* cross-origin top document */ }
    }
    this.attachFrame();

    // 2. Save signals delivered as window messages (FormEngine modals post
    //    to the top window; the Visual Editor preview script posts a
    //    `wew-refresh` fallback for cross-origin previews).
    for (const targetWindow of new Set([this.win, this.topWin].filter(Boolean))) {
      try {
        targetWindow.addEventListener('message', (event) => this.onWindowMessage(event), options);
      } catch { /* cross-origin */ }
    }

    // 3. Visibility + focus: pause the poll while hidden, catch up on return.
    this.doc.addEventListener('visibilitychange', () => this.onVisibilityChange(), options);
    this.win.addEventListener('focus', () => this.request('focus'), options);

    // 4. Same-origin BroadcastChannel (other tabs, module frame, VE preview).
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
   * Attach REFRESH_EVENTS to one document.
   */
  listen(targetDocument, options) {
    for (const eventName of REFRESH_EVENTS) {
      try {
        targetDocument.addEventListener(eventName, () => this.request(eventName), options);
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
    this.cancelPoll();
    try { this.channel?.close(); } catch { /* already closed */ }
    this.channel = null;
    this.requestId += 1; // invalidate in-flight responses
  }

  /**
   * Debounced entry point for every trigger.
   */
  request(reason = 'manual') {
    if (!this.started) return;
    this.pendingReason = reason;
    this.cancelDebounce();
    this.debounceTimer = this.win.setTimeout(() => {
      this.debounceTimer = null;
      void this.run(this.pendingReason);
    }, this.debounceMs);
  }

  /**
   * Fetch immediately (bypasses the debounce). Resolves after the response
   * has been applied or discarded.
   */
  async run(reason = 'manual') {
    const requestId = ++this.requestId;
    this.cancelPoll();
    try {
      const payload = await this.fetchBadge(this.query());
      if (requestId !== this.requestId) return; // stale — a newer request is in flight or resolved
      this.errorStreak = 0;
      this.apply(payload, { reason });
    } catch (error) {
      if (requestId !== this.requestId) return;
      this.errorStreak += 1;
      console.warn('[easy-workspace] badge request failed', error);
    } finally {
      if (requestId === this.requestId) this.schedulePoll();
    }
  }

  /**
   * Apply a server payload (from /badge or embedded in publish/discard
   * responses). Writes host.badgeCount, updates the DOM badge and the
   * toolbar visibility, and re-fetches an open list when the stamp moved.
   */
  apply(payload, { reason = 'response', refreshList = true } = {}) {
    const next = normalizeBadgePayload(payload);
    if (!next) return;
    const previousCount = this.count;
    const previousStamp = this.stamp;

    this.count = next.changedCount;
    this.workspaceId = next.workspaceId;
    this.stamp = next.stamp;
    this.byState = next.byState;
    this.byTable = next.byTable;
    this.latestChangeAt = next.latestChangeAt;
    if (next.workspaceTitle) this.workspaceTitle = next.workspaceTitle;

    this.host.badgeCount = this.count;
    this.host.workspaceId = this.workspaceId;
    if (next.workspaceTitle) this.host.workspaceTitle = next.workspaceTitle;

    this.render({ pulse: this.count > previousCount && reason !== 'connect' });
    this.syncVisibility();

    const stampChanged = previousStamp !== '' && previousStamp !== this.stamp;
    if (stampChanged && refreshList && this.isDropdownOpen()) {
      void this.host._refresh?.({ quiet: true });
    }
    this.host.requestUpdate?.();
    this.host.dispatchEvent?.(new CustomEvent('wew:badge', { detail: { ...next, reason } }));
  }

  /**
   * Tell other tabs/frames that this instance changed the workspace.
   */
  broadcast(reason = 'change') {
    try {
      this.channel?.postMessage({
        type: 'refresh',
        reason,
        workspaceId: this.workspaceId,
        stamp: this.stamp,
        instanceId: this.instanceId,
      });
    } catch { /* channel closed */ }
  }

  onChannelMessage(event) {
    const message = event?.data;
    if (!message || message.type !== 'refresh' || message.instanceId === this.instanceId) return;
    if (typeof message.stamp === 'string' && message.stamp !== '' && message.stamp === this.stamp) return;
    this.request(`channel:${message.reason || 'unknown'}`);
  }

  onWindowMessage(event) {
    const data = event?.data;
    if (!data || typeof data !== 'object') return;
    if (data.type === 'wew-refresh') {
      this.request(`message:${data.reason || 'refresh'}`);
      return;
    }
    if (!isSameOrigin(event, this.win)) return;
    if (data.actionName === 'typo3:editform:saved' || data.command === 've_saveEnded') {
      this.request(`message:${data.actionName || data.command}`);
    }
  }

  onVisibilityChange() {
    if (this.doc.hidden) {
      this.cancelPoll();
      return;
    }
    this.request('visible');
  }

  schedulePoll() {
    this.cancelPoll();
    if (!this.started || this.pollInterval <= 0 || this.doc.hidden) return;
    const base = this.errorStreak >= this.errorThreshold ? this.pollBackoff : this.pollInterval;
    const delay = base + Math.floor(this.random() * this.pollJitter);
    this.pollTimer = this.win.setTimeout(() => {
      this.pollTimer = null;
      this.request('poll');
    }, delay);
  }

  cancelPoll() {
    if (this.pollTimer !== null) {
      this.win.clearTimeout(this.pollTimer);
      this.pollTimer = null;
    }
  }

  cancelDebounce() {
    if (this.debounceTimer !== null) {
      this.win.clearTimeout(this.debounceTimer);
      this.debounceTimer = null;
    }
  }

  query() {
    const { pageUid, newsUid } = detectContext(this.host);
    const query = { _: Date.now() };
    if (newsUid > 0) query.newsUid = newsUid;
    else if (pageUid > 0) query.pageUid = pageUid;
    return query;
  }

  isDropdownOpen() {
    return Boolean(this.host._isDropdownOpen?.());
  }

  render({ pulse = false } = {}) {
    const badge = toolbarBadgeElement(this.host);
    if (!badge) return;
    const count = this.workspaceId > 0 ? this.count : 0;
    badge.textContent = count > 0 ? String(count) : '';
    badge.hidden = count <= 0;
    badge.classList.toggle('hidden', count <= 0);
    if (count > 0) {
      badge.setAttribute('aria-label', label(this.host, 'toolbar.badge.pending', { count }));
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

export function normalizeBadgePayload(data) {
  if (!data || typeof data !== 'object') return null;
  const changedCount = Math.max(0, parseInt(String(data.changedCount ?? '0'), 10) || 0);
  const workspaceId = Math.max(0, parseInt(String(data.workspaceId ?? '0'), 10) || 0);
  const rawState = data.byState && typeof data.byState === 'object' ? data.byState : {};
  return {
    workspaceId,
    workspaceTitle: typeof data.workspaceTitle === 'string' ? data.workspaceTitle : '',
    changedCount,
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
