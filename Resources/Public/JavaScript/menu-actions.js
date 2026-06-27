import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

import { ENDPOINTS } from './menu-constants.js';
import {
  detectContext,
  label,
} from './menu-context.js';
import { broadcastDeclineState } from './menu-backend-save-sync.js';
import {
  key,
  publishRecordsForItem,
  resetSelection,
  syncSelectionWithItems,
} from './menu-selection.js';

export { key, publishRecordsForItem, discardRecordsForItem } from './menu-selection.js';

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

function nextBadgeRequestId(host) {
  host._badgeRequestId = (host._badgeRequestId || 0) + 1;
  return host._badgeRequestId;
}

function isCurrentBadgeRequest(host, requestId) {
  return host._badgeRequestId === requestId;
}

function selectionContextKey(pageUid, newsUid, workspaceId) {
  const contextType = pageUid > 0 ? 'page' : 'news';
  const contextUid = pageUid > 0 ? pageUid : newsUid;

  return `${workspaceId}:${contextType}:${contextUid}`;
}

function currentToolbarContext(host) {
  const { pageUid, newsUid } = detectContext(host);
  host.pageUid = pageUid;
  host.newsUid = newsUid;

  return {
    pageUid,
    newsUid,
    hasContext: pageUid > 0 || newsUid > 0,
  };
}

function contextQuery(context, extra = {}) {
  const query = context.pageUid > 0
    ? { pageUid: context.pageUid, ...extra }
    : { newsUid: context.newsUid, ...extra };
  query._ = Date.now();
  return query;
}

export async function refresh(host, options = {}) {
  const requestId = nextRefreshRequestId(host);
  if (!ENDPOINTS.items) {
    host.state = 'error';
    host.items = [];
    host.itemGroups = [];
    host.changedItemGroups = [];
    host.workspaceId = 0;
    host.badgeCount = 0;
    resetSelection(host);
    notifyView(host);
    syncToolbarVisibility(host);
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
    host.itemGroups = [];
    host.changedItemGroups = [];
    host.workspaceId = configuredWorkspaceId(host);
    host.badgeCount = 0;
    resetSelection(host);
    notifyView(host);
    updateToolbarBadge(host);
    syncToolbarVisibility(host);
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
    host.itemGroups = Array.isArray(data.itemGroups) ? data.itemGroups : [];
    host.changedItemGroups = Array.isArray(data.changedItemGroups) ? data.changedItemGroups : [];
    host.workspaceId = Number.isFinite(Number(data.workspaceId)) ? Number(data.workspaceId) : 0;
    host.workspaceTitle = typeof data.workspaceTitle === 'string' ? data.workspaceTitle : '';
    syncSelectionWithItems(
      host,
      selectionContextKey(context.pageUid, context.newsUid, host.workspaceId),
    );
    host.state = data.context === 'none' ? 'no-context' : (host.items.length === 0 ? 'empty' : 'loaded');
    host.badgeCount = changedItemCount(host);
    notifyView(host);
    updateToolbarBadge(host);
    syncToolbarVisibility(host);
    broadcastDeclineState(host);
  } catch (error) {
    if (!isCurrentRefreshRequest(host, requestId)) {
      return;
    }
    console.error('[easy-workspace] items request failed', error);
    host.state = 'error';
    host.itemGroups = [];
    host.changedItemGroups = [];
    host.badgeCount = 0;
    resetSelection(host);
    notifyView(host);
    updateToolbarBadge(host);
  }
}

export async function refreshAfterBackendSave(host, options = {}) {
  await refreshBadge(host);
  if (options.list !== false) {
    await refresh(host, { quiet: true });
  }
}

export async function refreshBadge(host) {
  if (!ENDPOINTS.badge) {
    return;
  }
  const requestId = nextBadgeRequestId(host);
  const context = currentToolbarContext(host);
  if (!context.hasContext) {
    host.badgeCount = 0;
    host.workspaceId = configuredWorkspaceId(host);
    updateToolbarBadge(host);
    syncToolbarVisibility(host);
    return;
  }
  try {
    const response = await new AjaxRequest(ENDPOINTS.badge)
      .withQueryArguments(contextQuery(context))
      .get();
    const data = await response.resolve();
    if (!isCurrentBadgeRequest(host, requestId)) {
      return;
    }
    host.workspaceId = Number.isFinite(Number(data.workspaceId)) ? Number(data.workspaceId) : 0;
    host.workspaceTitle = typeof data.workspaceTitle === 'string' ? data.workspaceTitle : host.workspaceTitle;
    host.badgeCount = Math.max(0, parseInt(String(data.changedCount ?? '0'), 10) || 0);
    host.workspaceChangeRevision = Math.max(0, parseInt(String(data.revision ?? '0'), 10) || 0);
    updateToolbarBadge(host);
    syncToolbarVisibility(host);
  } catch (error) {
    console.warn('[easy-workspace] badge request failed', error);
  }
}

