import { collectIframes, isKnownPreviewFrame, tableLabel } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { isKnownPreviewWindow } from '@webconsulting/webcon-easy-workspace/menu-preview-locate.js';

/**
 * Message protocol with the Visual Editor preview iframe: the preview
 * asks which records carry workspace changes (to draw the per-element
 * decline button) and posts back when the editor clicks it.
 *
 * Save signals do not live here — BadgeSync (menu-badge.js) receives them
 * as top-window messages, and its responses (`wew:badge`) re-broadcast the
 * state to every preview.
 */
function findDeclined(host, table, uid) {
  return (host.items || []).find(
    (i) => i.table === table && (i.workspaceUid === uid || i.liveUid === uid),
  );
}

export async function onDeclineMessage(host, event) {
  const data = event.data;
  if (!data || (data.type !== 'wew-decline' && data.type !== 'wew-decline-state-request')) return;
  if (!isKnownPreviewWindow(host, event.source)) return;

  if (data.type === 'wew-decline-state-request') {
    // A preview (re)loaded — possibly on another page or news article than
    // the one the badge last counted (the Visual Editor navigates inside
    // its own frame). The answer below is the current state; when the page
    // changed, the badge response that follows broadcasts the new one.
    host.badge?.checkContext?.('preview');
    sendDeclineState(host, event.source, event.origin);
    return;
  }

  const table = String(data.table || '');
  const uid = parseInt(data.uid, 10);
  if (!table || uid <= 0) return;
  // The list is only loaded while the dropdown is used and goes stale once
  // it closes; the confirm must name the companions the server discards
  // along and the dependents Core's discard takes with it (a translator's
  // edits of a moved or deleted element's translations included), so the
  // current list is asked for first (one request through BadgeSync, on a
  // deliberate click — no polling). A record the list does not hold gets
  // the plain confirm. A second click while it loads opens no second
  // confirm.
  if (host._declineBusy === true) return;
  if (host.badge) {
    host._declineBusy = true;
    try {
      await host._refreshList('decline');
    } finally {
      host._declineBusy = false;
    }
  }
  const item = findDeclined(host, table, uid) || {
    table,
    workspaceUid: uid,
    liveUid: uid,
    title: `${table} #${uid}`,
    tableLabel: tableLabel(host, table),
    isChanged: true,
  };
  host._confirmAndDiscard(item);
}

/**
 * The changed records of the current page: from the badge (`records`,
 * refreshed with every badge response) — the list is only loaded while the
 * dropdown is used — falling back to the list for older servers.
 */
export function declineStatePayload(host) {
  const records = Array.isArray(host.changedRecords)
    ? host.changedRecords
    : (host.items || []).filter((item) => item?.isChanged);
  return {
    type: 'wew-decline-state',
    workspaceId: host.workspaceId || 0,
    records: records.map((record) => ({
      table: record.table,
      liveUid: record.liveUid,
      workspaceUid: record.workspaceUid,
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
