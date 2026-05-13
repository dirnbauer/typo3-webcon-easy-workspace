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
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

const ENDPOINTS = {
  items: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_items || '',
  publish: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_publish || '',
  previewLink: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_preview_link || '',
  discard: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_discard || '',
  latest: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_latest || '',
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
    if (!current) return;
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
              <span>Workspace publish</span>
              ${this._config.enableWorkspaceChip && this.workspaceTitle
                ? html`<span class="wew-menu__ws-chip" title="Active workspace">${this.workspaceTitle}</span>`
                : nothing}
            </h3>
            <p class="wew-menu__subtitle">${this.contextLabel || 'Loading…'}</p>
          </div>
        </header>
        ${this._renderFilter()}
        ${this._renderBody()}
        ${this._renderFooter()}
        ${this._renderLatestAccordion()}
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

  _linkIcon() {
    return html`
      <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
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
    return html`
      <ul class="wew-list">
        ${visible.map((item) => this._renderItem(item))}
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
    const revertable = this._config.enableRevert && item.isChanged;
    const hasActions = locatable || revertable;
    return html`
      <li class=${stateClasses} data-table=${item.table}>
        <label class="wew-list__label" for=${item.isChanged ? id : nothing}>
          <span class="wew-list__check-cell">
            ${item.isChanged
              ? html`<input
                  type="checkbox"
                  id=${id}
                  class="form-check-input wew-list__check"
                  .checked=${checked}
                  @change=${(e) => this._toggle(item, e.target.checked)}
                />`
              : html`<span class="wew-list__check-spacer" aria-hidden="true"></span>`}
          </span>
          <span class="wew-list__title">
            <span class="wew-list__title-text" title=${item.title}>${item.title}</span>
            <span class="wew-list__meta">
              <span class="badge badge-${item.badge || 'info'}">${item.kindLabel}</span>
              ${item.isHidden && this._config.enableHiddenBadge
                ? html`<span class="badge badge-dark wew-list__hidden-badge" title="Record is hidden (won't show on the live site)">Hidden</span>`
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
          </span>
          ${hasActions
            ? html`<span class="wew-list__actions" @click=${(e) => e.preventDefault()}>
                ${locatable ? this._renderLocateButton(item) : nothing}
                ${revertable ? this._renderRevertButton(item) : nothing}
              </span>`
            : nothing}
          ${this._config.enableThumbnails && item.thumbnailUrl
            ? html`<span class="wew-list__thumb"><img src=${item.thumbnailUrl} alt="" loading="lazy"/></span>`
            : nothing}
        </label>
      </li>
    `;
  }

  /**
   * The "discard" button — discard the workspace version of a single
   * record after a warning confirmation modal. SVG inlined from TYPO3
   * core's actions-undo icon (currentColor). "Discard" is TYPO3's own
   * term for the operation (the DataHandler command is `flush`).
   */
  _renderRevertButton(item) {
    return html`
      <button
        type="button"
        class="wew-list__discard"
        title="Discard this change"
        aria-label="Discard this workspace change"
        @click=${(e) => { e.preventDefault(); e.stopPropagation(); this._confirmAndDiscard(item); }}
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
   * The "eye" button — hover it to scroll the corresponding content
   * element into view in the Visual Editor iframe (and outline it),
   * click it to do the same. SVG inlined from TYPO3 core's
   * actions-eye icon (currentColor).
   */
  _renderLocateButton(item) {
    const tooltip = this._config.hasVisualEditor
      ? 'Hover or click to focus this element in the Visual Editor'
      : 'Hover or click to locate in preview';
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
        @click=${(e) => { e.preventDefault(); e.stopPropagation(); this._highlightInIframe(item, { announce: true }); }}
      >
        <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
          <path
            fill="currentColor"
            d="M8.07 3C4.112 3 1 5.286 1 8s2.97 5 7 5c3.889 0 7-2.286 7-4.93C15 5.285 11.889 3.142 8.212 3h-.141Zm-.025 1.127c.141 0 .423.141.423.282s-.14.282-.423.282c-.845 0-1.69.704-1.69 1.55 0 .14-.141.282-.423.282-.282 0-.423-.141-.423-.282.141-1.127 1.268-2.114 2.536-2.114ZM2 8.03c0-1.298 1.017-2.591 2.647-3.312-.296.432-.296 1.01-.296 1.587 0 2.02 1.63 3.606 3.703 3.606 2.074 0 3.704-1.587 3.704-3.606 0-.577-.148-1.01-.296-1.443C12.943 5.582 14 6.875 14 8.029c-.148 2.02-2.841 3.924-6 3.971-3.36-.047-6-1.95-6-3.97Z"
          />
        </svg>
      </button>
    `;
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
  _renderFooter() {
    if (this.state !== 'loaded') {
      return nothing;
    }
    const { pageUid } = this._detectContext();
    const showPreview = this._config.enablePreviewLink && pageUid > 0;
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
        <div class="wew-menu__foot-preview">
          ${showPreview
            ? html`<button
                type="button"
                class="wew-menu__preview-icon"
                title="Copy a shareable preview link for this page"
                aria-label="Copy preview link"
                @click=${() => this._copyPreviewLink(pageUid)}
                ?disabled=${this.copyingPreview}
              >
                ${this.copyingPreview
                  ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
                  : this._linkIcon()}
              </button>`
            : nothing}
        </div>

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
      <ul class="wew-list wew-list--compact">
        ${this.latestItems.map((item) => this._renderLatestItem(item))}
      </ul>
    `;
  }

  /**
   * Compact row for the accordion. Deliberately stripped down vs.
   * _renderItem(): no selection checkbox, no publish/discard action
   * — those operations are scoped to the current page in this UI.
   * Click navigates to the record's edit form so the editor can
   * jump from the feed straight into the form.
   */
  _renderLatestItem(item) {
    const editHref = item.editUrl || null;
    const tableLabel = item.tableLabel || item.table;
    const kindBadge = item.kindLabel
      ? html`<span class="badge badge-${item.badge || 'info'}">${item.kindLabel}</span>`
      : nothing;
    return html`
      <li class="wew-list__item wew-list__item--compact">
        ${editHref
          ? html`<a class="wew-list__compact-link" href=${editHref}>
              <span class="wew-list__title">${item.title || '[No title]'}</span>
              <span class="wew-list__meta">${tableLabel} · #${item.workspaceUid}</span>
            </a>`
          : html`<div class="wew-list__compact-link wew-list__compact-link--inert">
              <span class="wew-list__title">${item.title || '[No title]'}</span>
              <span class="wew-list__meta">${tableLabel} · #${item.workspaceUid}</span>
            </div>`}
        ${kindBadge}
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
    } catch (error) {
      console.error('[easy-workspace] items request failed', error);
      this.state = 'error';
    }
  }

  _buildContextLabel(data) {
    const changedCount = (data.items || []).filter((i) => i.isChanged).length;
    const totalCount = (data.items || []).length;
    if (data.context === 'news' && data.newsUid) {
      return this.mode === 'all'
        ? `News #${data.newsUid} · ${totalCount} record(s), ${changedCount} pending`
        : `News #${data.newsUid} · ${changedCount} pending`;
    }
    if (data.context === 'page' && data.pageUid) {
      return this.mode === 'all'
        ? `Page #${data.pageUid} · ${totalCount} record(s), ${changedCount} pending`
        : `Page #${data.pageUid} · ${changedCount} pending`;
    }
    return 'Current workspace';
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