export function changedItemCount(host) {
  return Array.isArray(host.items) ? host.items.filter((i) => i.isChanged).length : 0;
}

export function updateToolbarBadge(host) {
  const badge = toolbarBadgeElement(host);
  if (!badge) return;
  const count = host.workspaceId > 0
    ? Math.max(0, parseInt(String(host.badgeCount ?? changedItemCount(host)), 10) || 0)
    : 0;
  badge.textContent = count > 0 ? String(count) : '';
  badge.hidden = count <= 0;
  badge.classList.toggle('hidden', count <= 0);
  if (count > 0) {
    const badgeLabel = label(host, 'toolbar.badge.pending', { count });
    badge.setAttribute('aria-label', badgeLabel);
  } else {
    badge.removeAttribute('aria-label');
  }
}

export function syncToolbarVisibility(host) {
  const toolbarHost = toolbarHostElement(host);
  if (!toolbarHost) return;
  const stateKnown = host.state === 'loaded'
    || host.state === 'empty'
    || host.state === 'no-context'
    || Number.isFinite(Number(host.badgeCount));
  if (!stateKnown) return;
  toolbarHost.hidden = host.workspaceId <= 0;
}

export function configuredWorkspaceId(host) {
  const configuredId = Number(host._config.activeWorkspaceId || 0);
  return Number.isFinite(configuredId) ? Math.max(0, configuredId) : 0;
}

export function toolbarHostElement(host) {
  const localHost = host.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
    || host.closest('.toolbar-item');
  if (localHost?.querySelector?.('[data-wew-workspace-badge]')) {
    return localHost;
  }
  const badge = toolbarBadgeElement(host);
  return badge?.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
    || badge?.closest('.toolbar-item')
    || localHost
    || topDocument()?.querySelector('[id*="easyworkspacetoolbaritem"]')
    || null;
}

export function toolbarBadgeElement(host) {
  const roots = [
    host.closest('[id^="typo3-cms-backend-backend-toolbaritems"]'),
    host.closest('.toolbar-item'),
    document,
    topDocument(),
  ];
  for (const root of roots) {
    const badge = root?.querySelector?.('[data-wew-workspace-badge]');
    if (badge) {
      return badge;
    }
  }
  return null;
}

function topDocument() {
  try {
    return window.top?.document || window.parent?.document || null;
  } catch {
    return null;
  }
}

export async function publish(host) {
  if (!ENDPOINTS.publish || host.selection.size === 0) {
    return;
  }
  host.publishing = true;
  try {
    const selections = host.items
      .filter((i) => host.selection.has(key(host, i)))
      .flatMap((i) => publishRecordsForItem(host, i));
    const uniqueSelections = Array.from(
      new Map(selections.map((selection) => [`${selection.table}:${selection.workspaceUid}`, selection])).values(),
    );
    if (uniqueSelections.length === 0) {
      Notification.warning(label(host, 'publish.warning.title'), label(host, 'error.noPublishableRecords'));
      await refresh(host);
      return;
    }
    const response = await new AjaxRequest(ENDPOINTS.publish)
      .post({ selections: uniqueSelections }, { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
    const result = await response.resolve();
    if (result?.success && Number(result.published || 0) > 0) {
      Notification.success(
        label(host, 'publish.success.title'),
        label(host, 'publish.success.message', { count: Number(result.published || 0) }),
      );
      await refresh(host);
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

export async function copyPreviewLink(host, pageUid) {
  if (!ENDPOINTS.previewLink || pageUid <= 0) {
    return;
  }
  host.copyingPreview = true;
  try {
    const response = await new AjaxRequest(ENDPOINTS.previewLink)
      .withQueryArguments({ pageUid })
      .get();
    const data = await response.resolve();
    if (!data?.url) {
      Notification.error(label(host, 'preview.link.title'), data?.error || label(host, 'preview.link.noUrl'));
      return;
    }
    await writeToOsClipboard(data.url);
    Notification.success(label(host, 'preview.link.copied'), data.url, 4);
    host.previewJustCopied = true;
    if (host._previewCopiedResetTimer) {
      clearTimeout(host._previewCopiedResetTimer);
    }
    host._previewCopiedResetTimer = setTimeout(() => {
      host.previewJustCopied = false;
      host._previewCopiedResetTimer = null;
    }, 2000);
  } catch (error) {
    Notification.error(label(host, 'preview.link.title'), error?.message || label(host, 'error.unexpected'));
  } finally {
    host.copyingPreview = false;
  }
}

export async function writeToOsClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return;
    } catch (error) {
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
