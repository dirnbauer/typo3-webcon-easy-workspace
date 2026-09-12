import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

import { ENDPOINTS } from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import { detectContext, label } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { broadcastDeclineState } from '@webconsulting/webcon-easy-workspace/menu-decline-sync.js';
import {
  key,
  publishRecordsForItem,
  discardRecordsForItem,
  resetSelection,
  syncSelectionWithItems,
} from '@webconsulting/webcon-easy-workspace/menu-selection.js';

export { key, publishRecordsForItem, discardRecordsForItem } from '@webconsulting/webcon-easy-workspace/menu-selection.js';

const JSON_HEADERS = { headers: { 'Content-Type': 'application/json; charset=utf-8' } };

function notifyView(host) {
  host.requestUpdate?.();
}

function nextRefreshRequestId(host) {
  host._refreshRequestId = (host._refreshRequestId || 0) + 1;
  return host._refreshRequestId;
}

function isCurrentRefreshRequest(host, requestId) {
  return host._refreshRequestId === requestId;
}

function selectionContextKey(pageUid, newsUid, workspaceId) {
  const contextType = pageUid > 0 ? 'page' : 'news';
  const contextUid = pageUid > 0 ? pageUid : newsUid;
  return `${workspaceId}:${contextType}:${contextUid}`;
}

export function currentToolbarContext(host) {
  const { pageUid, newsUid } = detectContext(host);
  host.pageUid = pageUid;
  host.newsUid = newsUid;
  return { pageUid, newsUid, hasContext: pageUid > 0 || newsUid > 0 };
}

function contextQuery(context, extra = {}) {
  const query = context.pageUid > 0
    ? { pageUid: context.pageUid, ...extra }
    : { newsUid: context.newsUid, ...extra };
  query._ = Date.now();
  return query;
}

/**
 * Refresh the context-scoped list. Never touches the badge count — that
 * is BadgeSync's job (menu-badge.js).
 */
export async function refresh(host, options = {}) {
  const requestId = nextRefreshRequestId(host);
  if (!ENDPOINTS.items) {
    host.state = 'error';
    host.items = [];
    host.changedItemGroups = [];
    resetSelection(host);
    notifyView(host);
    return;
  }
  const quiet = Boolean(options.quiet);
  const settled = host.state === 'loaded' || host.state === 'empty' || host.state === 'no-context';
  if (!quiet || !settled) {
    host.state = 'loading';
    notifyView(host);
  }
  const context = currentToolbarContext(host);
  if (!context.hasContext) {
    host.state = 'no-context';
    host.items = [];
    host.changedItemGroups = [];
    host.contextRecord = null;
    host.stage = null;
    resetSelection(host);
    notifyView(host);
    broadcastDeclineState(host);
    return;
  }

  try {
    const response = await new AjaxRequest(ENDPOINTS.items)
      .withQueryArguments(contextQuery(context))
      .get();
    const data = await response.resolve();
    if (!isCurrentRefreshRequest(host, requestId)) {
      return;
    }
    host.context = data.context;
    host.items = Array.isArray(data.items) ? data.items : [];
    host.changedItemGroups = Array.isArray(data.changedItemGroups) ? data.changedItemGroups : [];
    host.contextRecord = data.contextRecord && typeof data.contextRecord === 'object' ? data.contextRecord : null;
    host.stage = data.stage && typeof data.stage === 'object' ? data.stage : null;
    host.workspaceId = Number.isFinite(Number(data.workspaceId)) ? Number(data.workspaceId) : 0;
    host.workspaceTitle = typeof data.workspaceTitle === 'string' ? data.workspaceTitle : '';
    syncSelectionWithItems(
      host,
      selectionContextKey(context.pageUid, context.newsUid, host.workspaceId),
    );
    host.state = data.context === 'none' ? 'no-context' : (host.items.length === 0 ? 'empty' : 'loaded');
    notifyView(host);
    broadcastDeclineState(host);
  } catch (error) {
    if (!isCurrentRefreshRequest(host, requestId)) {
      return;
    }
    console.error('[easy-workspace] items request failed', error);
    host.state = 'error';
    host.changedItemGroups = [];
    resetSelection(host);
    notifyView(host);
  }
}

export function configuredWorkspaceId(host) {
  const configuredId = Number(host._config.activeWorkspaceId || 0);
  return Number.isFinite(configuredId) ? Math.max(0, configuredId) : 0;
}

