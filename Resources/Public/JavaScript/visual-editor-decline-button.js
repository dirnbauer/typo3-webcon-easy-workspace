const STATE_REQUEST = 'wew-decline-state-request';
const STATE_RESPONSE = 'wew-decline-state';
const DECLINE_REQUEST = 'wew-decline';

let changedRecords = new Map();
let stateReady = false;
const observedShadowRoots = new WeakSet();

function postToBackend(message) {
  const target = window.top || window.parent;
  target?.postMessage(message, '*');
}

function uidValues(record) {
  return [record?.workspaceUid, record?.liveUid]
    .map((uid) => Number.parseInt(uid, 10))
    .filter((uid, index, all) => uid > 0 && all.indexOf(uid) === index);
}

function updateState(records) {
  const next = new Map();
  for (const record of Array.isArray(records) ? records : []) {
    const table = String(record?.table || '');
    if (!table) continue;
    if (!next.has(table)) {
      next.set(table, new Set());
    }
    for (const uid of uidValues(record)) {
      next.get(table).add(uid);
    }
  }
  changedRecords = next;
  stateReady = true;
  scan();
}

function canDecline(element) {
  if (!stateReady) return false;
  const table = element.getAttribute('table') || '';
  const uid = Number.parseInt(element.getAttribute('uid') || '', 10);
  return uid > 0 && changedRecords.get(table)?.has(uid) === true;
}

function declineLabels() {
  // Injected as a priority inline script by VisualEditorDeclineButtonMiddleware;
  // fall back to English when the global is absent.
  const labels = window.webconEasyWorkspaceDeclineLabels || {};
  const title = typeof labels.title === 'string' && labels.title !== '' ? labels.title : '';
  const subtitle = typeof labels.subtitle === 'string' && labels.subtitle !== '' ? labels.subtitle : '';
  return {
    title: title && subtitle
      ? `${title} - ${subtitle}`
      : 'Decline workspace changes - back to the last published version',
    ariaLabel: title || 'Decline workspace changes',
  };
}

function makeButton(element) {
  const labels = declineLabels();
  const button = document.createElement('button');
  button.className = 'button wew-ve-decline-button';
  button.type = 'button';
  button.tabIndex = 0;
  button.title = labels.title;
  button.setAttribute('aria-label', labels.ariaLabel);
  button.innerHTML = '<ve-icon name="actions-undo"></ve-icon>';
  button.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    postToBackend({
      type: DECLINE_REQUEST,
      table: element.getAttribute('table') || '',
      uid: Number.parseInt(element.getAttribute('uid') || '', 10),
    });
  });
  return button;
}

function syncButton(element) {
  const root = element.shadowRoot;
  const actionBar = root?.querySelector('ve-drag-handle');
  if (!actionBar) return;

  const existing = actionBar.querySelector('.wew-ve-decline-button');
  if (!canDecline(element)) {
    existing?.remove();
    return;
  }

  if (!existing) {
    actionBar.append(makeButton(element));
  }
}

function observeShadowRoot(element) {
  const root = element.shadowRoot;
  if (!root || observedShadowRoots.has(root)) return;
  observedShadowRoots.add(root);
  const observer = new MutationObserver(() => syncButton(element));
  observer.observe(root, { childList: true, subtree: true });
}

function scan(root = document) {
  for (const element of root.querySelectorAll?.('ve-content-element') || []) {
    observeShadowRoot(element);
    syncButton(element);
  }
}

window.addEventListener('message', (event) => {
  if (event.data?.type !== STATE_RESPONSE) return;
  updateState(event.data.records);
});

if (window.veInfo) {
  customElements.whenDefined('ve-content-element').then(() => {
    scan();
    new MutationObserver(() => scan()).observe(document.documentElement, { childList: true, subtree: true });
    postToBackend({ type: STATE_REQUEST, pageId: window.veInfo.pageId || 0 });
  });
}
