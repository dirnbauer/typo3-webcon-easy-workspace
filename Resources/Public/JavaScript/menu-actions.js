import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

import { ENDPOINTS } from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import { detectContext, detectModule, label } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { broadcastDeclineState } from '@webconsulting/webcon-easy-workspace/menu-decline-sync.js';
import {
  key,
  publishRecordsForItem,
  discardTargetForItem,
  resetSelection,
  syncSelectionWithItems,
} from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { companionLanguageTitles } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { layoutFlag } from '@webconsulting/webcon-easy-workspace/menu-layout.js';

export { key, publishRecordsForItem, discardTargetForItem } from '@webconsulting/webcon-easy-workspace/menu-selection.js';

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

/**
 * Page/news context sent along with writes, so the badge block the server
 * echoes back is already scoped to what the editor is looking at.
 */
function contextBody(host) {
  const pageUid = Number(host.pageUid) || 0;
  const newsUid = Number(host.newsUid) || 0;
  return newsUid > 0 ? { newsUid } : { pageUid };
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
 * The site's languages of the list, keyed by language id. `code` is the
 * short code the language chips show ("DE"), `hreflang` tags titles with
 * their language.
 */
export function languageMap(raw) {
  const map = {};
  for (const language of Array.isArray(raw) ? raw : []) {
    const uid = Number(language?.uid);
    if (!Number.isInteger(uid) || uid < 0) continue;
    map[uid] = {
      uid,
      title: String(language.title || ''),
      flag: String(language.flag || ''),
      code: String(language.code || ''),
      hreflang: String(language.hreflang || ''),
    };
  }
  return map;
}

/**
 * The languages the editor's module shows, or null when it shows no
 * particular language (a record list, the dashboard).
 */
function viewLanguageList(raw) {
  if (!Array.isArray(raw)) return null;
  const ids = raw.map((id) => Number(id)).filter((id) => Number.isInteger(id) && id >= 0);
  return ids.length > 0 ? ids : [0];
}

function clearList(host) {
  host.items = [];
  host.languages = {};
  host.viewLanguages = null;
  host.viewModule = '';
}

/**
 * Refresh the context-scoped list. Never touches the badge count — that
 * is BadgeSync's job (menu-badge.js): the response's `badge` block is
 * returned for BadgeSync to apply, which is also how BadgeSync uses this as
 * its fetcher while the list is wanted. Resolves to null when there is no
 * list (no context, stale or failed request).
 */
export async function refresh(host, options = {}) {
  const requestId = nextRefreshRequestId(host);
  if (!ENDPOINTS.items) {
    host.state = 'error';
    clearList(host);
    resetSelection(host);
    notifyView(host);
    return null;
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
    clearList(host);
    host.contextRecord = null;
    host.stage = null;
    resetSelection(host);
    notifyView(host);
    broadcastDeclineState(host);
    return null;
  }

  try {
    const response = await new AjaxRequest(ENDPOINTS.items)
      .withQueryArguments(contextQuery(context, { module: detectModule() }))
      .get();
    const data = await response.resolve();
    if (!isCurrentRefreshRequest(host, requestId)) {
      return null;
    }
    host.context = data.context;
    host.items = Array.isArray(data.items) ? data.items : [];
    // Counts the lists that arrived: focus restoration waits for the next one.
    host.listVersion = (Number(host.listVersion) || 0) + 1;
    host.languages = languageMap(data.languages);
    host.viewLanguages = viewLanguageList(data.viewLanguages);
    host.viewModule = typeof data.viewModule === 'string' ? data.viewModule : '';
    host.contextRecord = data.contextRecord && typeof data.contextRecord === 'object' ? data.contextRecord : null;
    host.stage = data.stage && typeof data.stage === 'object' ? data.stage : null;
    host.workspaceId = Number.isFinite(Number(data.workspaceId)) ? Number(data.workspaceId) : 0;
    host.workspaceTitle = typeof data.workspaceTitle === 'string' ? data.workspaceTitle : '';
    host.userUid = Number(data.userUid) || 0;
    const contextKey = selectionContextKey(context.pageUid, context.newsUid, host.workspaceId);
    // Another page, article or workspace: its sections start in their
    // default state again (the editor's toggles belong to one context).
    if (host._foldContextKey !== contextKey) {
      host._foldContextKey = contextKey;
      host.sectionState = new Map();
    }
    syncSelectionWithItems(host, contextKey);
    host.state = data.context === 'none' ? 'no-context' : (host.items.length === 0 ? 'empty' : 'loaded');
    notifyView(host);
    broadcastDeclineState(host);
    return data.badge && typeof data.badge === 'object' ? data.badge : null;
  } catch (error) {
    if (!isCurrentRefreshRequest(host, requestId)) {
      return null;
    }
    console.error('[easy-workspace] items request failed', error);
    host.state = 'error';
    clearList(host);
    resetSelection(host);
    notifyView(host);
    return null;
  }
}

/**
 * Refresh the list through BadgeSync when there is one, so the request
 * shares its debounce and single in-flight slot and applies the badge
 * block the list carries.
 */
function refreshList(host, reason, quiet = true) {
  return host.badge?.request
    ? host.badge.request(reason, { list: true })
    : refresh(host, { quiet });
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
      await refreshList(host, 'publish', false);
      return;
    }
    const response = await new AjaxRequest(ENDPOINTS.publish)
      .post({ selections: uniqueSelections, ...contextBody(host) }, JSON_HEADERS);
    const result = await response.resolve();
    if (result?.badge) {
      host.badge?.apply(result.badge, { reason: 'publish', refreshList: false });
    }
    if (result?.success) {
      // The editor selected edits; the server's record count (nested
      // records, translations carried along) stays in the payload.
      Notification.success(
        label(host, 'publish.success.title'),
        label(host, 'publish.success.message', { count: selectedItems.length }),
      );
      notifyPublishNotes(host, result);
      host.focusAfterRender?.({ index: 0 });
      await host._animateRowsLeaving?.(selectedItems.map((i) => key(host, i)));
      // apply() above already told the other tabs when the stamp moved.
      await refreshList(host, 'publish');
      host.setStatus?.('');
    } else {
      Notification.warning(label(host, 'publish.warning.title'), errorText(host, result));
    }
  } catch (error) {
    Notification.error(label(host, 'publish.failedTitle'), error?.message || label(host, 'error.unexpected'));
  } finally {
    host.publishing = false;
    notifyView(host);
  }
}