export async function publish(host) {
  if (!ENDPOINTS.publish || host.selection.size === 0) {
    return;
  }
  host.publishing = true;
  notifyView(host);
  try {
    const selectedItems = host.items.filter((i) => host.selection.has(key(host, i)));
    const selections = selectedItems.flatMap((i) => publishRecordsForItem(host, i));
    const uniqueSelections = Array.from(
      new Map(selections.map((selection) => [`${selection.table}:${selection.workspaceUid}`, selection])).values(),
    );
    if (uniqueSelections.length === 0) {
      Notification.warning(label(host, 'publish.warning.title'), label(host, 'error.noPublishableRecords'));
      await refresh(host);
      return;
    }
    const response = await new AjaxRequest(ENDPOINTS.publish).post({ selections: uniqueSelections }, JSON_HEADERS);
    const result = await response.resolve();
    if (result?.badge) {
      host.badge?.apply(result.badge, { reason: 'publish', refreshList: false });
    }
    if (result?.success && Number(result.published || 0) > 0) {
      Notification.success(
        label(host, 'publish.success.title'),
        label(host, 'publish.success.message', { count: Number(result.published || 0) }),
      );
      await host._animateRowsLeaving?.(selectedItems.map((i) => key(host, i)));
      host.badge?.broadcast('publish');
      await refresh(host, { quiet: true });
    } else {
      const errors = Array.isArray(result?.errors) && result.errors.length
        ? result.errors.join(' / ')
        : (result?.error || label(host, 'error.unknown'));
      Notification.warning(label(host, 'publish.warning.title'), errors);
    }
  } catch (error) {
    Notification.error(label(host, 'publish.failedTitle'), error?.message || label(host, 'error.unexpected'));
  } finally {
    host.publishing = false;
    notifyView(host);
  }
}

/**
 * Discard every workspace record behind an item (children first, the
 * record itself last). Returns the aggregated result; the last server
 * response carries the freshest badge payload.
 *
 * @returns {Promise<{success: boolean, errors: string[]}>}
 */
export async function discardItem(host, item) {
  const results = [];
  for (const record of discardRecordsForItem(host, item)) {
    const response = await new AjaxRequest(ENDPOINTS.discard)
      .post({ table: record.table, workspaceUid: record.workspaceUid }, JSON_HEADERS);
    results.push(await response.resolve());
  }
  const last = results.at(-1);
  if (last?.badge) {
    host.badge?.apply(last.badge, { reason: 'discard', refreshList: false });
  }
  const failed = results.filter((result) => !result?.success);
  return {
    success: failed.length === 0,
    errors: failed.flatMap((result) => (Array.isArray(result?.errors) && result.errors.length
      ? result.errors
      : [result?.error || label(host, 'error.unknown')])),
  };
}

async function resolvePreviewUrl(host, pageUid) {
  if (!ENDPOINTS.previewLink || pageUid <= 0) {
    return '';
  }
  const response = await new AjaxRequest(ENDPOINTS.previewLink).withQueryArguments({ pageUid }).get();
  const data = await response.resolve();
  if (!data?.url) {
    Notification.error(label(host, 'preview.link.title'), data?.error || label(host, 'preview.link.noUrl'));
    return '';
  }
  return String(data.url);
}

export async function copyPreviewLink(host, pageUid) {
  try {
    const url = await resolvePreviewUrl(host, pageUid);
    if (!url) return;
    await writeToOsClipboard(url);
    Notification.success(label(host, 'preview.link.copied'), url, 4);
  } catch (error) {
    Notification.error(label(host, 'preview.link.title'), error?.message || label(host, 'error.unexpected'));
  }
}

export async function openPreview(host, pageUid) {
  try {
    const url = await resolvePreviewUrl(host, pageUid);
    if (!url) return;
    const opened = (window.top || window).open(url, '_blank', 'noopener');
    if (!opened) {
      await writeToOsClipboard(url);
      Notification.info(label(host, 'preview.link.copied'), url, 4);
    }
  } catch (error) {
    Notification.error(label(host, 'preview.link.title'), error?.message || label(host, 'error.unexpected'));
  }
}

/**
 * Navigate the backend content frame to the Easy Workspace module for the
 * current page. Uses Core's module menu API when available.
 */
export function openModule(host) {
  const pageUid = Number(host.pageUid) || 0;
  const identifier = String(host._config.moduleIdentifier || '');
  const app = safeTop()?.TYPO3?.ModuleMenu?.App;
  if (identifier && typeof app?.showModule === 'function') {
    app.showModule(identifier, pageUid > 0 ? `id=${pageUid}` : '');
    return true;
  }
  const url = moduleHref(host);
  if (!url) return false;
  try {
    (window.top || window).location.href = url;
  } catch {
    window.location.href = url;
  }
  return true;
}

export function moduleHref(host) {
  const base = String(host._config.moduleUrl || '');
  if (!base) return '';
  try {
    const target = new URL(base, window.location.href);
    const pageUid = Number(host.pageUid) || 0;
    if (pageUid > 0) target.searchParams.set('id', String(pageUid));
    return target.toString();
  } catch {
    return base;
  }
}

function safeTop() {
  try {
    return window.top || window;
  } catch {
    return window;
  }
}

export async function writeToOsClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return;
    } catch {
      // Fall through to the textarea copy path.
    }
  }
  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.left = '-9999px';
  document.body.appendChild(textarea);
  textarea.select();
  try {
    document.execCommand('copy');
  } finally {
    document.body.removeChild(textarea);
  }
}
