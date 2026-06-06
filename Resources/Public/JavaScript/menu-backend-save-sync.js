import { collectIframes, isKnownPreviewFrame } from './menu-dom-utils.js';
import { isKnownPreviewWindow } from './menu-preview-locate.js';

/**
 * Use backend save lifecycles as refresh signals only. The toolbar
 * badge must show server-side workspace versions ready to publish,
 * not Visual Editor's temporary unsaved field count.
 */
export function onBackendSaveMessage(host, event) {
  if (!isTrustedBackendSaveMessage(host, event)) {
    return;
  }
  const fromVisualEditor = event.data?.command === 've_saveEnded';
  host._refreshAfterBackendSave({ force: fromVisualEditor });
}

export function isTrustedBackendSaveMessage(host, event) {
  const command = event.data?.command;
  if (command === 've_saveEnded') {
    if (isKnownPreviewWindow(host, event.source)) {
      return true;
    }
    // saveEnded is posted from the preview iframe to the VE module frame.
    // Accept same-origin payloads even when iframe discovery is briefly stale.
    return isSameOriginMessage(event);
  }
  if (event.data?.actionName === 'typo3:editform:saved') {
    return !event.origin || event.origin === window.location.origin;
  }
  return false;
}

function isSameOriginMessage(event) {
  if (!event.origin) {
    return true;
  }
  if (event.origin === window.location.origin) {
    return true;
  }
  try {
    return event.origin === window.top?.location?.origin;
  } catch {
    return false;
  }
}

export function registerBackendSaveSignalListeners(host) {
  if (!host._backendSaveMessageListener) {
    return;
  }

  resetBackendSaveMessageTargets(host);
  addBackendSaveMessageTarget(host, window);
  try { addBackendSaveMessageTarget(host, window.top); } catch { /* cross-origin */ }
  try { addBackendSaveMessageTarget(host, window.parent); } catch { /* cross-origin */ }

  addBackendSaveDocumentTarget(host, document);
  try { addBackendSaveDocumentTarget(host, window.top?.document); } catch { /* cross-origin */ }

  for (const iframe of collectIframes()) {
    // Listen on backend/module frames only. The preview iframe receives
    // parent→child commands; attaching here is unnecessary and can race
    // Visual Editor's strict postMessage routing during save.
    if (isKnownPreviewFrame(iframe)) {
      continue;
    }
    try { addBackendSaveMessageTarget(host, iframe.contentWindow); } catch { /* cross-origin */ }
    addBackendFrameLoadTarget(host, iframe);
  }
}

export function addBackendSaveMessageTarget(host, targetWindow) {
  if (!targetWindow || host._backendSaveMessageTargets.has(targetWindow)) {
    return;
  }
  try {
    targetWindow.addEventListener('message', host._backendSaveMessageListener);
    host._backendSaveMessageTargets.set(targetWindow, () => {
      try {
        targetWindow.removeEventListener('message', host._backendSaveMessageListener);
      } catch { /* target window may be gone */ }
    });
  } catch {
    // Cross-origin frames deliberately stay opaque.
  }
}

export function addBackendSaveDocumentTarget(host, targetDocument) {
  if (!targetDocument || host._backendSaveDocumentTargets.has(targetDocument)) {
    return;
  }
  const handler = () => scheduleBackendFrameLoadRefresh(host);
  try {
    targetDocument.addEventListener('typo3:pagetree:refresh', handler);
    host._backendSaveDocumentTargets.set(targetDocument, () => {
      try {
        targetDocument.removeEventListener('typo3:pagetree:refresh', handler);
      } catch { /* target document may be gone */ }
    });
  } catch {
    // Cross-origin frames deliberately stay opaque.
  }
}

export function addBackendFrameLoadTarget(host, iframe) {
  if (!iframe || host._backendFrameLoadTargets.has(iframe) || !isBackendModuleFrame(iframe)) {
    return;
  }
  host._backendFrameUrls.set(iframe, frameHref(iframe));
  const handler = () => {
    const previousUrl = host._backendFrameUrls.get(iframe) || '';
    const currentUrl = frameHref(iframe);
    host._backendFrameUrls.set(iframe, currentUrl);
    registerBackendSaveSignalListeners(host);
    if (shouldRefreshAfterFrameLoad(iframe, previousUrl, currentUrl)) {
      scheduleBackendFrameLoadRefresh(host);
    }
  };
  try {
    iframe.addEventListener('load', handler);
    host._backendFrameLoadTargets.set(iframe, () => iframe.removeEventListener('load', handler));
  } catch {
    // Detached frames can reject listener wiring.
  }
}