/**
 * What the server noted on a successful publish: translations it did not
 * carry along because someone edited them meanwhile (they are rows of their
 * own now), and warnings of the DataHandler run.
 */
function notifyPublishNotes(host, result) {
  const skipped = Array.isArray(result?.skippedCompanions) ? result.skippedCompanions : [];
  if (skipped.length > 0) {
    Notification.info(
      label(host, 'publish.warning.title'),
      label(host, 'publish.companionsSkipped', { languages: companionLanguageTitles(host, skipped) }),
    );
  }
  const warnings = stringList(result?.warnings);
  if (warnings.length > 0) {
    Notification.warning(label(host, 'publish.warning.title'), warnings.join(' / '));
  }
}

function stringList(value) {
  return Array.isArray(value) ? value.filter((entry) => typeof entry === 'string' && entry !== '') : [];
}

function errorText(host, result) {
  const errors = stringList(result?.errors);
  if (errors.length > 0) return errors.join(' / ');
  return result?.error || label(host, 'error.unknown');
}

/**
 * Discard one row: a single request for the row's record. The server
 * discards its nested records and the translations TYPO3 copied along with
 * it (after checking they are still unchanged) in the same run; Core's
 * discard takes the row's dependents along (`dependentsDiscarded`, 0 from an
 * older server).
 *
 * @returns {Promise<{success: boolean, errors: string[], warnings: string[], companionsDiscarded: number, dependentsDiscarded: number}>}
 */
export async function discardItem(host, item) {
  const response = await new AjaxRequest(ENDPOINTS.discard)
    .post({ ...discardTargetForItem(item), ...contextBody(host) }, JSON_HEADERS);
  const result = await response.resolve();
  if (result?.badge) {
    host.badge?.apply(result.badge, { reason: 'discard', refreshList: false });
  }
  const success = Boolean(result?.success);
  return {
    success,
    errors: success ? [] : [errorText(host, result)],
    warnings: stringList(result?.warnings),
    companionsDiscarded: Math.max(0, Number(result?.companionsDiscarded) || 0),
    dependentsDiscarded: Math.max(0, Number(result?.dependentsDiscarded) || 0),
  };
}

/**
 * Discard leftover copies (the cleanup section). The server checks each one
 * is still identical to live and keeps the ones someone edited meanwhile;
 * nothing is ever published here.
 *
 * @param {Array<{table: string, workspaceUid: number}>} records copy items; the server adds their translations
 * @returns {Promise<{success: boolean, discarded: number, skipped: number, errors: string[]}>}
 */
export async function discardCopies(host, records) {
  const failed = (errors) => ({ success: false, discarded: 0, skipped: 0, errors });
  if (!ENDPOINTS.discardCopies) {
    return failed([label(host, 'error.unknown')]);
  }
  try {
    const response = await new AjaxRequest(ENDPOINTS.discardCopies)
      .post({ records, ...contextBody(host) }, JSON_HEADERS);
    const result = await response.resolve();
    if (result?.badge) {
      host.badge?.apply(result.badge, { reason: 'discard', refreshList: false });
    }
    const discarded = Math.max(0, Number(result?.discarded) || 0);
    const skipped = Math.max(0, Number(result?.skipped) || 0);
    if (result?.success) {
      // The layout names the records as its cleanup section does.
      Notification.success(
        label(host, 'toolbar.cleanup.success.title'),
        label(host, String(layoutFlag(host, 'cleanupSuccessKey') || 'toolbar.cleanup.success.message'), { count: discarded }),
        4,
      );
      if (skipped > 0) {
        Notification.info(
          label(host, 'toolbar.cleanup.success.title'),
          label(host, String(layoutFlag(host, 'cleanupSkippedKey') || 'toolbar.cleanup.skipped'), { count: skipped }),
        );
      }
    } else {
      Notification.error(label(host, 'discard.error.title'), errorText(host, result));
    }
    await refreshList(host, 'discard');
    return { success: Boolean(result?.success), discarded, skipped, errors: stringList(result?.errors) };
  } catch (error) {
    Notification.error(label(host, 'discard.error.failedTitle'), error?.message || label(host, 'error.unexpected'));
    return failed([error?.message || label(host, 'error.unexpected')]);
  }
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
