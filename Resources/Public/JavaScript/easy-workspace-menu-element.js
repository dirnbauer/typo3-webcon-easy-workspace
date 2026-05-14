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
 *  - Hidden state badge.
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
  publish: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_publish || '',
  previewLink: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_preview_link || '',
  discard: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_discard || '',
  latest: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_latest || '',
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
  // Runtime-detected environment (NOT user-configurable). Set by
  // EasyWorkspaceToolbarItem::getDropDown() via ExtensionManagementUtility::isLoaded.
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
    config: { type: String, reflect: false },
    state: { state: true },
    items: { state: true },
    selection: { state: true },
    context: { state: true },
    publishing: { state: true },
    contextLabel: { state: true },
    workspaceTitle: { state: true },
    mode: { state: true },
    copyingPreview: { state: true },
    previewJustCopied: { state: true },
    // Latest-changes accordion (cross-page feed). Idle until the
    // editor first opens it — then transitions to loading → loaded.
    latestState: { state: true },
    latestItems: { state: true },
  };

  createRenderRoot() {
    return this;
  }

  constructor() {
    super();
    this.state = 'idle';
    this.items = [];
    this.selection = new Set();
    this.context = null;
    this.contextLabel = '';
    this.workspaceTitle = '';
    this.publishing = false;
    this.copyingPreview = false;
    this.previewJustCopied = false;
    this.latestState = 'idle';
    this.latestItems = [];
    this._config = { ...DEFAULT_CONFIG };
    this.mode = this._config.defaultMode;
  }

  connectedCallback() {
    super.connectedCallback();
    this._config = this._readConfig();
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
      dropdownHost.addEventListener('shown.bs.dropdown', () => this._refresh());
      // Belt + braces: also refresh on a direct click on the toggle.
      const toggle = dropdownHost.querySelector('.dropdown-toggle');
      toggle?.addEventListener('click', () => this._refresh());
    }

    // Listen for the {type: 'wew-decline', table, uid} postMessage
    // sent by visual-editor's <ve-content-element> action-bar
    // "decline" button (added via patches/visual-editor-add-decline-
    // workspace-changes-button.patch). The button lives in the FE
    // iframe and doesn't have direct access to the BE token /
    // ajaxUrls — it just signals intent; the discard runs here in
    // the BE context with full session + the existing confirmation
    // modal.
    this._declineMessageListener = (event) => this._onDeclineMessage(event);
    window.addEventListener('message', this._declineMessageListener);
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
  }

  /**
   * Handle a `wew-decline` postMessage from the visual-editor FE
   * iframe (the patched <ve-content-element> action-bar button).
   * Validates the payload, then synthesizes an item descriptor that
   * matches what _confirmAndDiscard expects from the dropdown list.
   *
   * The discard endpoint takes (table, workspaceUid). When the
   * editor clicks the FE-side button we only know the rendered uid
   * — which IS the workspace uid because visual-editor uses the
   * versioned uid via getVersionedUid() on tt_content nodes. The
   * backend accepts this and resolves the live record via DataHandler.
   */
  _onDeclineMessage(event) {
    // Origin check: only accept messages from our own origin. The
    // FE iframe lives on the same host (BE cookie path is /) so
    // event.origin matches window.origin.
    if (event.origin !== window.location.origin) return;
    const data = event.data;
    if (!data || data.type !== 'wew-decline') return;
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
    const liveUid = item?.liveUid;
    const workspaceUid = item?.workspaceUid;
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
            ? 'No preview iframe present. Open the page in Web → Edit (Visual Editor).'
            : (this._config.hasViewpage
                ? 'No preview iframe present. Open the page in Web → View.'
                : 'No preview iframe present. Install friendsoftypo3/visual-editor or use Web → View.');
          Notification.info('Show in preview', hint, 5);
        } else if (gated.length === accessible.length) {
          Notification.info(
            'Show in preview',
            'The Visual Editor preview is waiting for a frontend login (the BE cookie doesn’t reach the FE domain). Click “Go to login” inside the editor panel, sign in, then reload — or use Web → Layout where this works without the extra login.',
            10,
          );
        } else {
          // Dump diagnostics so the next iteration of the locator can
          // be tuned to whatever wrapper this site actually emits.
          this._logIframeDiagnostics(accessible, liveUid, workspaceUid);
          Notification.warning(
            'Show in preview',
            `Searched ${accessible.length} preview iframe(s) but did not find element for uid ${liveUid}${workspaceUid !== liveUid ? ` (workspace #${workspaceUid})` : ''}. See the browser console for what was actually in the preview iframe.`,
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
    const uids = [item?.liveUid, item?.workspaceUid]
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

  render() {
    return html`
      <div class="wew-menu">
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
              <span>Workspace</span>
              ${this._config.enableWorkspaceChip && this.workspaceTitle
                ? html`<span class="wew-menu__ws-chip" title="Active workspace">${this.workspaceTitle}</span>`
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
      ? 'All selected — click to deselect'
      : (someChecked ? 'Some selected — click to select all' : 'Select all');
    return html`
      <div class="wew-menu__selectbar">
        <label class="wew-menu__selectbar-label">
          <input
            type="checkbox"
            class="wew-menu__selectbar-check"
            .checked=${allChecked}
            .indeterminate=${someChecked}
            aria-checked=${allChecked ? 'true' : (someChecked ? 'mixed' : 'false')}
            aria-label=${allChecked ? 'Deselect all changes' : 'Select all changes'}
            @change=${() => this._selectAll(!allChecked)}
          />
          <span class="wew-menu__selectbar-text">${label}</span>
        </label>
        <span class="wew-menu__selectbar-count" aria-hidden="true">${selectedCount} / ${changeable.length}</span>
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
        aria-label="Filter pending records"
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
        >To publish <span class="wew-menu__chip-count" aria-label="${changedCount} record${changedCount === 1 ? '' : 's'}">${changedCount}</span></button>
        <button
          type="button"
          id="wew-tab-all"
          class="wew-menu__chip ${this.mode === 'all' ? 'wew-menu__chip--active' : ''}"
          role="tab"
          aria-selected=${this.mode === 'all' ? 'true' : 'false'}
          aria-controls="wew-tabpanel"
          tabindex=${this.mode === 'all' ? '0' : '-1'}
          @click=${() => this._setMode('all')}
        >All on page <span class="wew-menu__chip-count" aria-label="${totalCount} record${totalCount === 1 ? '' : 's'}">${totalCount}</span></button>
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
          <span>Loading pending changes…</span>
        </div>
      `;
    }
    if (this.state === 'no-context') {
      return html`
        <div class="alert alert-info wew-menu__alert" role="status">
          Open a page or a news record in the page tree to see what's ready to publish.
        </div>
      `;
    }
    if (this.state === 'empty') {
      const message = this.mode === 'all'
        ? 'This page is empty (no records yet).'
        : 'Nothing pending on this page.';
      return html`
        <div class="wew-menu__empty">
          <span class="wew-menu__empty-icon" aria-hidden="true">✓</span>
          <p>${message}</p>
        </div>
      `;
    }
    if (this.state === 'error') {
      return html`<div class="alert alert-danger wew-menu__alert">Could not load pending changes.</div>`;
    }
    // Client-side filter: "To publish" hides records without a
    // workspace version, "All on page" shows everything. The server
    // already returned the full record set so we don't refetch.
    const visible = this.mode === 'changed'
      ? this.items.filter((i) => i.isChanged)
      : this.items;
    if (visible.length === 0) {
      const message = this.mode === 'all'
        ? 'This page is empty (no records yet).'
        : 'Nothing pending on this page.';
      return html`
        <div class="wew-menu__empty">
          <span class="wew-menu__empty-icon" aria-hidden="true">✓</span>
          <p>${message}</p>
        </div>
      `;
    }
    // Walk the visible list and emit a column header before each
    // run of tt_content rows that belong to a different colPos.
    // Server sorted by colPos ASC + sorting ASC, so each colPos
    // appears in a contiguous block — a single previousColPos
    // tracker is enough; no grouping into intermediate arrays.
    let previousColPos = null;
    const rendered = [];
    for (const item of visible) {
      const itemColPos = item.table === 'tt_content' && Number.isInteger(item.colPos)
        ? item.colPos
        : null;
      if (itemColPos !== null && itemColPos !== previousColPos) {
        rendered.push(html`
          <li class="wew-list__colheader" role="presentation">
            <span class="wew-list__colheader-label">${item.colPosLabel || `Column ${itemColPos}`}</span>
          </li>
        `);
        previousColPos = itemColPos;
      }
      if (itemColPos === null) {
        // Non-tt_content rows (pages / news) reset the tracker so a
        // tt_content block that follows still emits its header.
        previousColPos = null;
      }
      rendered.push(this._renderItem(item));
    }
    return html`
      <ul class="wew-list">
        ${rendered}
      </ul>
    `;
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

    const locatable = this._config.enableHoverHighlight && item.table === 'tt_content';
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
    const hasActions = locatable || showRevertButton;
    // Diff data ships with every changed row via PendingItem::$diff.
    // Empty for unchanged rows or rows the diff service couldn't
    // resolve (broken FAL refs, etc.) — in which case we just skip
    // the disclosure so the row reads cleanly without a "0 changes"
    // affordance to nowhere.
    const diff = Array.isArray(item.diff) ? item.diff : [];
    const hasDiff = diff.length > 0;
    // Compact 3-row layout per item:
    //  Row 1: title (full width)  +  actions on right (discard, eye)
    //  Row 2: status pill          +  table label · type label
    //  Row 3: "N changes" trigger  (opens diff modal)
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
          <span class="wew-list__body">
            <span class="wew-list__head">
              <span class="wew-list__title-text" title=${item.title}>${item.title}</span>
              ${hasActions
                ? html`<span class="wew-list__actions" @click=${(e) => e.preventDefault()}>
                    ${showRevertButton ? this._renderRevertButton(item, canRevert) : nothing}
                    ${locatable ? this._renderLocateButton(item) : nothing}
                  </span>`
                : nothing}
            </span>
            <span class="wew-list__sub">
              <span class="wew-state-pill wew-state-pill--${item.badge || 'info'} wew-state-pill--inline">${item.kindLabel}</span>
              ${item.isHidden && this._config.enableHiddenBadge
                ? html`<span class="wew-state-pill wew-state-pill--secondary wew-state-pill--inline" title="Record is hidden (won't show on the live site)">Hidden</span>`
                : nothing}
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
            ${hasDiff && ENDPOINTS.diff
              ? html`<button
                  type="button"
                  class="wew-list__diff-trigger"
                  title="View what changed in this record (opens a dialog)"
                  @click=${(e) => { e.preventDefault(); e.stopPropagation(); this._openDiffModal(item); }}
                >
                  <span class="wew-list__diff-trigger-icon" aria-hidden="true">⇄</span>
                  <span class="wew-list__diff-trigger-label">${diff.length === 1 ? '1 change' : `${diff.length} changes`}</span>
                </button>`
              : nothing}
          </span>
        </label>
      </li>
    `;
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
      ? 'Discard this change'
      : 'Discard is disabled by configuration (enableRevert = 0)';
    const ariaLabel = canRevert
      ? 'Discard this workspace change'
      : 'Discard disabled by configuration';
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
      const labels = this._config.labels || {};
      const title = labels.discardTagTitle || 'Decline the changes';
      const subtitle = labels.discardTagSubtitle || 'Back to the last published version';
      const tag = doc.createElement('div');
      tag.className = 'wew-discard-tag';
      Object.assign(tag.style, {
        position: 'absolute',
        zIndex: '999999',
        padding: '4px 9px 5px',
        fontSize: '11px',
        lineHeight: '1.25',
        background: '#dc3545',
        color: '#fff',
        borderRadius: '3px',
        boxShadow: '0 2px 6px rgba(0,0,0,.25)',
        pointerEvents: 'none',
        whiteSpace: 'nowrap',
        fontFamily: 'inherit',
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
      'Discard this change?',
      `“${item.title}” (${item.tableLabel || item.table}) will lose its workspace edits. The live record stays untouched — but the staged change is gone for good. This cannot be undone.`,
      SeverityEnum.warning,
      [
        { text: 'Cancel', btnClass: 'btn-default', name: 'cancel', trigger: () => modal.hideModal() },
        { text: 'Discard', btnClass: 'btn-warning', name: 'discard', active: true, trigger: () => modal.hideModal() },
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
          const response = await new AjaxRequest(ENDPOINTS.discard)
            .post(
              { table: item.table, workspaceUid: item.workspaceUid },
              { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
            );
          const result = await response.resolve();
          if (result?.success) {
            Notification.success('Discarded', `Workspace version of “${item.title}” discarded.`, 4);
            // Refresh our own list, then reload the editor's preview
            // iframe so the live record (no longer overlaid by the
            // discarded workspace version) is rendered.
            await this._refresh();
            this._reloadPreviewIframes();
            this._invalidateLatest();
          } else {
            const errors = Array.isArray(result?.errors) && result.errors.length
              ? result.errors.join(' / ')
              : (result?.error || 'Unknown error.');
            Notification.error('Could not discard', errors);
          }
        } catch (error) {
          Notification.error('Discard failed', error?.message || 'Unexpected error.');
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
  _renderLocateButton(item) {
    const hasEditUrl = !!item.editUrl;
    const editPart = hasEditUrl ? ' · Click to edit this element' : '';
    const tooltip = this._config.hasVisualEditor
      ? `Hover to show this in the Visual Editor${editPart}`
      : `Hover to locate in preview${editPart}`;
    return html`
      <button
        type="button"
        class="wew-list__locate"
        title=${tooltip}
        aria-label=${tooltip}
        @mouseenter=${(e) => { e.stopPropagation(); this._highlightInIframe(item); }}
        @mouseleave=${() => this._clearIframeHighlight()}
        @focus=${() => this._highlightInIframe(item)}
        @blur=${() => this._clearIframeHighlight()}
        @click=${(e) => {
          e.preventDefault();
          e.stopPropagation();
          this._clearIframeHighlight();
          if (hasEditUrl) {
            this._openEditModal(item);
          } else {
            Notification.info('Edit', 'No edit form available for this record.');
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
   * Open the record's edit form in a right-side sheet modal.
   * Mirrors the "Open in form editor" pivot inside the diff modal —
   * same TYPO3 core record_edit URL, same sheet position, same
   * additional CSS class so the styling stays consistent across
   * entry points into the form.
   */
  _openEditModal(item) {
    if (!item.editUrl) return;
    Modal.advanced({
      title: `Edit — ${item.title || '[No title]'}`,
      type: ModalTypes.iframe,
      content: item.editUrl,
      size: ModalSizes.large,
      position: ModalPositions.sheet,
      additionalCssClasses: ['wew-edit-modal-shell'],
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
        title="Copy a shareable preview URL of this page to the clipboard"
        @click=${() => this._copyPreviewLink(pageUid)}
        ?disabled=${this.copyingPreview}
      >
        ${this.copyingPreview
          ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
          : justCopied
            ? this._checkIcon()
            : this._copyIcon()}
        <span class="wew-menu__preview-label">${this.copyingPreview ? 'Copying…' : justCopied ? 'Copied' : 'Preview'}</span>
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
      ? 'Deselect all'
      : (someChecked ? 'Select all' : 'Select all');
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
                aria-label=${allChecked ? 'Deselect all changes' : 'Select all changes'}
                @change=${() => this._selectAll(!allChecked)}
              />
              <span class="wew-menu__selectall-label">${selectLabel}</span>
            </label>
            <span class="wew-menu__count" aria-live="polite">
              <strong>${selectedCount}</strong> of ${total}
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
              ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner> Publishing…`
              : html`Publish to live${selectedCount > 0 ? html` (${selectedCount})` : nothing}`}
          </button>
        </div>
      </footer>
    `;
  }

  _friendlyTable(table) {
    switch (table) {
      case 'pages':                       return 'Page';
      case 'tt_content':                  return 'Content element';
      case 'tx_news_domain_model_news':   return 'News';
      case 'tt_address':                  return 'Address';
      default:                            return table;
    }
  }

  /**
   * Renders the lazy-loaded "Latest workspace changes" accordion.
   *
   * Uses the native <details>/<summary> disclosure widget so the
   * toggle, keyboard handling and ARIA semantics come for free.
   * Body content is built per state — `idle` shows just the hint
   * "Open to load…", `loading` a spinner, etc. The actual fetch is
   * deferred to the first `toggle` event with `details.open === true`.
   *
   * Rendered after the footer so the primary publish action stays
   * the visual center of gravity — the accordion is a peripheral
   * "what's going on elsewhere" view, deliberately deprioritized.
   */
  _renderLatestAccordion() {
    if (!ENDPOINTS.latest) return nothing;
    if (this.state === 'loading') return nothing;
    return html`
      <details
        class="wew-menu__latest"
        @toggle=${(e) => this._onLatestToggle(e)}
      >
        <summary class="wew-menu__latest-summary">
          <span class="wew-menu__latest-summary-label">Latest workspace changes</span>
          ${this.latestState === 'loaded' && this.latestItems.length > 0
            ? html`<span class="wew-menu__chip-count" aria-label="${this.latestItems.length} record${this.latestItems.length === 1 ? '' : 's'}">${this.latestItems.length}</span>`
            : nothing}
        </summary>
        <div class="wew-menu__latest-body">
          ${this._renderLatestBody()}
        </div>
      </details>
    `;
  }

  _renderLatestBody() {
    if (this.latestState === 'idle') {
      // The native disclosure widget keeps this hidden until the
      // editor expands the accordion, so the placeholder is just a
      // belt-and-braces "in case CSS doesn't" affordance — won't
      // normally be seen.
      return html`<p class="wew-menu__latest-hint">Open to load.</p>`;
    }
    if (this.latestState === 'loading') {
      return html`
        <div class="wew-menu__loading">
          <typo3-backend-spinner size="default"></typo3-backend-spinner>
          <span>Loading latest changes…</span>
        </div>
      `;
    }
    if (this.latestState === 'error') {
      return html`<div class="alert alert-danger wew-menu__alert">Could not load latest changes.</div>`;
    }
    if (this.latestState === 'empty') {
      return html`<p class="wew-menu__latest-hint">No pending changes in this workspace yet.</p>`;
    }
    return html`
      <ul class="wew-changelog">
        ${this.latestItems.map((item) => this._renderLatestItem(item))}
      </ul>
    `;
  }

  /**
   * One card per record in the "Latest workspace changes" accordion.
   * Header row mirrors the publish list (icon · title · meta · pill);
   * the body lists every field that differs between the live record
   * and its workspace version, with old → new value chips so editors
   * can see *what* changed without leaving the dropdown.
   *
   * For "New" or "Will be deleted" placeholders the diff payload
   * still lists field values (added or removed respectively); the
   * `kind` field on each diff entry switches the rendering between
   * those three cases.
   */
  _renderLatestItem(item) {
    const editHref = item.editUrl || null;
    const tableLabel = item.tableLabel || item.table;
    const kindBadge = item.kindLabel
      ? html`<span class="wew-state-pill wew-state-pill--${item.badge || 'info'}">${item.kindLabel}</span>`
      : nothing;
    const diff = Array.isArray(item.diff) ? item.diff : [];

    const headerInner = html`
      <div class="wew-changelog__heading">
        <span class="wew-changelog__title">${item.title || '[No title]'}</span>
        <span class="wew-changelog__meta">${tableLabel} · #${item.workspaceUid}</span>
      </div>
      ${kindBadge}
    `;
    const header = editHref
      ? html`<a class="wew-changelog__header wew-changelog__header--link" href=${editHref}>${headerInner}</a>`
      : html`<div class="wew-changelog__header">${headerInner}</div>`;

    return html`
      <li class="wew-changelog__item">
        ${header}
        ${diff.length > 0
          ? html`<ul class="wew-changelog__fields">
              ${diff.map((d) => this._renderDiffEntry(d))}
            </ul>`
          : html`<p class="wew-changelog__nodiff">No field-level differences resolved for this record.</p>`}
      </li>
    `;
  }

  /**
   * Renders one row of the per-record diff list.
   *
   *  - kind === 'changed': before → after (both chips)
   *  - kind === 'added'  : just the after chip, marked "new"
   *  - kind === 'removed': just the before chip, marked "removed"
   *
   * Empty before/after values render as a faint "empty" placeholder
   * rather than nothing — otherwise an edit that *cleared* a field
   * would render as a baffling single chip with no arrow target.
   */
  _renderDiffEntry(d) {
    const kind = d.kind || 'changed';
    const beforeChip = (val) => html`<span
      class="wew-changelog__chip wew-changelog__chip--before"
      title=${d.beforeFull || val || ''}
    >${val ? val : html`<em class="wew-changelog__chip-empty">empty</em>`}</span>`;
    const afterChip = (val) => html`<span
      class="wew-changelog__chip wew-changelog__chip--after"
      title=${d.afterFull || val || ''}
    >${val ? val : html`<em class="wew-changelog__chip-empty">empty</em>`}</span>`;

    let body;
    if (kind === 'added') {
      body = html`<span class="wew-changelog__kind">Added</span>${afterChip(d.after)}`;
    } else if (kind === 'removed') {
      body = html`<span class="wew-changelog__kind">Removed</span>${beforeChip(d.before)}`;
    } else {
      body = html`${beforeChip(d.before)}<span class="wew-changelog__arrow" aria-hidden="true">→</span>${afterChip(d.after)}`;
    }
    return html`
      <li class="wew-changelog__field wew-changelog__field--${kind}">
        <span class="wew-changelog__label">${d.label}</span>
        <span class="wew-changelog__values">${body}</span>
      </li>
    `;
  }

  _onLatestToggle(event) {
    if (!event.target.open) return;
    // Lazy: fetch only the first time the editor expands the panel
    // (or after explicit reset on a context change — see _refresh).
    if (this.latestState !== 'idle' && this.latestState !== 'error') return;
    this._loadLatestChanges();
  }

  /**
   * Resets the accordion to its idle state so the next time the
   * editor expands it, the latest list is re-fetched. Called after
   * publish/discard since those mutations change which records are
   * still pending in the workspace.
   *
   * The accordion's `open` state is preserved by the DOM, so when
   * we reset to 'idle' while it's still expanded, the `toggle`
   * event won't fire — we have to invoke the loader directly.
   */
  _invalidateLatest() {
    const wasOpen = this.querySelector('.wew-menu__latest')?.open === true;
    this.latestState = 'idle';
    this.latestItems = [];
    if (wasOpen) {
      this._loadLatestChanges();
    }
  }

  async _loadLatestChanges() {
    if (!ENDPOINTS.latest) {
      this.latestState = 'error';
      return;
    }
    this.latestState = 'loading';
    try {
      const response = await new AjaxRequest(ENDPOINTS.latest).get();
      const data = await response.resolve();
      this.latestItems = Array.isArray(data.items) ? data.items : [];
      this.latestState = this.latestItems.length === 0 ? 'empty' : 'loaded';
    } catch (error) {
      console.error('[easy-workspace] latest-changes request failed', error);
      this.latestState = 'error';
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
    const recordTitle = item.title || '[No title]';
    const modal = Modal.advanced({
      title: `History — ${recordTitle}`,
      type: ModalTypes.ajax,
      content: url,
      size: ModalSizes.large,
      additionalCssClasses: ['wew-diff-modal-shell'],
      // ajaxCallback fires once the Fluid-rendered HTML is in the
      // modal body. Wire interactive bits that the template can't
      // express declaratively: rollback buttons and the "Open in
      // form editor" pivot.
      ajaxCallback: (m) => {
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
                title: `Edit — ${recordTitle}`,
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
        Notification.error('Revert failed', `Missing data on the revert button (mode=${mode || '∅'}, historyUid=${btn.dataset.historyUid || '∅'}).`);
        return;
      }
      if (mode === 'field' && field === '') {
        Notification.error('Revert failed', 'No field name on the revert button.');
        return;
      }

      const confirmMsg = mode === 'field'
        ? `Revert this field’s change for “${item.title || item.workspaceUid}”? Later edits to other fields are kept.`
        : `Revert this edit and every later edit on “${item.title || item.workspaceUid}”? This affects every field touched after this point.`;
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
          Notification.success('Reverted', mode === 'field' ? `Field “${field}” reverted.` : 'Edit reverted.', 4);
          modal.hideModal();
          await this._refresh();
          this._reloadPreviewIframes();
          this._invalidateLatest();
        } else {
          Notification.error('Could not revert', result?.error || 'Unknown error.');
          btn.disabled = false;
        }
      } catch (error) {
        Notification.error('Revert failed', error?.message || 'Unexpected error.');
        btn.disabled = false;
      }
    });
  }

  _key(item) {
    return `${item.table}:${item.workspaceUid}`;
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
      return;
    }
    this.state = 'loading';
    const { pageUid, newsUid } = this._detectContext();
    if (!pageUid && !newsUid) {
      this.state = 'no-context';
      this.contextLabel = 'No page or news selected.';
      return;
    }

    // Always fetch the full set of records on the page. The mode tab
    // (changed vs. all) is applied client-side in _renderList — that
    // way the count badges on both tabs stay stable as the editor
    // switches between filters, instead of mutating each time we
    // re-query the server with a narrower scope.
    const query = pageUid ? { pageUid, mode: 'all' } : { newsUid, mode: 'all' };
    try {
      const response = await new AjaxRequest(ENDPOINTS.items).withQueryArguments(query).get();
      const data = await response.resolve();
      this.context = data.context;
      this.items = Array.isArray(data.items) ? data.items : [];
      this.workspaceTitle = typeof data.workspaceTitle === 'string' ? data.workspaceTitle : '';
      this.contextLabel = this._buildContextLabel(data);
      // Default selection: every changed item is selected.
      this.selection = new Set(this.items.filter((i) => i.isChanged).map((i) => this._key(i)));
      this.state = this.items.length === 0 ? 'empty' : 'loaded';
      this._updateToolbarBadge();
    } catch (error) {
      console.error('[easy-workspace] items request failed', error);
      this.state = 'error';
      this._updateToolbarBadge();
    }
  }

  /**
   * Sync the pending-count badge on the toolbar trigger with the
   * current page's changed-count. Mirrors core's System Information
   * pattern: the badge is a sibling of `.toolbar-item-icon` under the
   * toolbar item host, gets the standard `.toolbar-item-badge`,
   * `.badge`, `.badge-pill` classes, and toggles the `.hidden` class
   * (not the `hidden` attribute) to match the rest of the toolbar.
   *
   * Count source is the same number the dropdown's "To publish" tab
   * shows — what's actually publishable from this page right now —
   * which is what editors expect to see when they glance at the icon.
   */
  _updateToolbarBadge() {
    const host = this.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
      || this.closest('.toolbar-item')
      || (window.top || window.parent)?.document?.querySelector('[id*="easyworkspacetoolbaritem"]');
    const badge = host?.querySelector('[data-wew-workspace-badge]');
    if (!badge) return;
    const count = this.state === 'loaded'
      ? this.items.filter((i) => i.isChanged).length
      : 0;
    badge.textContent = count > 0 ? String(count) : '';
    badge.classList.toggle('hidden', count <= 0);
    if (count > 0) {
      badge.setAttribute('aria-label', `${count} pending change${count === 1 ? '' : 's'} on this page`);
    } else {
      badge.removeAttribute('aria-label');
    }
  }

  _buildContextLabel(data) {
    const changedCount = (data.items || []).filter((i) => i.isChanged).length;
    const totalCount = (data.items || []).length;
    // Header subtitle: just the counts. The page/news identifier
    // moved to a small footnote at the bottom of the dropdown so it
    // doesn't compete with the workspace name + preview button up
    // top.
    if (this.mode === 'all') {
      return `${totalCount} record${totalCount === 1 ? '' : 's'}, ${changedCount} pending`;
    }
    return `${changedCount} pending`;
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
      label = `News #${newsUid}`;
    } else if (pageUid > 0) {
      label = `Page #${pageUid}`;
    }
    if (!label) return nothing;
    return html`<div class="wew-menu__context" role="note">${label}</div>`;
  }

  _detectContext() {
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

  async _publish() {
    if (!ENDPOINTS.publish || this.selection.size === 0) {
      return;
    }
    this.publishing = true;
    try {
      const selections = this.items
        .filter((i) => this.selection.has(this._key(i)))
        .map((i) => ({ table: i.table, workspaceUid: i.workspaceUid }));
      const response = await new AjaxRequest(ENDPOINTS.publish)
        .post({ selections }, { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
      const result = await response.resolve();
      if (result?.success) {
        Notification.success(
          'Published to live',
          `${result.published} record(s) updated.`,
        );
        await this._refresh();
        this._invalidateLatest();
      } else {
        const errors = Array.isArray(result?.errors) && result.errors.length
          ? result.errors.join(' / ')
          : (result?.error || 'Unknown error.');
        Notification.warning('Publish finished with errors', errors);
      }
    } catch (error) {
      Notification.error('Publish failed', error?.message || 'Unexpected error.');
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
        Notification.error('Preview link', data?.error || 'No URL returned.');
        return;
      }
      await this._writeToOsClipboard(data.url);
      Notification.success('Preview link copied', data.url, 4);
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
      Notification.error('Preview link', error?.message || 'Unexpected error.');
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
      await navigator.clipboard.writeText(text);
      return;
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
