/**
 * Easy Workspace toolbar dropdown.
 *
 * Renders into light DOM so backend Bootstrap classes apply. Reads the
 * current page / news context from window.location, fetches the list of
 * pending workspace changes for that context, and lets the editor publish
 * the selected items in one DataHandler call.
 *
 * Features:
 *  - Filter chips: "Changed only" / "All on page".
 *  - Copy preview link (uses TYPO3 Workspaces\Preview\PreviewUriBuilder).
 *  - Optional subelement detail badges.
 *  - Changed items get an accent border (no greyed-out fallback for the
 *    unchanged ones — they just render normally, no checkbox).
 */

import { LitElement, html, nothing } from 'lit';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal, { Sizes as ModalSizes, Types as ModalTypes, Positions as ModalPositions } from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

const ENDPOINTS = {
  items: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_items || '',
  hasChanges: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_has_changes || '',
  publish: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_publish || '',
  previewLink: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_preview_link || '',
  discard: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_discard || '',
  diff: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_diff || '',
  historyRollback: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_history_rollback || '',
};

// Fallback defaults — overridden by the TSconfig-driven JSON the
// toolbar item attaches via the `config` attribute on this element.
const DEFAULT_CONFIG = Object.freeze({
  enabled: true,
  enableWorkspaceChip: true,
  enablePreviewLink: true,
  enableFilter: true,
  defaultMode: 'changed',
  enableThumbnails: true,
  enableTypeLabels: true,
  enableHiddenBadge: true,
  showHidden: true,
  maxItems: 200,
  enableNewsBundles: true,
  enableHoverHighlight: true,
  enableRevert: true,
  userEnabled: true,
  showSubelementsInToolbar: false,
  showSubelementsInModule: true,
  // Runtime-detected environment (NOT user-configurable). Set by
  // EasyWorkspaceToolbarItem::getDropDown() via ExtensionManagementUtility::isLoaded.
  activeWorkspaceId: 0,
  pageUid: 0,
  newsUid: 0,
  hasVisualEditor: false,
  hasViewpage: false,
});

// Inline highlight styles applied to the iframe element. Hard-coded
// colors because the iframe document has its own CSS scope and v14
// backend custom properties don't propagate there.
const IFRAME_HIGHLIGHT_STYLE = {
  outline: '3px solid #4a90e2',
  outlineOffset: '2px',
  boxShadow: '0 0 0 6px rgba(74, 144, 226, 0.22)',
  transition: 'outline 0.15s ease, box-shadow 0.15s ease',
  scrollMarginTop: '40px',
  scrollMarginBottom: '40px',
  // Faint background tint while hovered so the entire CE area is obvious.
  backgroundColor: '',
};

class WebconEasyWorkspaceMenu extends LitElement {
  static properties = {
    variant: { type: String, reflect: false },
    config: { type: String, reflect: false },
    state: { state: true },
    items: { state: true },
    itemGroups: { state: true },
    changedItemGroups: { state: true },
    selection: { state: true },
    context: { state: true },
    publishing: { state: true },
    contextLabel: { state: true },
    workspaceTitle: { state: true },
    workspaceId: { state: true },
    mode: { state: true },
    copyingPreview: { state: true },
    previewJustCopied: { state: true },
  };

  createRenderRoot() {
    return this;
  }

  constructor() {
    super();
    this.state = 'idle';
    this.items = [];
    this.itemGroups = [];
    this.changedItemGroups = [];
    this.selection = new Set();
    this.context = null;
    this.contextLabel = '';
    this.workspaceTitle = '';
    this.workspaceId = 0;
    this.publishing = false;
    this.copyingPreview = false;
    this.previewJustCopied = false;
    this._config = { ...DEFAULT_CONFIG };
    this.mode = this._config.defaultMode;
    this.variant = 'toolbar';
    this._refreshAfterSaveTimer = null;
    this._backendFrameLoadRefreshTimer = null;
    this._backendSaveMessageTargets = new Map();
    this._backendSaveDocumentTargets = new Map();
    this._backendFrameLoadTargets = new Map();
    this._backendFrameUrls = new WeakMap();
  }

  connectedCallback() {
    super.connectedCallback();
    this._config = this._readConfig();
    this.workspaceId = this._configuredWorkspaceId();
    this.classList.toggle('wew-menu-host--compact-toolbar', this._isCompactToolbar());
    // Mode resolution: user's last choice in this browser >
    // TSconfig defaultMode > hardcoded 'changed' fallback.
    this.mode = this._readPersistedMode() ?? this._config.defaultMode;
    this._refresh();

    // Re-fetch whenever the editor's selected page changes. v14
    // dispatches this event from ModuleStateStorage.commit() on
    // top.document.
    this._navListener = () => this._refresh();
    try {
      window.top?.document.addEventListener('typo3:module-state-storage:update:web', this._navListener);
      window.top?.document.addEventListener('typo3:module-state-storage:update-with-tree-identifier:web', this._navListener);
    } catch {
      // top.document may throw on cross-origin frames — fall back to local doc.
      document.addEventListener('typo3:module-state-storage:update:web', this._navListener);
    }

    // Refresh on dropdown open (covers cases where the URL changed
    // via History API without a state-storage event).
    const dropdownHost = this.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
      || this.closest('.toolbar-item');
    if (dropdownHost) {
      dropdownHost.addEventListener('shown.bs.dropdown', () => {
        this._refresh();
      });
      // Belt + braces: also refresh on a direct click on the toggle.
      const toggle = dropdownHost.querySelector('.dropdown-toggle');
      toggle?.addEventListener('click', () => this._refresh());
    }

    // Listen for the Visual Editor iframe helper. The button lives in
    // the FE iframe and doesn't have direct access to the BE token /
    // ajaxUrls, so the discard runs here in the BE context with the
    // existing confirmation modal.
    this._declineMessageListener = (event) => this._onDeclineMessage(event);
    window.addEventListener('message', this._declineMessageListener);

    this._backendSaveMessageListener = (event) => this._onBackendSaveMessage(event);
    this._registerBackendSaveSignalListeners();
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this._clearIframeHighlight();
    if (this._navListener) {
      try {
        window.top?.document.removeEventListener('typo3:module-state-storage:update:web', this._navListener);
        window.top?.document.removeEventListener('typo3:module-state-storage:update-with-tree-identifier:web', this._navListener);
      } catch { /* noop */ }
      document.removeEventListener('typo3:module-state-storage:update:web', this._navListener);
    }
    if (this._declineMessageListener) {
      window.removeEventListener('message', this._declineMessageListener);
      this._declineMessageListener = null;
    }
    if (this._refreshAfterSaveTimer) {
      window.clearTimeout(this._refreshAfterSaveTimer);
      this._refreshAfterSaveTimer = null;
    }
    if (this._backendFrameLoadRefreshTimer) {
      window.clearTimeout(this._backendFrameLoadRefreshTimer);
      this._backendFrameLoadRefreshTimer = null;
    }
    this._clearBackendSaveSignalListeners();
  }

  /**
   * Use backend save lifecycles as refresh signals only. The toolbar
   * badge must show server-side workspace versions ready to publish,
   * not Visual Editor's temporary unsaved field count.
   */
  _onBackendSaveMessage(event) {
    if (!this._isTrustedBackendSaveMessage(event)) {
      return;
    }
    this._refreshAfterBackendSave();
  }

  _isTrustedBackendSaveMessage(event) {
    const command = event.data?.command;
    if (command === 've_saveEnded') {
      return this._isKnownPreviewWindow(event.source);
    }
    if (event.data?.actionName === 'typo3:editform:saved') {
      return !event.origin || event.origin === window.location.origin;
    }
    return false;
  }

  /**
   * The toolbar lives in the top backend frame, while Visual Editor
   * posts `ve_saveEnded` to its immediate parent module iframe. Add
   * listeners to same-origin backend frames so saves in Visual Editor,
   * ContextualRecordEditController, and regular FormEngine reloads all
   * refresh the persisted workspace count.
   */
  _registerBackendSaveSignalListeners() {
    if (!this._backendSaveMessageListener) {
      return;
    }

    this._resetBackendSaveMessageTargets();
    this._addBackendSaveMessageTarget(window);
    try { this._addBackendSaveMessageTarget(window.top); } catch { /* cross-origin */ }
    try { this._addBackendSaveMessageTarget(window.parent); } catch { /* cross-origin */ }

    this._addBackendSaveDocumentTarget(document);
    try { this._addBackendSaveDocumentTarget(window.top?.document); } catch { /* cross-origin */ }

    for (const iframe of this._collectIframes()) {
      try { this._addBackendSaveMessageTarget(iframe.contentWindow); } catch { /* cross-origin */ }
      this._addBackendFrameLoadTarget(iframe);
    }
  }

  _addBackendSaveMessageTarget(targetWindow) {
    if (!targetWindow || this._backendSaveMessageTargets.has(targetWindow)) {
      return;
    }
    try {
      targetWindow.addEventListener('message', this._backendSaveMessageListener);
      this._backendSaveMessageTargets.set(targetWindow, () => {
        try {
          targetWindow.removeEventListener('message', this._backendSaveMessageListener);
        } catch { /* target window may be gone */ }
      });
    } catch {
      // Cross-origin frames deliberately stay opaque.
    }
  }

  _addBackendSaveDocumentTarget(targetDocument) {
    if (!targetDocument || this._backendSaveDocumentTargets.has(targetDocument)) {
      return;
    }
    const handler = () => this._scheduleBackendFrameLoadRefresh();
    try {
      targetDocument.addEventListener('typo3:pagetree:refresh', handler);
      this._backendSaveDocumentTargets.set(targetDocument, () => {
        try {
          targetDocument.removeEventListener('typo3:pagetree:refresh', handler);
        } catch { /* target document may be gone */ }
      });
    } catch {
      // Cross-origin frames deliberately stay opaque.
    }
  }

  _addBackendFrameLoadTarget(iframe) {
    if (!iframe || this._backendFrameLoadTargets.has(iframe) || !this._isBackendModuleFrame(iframe)) {
      return;
    }
    this._backendFrameUrls.set(iframe, this._frameHref(iframe));
    const handler = () => {
      const previousUrl = this._backendFrameUrls.get(iframe) || '';
      const currentUrl = this._frameHref(iframe);
      this._backendFrameUrls.set(iframe, currentUrl);
      this._registerBackendSaveSignalListeners();
      if (this._shouldRefreshAfterFrameLoad(iframe, previousUrl, currentUrl)) {
        this._scheduleBackendFrameLoadRefresh();
      }
    };
    try {
      iframe.addEventListener('load', handler);
      this._backendFrameLoadTargets.set(iframe, () => iframe.removeEventListener('load', handler));
    } catch {
      // Detached frames can reject listener wiring.
    }
  }

