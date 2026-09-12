import { collectIframes, isKnownPreviewFrame, tableLabel } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { isKnownPreviewWindow } from '@webconsulting/webcon-easy-workspace/menu-preview-locate.js';

/**
 * Use backend save lifecycles as refresh signals only. The toolbar
 * badge must show server-side workspace versions ready to publish,
 * not Visual Editor's temporary unsaved field count.
 */
export function onBackendSaveMessage(host, event) {
  if (!isTrustedBackendSaveMessage(host, event)) {
    return;
  }
  host._refreshAfterBackendSave();
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

  clearBackendSaveSignalListeners(host);
  const controller = new AbortController();
  host._backendSaveAbortController = controller;
  const options = { signal: controller.signal };
  const windows = new Set([window]);
  const documents = new Set([document]);
  try { windows.add(window.top); documents.add(window.top.document); } catch { /* cross-origin */ }
  try { windows.add(window.parent); } catch { /* cross-origin */ }

  for (const iframe of collectIframes()) {
    // Listen on backend/module frames only. The preview iframe receives
    // parent→child commands; attaching here is unnecessary and can race
    // Visual Editor's strict postMessage routing during save.
    if (isKnownPreviewFrame(iframe)) {
      continue;
    }
    try { windows.add(iframe.contentWindow); } catch { /* cross-origin */ }
  }
  for (const targetWindow of windows) {
    try {
      targetWindow?.addEventListener('message', host._backendSaveMessageListener, options);
    } catch { /* cross-origin frames stay opaque */ }
  }

  // Core emits this even when the module iframe was created after the toolbar.
  // Rebind save messages to the current frames when navigation replaces them.
  const handler = () => {
    registerBackendSaveSignalListeners(host);
    scheduleBackendRefresh(host);
  };
  const eventNames = [
    'typo3-module-loaded',
    'typo3:datahandler:process',
    'typo3:pagetree:refresh',
    'typo3:workspace:changed',
    'typo3:workspaces:refresh',
  ];
  for (const targetDocument of documents) {
    for (const eventName of eventNames) {
      targetDocument.addEventListener(eventName, handler, options);
    }
  }
}

function scheduleBackendRefresh(host) {
  if (host._backendRefreshTimer) {
    window.clearTimeout(host._backendRefreshTimer);
  }
  host._backendRefreshTimer = window.setTimeout(() => {
    host._backendRefreshTimer = null;
    host._refreshAfterBackendSave();
  }, 120);
}

export function clearBackendSaveSignalListeners(host) {
  host._backendSaveAbortController?.abort();
  host._backendSaveAbortController = null;
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
    tableLabel: tableLabel(host, table),
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
