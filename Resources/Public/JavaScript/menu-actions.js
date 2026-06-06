import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

import { ENDPOINTS } from './menu-constants.js';
import {
  buildContextLabel,
  detectContext,
  detectLanguageUid,
  label,
} from './menu-context.js';
import { reloadPreviewIframes } from './menu-preview-locate.js';
import { broadcastDeclineState } from './menu-backend-save-sync.js';
import {
  key,
  publishRecordsForItem,
  selectAll,
} from './menu-selection.js';

export { key, publishRecordsForItem, discardRecordsForItem } from './menu-selection.js';

export const MODE_STORAGE_KEY = 'wew:filter-mode';

export function readPersistedMode() {
  try {
    const value = window.localStorage?.getItem(MODE_STORAGE_KEY);
    return value === 'all' || value === 'changed' ? value : null;
  } catch {
    return null;
  }
}

export function writePersistedMode(mode) {
  try {
    window.localStorage?.setItem(MODE_STORAGE_KEY, mode);
  } catch { /* ignore */ }
}

function notifyView(host) {
  host.requestUpdate?.();
}

export function setMode(host, mode) {
  if (host.mode === mode) {
    return;
  }
  host.mode = mode;
  writePersistedMode(mode);
  notifyView(host);
}

export async function refresh(host) {
  if (!ENDPOINTS.items) {
    host.state = 'error';
    host.items = [];
    host.itemGroups = [];
    host.changedItemGroups = [];
    host.workspaceId = 0;
    notifyView(host);
    syncToolbarVisibility(host);
    return;
  }
  host.state = 'loading';
  notifyView(host);
  const { pageUid, newsUid } = detectContext(host);
  host.pageUid = pageUid;
  host.newsUid = newsUid;
  const languageUid = detectLanguageUid(host);
  if (!pageUid && !newsUid) {
    host.state = 'no-context';
    host.contextLabel = label(host, 'toolbar.context.none');
    host.items = [];
    host.itemGroups = [];
    host.changedItemGroups = [];
    host.workspaceId = configuredWorkspaceId(host);
    notifyView(host);
    updateToolbarBadge(host);
    syncToolbarVisibility(host);
    broadcastDeclineState(host);
    return;
  }

  const query = pageUid ? { pageUid, mode: host.mode } : { newsUid, mode: host.mode };
  query._ = Date.now();
  if (languageUid !== null) {
    query.languageUid = languageUid;
  }
  try {
    const response = await new AjaxRequest(ENDPOINTS.items).withQueryArguments(query).get();
    const data = await response.resolve();
    host.context = data.context;
    host.items = Array.isArray(data.items) ? data.items : [];
    host.itemGroups = Array.isArray(data.itemGroups) ? data.itemGroups : [];
    host.changedItemGroups = Array.isArray(data.changedItemGroups) ? data.changedItemGroups : [];
    host.workspaceId = Number.isFinite(Number(data.workspaceId)) ? Number(data.workspaceId) : 0;
    host.workspaceTitle = typeof data.workspaceTitle === 'string' ? data.workspaceTitle : '';
    host.contextLabel = buildContextLabel(host, data);
    host.selection = new Set(host.items.filter((i) => i.isChanged).map((i) => key(host, i)));
    host.state = data.context === 'none' ? 'no-context' : (host.items.length === 0 ? 'empty' : 'loaded');
    notifyView(host);
    updateToolbarBadge(host);
    syncToolbarVisibility(host);
    broadcastDeclineState(host);
  } catch (error) {
    console.error('[easy-workspace] items request failed', error);
    host.state = 'error';
    host.itemGroups = [];
    host.changedItemGroups = [];
    notifyView(host);
    updateToolbarBadge(host);
  }
}

export async function refreshAfterBackendSave(host, options = {}) {
  const force = Boolean(options.force);
  if (host._refreshAfterSaveTimer) {
    window.clearTimeout(host._refreshAfterSaveTimer);
    host._refreshAfterSaveTimer = null;
  }
  await refreshIfPersistedChangesExist(host, { force });
  host._refreshAfterSaveTimer = window.setTimeout(() => {
    host._refreshAfterSaveTimer = null;
    refreshIfPersistedChangesExist(host, { force });
  }, 800);
}

export async function refreshIfPersistedChangesExist(host, options = {}) {
  const force = Boolean(options.force);
  const currentCount = changedItemCount(host);
  if (!force) {
    try {
      const hasChanges = await hasPersistedChangesInCurrentContext(host);
      if (!hasChanges && currentCount === 0) {
        return;
      }
    } catch (error) {
      console.warn('[easy-workspace] has-changes request failed; refreshing item list', error);
    }
  }
  await refresh(host);
}

export async function hasPersistedChangesInCurrentContext(host) {
  if (!ENDPOINTS.hasChanges) {
    return true;
  }
  const { pageUid, newsUid } = detectContext(host);
  const languageUid = detectLanguageUid(host);
  if (!pageUid && !newsUid) {
    return false;
  }
  const query = pageUid ? { pageUid } : { newsUid };
  if (languageUid !== null) {
    query.languageUid = languageUid;
  }
  const response = await new AjaxRequest(ENDPOINTS.hasChanges).withQueryArguments(query).get();
  const data = await response.resolve();
  return Boolean(data?.hasChanges);
}

export function changedItemCount(host) {
  return Array.isArray(host.items) ? host.items.filter((i) => i.isChanged).length : 0;
}

export function updateToolbarBadge(host) {
  const toolbarHost = toolbarHostElement(host);
  const badge = toolbarHost?.querySelector('[data-wew-workspace-badge]');
  if (!badge) return;
  const count = host.workspaceId > 0 && (host.state === 'loaded' || host.state === 'empty')
    ? changedItemCount(host)
    : 0;
  badge.textContent = count > 0 ? String(count) : '';
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
  const stateKnown = host.state === 'loaded' || host.state === 'empty' || host.state === 'no-context';
  if (!stateKnown) return;
  toolbarHost.hidden = host.workspaceId <= 0;
}

export function configuredWorkspaceId(host) {
  const configuredId = Number(host._config.activeWorkspaceId || 0);
  return Number.isFinite(configuredId) ? Math.max(0, configuredId) : 0;
}

export function toolbarHostElement(host) {
  return host.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
    || host.closest('.toolbar-item')
    || (window.top || window.parent)?.document?.querySelector('[id*="easyworkspacetoolbaritem"]');
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