export function isBackendModuleFrame(iframe) {
  if (!iframe || isKnownPreviewFrame(iframe)) {
    return false;
  }
  const id = String(iframe.id || '').toLowerCase();
  const name = String(iframe.name || '').toLowerCase();
  const src = String(iframe.src || '');
  return id === 'typo3-contentiframe'
    || name === 'typo3-contentiframe'
    || /\/record\/edit(?:\/contextual)?(?:[/?#]|$)/.test(src);
}

export function shouldRefreshAfterFrameLoad(iframe, previousUrl, currentUrl) {
  const id = String(iframe.id || '').toLowerCase();
  const name = String(iframe.name || '').toLowerCase();
  if (id === 'typo3-contentiframe' || name === 'typo3-contentiframe') {
    return true;
  }
  return /\/record\/edit(?:\/contextual)?(?:[/?#]|$)/.test(previousUrl)
    || /\/record\/edit(?:\/contextual)?(?:[/?#]|$)/.test(currentUrl)
    || /[?&](justSaved|closed)=1(?:&|$)/.test(currentUrl);
}

export function frameHref(iframe) {
  try {
    return iframe.contentWindow?.location?.href || iframe.src || '';
  } catch {
    return iframe.src || '';
  }
}

export function scheduleBackendFrameLoadRefresh(host) {
  if (host._backendFrameLoadRefreshTimer) {
    window.clearTimeout(host._backendFrameLoadRefreshTimer);
  }
  host._backendFrameLoadRefreshTimer = window.setTimeout(() => {
    host._backendFrameLoadRefreshTimer = null;
    host._refreshAfterBackendSave({ force: true });
  }, 120);
}

export function resetBackendSaveMessageTargets(host) {
  for (const cleanup of host._backendSaveMessageTargets.values()) {
    cleanup();
  }
  host._backendSaveMessageTargets.clear();
}

export function clearBackendSaveSignalListeners(host) {
  resetBackendSaveMessageTargets(host);
  for (const cleanup of host._backendSaveDocumentTargets.values()) {
    cleanup();
  }
  host._backendSaveDocumentTargets.clear();
  for (const cleanup of host._backendFrameLoadTargets.values()) {
    cleanup();
  }
  host._backendFrameLoadTargets.clear();
}

/**
 * Handle Visual Editor iframe messages from our FE helper module.
 */
export function onDeclineMessage(host, event) {
  const data = event.data;
  if (!data || (data.type !== 'wew-decline' && data.type !== 'wew-decline-state-request')) return;
  if (!isKnownPreviewWindow(host, event.source)) return;

  if (data.type === 'wew-decline-state-request') {
    sendDeclineState(host, event.source, event.origin);
    return;
  }

  const table = String(data.table || '');
  const uid = parseInt(data.uid, 10);
  if (!table || uid <= 0) return;
  const known = host.items.find(
    (i) => i.table === table && (i.workspaceUid === uid || i.liveUid === uid),
  );
  const item = known || {
    table,
    workspaceUid: uid,
    liveUid: uid,
    title: `${table} #${uid}`,
    tableLabel: host._friendlyTable(table),
    isChanged: true,
  };
  host._confirmAndDiscard(item);
}

export function declineStatePayload(host) {
  return {
    type: 'wew-decline-state',
    workspaceId: host.workspaceId || 0,
    records: host.items
      .filter((item) => item?.isChanged)
      .map((item) => ({
        table: item.table,
        liveUid: item.liveUid,
        workspaceUid: item.workspaceUid,
      })),
  };
}

export function sendDeclineState(host, source, origin = '*') {
  try {
    source?.postMessage(declineStatePayload(host), origin || '*');
  } catch { /* target frame may already be gone */ }
}

export function broadcastDeclineState(host) {
  const payload = declineStatePayload(host);
  for (const iframe of collectIframes()) {
    if (!isKnownPreviewFrame(iframe)) continue;
    try {
      iframe.contentWindow?.postMessage(payload, '*');
    } catch { /* target frame may already be gone */ }
  }
}