  _isBackendModuleFrame(iframe) {
    if (!iframe || this._isKnownPreviewFrame(iframe)) {
      return false;
    }
    const id = String(iframe.id || '').toLowerCase();
    const name = String(iframe.name || '').toLowerCase();
    const src = String(iframe.src || '');
    return id === 'typo3-contentiframe'
      || name === 'typo3-contentiframe'
      || /\/record\/edit(?:\/contextual)?(?:[/?#]|$)/.test(src);
  }

  _shouldRefreshAfterFrameLoad(iframe, previousUrl, currentUrl) {
    const id = String(iframe.id || '').toLowerCase();
    const name = String(iframe.name || '').toLowerCase();
    if (id === 'typo3-contentiframe' || name === 'typo3-contentiframe') {
      return true;
    }
    return /\/record\/edit(?:\/contextual)?(?:[/?#]|$)/.test(previousUrl)
      || /\/record\/edit(?:\/contextual)?(?:[/?#]|$)/.test(currentUrl)
      || /[?&](justSaved|closed)=1(?:&|$)/.test(currentUrl);
  }

  _frameHref(iframe) {
    try {
      return iframe.contentWindow?.location?.href || iframe.src || '';
    } catch {
      return iframe.src || '';
    }
  }

  _scheduleBackendFrameLoadRefresh() {
    if (this._backendFrameLoadRefreshTimer) {
      window.clearTimeout(this._backendFrameLoadRefreshTimer);
    }
    this._backendFrameLoadRefreshTimer = window.setTimeout(() => {
      this._backendFrameLoadRefreshTimer = null;
      this._refreshAfterBackendSave();
    }, 120);
  }

  _resetBackendSaveMessageTargets() {
    for (const cleanup of this._backendSaveMessageTargets.values()) {
      cleanup();
    }
    this._backendSaveMessageTargets.clear();
  }

  _clearBackendSaveSignalListeners() {
    this._resetBackendSaveMessageTargets();
    for (const cleanup of this._backendSaveDocumentTargets.values()) {
      cleanup();
    }
    this._backendSaveDocumentTargets.clear();
    for (const cleanup of this._backendFrameLoadTargets.values()) {
      cleanup();
    }
    this._backendFrameLoadTargets.clear();
  }

  /**
   * Handle Visual Editor iframe messages from our FE helper module.
   * Validates the iframe source, serves the changed-record state,
   * or synthesizes an item descriptor for _confirmAndDiscard.
   *
   * The discard endpoint takes (table, workspaceUid). When the
   * editor clicks the FE-side button we only know the rendered uid.
   * That can be either the live uid or the workspace uid, so we
   * resolve it through the currently loaded toolbar item list first.
   */
  _onDeclineMessage(event) {
    const data = event.data;
    if (!data || (data.type !== 'wew-decline' && data.type !== 'wew-decline-state-request')) return;
    if (!this._isKnownPreviewWindow(event.source)) return;

    if (data.type === 'wew-decline-state-request') {
      this._sendDeclineState(event.source, event.origin);
      return;
    }

    const table = String(data.table || '');
    const uid = parseInt(data.uid, 10);
    if (!table || uid <= 0) return;
    // Find the matching item in our currently-loaded list so the
    // confirmation modal can show its title. Falls back to a
    // synthetic descriptor if the page-scoped list doesn't include
    // this record (rare — would require the editor decline-clicking
    // a CE that isn't in the publish list, e.g. on a different page
    // than the one selected in the page tree).
    const known = this.items.find(
      (i) => i.table === table && (i.workspaceUid === uid || i.liveUid === uid),
    );
    const item = known || {
      table,
      workspaceUid: uid,
      liveUid: uid,
      title: `${table} #${uid}`,
      tableLabel: this._friendlyTable(table),
      isChanged: true,
    };
    this._confirmAndDiscard(item);
  }

  _isKnownPreviewWindow(source) {
    if (!source) return false;
    for (const iframe of this._collectIframes()) {
      try {
        if (this._isKnownPreviewFrame(iframe) && iframe.contentWindow === source) {
          return true;
        }
      } catch { /* cross-origin frame access can throw */ }
    }
    return false;
  }

  _declineStatePayload() {
    return {
      type: 'wew-decline-state',
      workspaceId: this.workspaceId || 0,
      records: this.items
        .filter((item) => item?.isChanged)
        .map((item) => ({
          table: item.table,
          liveUid: item.liveUid,
          workspaceUid: item.workspaceUid,
        })),
    };
  }

  _sendDeclineState(source, origin = '*') {
    try {
      source?.postMessage(this._declineStatePayload(), origin || '*');
    } catch { /* target frame may already be gone */ }
  }

  _broadcastDeclineState() {
    const payload = this._declineStatePayload();
    for (const iframe of this._collectIframes()) {
      if (!this._isKnownPreviewFrame(iframe)) continue;
      try {
        iframe.contentWindow?.postMessage(payload, '*');
      } catch { /* target frame may already be gone */ }
    }
  }

  _isKnownPreviewFrame(iframe) {
    const src = iframe?.src || '';
    return iframe?.id === 'visual-editor-iframe' || /[?&]editMode=/.test(src);
  }

  /**
   * Reach into the Visual Editor iframe (#visual-editor-iframe),
   * locate the rendered content element for the given record by its
   * standard TYPO3 frontend id ("c{uid}"), scroll it into view if it
   * is off-screen and apply an inline outline.
   *
   * Tells the editor *why* the lookup failed (with a TYPO3
   * Notification) instead of silently noop'ing — too easy to think
   * the feature is broken when in reality the iframe just isn't on
   * the page yet, or the CE uses a non-default wrapper element.
   */
  _highlightInIframe(item, { announce = false } = {}) {
    this._clearIframeHighlight();
    const target = this._locateTarget(item);
    const liveUid = target.liveUid;
    const workspaceUid = target.workspaceUid;
    if (!liveUid && !workspaceUid) return;

    // Prefer visual-editor's own <ve-content-element uid="X"> wrapper
    // if present — dispatching mouseenter on it triggers the dashed
    // border + the floating action-bar (Text & Images, edit / hide /
    // delete) the editor expects. Fall back to a custom outline on
    // the rendered <div id="cX"> if visual-editor isn't active.
    //
    // Visual-editor's wrapper uses TYPO3's "versioned uid" — which is
    // the workspace-version uid for MODIFIED records and the live
    // uid for unchanged ones — so we have to look up by BOTH uids
    // and let the first hit win.
    const located = this._locateInAnyIframe(item);
    if (!located) {
      if (announce) {
        const allIframes = this._collectIframes();
        const accessible = allIframes.filter((f) => {
          try { return !!f.contentDocument?.body; } catch { return false; }
        });
        // Visual-editor's PersistenceMiddleware throws an
        // ImmediateResponseException with its NotLoggedIn.html
        // template whenever the FE iframe load arrives without a BE
        // session (default TYPO3 BE cookie path is /typo3/, so it
        // doesn't reach /?editMode=1). The iframe is "accessible" in
        // the same-origin sense, but it never contains any content
        // elements to highlight. Detect that gate and surface a
        // dedicated message rather than the generic "searched N
        // iframes" diagnostic — editors otherwise think the eye is
        // broken when the real problem is BE↔FE session bridging.
        const gated = accessible.filter((f) => this._isPreviewGate(f.contentDocument));
        if (accessible.length === 0) {
          const hint = this._config.hasVisualEditor
            ? this._label('preview.noIframe.visualEditor')
            : (this._config.hasViewpage
                ? this._label('preview.noIframe.viewpage')
                : this._label('preview.noIframe.install'));
          Notification.info(this._label('preview.show.title'), hint, 5);
        } else if (gated.length === accessible.length) {
          Notification.info(
            this._label('preview.show.title'),
            this._label('preview.loginHint'),
            10,
          );
        } else {
          // Dump diagnostics so the next iteration of the locator can
          // be tuned to whatever wrapper this site actually emits.
          this._logIframeDiagnostics(accessible, liveUid, workspaceUid);
          Notification.warning(
            this._label('preview.show.title'),
            this._label('preview.notFound', { count: accessible.length, liveUid, workspaceUid }),
            8,
          );
        }
      }
      return;
    }
    const { el, isVeWrapper } = located;

    // Centre the element in the iframe viewport — smooth scroll, no jump.
    try {
      el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    } catch {
      el.scrollIntoView();
    }

    if (isVeWrapper) {
      // Visual-editor path: dispatch the same native events its own
      // <ve-content-element> listens to. This shows the dashed border
      // and the floating action-bar (Text & Images, edit, hide, delete)
      // exactly as if the editor moved their mouse onto the element.
      try {
        el.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
        el.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
      } catch { /* event constructor failed - ignore */ }
      this._iframeHighlight = { el, isVeWrapper: true };
      return;
    }

    // Fallback: cms-viewpage / plain frontend — no <ve-content-element>
    // to lean on, so paint our own outline + glow and revert on leave.
    const previous = {};
    for (const key of Object.keys(IFRAME_HIGHLIGHT_STYLE)) {
      previous[key] = el.style[key];
    }
    Object.assign(el.style, IFRAME_HIGHLIGHT_STYLE);
    this._iframeHighlight = { el, isVeWrapper: false, previous };
  }

  _clearIframeHighlight() {
    const current = this._iframeHighlight;
    if (current) {
      try {
        if (current.isVeWrapper) {
          // Tell visual-editor's <ve-content-element> the mouse has left.
          current.el.dispatchEvent(new MouseEvent('mouseleave', { bubbles: true }));
        } else if (current.previous) {
          for (const [key, value] of Object.entries(current.previous)) {
            current.el.style[key] = value ?? '';
          }
        }
      } catch { /* element may already be detached */ }
      this._iframeHighlight = null;
    }
    // Remove any discard-preview tags we injected into iframes
    // (see _previewDiscard). Iterate all reachable iframes so a
    // stale tag doesn't survive in a frame we no longer track.
    for (const iframe of this._collectIframes()) {
      let doc;
      try { doc = iframe.contentDocument; } catch { doc = null; }
      if (!doc) continue;
      doc.querySelectorAll('.wew-discard-tag').forEach((el) => el.remove());
    }
  }

  /**
   * Print a diagnostic snapshot of each accessible preview iframe to
   * the browser console so the user can copy it back to us. Only
   * fires when the announce-mode locator missed (i.e. click on the
   * eye produced no match). Output includes:
   *   - iframe id/src
   *   - all <ve-content-element> uids found in the document
   *   - all elements with id="cN", data-uid, data-content-uid, …
   *   - a short snippet of the body markup near uid hits
   */
  _logIframeDiagnostics(iframes, liveUid, workspaceUid) {
    // Render as console.warn (yellow background, can't be missed) and
    // attach a single payload object — the user can right-click → Copy
    // object to send back. Stays at the top of the console alongside
    // the existing visual-editor / cowriter warnings.
    const payload = [];
    for (const [i, iframe] of iframes.entries()) {
      const doc = iframe.contentDocument;
      if (!doc?.body) continue;
      const veWrappers = Array.from(doc.querySelectorAll('ve-content-element'));
      const dataUidElements = Array.from(doc.querySelectorAll('[data-uid]'));
      const cElements = Array.from(doc.querySelectorAll('[id]'))
        .map((el) => el.id)
        .filter((id) => /^c\d+$/.test(id) || /tt_content/.test(id));
      const haystack = doc.body.innerHTML;
      const textHits = {};
      for (const uid of [liveUid, workspaceUid].filter((u, idx, arr) => u && arr.indexOf(u) === idx)) {
        const re = new RegExp(`["'\\s=>](c${uid}|tt_content:${uid}|uid="${uid}")["'\\s<]`, 'g');
        textHits[`uid_${uid}`] = haystack.match(re)?.slice(0, 5) || null;
      }
      payload.push({
        iframe: i,
        id: iframe.id || '(none)',
        src: iframe.src?.slice(0, 120),
        bodyLength: haystack.length,
        veContentElementCount: veWrappers.length,
        veContentElements: veWrappers.slice(0, 20).map((el) => ({
          uid: el.getAttribute('uid'),
          table: el.getAttribute('table'),
          id: el.getAttribute('id'),
          CType: el.getAttribute('CType'),
        })),
        idCElementCount: cElements.length,
        idCElements: cElements.slice(0, 30),
        dataUidElementCount: dataUidElements.length,
        dataUidElements: dataUidElements.slice(0, 20).map((el) => ({
          tag: el.tagName.toLowerCase(),
          'data-uid': el.getAttribute('data-uid'),
          'data-table': el.getAttribute('data-table'),
          'data-content-uid': el.getAttribute('data-content-uid'),
          id: el.id || null,
        })),
        textHits,
      });
    }
    console.warn(
      `[easy-workspace] eye couldn't locate uid ${liveUid} / ws #${workspaceUid}. Iframe diagnostics:`,
      payload,
    );
  }

  /**
   * Collect every iframe reachable from this document and its top,
   * including iframes nested *inside* other iframes' contentDocument.
   *
   * TYPO3 v14's Web → Edit (Visual Editor) module renders TWO frames:
   *
   *   window.top                          ← BE shell, where this toolbar lives
   *     iframe#typo3-contentIframe        ← module's content frame
   *       iframe#visual-editor-iframe     ← visual-editor's FE preview
   *
   * The content elements we need to highlight live in the *inner*
   * (visual-editor) iframe. A single-level querySelectorAll('iframe')
   * only finds #typo3-contentIframe — so we have to walk the tree.
   *
   * Skip cross-origin frames silently (their contentDocument access
   * would throw); also cap the recursion depth so a malformed page
   * with circular frame references can't infinite-loop.
   *
   * Deduped by element identity so iframes living in both root
   * contexts aren't counted twice.
   *
   * @returns {HTMLIFrameElement[]}
   */
  _collectIframes(maxDepth = 4) {
    const seen = new Set();
    const out = [];
    const roots = [document];
    try { if (window.top?.document && window.top.document !== document) roots.push(window.top.document); } catch { /* cross-origin */ }
    const walk = (root, depth) => {
      if (!root || depth > maxDepth) return;
      let frames;
      try { frames = root.querySelectorAll('iframe'); } catch { return; }
      for (const iframe of frames) {
        if (seen.has(iframe)) continue;
        seen.add(iframe);
        out.push(iframe);
        // Recurse into same-origin contentDocument. Wrapped in try
        // because cross-origin access throws synchronously.
        let inner;
        try { inner = iframe.contentDocument; } catch { inner = null; }
        if (inner) walk(inner, depth + 1);
      }
    };
    for (const root of roots) walk(root, 0);
    return out;
  }

  /**
   * Detects friendsoftypo3/visual-editor's NotLoggedIn.html gate
   * page. That template is served whenever its PersistenceMiddleware
   * rejects an editMode FE request for missing BE auth — the iframe
   * is "accessible" in the same-origin sense but contains zero
   * content elements, so the locator would otherwise mis-report a
   * generic "didn't find your element" warning.
   *
   * Signal: <title>Backend login required</title>. Robust against
   * locale (the title is set in the template head, not via
   * Fluid/translation), and identical across all visual-editor v1.x
   * releases that ship the template.
   */
  _isPreviewGate(doc) {
    if (!doc) return false;
    return doc.title === 'Backend login required';
  }

  /**
   * After a successful discard the workspace version is gone — the live
   * record is unchanged, but the editor's preview iframe is still
   * showing the now-deleted workspace render. Reload it so the live
   * content reappears.
   *
   * Scoped to FE-preview frames only:
   *  - Visual Editor:  iframe#visual-editor-iframe
   *  - cms-viewpage:   any iframe whose src carries the editMode flag
   *                    (visual-editor sets it; the View module's
   *                    plain-preview iframe doesn't, so we still catch
   *                    it via the visual-editor frame id)
   *
   * The outer BE module shell (#typo3-contentIframe) is intentionally
   * left alone so dropdown state, scroll position and any pending BE
   * form input survive the discard.
   *
   * @returns {number} count of preview iframes that were reloaded
   */
  _reloadPreviewIframes() {
    let reloaded = 0;
    for (const iframe of this._collectIframes()) {
      const src = iframe.src || '';
      const isPreview = iframe.id === 'visual-editor-iframe'
        || /[?&]editMode=/.test(src);
      if (!isPreview) continue;
      try {
        // Preferred: same-origin reload preserves the existing URL
        // (including editMode + any anchor/scroll state).
        iframe.contentWindow.location.reload();
      } catch {
        // Cross-origin or detached document — re-assigning the same
        // src forces the iframe to refetch. eslint disabled intentionally:
        // the assignment is the side-effect we want.
        // eslint-disable-next-line no-self-assign
        iframe.src = iframe.src;
      }
      reloaded++;
    }
    return reloaded;
  }

  /**
   * Iterate every reachable iframe and return the first one whose
   * contentDocument contains a content element for the given item.
   * We prefer the visual-editor <ve-content-element> wrapper because
   * dispatching mouseenter on it triggers the editor's native dashed
   * border + floating action-bar. Only if no wrapper is present do
   * we fall back to the rendered <div id="cX">.
   *
   * Probes BOTH the live uid and the workspace-version uid since the
   * visual-editor wrapper uses "versioned uid" (live for unchanged,
   * workspace for modified records).
   *
   * @returns {{ iframe: HTMLIFrameElement, doc: Document, el: HTMLElement, isVeWrapper: boolean }|null}
   */
  _locateInAnyIframe(item) {
    const target = this._locateTarget(item);
    if (target.table !== 'tt_content') return null;
    const uids = [target.liveUid, target.workspaceUid]
      .map((n) => parseInt(n, 10))
      .filter((n) => n > 0)
      .filter((n, i, arr) => arr.indexOf(n) === i); // unique
    if (uids.length === 0) return null;

    const veSelector = uids.flatMap((u) => [
      `ve-content-element[uid="${u}"][table="tt_content"]`,
      `ve-content-element[id="tt_content:${u}"]`,
    ]).join(', ');

    for (const iframe of this._collectIframes()) {
      let doc;
      try {
        doc = iframe.contentDocument || iframe.contentWindow?.document;
      } catch { continue; }
      if (!doc) continue;

      // First-choice: visual-editor's wrapper (any matching uid).
      const veEl = doc.querySelector(veSelector);
      if (veEl) return { iframe, doc, el: veEl, isVeWrapper: true };

      // Fallback for cms-viewpage / plain frontend rendering — try
      // every uid against every known inner-element selector.
      for (const u of uids) {
        const el = this._findContentElement(doc, u);
        if (el) return { iframe, doc, el, isVeWrapper: false };
      }
    }
    return null;
  }

  _locateTarget(item) {
    return {
      table: String(item?.locateTable || item?.table || ''),
      liveUid: parseInt(item?.locateLiveUid ?? item?.liveUid ?? 0, 10) || 0,
      workspaceUid: parseInt(item?.locateWorkspaceUid ?? item?.workspaceUid ?? 0, 10) || 0,
    };
  }

  _isLocatable(item) {
    const target = this._locateTarget(item);
    return this._config.enableHoverHighlight && target.table === 'tt_content' && (target.liveUid > 0 || target.workspaceUid > 0);
  }

  _isEditable(item) {
    return Boolean(item?.contextualEditUrl || item?.editUrl);
  }

  /**
   * Search every reachable document context for the Visual Editor
   * iframe. We can't always rely on `window.top` (the toolbar may be
   * nested in a chrome frame), so try `document` first and also walk
   * any same-origin parent windows.
   *
   * @returns {HTMLIFrameElement|null}
   */
  _findVisualEditorIframe() {
    const tries = [
      // friendsoftypo3/visual-editor — the canonical selector
      () => document.querySelector('iframe#visual-editor-iframe'),
      () => document.querySelector('iframe[id*="visual-editor"]'),
      () => window.top?.document?.querySelector('iframe#visual-editor-iframe'),
      () => window.top?.document?.querySelector('iframe[id*="visual-editor"]'),
      // typo3/cms-viewpage — Web → View module
      () => document.querySelector('iframe#tx_viewpage_iframe'),
      () => window.top?.document?.querySelector('iframe#tx_viewpage_iframe'),
      // Page module / preview module fallbacks
      () => window.top?.document?.querySelector('iframe[id*="page-preview"], iframe[id*="pagepreview"], iframe[name*="pagepreview"], iframe[name*="preview"]'),
      // Final fallback: any iframe whose contentDocument we can read.
      () => {
        const roots = [document, window.top?.document].filter(Boolean);
        for (const root of roots) {
          for (const candidate of root.querySelectorAll('iframe')) {
            try {
              if (candidate.contentDocument?.body) return candidate;
            } catch { /* cross-origin */ }
          }
        }
        return null;
      },
    ];
    for (const fn of tries) {
      try {
        const iframe = fn();
        if (iframe) return iframe;
      } catch { /* skip */ }
    }
    return null;
  }

  /**
   * Find the rendered DOM node for a tt_content record. The standard
   * TYPO3 convention is `<div id="c{uid}">` (fluid_styled_content),
   * but Content Blocks and custom templates may wrap differently.
   * Try many selectors before giving up.
   *
   * @param {Document} doc
   * @param {number} uid
   * @returns {HTMLElement|null}
   */
  _findContentElement(doc, uid) {
    const selectors = [
      // Standard fluid_styled_content / fsc-default frame
      '#c' + uid,
      '[id="c' + uid + '"]',
      // Content Blocks variants
      '#cb-content-' + uid,
      '#cb' + uid,
      '#content-block-' + uid,
      // Generic data-attributes used by various templates
      '[data-uid="' + uid + '"][data-table="tt_content"]',
      '[data-content-uid="' + uid + '"]',
      '[data-tt-content-uid="' + uid + '"]',
      '[data-record-uid="' + uid + '"]',
      '[data-typo3-record-uid="' + uid + '"]',
      // Visual Editor JSON payload on the wrapping element
      '[data-veedit*="\\"uid\\":' + uid + ',\\"table\\":\\"tt_content\\""]',
      '[data-veedit*="\\"uid\\":' + uid + '"][data-veedit*="tt_content"]',
      // Some templates use the live uid as classname
      '.tt-content-' + uid,
      '.ce-' + uid,
    ];

    for (const sel of selectors) {
      try {
        const hit = doc.querySelector(sel);
        if (hit) return hit;
      } catch { /* invalid selector — ignore */ }
    }

    // Last resort: scan all elements for any attribute that contains
    // both the uid AND the table name "tt_content" close together.
    const probe = doc.querySelector(`[id^="c"][id$="${uid}"]`);
    return probe || null;
  }

  _readConfig() {
    const raw = this.getAttribute('config') || '';
    if (raw === '') {
      return { ...DEFAULT_CONFIG };
    }
    try {
      const parsed = JSON.parse(raw);
      return { ...DEFAULT_CONFIG, ...parsed };
    } catch (e) {
      console.warn('[easy-workspace] Could not parse TSconfig payload, falling back to defaults.', e);
      return { ...DEFAULT_CONFIG };
    }
  }

  _label(key, variables = {}) {
    const labels = this._config.labels || {};
    const message = typeof labels[key] === 'string' && labels[key] !== '' ? labels[key] : key;
    return this._formatIcu(message, variables);
  }

  _formatIcu(message, variables = {}) {
    let formatted = String(message);
    formatted = formatted.replace(
      /\{(\w+),\s*plural,\s*one\s*\{([^{}]*)}\s*other\s*\{([^{}]*)}}/g,
      (_match, name, one, other) => {
        const count = Number(variables[name] ?? 0);
        return (count === 1 ? one : other).replaceAll('#', String(count));
      },
    );
    for (const [name, value] of Object.entries(variables)) {
      formatted = formatted.replaceAll(`{${name}}`, String(value));
    }
    return formatted;
  }

  render() {
    const compactClass = this._isCompactToolbar() ? ' wew-menu--compact-toolbar' : '';
    return html`
      <div class="wew-menu${compactClass}">
        <header class="wew-menu__head">
          <div class="wew-menu__icon" aria-hidden="true">
            <!-- TYPO3 core "module-workspaces" — currentColor + accent (orange). -->
            <svg viewBox="0 0 64 64" width="22" height="22">
              <path fill="currentColor" d="M36 20v20H16V20h20m2-4H14c-1.1 0-2 .9-2 2v24c0 1.1.9 2 2 2h24c1.1 0 2-.9 2-2V18c0-1.1-.9-2-2-2Z"/>
              <path fill="var(--icon-color-accent, #ff8700)" d="M50 24H40v4h8v20H28v-4h-4v6c0 1.1.9 2 2 2h24c1.1 0 2-.9 2-2V26c0-1.1-.9-2-2-2Z"/>
              <path fill="currentColor" opacity=".4" d="M40 18v6h8V14c0-1.1-.9-2-2-2H22c-1.1 0-2 .9-2 2v2h18c1.1 0 2 .9 2 2Z"/>
            </svg>
          </div>
          <div class="wew-menu__title-wrap">
            <h3 class="wew-menu__title">
              <span>${this._label('toolbar.title')}</span>
              ${this._config.enableWorkspaceChip && this.workspaceTitle
                ? html`<span class="wew-menu__ws-chip" title=${this._label('toolbar.activeWorkspace')}>${this.workspaceTitle}</span>`
                : nothing}
            </h3>
          </div>
          ${this._renderPreviewButton()}
        </header>
        ${this._renderFilter()}
        ${this._renderBody()}
        ${this._renderFooter()}
        ${this._renderContextFootnote()}
      </div>
    `;
  }

  /**
   * Tri-state master-checkbox selection bar — the proper pattern for
   * "select all" in a multi-item list (GitHub PRs, Gmail, JIRA all
   * use this). One control, three states:
   *
   *   unchecked      → click selects all changeable rows
   *   indeterminate  → click selects all changeable rows
   *   checked        → click deselects all
   *
   * Replaces the "Select all · None" link pair which mixed two
   * mutually exclusive commands into parallel affordances and gave
   * editors no visual cue about the current selection state.
   */
  _renderSelectionBar() {
    if (this.state !== 'loaded') return nothing;
    const changeable = this.items.filter((i) => i.isChanged);
    if (changeable.length === 0) return nothing;
    const selectedCount = this.selection.size;
    const allChecked = selectedCount === changeable.length;
    const someChecked = selectedCount > 0 && selectedCount < changeable.length;
    const label = allChecked
      ? this._label('toolbar.allSelected')
      : (someChecked ? this._label('toolbar.someSelected') : this._label('toolbar.selectAll'));
    return html`
      <div class="wew-menu__selectbar">
        <label class="wew-menu__selectbar-label">
          <input
            type="checkbox"
            class="wew-menu__selectbar-check"
            .checked=${allChecked}
            .indeterminate=${someChecked}
            aria-checked=${allChecked ? 'true' : (someChecked ? 'mixed' : 'false')}
            aria-label=${allChecked ? this._label('toolbar.deselectAllChanges') : this._label('toolbar.selectAllChanges')}
            @change=${() => this._selectAll(!allChecked)}
          />
          <span class="wew-menu__selectbar-text">${label}</span>
        </label>
        <span class="wew-menu__selectbar-count" aria-hidden="true">${this._label('toolbar.selectedOf', { selected: selectedCount, total: changeable.length })}</span>
      </div>
    `;
  }

  /**
   * "Copy to clipboard" glyph: two overlapping documents. Replaces
   * the old chain-link icon since editors kept reading the button
   * as "open this link" rather than "copy the URL". The
   * two-rectangle motif is the conventional copy affordance
   * (Office, GitHub, browser DevTools all use it).
   */
  _copyIcon() {
    return html`
      <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
        <rect x="3" y="3" width="8" height="9" rx="1.2"
              fill="none" stroke="currentColor" stroke-width="1.3"/>
        <path d="M5.5 5h.7M5.5 7h3M5.5 9h3"
              stroke="currentColor" stroke-width="1.1" stroke-linecap="round" fill="none"/>
        <rect x="6" y="6" width="7" height="8" rx="1.2"
              fill="var(--typo3-state-default-bg, currentColor)"
              stroke="currentColor" stroke-width="1.3"/>
      </svg>
    `;
  }

  /**
   * Checkmark glyph for the transient "Copied" state right after
   * a successful clipboard write. Uses --typo3-state-success-color
   * so it reads as positive confirmation against the button's
   * default surface.
   */
  _checkIcon() {
    return html`
      <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
        <path d="M3.5 8.5l2.7 2.7L12.5 4.8"
              fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    `;
  }

  _linkIcon() {
    return html`
      <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
        <path d="M6.5 9.5a2.5 2.5 0 0 0 3.54 0l2-2a2.5 2.5 0 0 0-3.54-3.54l-.66.66"
              fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        <path d="M9.5 6.5a2.5 2.5 0 0 0-3.54 0l-2 2a2.5 2.5 0 0 0 3.54 3.54l.66-.66"
              fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    `;
  }

  /**
   * WAI-ARIA "Tabs with Automatic Activation" pattern.
   * https://www.w3.org/WAI/ARIA/apg/patterns/tabs/
   *
   *  - role=tablist around the buttons
   *  - role=tab on each button + aria-selected + aria-controls
   *  - role=tabpanel on the list area + aria-labelledby
   *  - Roving tabindex: active tab tabindex=0, others tabindex=-1
   *  - Arrow Left/Right + Home/End move focus AND activate
   *    ("automatic activation" is the friendlier UX for filter tabs)
   */
  _renderFilter() {
    if (!this._config.enableFilter) {
      return nothing;
    }
    if (this.state === 'loading' || this.state === 'no-context' || this.state === 'error') {
      return nothing;
    }
    // Counts shown next to each tab label. "To publish" only counts
    // records that actually have a workspace version (isChanged), while
    // "All on page" counts everything in scope — matches the panel
    // contents under each mode.
    const changedCount = this.items.filter((i) => i.isChanged).length;
    const totalCount = this.items.length;
    return html`
      <div
        class="wew-menu__filter"
        role="tablist"
        aria-label=${this._label('toolbar.filter.aria')}
        @keydown=${(e) => this._handleTabKeydown(e)}
      >
        <button
          type="button"
          id="wew-tab-changed"
          class="wew-menu__chip ${this.mode === 'changed' ? 'wew-menu__chip--active' : ''}"
          role="tab"
          aria-selected=${this.mode === 'changed' ? 'true' : 'false'}
          aria-controls="wew-tabpanel"
          tabindex=${this.mode === 'changed' ? '0' : '-1'}
          @click=${() => this._setMode('changed')}
        >${this._label('toolbar.tab.changed')} <span class="wew-menu__chip-count" aria-label=${this._label('toolbar.records', { count: changedCount })}>${changedCount}</span></button>
        <button
          type="button"
          id="wew-tab-all"
          class="wew-menu__chip ${this.mode === 'all' ? 'wew-menu__chip--active' : ''}"
          role="tab"
          aria-selected=${this.mode === 'all' ? 'true' : 'false'}
          aria-controls="wew-tabpanel"
          tabindex=${this.mode === 'all' ? '0' : '-1'}
          @click=${() => this._setMode('all')}
        >${this._label('toolbar.tab.all')} <span class="wew-menu__chip-count" aria-label=${this._label('toolbar.records', { count: totalCount })}>${totalCount}</span></button>
      </div>
    `;
  }

  _handleTabKeydown(event) {
    const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
    if (!keys.includes(event.key)) return;
    event.preventDefault();
    const tabs = Array.from(this.querySelectorAll('.wew-menu__filter [role="tab"]'));
    if (tabs.length === 0) return;
    const currentIdx = tabs.indexOf(document.activeElement);
    let nextIdx;
    switch (event.key) {
      case 'ArrowLeft':  nextIdx = currentIdx <= 0 ? tabs.length - 1 : currentIdx - 1; break;
      case 'ArrowRight': nextIdx = currentIdx >= tabs.length - 1 ? 0 : currentIdx + 1; break;
      case 'Home':       nextIdx = 0; break;
      case 'End':        nextIdx = tabs.length - 1; break;
      default:           return;
    }
    const next = tabs[nextIdx];
    next.focus();
    next.click(); // automatic activation
  }

  _renderBody() {
    // The panel changes contents based on the active tab; that's
    // the WAI-ARIA "single tabpanel reflecting selection" pattern
    // referenced by ARIA APG when the panel content is filtered
    // rather than swapped wholesale.
    return html`
      <div
        id="wew-tabpanel"
        role=${this._config.enableFilter ? 'tabpanel' : nothing}
        aria-labelledby=${this._config.enableFilter
          ? (this.mode === 'all' ? 'wew-tab-all' : 'wew-tab-changed')
          : nothing}
      >
        ${this._renderBodyInner()}
      </div>
    `;
  }

  _renderBodyInner() {
    if (this.state === 'loading') {
      return html`
        <div class="wew-menu__loading">
          <typo3-backend-spinner size="default"></typo3-backend-spinner>
          <span>${this._label('toolbar.loading')}</span>
        </div>
      `;
    }
    if (this.state === 'no-context') {
      return html`
        <div class="alert alert-info wew-menu__alert" role="status">
          ${this._label('toolbar.noContext')}
        </div>
      `;
    }
    if (this.state === 'empty') {
      const message = this.mode === 'all'
        ? this._label('toolbar.empty.all')
        : this._label('toolbar.empty.changed');
      return html`
        <div class="wew-menu__empty">
          <span class="wew-menu__empty-icon" aria-hidden="true">✓</span>
          <p>${message}</p>
        </div>
      `;
    }
    if (this.state === 'error') {
      return html`<div class="alert alert-danger wew-menu__alert">${this._label('toolbar.loadError')}</div>`;
    }
    // Client-side filter: "To publish" hides records without a
    // workspace version, "All on page" shows everything. The server
    // already returned the full record set so we don't refetch.
    const visible = this.mode === 'changed'
      ? this.items.filter((i) => i.isChanged)
      : this.items;
    if (visible.length === 0) {
      const message = this.mode === 'all'
        ? this._label('toolbar.empty.all')
        : this._label('toolbar.empty.changed');
      return html`
        <div class="wew-menu__empty">
          <span class="wew-menu__empty-icon" aria-hidden="true">✓</span>
          <p>${message}</p>
        </div>
      `;
    }
    const rendered = [];
    for (const group of this._visibleGroups()) {
      if (group.label) {
        rendered.push(html`
          <li class="wew-list__colheader" role="presentation">
            <span class="wew-list__colheader-label">${group.label}</span>
          </li>
        `);
      }
      for (const item of group.items) {
        rendered.push(this._renderItem(item));
      }
    }
    return html`
      <ul class="wew-list">
        ${rendered}
      </ul>
    `;
  }

  _visibleGroups() {
    const groups = this.mode === 'changed'
      ? (Array.isArray(this.changedItemGroups) ? this.changedItemGroups : [])
      : (Array.isArray(this.itemGroups) ? this.itemGroups : []);
    return groups
      .map((group) => ({
        key: group.key || '',
        label: group.label || null,
        items: Array.isArray(group.items) ? group.items : [],
      }))
      .filter((group) => group.items.length > 0);
  }

  _renderItem(item) {
    const id = `wew-${item.table}-${item.workspaceUid}`;
    const key = this._key(item);
    const checked = this.selection.has(key);
    const stateClasses = [
      'wew-list__row',
      item.isPrimary ? 'wew-list__row--primary' : '',
      item.isChanged ? 'wew-list__row--changed' : 'wew-list__row--unchanged',
      item.isHidden ? 'wew-list__row--hidden' : '',
    ].filter(Boolean).join(' ');

    const locatable = this._isLocatable(item);
    const editable = this._isEditable(item);
    // Render the discard icon on every changed row so the action
    // affordance never silently disappears between items. When the
    // admin has turned off rollback in TSconfig (enableRevert=false)
    // we render the icon in a disabled-greyed state instead of
    // hiding it — the visual placeholder tells the editor "yes, this
    // is where the discard control lives, your environment just has
    // it switched off" rather than leaving them hunting for a missing
    // button.
    const canRevert = this._config.enableRevert && item.isChanged;
    const showRevertButton = item.isChanged;
    const hasActions = locatable || editable || showRevertButton;
    const primaryRecordLabel = this._primaryRecordLabel(item);
    // Diff data ships with every changed row via PendingItem::$diff.
    // Empty for unchanged rows or rows the diff service couldn't
    // resolve (broken FAL refs, etc.) — in which case we just skip
    // the disclosure so the row reads cleanly without a "0 changes"
    // affordance to nowhere.
    const changeRecords = this._changeRecordsForItem(item);
    // Compact row layout:
    //  Left:  title, type metadata, then History + change badges.
    //  Right: stacked icon actions, one per row (discard, locate/edit).
    //
    // The checkbox is visually hidden (.visually-hidden CSS) but kept
    // in the DOM and focusable, so screen-reader users still get a
    // semantic toggle. The whole <label> is the click target — toggles
    // the checkbox — and CSS via :has(:checked) tints the row + left
    // stripe so the selected state is obvious without occupying the
    // first column.
    return html`
      <li class=${stateClasses} data-table=${item.table}>
        <label class="wew-list__label" for=${item.isChanged ? id : nothing}>
          ${item.isChanged
            ? html`<input
                type="checkbox"
                id=${id}
                class="form-check-input wew-list__check visually-hidden"
                .checked=${checked}
                @change=${(e) => this._toggle(item, e.target.checked)}
              />
              <span class="wew-list__mark" aria-hidden="true">
                <svg viewBox="0 0 16 16" width="10" height="10" aria-hidden="true">
                  <path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M3 8.5l3 3 7-7"/>
                </svg>
              </span>`
            : nothing}
          ${this._renderThumbnail(item)}
          <span class="wew-list__body">
            ${primaryRecordLabel
              ? html`<span class="wew-list__primary-kicker">${primaryRecordLabel}</span>`
              : nothing}
            <span class="wew-list__head">
              <span class="wew-list__title-text" title=${item.title}>${item.title}</span>
            </span>
            <span class="wew-list__sub">
              ${this._config.enableTypeLabels
                ? html`<span class="wew-list__table">
                    ${item.tableLabel || this._friendlyTable(item.table)}${
                      item.typeLabel && item.typeLabel !== item.tableLabel
                        ? html` <span class="wew-list__sep">·</span> ${item.typeLabel}`
                        : nothing
                    }
                  </span>`
                : nothing}
            </span>
            ${item.isChanged
              ? html`<span class="wew-list__change-actions">
                  ${ENDPOINTS.diff && changeRecords.length > 0
                    ? this._renderChangeAction(changeRecords[0])
                    : nothing}
                  ${this._renderChangeBadges(item)}
                  ${this._shouldShowStatePills(item) && item.isHidden && this._config.enableHiddenBadge
                    ? html`<span class="wew-state-pill wew-state-pill--secondary wew-state-pill--inline" title=${this._label('toolbar.hidden.title')}>${this._label('toolbar.hidden')}</span>`
                    : nothing}
                </span>`
              : nothing}
            ${this._renderChildChanges(item)}
          </span>
          ${hasActions
            ? html`<span class="wew-list__actions" @click=${(e) => e.preventDefault()}>
                ${showRevertButton ? this._renderRevertButton(item, canRevert) : nothing}
                ${locatable || editable ? this._renderLocateButton(item, locatable) : nothing}
              </span>`
            : nothing}
        </label>
      </li>
    `;
  }

  _renderChangeBadges(item) {
    if (!this._shouldShowStatePills(item)) return nothing;
    const badges = Array.isArray(item.changeBadges) && item.changeBadges.length > 0
      ? item.changeBadges
      : (item.kindLabel && this._shouldShowSubelementDetails()
        ? [{ kindKey: item.kindKey, kindLabel: item.kindLabel, badge: item.badge || 'info' }]
        : []);
    if (badges.length === 0) return nothing;
    return html`
      <span class="wew-list__badges">
        ${badges.map((badge) => html`
          <span class="wew-state-pill wew-state-pill--${badge.badge || 'info'} wew-state-pill--inline">${badge.kindLabel}</span>
        `)}
      </span>
    `;
  }

  _renderThumbnail(item) {
    if (!item?.thumbnailUrl) return nothing;
    return html`
      <span class="wew-list__thumb">
        <img src=${item.thumbnailUrl} alt="" loading="lazy">
      </span>
    `;
  }

  _renderChildChanges(item) {
    if (!this._shouldShowSubelementDetails()) return nothing;
    const children = Array.isArray(item.childChanges) ? item.childChanges : [];
    if (children.length === 0) return nothing;
    return html`
      <span class="wew-list__children">
        ${children.map((child) => html`
          <span class="wew-list__child-change">
            ${child.thumbnailUrl
              ? html`<span class="wew-list__child-thumb"><img src=${child.thumbnailUrl} alt="" loading="lazy"></span>`
              : html`<span class="wew-list__child-icon" aria-hidden="true"></span>`}
            <span class="wew-list__child-body">
              <span class="wew-list__child-title" title=${child.title || ''}>${child.title || child.tableLabel || child.table}</span>
              <span class="wew-list__child-meta">
                ${child.tableLabel || child.table}${child.typeLabel ? html` <span class="wew-list__sep">·</span> ${child.typeLabel}` : nothing}
              </span>
            </span>
            <span class="wew-state-pill wew-state-pill--${child.badge || 'info'} wew-state-pill--inline">${child.kindLabel || ''}</span>
          </span>
        `)}
      </span>
    `;
  }

  _primaryRecordLabel(item) {
    if (!item?.isPrimary) return '';
    if (item.table === 'pages') {
      return this._label('record.pageRecord');
    }
    if (item.table === 'tx_news_domain_model_news') {
      return this._label('record.newsRecord');
    }
    return '';
  }

  _changeRecordsForItem(item) {
    const records = Array.isArray(item.changeRecords) && item.changeRecords.length > 0
      ? item.changeRecords
      : (item.isChanged ? [item] : []);
    const byKind = new Map();
    for (const record of records) {
      const kind = record?.kindKey || 'modified';
      if (!byKind.has(kind)) {
        byKind.set(kind, { ...item, ...record });
      }
    }
    return Array.from(byKind.values());
  }

  _renderChangeAction(record) {
    const diff = Array.isArray(record.diff) ? record.diff : [];
    const hasDiff = diff.length > 0;
    return html`
      <button
        type="button"
        class="wew-list__diff-trigger"
        title=${this._diffTriggerTitle(record, hasDiff)}
        @click=${(e) => { e.preventDefault(); e.stopPropagation(); this._openDiffModal(record); }}
      >
        <span class="wew-list__diff-trigger-icon" aria-hidden="true">⇄</span>
        <span class="wew-list__diff-trigger-label">${this._diffTriggerLabel()}</span>
      </button>
    `;
  }

  _diffTriggerLabel() {
    return this._label('diff.viewHistory');
  }

  _diffTriggerTitle(item, hasDiff) {
    if (item.kindKey === 'new') {
      const historyDiffCount = Number.isInteger(item.historyDiffCount) ? item.historyDiffCount : 0;
      if (historyDiffCount > 0) {
        return this._label('diff.title.newWithChanges');
      }
      return this._label('diff.title.newDetails');
    }
    if (item.kindKey === 'delete') {
      return this._label('diff.title.removal');
    }
    if (item.kindKey === 'move') {
      return this._label('diff.title.move');
    }
    return hasDiff
      ? this._label('diff.title.changed')
      : this._label('diff.title.history');
  }

  /**
   * The "discard" button — discard the workspace version of a single
   * record after a warning confirmation modal. SVG inlined from TYPO3
   * core's actions-undo icon (currentColor). "Discard" is TYPO3's own
   * term for the operation (the DataHandler command is `flush`).
   *
   * When `canRevert` is false (admin has turned off enableRevert in
   * TSconfig) the same button renders in a disabled-greyed state
   * instead of being omitted from the row. Keeps the action column
   * visually consistent across editors with different TSconfig and
   * tells the user "the control lives here, your env has it off"
   * instead of leaving an unexplained gap.
   */
  _renderRevertButton(item, canRevert = true) {
    const title = canRevert
      ? this._label('discard.button.title')
      : this._label('discard.button.disabledTitle');
    const ariaLabel = canRevert
      ? this._label('discard.button.aria')
      : this._label('discard.button.disabledAria');
    const cls = `wew-list__discard${canRevert ? '' : ' wew-list__discard--disabled'}`;
    // Hover preview wiring is only meaningful when the button is
    // active — bind nothing when the button is disabled so we don't
    // light up the iframe outline on a click that can't happen.
    return html`
      <button
        type="button"
        class=${cls}
        title=${title}
        aria-label=${ariaLabel}
        ?disabled=${!canRevert}
        @mouseenter=${canRevert ? () => this._previewDiscard(item) : null}
        @mouseleave=${canRevert ? () => this._clearIframeHighlight() : null}
        @focus=${canRevert ? () => this._previewDiscard(item) : null}
        @blur=${canRevert ? () => this._clearIframeHighlight() : null}
        @click=${canRevert
          ? (e) => { e.preventDefault(); e.stopPropagation(); this._clearIframeHighlight(); this._confirmAndDiscard(item); }
          : (e) => { e.preventDefault(); e.stopPropagation(); }}
      >
        <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
          <path
            fill="currentColor"
            d="M8 2c-1.8 0-3.4.8-4.5 2l-1-1c-.2-.2-.4-.1-.4.1l-.9 3.8c0 .2.1.3.3.3l3.8-.9c.2 0 .3-.3.1-.4l-1-1c.9-1 2.2-1.7 3.7-1.7 2.7 0 4.9 2.2 4.9 4.9S10.8 13 8.1 13c-1.5 0-2.8-.7-3.7-1.7l-.9.7c1.1 1.2 2.7 2 4.5 2 3.3 0 6-2.7 6-6s-2.7-6-6-6z"
          />
        </svg>
      </button>
    `;
  }

  /**
   * Hover/focus the discard button → preview what'd be discarded.
   * Same scroll + outline as the eye locator, plus injects a small
   * floating tag into the iframe document anchored to the outlined
   * element. mouseleave clears both via _clearIframeHighlight().
   *
   * Reuses the existing highlight infrastructure so we don't have a
   * second source of truth for "outlined element" — that one
   * already deals with workspace-vs-live uid resolution.
   */
  _previewDiscard(item) {
    const located = this._locateInAnyIframe(item);
    if (!located) return;
    this._highlightInIframe(item, { announce: false });
    // Add a "Decline the changes" tag inside the iframe document,
    // anchored to the located element. Lives in the iframe so the
    // BE dropdown's z-index doesn't fight it — and clears on
    // _clearIframeHighlight() since we tag it with a class the
    // cleanup routine looks for.
    try {
      const doc = located.doc;
      const existing = doc.querySelector('.wew-discard-tag');
      if (existing) existing.remove();
      // Two-line label, server-translated. Title carries the action
      // verb ("Decline the changes" / "Änderungen verwerfen"),
      // subtitle adds the consequence in plain language ("Back to
      // the last published version" / "Zurück zur zuletzt
      // veröffentlichten Version") — same idea as a snackbar's
      // primary + secondary line.
      const title = this._label('discardTag.title');
      const subtitle = this._label('discardTag.subtitle');
      const tag = doc.createElement('div');
      tag.className = 'wew-discard-tag';
      Object.assign(tag.style, {
        position: 'absolute',
        zIndex: '999999',
        padding: '4px 9px 5px',
        fontSize: '11px',
        lineHeight: '1.25',
        background: 'rgba(220, 53, 69, 0.78)',
        color: '#fff',
        border: '1px solid rgba(255, 255, 255, 0.32)',
        borderRadius: '3px',
        boxShadow: '0 3px 12px rgba(0, 0, 0, 0.28)',
        backdropFilter: 'blur(4px) saturate(135%)',
        WebkitBackdropFilter: 'blur(4px) saturate(135%)',
        pointerEvents: 'none',
        whiteSpace: 'nowrap',
        fontFamily: 'inherit',
        textShadow: '0 1px 1px rgba(0, 0, 0, 0.38)',
      });
      const titleEl = doc.createElement('div');
      titleEl.textContent = title;
      titleEl.style.fontWeight = '600';
      const subtitleEl = doc.createElement('div');
      subtitleEl.textContent = subtitle;
      subtitleEl.style.fontWeight = '400';
      subtitleEl.style.opacity = '.92';
      subtitleEl.style.fontSize = '10.5px';
      tag.appendChild(titleEl);
      tag.appendChild(subtitleEl);
      const rect = located.el.getBoundingClientRect();
      const scrollY = doc.defaultView?.scrollY ?? 0;
      const scrollX = doc.defaultView?.scrollX ?? 0;
      // Two-line tag is taller; offset above the element so it
      // doesn't cover the first line of content.
      doc.body.appendChild(tag);
      const tagHeight = tag.getBoundingClientRect().height || 36;
      tag.style.top = `${rect.top + scrollY - tagHeight - 6}px`;
      tag.style.left = `${rect.left + scrollX}px`;
    } catch {
      /* DOM access guarded — cross-origin iframes throw silently. */
    }
  }

  /**
   * Confirm and run the discard via the v14 DataHandler flush command.
   * Confirmation modal uses SeverityEnum.warning + a btn-warning
   * confirm action so editors clearly see the operation is destructive.
   */
  async _confirmAndDiscard(item) {
    if (!ENDPOINTS.discard) return;

    const modal = Modal.confirm(
      this._label('discard.modal.title'),
      this._label('discard.modal.message', { title: item.title, table: item.tableLabel || item.table }),
      SeverityEnum.warning,
      [
        { text: this._label('discard.modal.cancel'), btnClass: 'btn-default', name: 'cancel', trigger: () => modal.hideModal() },
        { text: this._label('discard.modal.confirm'), btnClass: 'btn-warning', name: 'discard', active: true, trigger: () => modal.hideModal() },
      ],
    );

    return new Promise((resolve) => {
      modal.addEventListener('button.clicked', async (event) => {
        const choice = event.target?.getAttribute('name');
        if (choice !== 'discard') {
          resolve(false);
          return;
        }
        try {
          const results = [];
          for (const record of this._publishRecordsForItem(item)) {
            const response = await new AjaxRequest(ENDPOINTS.discard)
              .post(
                { table: record.table, workspaceUid: record.workspaceUid },
                { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
              );
            results.push(await response.resolve());
          }
          const failed = results.filter((result) => !result?.success);
          if (failed.length === 0) {
            Notification.success(this._label('discard.success.title'), this._label('discard.success.message', { title: item.title }), 4);
            // Refresh our own list, then reload the editor's preview
            // iframe so the live record (no longer overlaid by the
            // discarded workspace version) is rendered.
            await this._refresh();
            this._reloadPreviewIframes();
          } else {
            const errors = failed.flatMap((result) => Array.isArray(result?.errors) && result.errors.length
              ? result.errors
              : [result?.error || this._label('error.unknown')]).join(' / ');
            Notification.error(this._label('discard.error.title'), errors);
          }
        } catch (error) {
          Notification.error(this._label('discard.error.failedTitle'), error?.message || this._label('error.unexpected'));
        } finally {
          resolve(true);
        }
      });
    });
  }

  /**
   * The "locate / edit" button — two-step affordance:
   *
   *   step 1 (hover): scroll the Visual Editor iframe to this
   *                   element and outline it. Icon shows the eye:
   *                   "we're showing you where it is".
   *   step 2 (click): open the record's edit form in a right-side
   *                   sheet modal. Icon morphs to a pencil while
   *                   hovered: "the next click edits".
   *
   * The eye→pencil swap is opacity-only with a stack of two SVGs
   * sharing the same hit-area, so the icon transition is smooth
   * and there's no layout jump.
   *
   * SVG paths are inlined from TYPO3 core's actions-view-page and
   * actions-document-open icons (currentColor) so they automatically
   * follow the button's hover color.
   */
  _renderLocateButton(item, canHighlight = true) {
    const hasEditUrl = !!item.editUrl;
    const editPart = hasEditUrl ? this._label('edit.tooltip.part') : '';
    const tooltip = canHighlight
      ? (this._config.hasVisualEditor
          ? this._label('edit.tooltip.visualEditor', { editPart })
          : this._label('edit.tooltip.preview', { editPart }))
      : this._label('edit.modalTitle', { title: item.title || this._label('diff.noTitle') });
    const className = canHighlight ? 'wew-list__locate' : 'wew-list__locate wew-list__locate--edit-only';
    return html`
      <button
        type="button"
        class=${className}
        title=${tooltip}
        aria-label=${tooltip}
        @mouseenter=${canHighlight ? (e) => { e.stopPropagation(); this._highlightInIframe(item); } : null}
        @mouseleave=${canHighlight ? () => this._clearIframeHighlight() : null}
        @focus=${canHighlight ? () => this._highlightInIframe(item) : null}
        @blur=${canHighlight ? () => this._clearIframeHighlight() : null}
        @click=${(e) => {
          e.preventDefault();
          e.stopPropagation();
          this._clearIframeHighlight();
          if (hasEditUrl) {
            this._openEditModal(item);
          } else {
            Notification.info(this._label('edit.title'), this._label('edit.noForm'));
          }
        }}
      >
        <svg class="wew-list__locate-icon wew-list__locate-icon--eye" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
          <path
            fill="currentColor"
            d="M8.07 3C4.112 3 1 5.286 1 8s2.97 5 7 5c3.889 0 7-2.286 7-4.93C15 5.285 11.889 3.142 8.212 3h-.141Zm-.025 1.127c.141 0 .423.141.423.282s-.14.282-.423.282c-.845 0-1.69.704-1.69 1.55 0 .14-.141.282-.423.282-.282 0-.423-.141-.423-.282.141-1.127 1.268-2.114 2.536-2.114ZM2 8.03c0-1.298 1.017-2.591 2.647-3.312-.296.432-.296 1.01-.296 1.587 0 2.02 1.63 3.606 3.703 3.606 2.074 0 3.704-1.587 3.704-3.606 0-.577-.148-1.01-.296-1.443C12.943 5.582 14 6.875 14 8.029c-.148 2.02-2.841 3.924-6 3.971-3.36-.047-6-1.95-6-3.97Z"
          />
        </svg>
        <svg class="wew-list__locate-icon wew-list__locate-icon--pencil" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
          <path
            fill="currentColor"
            d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l10-10ZM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207 11.207 2.5ZM12.793 5.5 10.5 3.207 3 10.707V11h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293L12.793 5.5Zm-9.761 5.175-.215.541.659.659.541-.215-.215-.215a.5.5 0 0 1-.137-.275.5.5 0 0 1-.275-.137l-.358-.358Z"
          />
        </svg>
      </button>
    `;
  }

  /**
   * Open the record's edit form using TYPO3 v14's
   * ContextualRecordEditController — the lightweight FormEngine
   * variant designed for sheet-position modals (slim "Save / Close"
   * chrome, no breadcrumb or doc-header toolbar). This is the same
   * dialog the Page Layout module's "Edit page properties" button
   * opens. We replicate the wiring from core's
   * <typo3-backend-contextual-record-edit-trigger> element:
   *
   *   - size: expand, position: sheet, hideHeader: true
   *   - listen for the iframe's `typo3:editform:saved`,
   *     `typo3:editform:closed`, `typo3:editform:navigate`
   *     postMessages
   *   - on close-from-outside, request the form to confirm-and-close
   *     via `typo3:editform:requestclose` (handles "you have
   *     unsaved changes" prompts)
   *
   * Falls back to the regular record_edit URL (full chrome) if the
   * contextual URL is missing — older TYPO3 versions that don't
   * define the route still get an editable form.
   */
  _openEditModal(item) {
    const url = item.contextualEditUrl || item.editUrl;
    if (!url) return;
    const isContextual = Boolean(item.contextualEditUrl);
    const modal = Modal.advanced({
      title: isContextual ? '' : this._label('edit.modalTitle', { title: item.title || this._label('diff.noTitle') }),
      type: ModalTypes.iframe,
      content: url,
      size: isContextual ? ModalSizes.expand : ModalSizes.large,
      position: ModalPositions.sheet,
      hideHeader: isContextual,
      additionalCssClasses: ['wew-edit-modal-shell'],
    });
    if (isContextual) {
      this._wireContextualEditModal(modal, item);
    }
  }

  /**
   * Plumb the postMessage protocol the ContextualRecordEditController
   * uses to signal save / close back to the parent window. Mirrors
   * `setupMessageHandling` from core's contextual-record-edit-trigger,
   * with one extension-specific behaviour: on a successful save the
   * workspace dropdown refreshes so the row reflects the new field
   * values + any preview iframes reload.
   */
  _wireContextualEditModal(modal, item) {
    const topWindow = window.top || window;
    let savedTitle = '';
    let saved = false;
    let closedExplicitly = false;
    const onMessage = (event) => {
      if (event.origin !== window.location.origin) return;
      const action = event.data?.actionName;
      if (action === 'typo3:editform:saved') {
        saved = true;
        savedTitle = event.data.recordTitle ?? '';
      } else if (action === 'typo3:editform:closed' || action === 'typo3:editform:navigate') {
        closedExplicitly = true;
        modal.hideModal();
      }
    };
    topWindow.addEventListener('message', onMessage);
    modal.addEventListener('typo3-modal-hide', (event) => {
      if (closedExplicitly) return;
      // Ask the iframe to handle its own close — gives FormEngine
      // a chance to prompt the editor if there are unsaved changes.
      event.preventDefault();
      modal.querySelector('iframe')?.contentWindow?.postMessage(
        { actionName: 'typo3:editform:requestclose' },
        window.location.origin,
      );
    });
    modal.addEventListener('typo3-modal-hidden', async () => {
      topWindow.removeEventListener('message', onMessage);
      if (!saved) return;
      await this._refresh();
      this._reloadPreviewIframes();
      Notification.success(
        this._label('edit.saved.title'),
        savedTitle ? this._label('edit.saved.messageWithTitle', { title: savedTitle }) : this._label('edit.saved.message'),
        4,
      );
    });
  }

  /**
   * Footer is the action area of the dropdown. Three visual tiers,
   * left-to-right:
   *
   *   tier 3 (least): tiny icon-only Preview link
   *   tier 2:         tri-state master checkbox + "N of M selected"
   *   tier 1 (most):  the primary "Publish to live" button
   *
   * On narrow viewports the three tiers stack onto separate rows in
   * the same order, keeping Publish at the bottom-right (thumb-zone
   * on touch).
   */
  /**
   * Top-right preview-link affordance. Promoted from a tiny
   * icon-only button in the footer to a labeled icon+text button in
   * the header — editors kept missing it tucked away next to the
   * publish action, and the preview link is the first thing many
   * want for a quick shareable URL.
   *
   * Only renders when:
   *   - the feature is enabled in TSconfig (enablePreviewLink)
   *   - a page context is detected (preview is page-scoped)
   *   - we're past the "loading" state (otherwise the button would
   *     race the AJAX response and confuse editors mid-load)
   */
  _renderPreviewButton() {
    if (!this._config.enablePreviewLink) return nothing;
    if (this.state !== 'loaded' && this.state !== 'empty') return nothing;
    const { pageUid } = this._detectContext();
    if (pageUid <= 0) return nothing;
    // Three visual states:
    //  - idle      : copy icon + "Preview" label
    //  - loading   : spinner + "Copying…"  (AJAX in flight)
    //  - just-copied: check icon + "Copied" in success color (2 s
    //    after the clipboard write succeeds — gives editors
    //    immediate confirmation that the click did the copy and
    //    they can now paste the URL elsewhere).
    const justCopied = this.previewJustCopied && !this.copyingPreview;
    const stateClass = justCopied ? 'wew-menu__preview wew-menu__preview--copied' : 'wew-menu__preview';
    return html`
      <button
        type="button"
        class=${stateClass}
        title=${this._label('preview.button.title')}
        @click=${() => this._copyPreviewLink(pageUid)}
        ?disabled=${this.copyingPreview}
      >
        ${this.copyingPreview
          ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
          : justCopied
            ? this._checkIcon()
            : this._copyIcon()}
        <span class="wew-menu__preview-label">${this.copyingPreview ? this._label('preview.button.copying') : justCopied ? this._label('preview.button.copied') : this._label('preview.button.preview')}</span>
      </button>
    `;
  }

  _renderFooter() {
    if (this.state !== 'loaded') {
      return nothing;
    }
    const changeable = this.items.filter((i) => i.isChanged);
    const total = changeable.length;
    const selectedCount = this.selection.size;
    const allChecked = total > 0 && selectedCount === total;
    const someChecked = selectedCount > 0 && selectedCount < total;
    const selectLabel = allChecked
      ? this._label('toolbar.deselectAll')
      : (someChecked ? this._label('toolbar.selectAll') : this._label('toolbar.selectAll'));
    return html`
      <footer class="wew-menu__foot">
        <div class="wew-menu__foot-selection">
          ${total > 0 ? html`
            <label class="wew-menu__selectall">
              <input
                type="checkbox"
                class="wew-menu__selectall-check"
                .checked=${allChecked}
                .indeterminate=${someChecked}
                aria-checked=${allChecked ? 'true' : (someChecked ? 'mixed' : 'false')}
                aria-label=${allChecked ? this._label('toolbar.deselectAllChanges') : this._label('toolbar.selectAllChanges')}
                @change=${() => this._selectAll(!allChecked)}
              />
              <span class="wew-menu__selectall-label">${selectLabel}</span>
            </label>
            <span class="wew-menu__count" aria-live="polite">
              <strong>${selectedCount}</strong> ${this._label('toolbar.of')} ${total}
            </span>
          ` : nothing}
        </div>

        <div class="wew-menu__foot-action">
          <button
            type="button"
            class="btn btn-primary wew-menu__publish"
            ?disabled=${selectedCount === 0 || this.publishing}
            @click=${() => this._publish()}
          >
            ${this.publishing
              ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner> ${this._label('toolbar.publishing')}`
              : html`${this._label('toolbar.publishToLive')}${selectedCount > 0 ? html` (${selectedCount})` : nothing}`}
          </button>
        </div>
      </footer>
    `;
  }

  _friendlyTable(table) {
    switch (table) {
      case 'pages':                       return this._label('table.pages');
      case 'tt_content':                  return this._label('table.tt_content');
      case 'tx_news_domain_model_news':   return this._label('table.tx_news_domain_model_news');
      case 'sys_file_metadata':           return this._label('table.sys_file_metadata');
      case 'tt_address':                  return this._label('table.tt_address');
      default:                            return table;
    }
  }

  /**
   * Open a TYPO3 backend Modal with the field-level diff of the
   * given record. Content is fetched via the
   * /webcon-easy-workspace/diff AJAX endpoint, which renders a
   * Fluid template using core's DiffUtility (`<ins>`/`<del>`) — the
   * same inline-diff format the standalone Workspaces module uses,
   * so editors who already know "Show changes" feel at home.
   *
   * Modal.advanced({type: ajax}) handles the loading spinner, the
   * focus trap, ESC-to-close and the backdrop click — no
   * accessibility wiring needed on our side.
   */
  _openDiffModal(item) {
    if (!ENDPOINTS.diff) return;
    const url = `${ENDPOINTS.diff}&table=${encodeURIComponent(item.table)}&workspaceUid=${encodeURIComponent(item.workspaceUid)}`;
    const recordTitle = item.title || this._label('diff.noTitle');
    const modal = Modal.advanced({
      title: this._label('diff.modal.historyTitle', { title: recordTitle }),
      type: ModalTypes.ajax,
      content: url,
      size: ModalSizes.expand,
      additionalCssClasses: ['wew-diff-modal-shell'],
      // ajaxCallback fires once the Fluid-rendered HTML is in the
      // modal body. Wire interactive bits that the template can't
      // express declaratively: rollback buttons and the "Open in
      // form editor" pivot.
      ajaxCallback: (m) => {
        this._wireHistoryTabs(m);
        this._wireRollbackButtons(m, item);
        const editBtn = m.querySelector('.wew-diff-modal__edit');
        if (editBtn) {
          editBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const editUrl = editBtn.getAttribute('data-edit-url');
            if (!editUrl) return;
            m.hideModal();
            // Defer just enough that the close transition starts
            // before the new modal mounts — otherwise core's modal
            // manager occasionally races the two open/close.
            //
            // Position 'sheet' docks the modal to the right edge of
            // the viewport — same affordance TYPO3 v14 uses for its
            // own in-place edit dialogs. Keeps the page tree + the
            // workspace dropdown's surrounding context visible on the
            // left, so editors don't lose their place.
            setTimeout(() => {
              Modal.advanced({
                title: this._label('diff.modal.editTitle', { title: recordTitle }),
                type: ModalTypes.iframe,
                content: editUrl,
                size: ModalSizes.large,
                position: ModalPositions.sheet,
                additionalCssClasses: ['wew-edit-modal-shell'],
              });
            }, 60);
          });
        }
      },
    });
    return modal;
  }

  _wireHistoryTabs(modal) {
    const tabsRoot = modal.querySelector('[data-wew-history-tabs]');
    if (!tabsRoot) return;

    const tabs = Array.from(tabsRoot.querySelectorAll('[data-wew-history-tab]'));
    const panels = Array.from(tabsRoot.querySelectorAll('[data-wew-history-panel]'));
    if (tabs.length === 0 || panels.length === 0) return;

    const activate = (name, focus = false) => {
      tabs.forEach((tab) => {
        const active = tab.dataset.wewHistoryTab === name;
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
        if (active && focus) {
          tab.focus();
        }
      });

      panels.forEach((panel) => {
        const active = panel.dataset.wewHistoryPanel === name;
        panel.classList.toggle('active', active);
        panel.classList.toggle('show', active);
        panel.hidden = !active;
        if (active) {
          const frame = panel.querySelector('iframe[data-src]');
          if (frame && (!frame.getAttribute('src') || frame.getAttribute('src') === 'about:blank')) {
            frame.setAttribute('src', frame.dataset.src || 'about:blank');
          }
        }
      });
    };

    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => activate(tab.dataset.wewHistoryTab || 'record'));
      tab.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        const direction = event.key === 'ArrowRight' ? 1 : -1;
        const nextIndex = (index + direction + tabs.length) % tabs.length;
        activate(tabs[nextIndex].dataset.wewHistoryTab || 'record', true);
      });
    });

    activate(tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.wewHistoryTab || 'record');
  }

  /**
   * Wire the per-entry "Revert to before" and per-field "↻" buttons
   * inside the History panel. Two modes — both hit the same endpoint:
   *
   *  - linear: rollback this sys_history entry + every later edit.
   *            Click on the row's "Revert to before" button.
   *  - field:  rollback only one field's change from this entry.
   *            Click on the small ↻ next to a diff value.
   *
   * Closes the modal on success, refreshes the publish list, and
   * reloads any visible preview iframe so the editor sees the
   * rolled-back state immediately.
   */
  _wireRollbackButtons(modal, item) {
    if (!ENDPOINTS.historyRollback) return;
    // Event delegation on the modal host — robust against the
    // buttons not yet being in the DOM at the moment ajaxCallback
    // fires (we hit a "click does nothing" report on the field
    // rollback button that was traced to a binding race) and against
    // any future re-renders inside the modal body. Stops at the
    // first match, so the linear and per-field buttons never both
    // fire from one click.
    modal.addEventListener('click', async (e) => {
      const btn = e.target instanceof Element ? e.target.closest('[data-wew-rollback]') : null;
      if (!btn || !modal.contains(btn)) return;
      e.preventDefault();
      e.stopPropagation();
      const mode = btn.dataset.wewRollback;
      const historyUid = parseInt(btn.dataset.historyUid || '0', 10);
      const field = btn.dataset.field || '';
      if ((mode !== 'linear' && mode !== 'field') || !Number.isFinite(historyUid) || historyUid <= 0) {
        Notification.error(this._label('rollback.failedTitle'), this._label('rollback.missingData', { mode: mode || '-', historyUid: btn.dataset.historyUid || '-' }));
        return;
      }
      if (mode === 'field' && field === '') {
        Notification.error(this._label('rollback.failedTitle'), this._label('rollback.noField'));
        return;
      }

      const confirmMsg = mode === 'field'
        ? this._label('rollback.confirmField', { title: item.title || item.workspaceUid })
        : this._label('rollback.confirmLinear', { title: item.title || item.workspaceUid });
      if (!window.confirm(confirmMsg)) return;

      btn.disabled = true;
      try {
        const response = await new AjaxRequest(ENDPOINTS.historyRollback).post(
          {
            table: item.table,
            uid: item.workspaceUid,
            historyUid,
            mode,
            field,
          },
          { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
        );
        const result = await response.resolve();
        if (result?.success) {
          Notification.success(this._label('rollback.successTitle'), mode === 'field' ? this._label('rollback.successField', { field }) : this._label('rollback.successLinear'), 4);
          modal.hideModal();
          await this._refresh();
          this._reloadPreviewIframes();
        } else {
          Notification.error(this._label('rollback.errorTitle'), result?.error || this._label('error.unknown'));
          btn.disabled = false;
        }
      } catch (error) {
        Notification.error(this._label('rollback.failedTitle'), error?.message || this._label('error.unexpected'));
        btn.disabled = false;
      }
    });
  }

  _key(item) {
    return `${item.table}:${item.workspaceUid}`;
  }

  _publishRecordsForItem(item) {
    const records = Array.isArray(item.publishRecords) && item.publishRecords.length > 0
      ? item.publishRecords
      : (item.isChanged ? [{ table: item.table, workspaceUid: item.workspaceUid }] : []);
    const unique = new Map();
    for (const record of records) {
      const table = String(record?.table || '');
      const workspaceUid = parseInt(record?.workspaceUid || 0, 10);
      if (!table || workspaceUid <= 0) continue;
      unique.set(`${table}:${workspaceUid}`, { table, workspaceUid });
    }
    return Array.from(unique.values());
  }

  _toggle(item, checked) {
    const next = new Set(this.selection);
    const key = this._key(item);
    if (checked) {
      next.add(key);
    } else {
      next.delete(key);
    }
    this.selection = next;
  }

  _selectAll(value) {
    if (!value) {
      this.selection = new Set();
      return;
    }
    this.selection = new Set(this.items.filter((i) => i.isChanged).map((i) => this._key(i)));
  }

  _setMode(mode) {
    if (this.mode === mode) {
      return;
    }
    this.mode = mode;
    this._writePersistedMode(mode);
    this._refresh();
  }

  // localStorage key kept short to avoid colliding with other modules.
  static _MODE_STORAGE_KEY = 'wew:filter-mode';

  _readPersistedMode() {
    try {
      const value = window.localStorage?.getItem(WebconEasyWorkspaceMenu._MODE_STORAGE_KEY);
      return value === 'all' || value === 'changed' ? value : null;
    } catch {
      return null; // private mode / disabled storage
    }
  }

  _writePersistedMode(mode) {
    try {
      window.localStorage?.setItem(WebconEasyWorkspaceMenu._MODE_STORAGE_KEY, mode);
    } catch { /* ignore */ }
  }

  async _refresh() {
    if (!ENDPOINTS.items) {
      this.state = 'error';
      this.items = [];
      this.itemGroups = [];
      this.changedItemGroups = [];
      this.workspaceId = 0;
      this._syncToolbarVisibility();
      return;
    }
    this.state = 'loading';
    const { pageUid, newsUid } = this._detectContext();
    const languageUid = this._detectLanguageUid();
    if (!pageUid && !newsUid) {
      this.state = 'no-context';
      this.contextLabel = this._label('toolbar.context.none');
      this.items = [];
      this.itemGroups = [];
      this.changedItemGroups = [];
      this.workspaceId = this._configuredWorkspaceId();
      this._updateToolbarBadge();
      this._syncToolbarVisibility();
      this._broadcastDeclineState();
      return;
    }

    // Always fetch the full set of records on the page. The mode tab
    // (changed vs. all) is applied client-side in _renderList — that
    // way the count badges on both tabs stay stable as the editor
    // switches between filters, instead of mutating each time we
    // re-query the server with a narrower scope.
    const query = pageUid ? { pageUid, mode: 'all' } : { newsUid, mode: 'all' };
    query._ = Date.now();
    if (languageUid !== null) {
      query.languageUid = languageUid;
    }
    try {
      const response = await new AjaxRequest(ENDPOINTS.items).withQueryArguments(query).get();
      const data = await response.resolve();
      this.context = data.context;
      this.items = Array.isArray(data.items) ? data.items : [];
      this.itemGroups = Array.isArray(data.itemGroups) ? data.itemGroups : [];
      this.changedItemGroups = Array.isArray(data.changedItemGroups) ? data.changedItemGroups : [];
      this.workspaceId = Number.isFinite(Number(data.workspaceId)) ? Number(data.workspaceId) : 0;
      this.workspaceTitle = typeof data.workspaceTitle === 'string' ? data.workspaceTitle : '';
      this.contextLabel = this._buildContextLabel(data);
      // Default selection: every changed item is selected.
      this.selection = new Set(this.items.filter((i) => i.isChanged).map((i) => this._key(i)));
      this.state = this.items.length === 0 ? 'empty' : 'loaded';
      this._updateToolbarBadge();
      this._syncToolbarVisibility();
      this._broadcastDeclineState();
    } catch (error) {
      console.error('[easy-workspace] items request failed', error);
      this.state = 'error';
      this.itemGroups = [];
      this.changedItemGroups = [];
      this._updateToolbarBadge();
    }
  }

  async _refreshAfterBackendSave() {
    if (this._refreshAfterSaveTimer) {
      window.clearTimeout(this._refreshAfterSaveTimer);
      this._refreshAfterSaveTimer = null;
    }
    await this._refreshIfPersistedChangesExist();
    // Backend save signals are emitted after DataHandler returns, but
    // TYPO3 side effects such as page-tree/workspace overlays can still
    // settle one tick later in surrounding frames. Run the same cheap
    // "has any workspace row?" check once more shortly after; only the
    // positive path pays for the full item/detail refresh.
    this._refreshAfterSaveTimer = window.setTimeout(() => {
      this._refreshAfterSaveTimer = null;
      this._refreshIfPersistedChangesExist();
    }, 800);
  }

  async _refreshIfPersistedChangesExist() {
    const currentCount = this._changedItemCount();
    try {
      const hasChanges = await this._hasPersistedChangesInCurrentContext();
      if (!hasChanges && currentCount === 0) {
        return;
      }
    } catch (error) {
      console.warn('[easy-workspace] has-changes request failed; refreshing item list', error);
    }
    await this._refresh();
  }

  async _hasPersistedChangesInCurrentContext() {
    if (!ENDPOINTS.hasChanges) {
      return true;
    }
    const { pageUid, newsUid } = this._detectContext();
    const languageUid = this._detectLanguageUid();
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

  _changedItemCount() {
    return Array.isArray(this.items) ? this.items.filter((i) => i.isChanged).length : 0;
  }

  /**
   * Sync the toolbar trigger badge with the persisted workspace
   * versions for the current page/news context. Live workspace never
   * shows a count; Visual Editor's unsaved field count is deliberately
   * ignored because it is not the number of publishable workspace
   * changes.
   */
  _updateToolbarBadge() {
    const host = this._toolbarHost();
    const badge = host?.querySelector('[data-wew-workspace-badge]');
    if (!badge) return;
    const count = this.workspaceId > 0 && (this.state === 'loaded' || this.state === 'empty')
      ? this._changedItemCount()
      : 0;
    badge.textContent = count > 0 ? String(count) : '';
    badge.classList.toggle('hidden', count <= 0);
    if (count > 0) {
      const label = this._label('toolbar.badge.pending', { count });
      badge.setAttribute('aria-label', label);
    } else {
      badge.removeAttribute('aria-label');
    }
  }

  _syncToolbarVisibility() {
    const host = this._toolbarHost();
    if (!host) return;
    const stateKnown = this.state === 'loaded' || this.state === 'empty' || this.state === 'no-context';
    if (!stateKnown) return;
    host.hidden = this.workspaceId <= 0;
  }

  _configuredWorkspaceId() {
    const configuredWorkspaceId = Number(this._config.activeWorkspaceId || 0);
    return Number.isFinite(configuredWorkspaceId) ? Math.max(0, configuredWorkspaceId) : 0;
  }

  _toolbarHost() {
    return this.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
      || this.closest('.toolbar-item')
      || (window.top || window.parent)?.document?.querySelector('[id*="easyworkspacetoolbaritem"]');
  }

  _buildContextLabel(data) {
    const changedCount = (data.items || []).filter((i) => i.isChanged).length;
    const totalCount = (data.items || []).length;
    // Header subtitle: just the counts. The page/news identifier
    // moved to a small footnote at the bottom of the dropdown so it
    // doesn't compete with the workspace name + preview button up
    // top.
    if (this.mode === 'all') {
      return this._label('toolbar.context.recordsPending', { total: totalCount, changed: changedCount });
    }
    return this._label('toolbar.context.pending', { count: changedCount });
  }

  /**
   * Tiny "context" footnote rendered at the very bottom of the
   * dropdown (under Deselect all / Publish): identifies which page
   * or news record the publish list is scoped to. Lives in its own
   * row so the primary controls have a clear visual hierarchy
   * (header / list / footer-actions / context-footnote).
   */
  _renderContextFootnote() {
    if (this.state === 'loading' || this.state === 'no-context' || this.state === 'error') {
      return nothing;
    }
    const { pageUid, newsUid } = this._detectContext();
    let label = null;
    if (newsUid > 0) {
      label = this._label('context.news', { uid: newsUid });
    } else if (pageUid > 0) {
      label = this._label('context.page', { uid: pageUid });
    }
    if (!label) return nothing;
    return html`<div class="wew-menu__context" role="note">${label}</div>`;
  }

  _detectContext() {
    const configuredNewsUid = parseInt(String(this._config.newsUid || '0'), 10);
    if (configuredNewsUid > 0) {
      return { pageUid: 0, newsUid: configuredNewsUid };
    }

    const configuredPageUid = parseInt(String(this._config.pageUid || '0'), 10);
    if (configuredPageUid > 0) {
      return { pageUid: configuredPageUid, newsUid: 0 };
    }

    // Primary source: v14's ModuleStateStorage tracks the currently
    // selected page in the Web module group (id stored in sessionStorage,
    // mutated whenever the page tree selection changes).
    let pageUid = 0;
    try {
      const storage = window.top?.ModuleStateStorage || window.ModuleStateStorage;
      if (storage && typeof storage.current === 'function') {
        const state = storage.current('web');
        const identifier = parseInt(String(state?.identifier || '0'), 10);
        if (identifier > 0) {
          pageUid = identifier;
        }
      }
    } catch {
      // Cross-frame access errors → fall through to URL parsing.
    }

    // Fallback: URL ?id= parameter (e.g. when a module link was opened
    // before any page-tree selection happened in this session).
    if (pageUid <= 0) {
      const fromUrl = parseInt(new URLSearchParams(window.location.search).get('id') || '0', 10);
      if (fromUrl > 0) {
        pageUid = fromUrl;
      }
    }

    // News context: edit[tx_news_domain_model_news][N]=edit in URL.
    let newsUid = 0;
    for (const key of new URLSearchParams(window.location.search).keys()) {
      const match = key.match(/^edit\[tx_news_domain_model_news\]\[(\d+)\]$/);
      if (match) {
        newsUid = parseInt(match[1], 10);
        break;
      }
    }

    return { pageUid: pageUid > 0 ? pageUid : 0, newsUid };
  }

  _shouldShowSubelementDetails() {
    return this._configBool('showSubelementsInToolbar', false);
  }

  _shouldShowStatePills(item) {
    return this._shouldShowSubelementDetails();
  }

  _isCompactToolbar() {
    return !this._shouldShowSubelementDetails();
  }

  _configBool(key, fallback = false) {
    const value = this._config?.[key];
    if (value === undefined || value === null) return fallback;
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value !== 0;
    if (typeof value === 'string') {
      const normalized = value.trim().toLowerCase();
      if (['1', 'true', 'on', 'yes'].includes(normalized)) return true;
      if (['0', 'false', 'off', 'no', ''].includes(normalized)) return false;
    }
    return Boolean(value);
  }

  _detectLanguageUid() {
    const stateCandidates = [];
    try {
      const storage = window.top?.ModuleStateStorage || window.ModuleStateStorage;
      if (storage && typeof storage.current === 'function') {
        stateCandidates.push(storage.current('web'));
        stateCandidates.push(storage.current('web_layout'));
        stateCandidates.push(storage.current('web_layout_language'));
      }
    } catch {
      // Cross-frame access errors -> URL parsing below.
    }

    for (const state of stateCandidates) {
      const languageUid = this._extractLanguageUid(state);
      if (languageUid !== null) return languageUid;
    }

    for (const params of this._collectSearchParams()) {
      for (const key of ['language', 'sys_language_uid', 'L', 'siteLanguage', 'lang']) {
        const value = params.get(key);
        if (value === null || value === '') continue;
        const languageUid = parseInt(value, 10);
        if (Number.isInteger(languageUid) && languageUid >= 0) {
          return languageUid;
        }
      }
    }

    return null;
  }

  _extractLanguageUid(value) {
    if (!value || typeof value !== 'object') return null;
    const directKeys = ['language', 'languageUid', 'languageId', 'sys_language_uid', 'siteLanguage'];
    for (const key of directKeys) {
      const parsed = parseInt(String(value[key] ?? ''), 10);
      if (Number.isInteger(parsed) && parsed >= 0) return parsed;
    }
    for (const nestedKey of ['settings', 'moduleData', 'data']) {
      const nested = this._extractLanguageUid(value[nestedKey]);
      if (nested !== null) return nested;
    }
    return null;
  }

  _collectSearchParams() {
    const seen = new Set();
    const params = [];
    const addUrl = (url) => {
      if (!url || seen.has(url)) return;
      seen.add(url);
      try {
        params.push(new URL(url, window.location.href).searchParams);
      } catch { /* ignore invalid/detached URLs */ }
    };

    addUrl(window.location.href);
    try { addUrl(window.top?.location?.href); } catch { /* cross-origin */ }

    const roots = [document];
    try {
      if (window.top?.document && window.top.document !== document) {
        roots.push(window.top.document);
      }
    } catch { /* cross-origin */ }

    for (const root of roots) {
      for (const iframe of root.querySelectorAll('iframe')) {
        addUrl(iframe.src);
        try { addUrl(iframe.contentWindow?.location?.href); } catch { /* cross-origin */ }
      }
    }

    return params;
  }

  async _publish() {
    if (!ENDPOINTS.publish || this.selection.size === 0) {
      return;
    }
    this.publishing = true;
    try {
      const selections = this.items
        .filter((i) => this.selection.has(this._key(i)))
        .flatMap((i) => this._publishRecordsForItem(i));
      const uniqueSelections = Array.from(
        new Map(selections.map((selection) => [`${selection.table}:${selection.workspaceUid}`, selection])).values(),
      );
      if (uniqueSelections.length === 0) {
        Notification.warning(this._label('publish.warning.title'), this._label('error.noPublishableRecords'));
        await this._refresh();
        return;
      }
      const response = await new AjaxRequest(ENDPOINTS.publish)
        .post({ selections: uniqueSelections }, { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
      const result = await response.resolve();
      if (result?.success && Number(result.published || 0) > 0) {
        Notification.success(
          this._label('publish.success.title'),
          this._label('publish.success.message', { count: Number(result.published || 0) }),
        );
        await this._refresh();
      } else {
        const errors = Array.isArray(result?.errors) && result.errors.length
          ? result.errors.join(' / ')
          : (result?.error || this._label('error.unknown'));
        Notification.warning(this._label('publish.warning.title'), errors);
      }
    } catch (error) {
      Notification.error(this._label('publish.failedTitle'), error?.message || this._label('error.unexpected'));
    } finally {
      this.publishing = false;
    }
  }

  async _copyPreviewLink(pageUid) {
    if (!ENDPOINTS.previewLink || pageUid <= 0) {
      return;
    }
    this.copyingPreview = true;
    try {
      const response = await new AjaxRequest(ENDPOINTS.previewLink)
        .withQueryArguments({ pageUid })
        .get();
      const data = await response.resolve();
      if (!data?.url) {
        Notification.error(this._label('preview.link.title'), data?.error || this._label('preview.link.noUrl'));
        return;
      }
      await this._writeToOsClipboard(data.url);
      Notification.success(this._label('preview.link.copied'), data.url, 4);
      // Transient in-button confirmation: swap to a green
      // checkmark + "Copied" for 2 s. Auto-reset on a timer rather
      // than a re-render trigger so the editor can read the
      // success state even if other interactions cause a render.
      this.previewJustCopied = true;
      if (this._previewCopiedResetTimer) {
        clearTimeout(this._previewCopiedResetTimer);
      }
      this._previewCopiedResetTimer = setTimeout(() => {
        this.previewJustCopied = false;
        this._previewCopiedResetTimer = null;
      }, 2000);
    } catch (error) {
      Notification.error(this._label('preview.link.title'), error?.message || this._label('error.unexpected'));
    } finally {
      this.copyingPreview = false;
    }
  }

  /**
   * Write the given text to the operating-system clipboard using
   * the browser's native API. Falls back to a hidden textarea +
   * document.execCommand('copy') if the modern API is unavailable
   * (e.g. on plain HTTP setups).
   */
  async _writeToOsClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(text);
        return;
      } catch (error) {
        // Fall through to the textarea copy path. Some browsers reject
        // Clipboard API writes after an awaited AJAX call.
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
}

customElements.define('webcon-easy-workspace-menu', WebconEasyWorkspaceMenu);
