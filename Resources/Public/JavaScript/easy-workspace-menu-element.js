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
    this._config = { ...DEFAULT_CONFIG };
    this.mode = this._config.defaultMode;
  }

  connectedCallback() {
    super.connectedCallback();
    this._config = this._readConfig();
    this.mode = this._config.defaultMode;
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
    const uid = item?.liveUid;
    if (!uid) return;

    // Scan EVERY reachable iframe for the element. Picking "the
    // visual-editor iframe" and looking only there is fragile when
    // there are nested or hidden iframes; locating by content is
    // both correct and tolerant of wrappers we don't know about.
    const located = this._locateInAnyIframe(uid);
    if (!located) {
      if (announce) {
        const allIframes = this._collectIframes();
        const accessible = allIframes.filter((f) => {
          try { return !!f.contentDocument?.body; } catch { return false; }
        });
        if (accessible.length === 0) {
          const hint = this._config.hasVisualEditor
            ? 'No preview iframe present. Open the page in Web → Edit (Visual Editor).'
            : (this._config.hasViewpage
                ? 'No preview iframe present. Open the page in Web → View.'
                : 'No preview iframe present. Install friendsoftypo3/visual-editor or use Web → View.');
          Notification.info('Show in preview', hint, 5);
        } else {
          Notification.warning(
            'Show in preview',
            `Searched ${accessible.length} preview iframe(s) but did not find #c${uid}. The content element might be wrapped without an id="c${uid}" attribute.`,
            6,
          );
        }
      }
      return;
    }
    const { el } = located;

    // Remember inline styles so we can restore them cleanly.
    const previous = {};
    for (const key of Object.keys(IFRAME_HIGHLIGHT_STYLE)) {
      previous[key] = el.style[key];
    }
    Object.assign(el.style, IFRAME_HIGHLIGHT_STYLE);

    // Centre the CE in the iframe viewport — smooth scroll, no jump.
    try {
      el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    } catch {
      el.scrollIntoView();
    }

    this._iframeHighlight = { el, previous };
  }

  /**
   * Open the standard TYPO3 FormEngine edit form for a record in a
   * backend modal — same form the page tree's context-menu "Edit"
   * opens. The URL is pre-built server-side via BackendUriBuilder
   * (`record_edit` route) and shipped on each PendingItem.
   *
   * When the modal closes the dropdown reloads so any in-modal save
   * is reflected in the change state (badge, checkbox).
   */
  _openEditModal(item) {
    if (!item?.editUrl) {
      Notification.info('Edit', 'No editor URL available for this record.', 4);
      return;
    }
    const modal = Modal.advanced({
      type: Modal.types.iframe,
      title: `Edit: ${item.title}`,
      content: item.editUrl,
      size: Modal.sizes.large,
      buttons: [
        { text: 'Close', btnClass: 'btn-default', name: 'close', trigger: () => modal.hideModal() },
      ],
    });
    modal.addEventListener('typo3-modal-hidden', () => this._refresh(), { once: true });
  }

  _clearIframeHighlight() {
    const current = this._iframeHighlight;
    if (!current) return;
    try {
      for (const [key, value] of Object.entries(current.previous)) {
        current.el.style[key] = value ?? '';
      }
    } catch { /* element may already be detached */ }
    this._iframeHighlight = null;
  }

  /**
   * Collect every iframe reachable from this document and its top.
   * Deduped by element identity so iframes living in both contexts
   * (when the toolbar is nested) aren't counted twice.
   *
   * @returns {HTMLIFrameElement[]}
   */
  _collectIframes() {
    const seen = new Set();
    const out = [];
    const roots = [document];
    try { if (window.top?.document && window.top.document !== document) roots.push(window.top.document); } catch { /* cross-origin */ }
    for (const root of roots) {
      for (const iframe of root.querySelectorAll('iframe')) {
        if (!seen.has(iframe)) {
          seen.add(iframe);
          out.push(iframe);
        }
      }
    }
    return out;
  }

  /**
   * Iterate every reachable iframe and return the first one whose
   * contentDocument contains a content element matching `uid`.
   * Replaces "find the right iframe by selector, then search inside"
   * with "find the iframe that actually has the content".
   *
   * @returns {{ iframe: HTMLIFrameElement, doc: Document, el: HTMLElement }|null}
   */
  _locateInAnyIframe(uid) {
    for (const iframe of this._collectIframes()) {
      let doc;
      try {
        doc = iframe.contentDocument || iframe.contentWindow?.document;
      } catch { continue; }
      if (!doc) continue;
      const el = this._findContentElement(doc, uid);
      if (el) return { iframe, doc, el };
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
    const { pageUid } = this._detectContext();
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
          ${pageUid > 0 && this._config.enablePreviewLink
            ? html`<button
                type="button"
                class="btn btn-sm btn-default wew-menu__copy"
                title="Copy a shareable preview link for this page"
                @click=${() => this._copyPreviewLink(pageUid)}
                ?disabled=${this.copyingPreview}
              >
                ${this.copyingPreview
                  ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
                  : this._linkIcon()}
                <span class="wew-menu__copy-label">Preview link</span>
              </button>`
            : nothing}
        </header>
        ${this._renderFilter()}
        ${this._renderBody()}
        ${this._renderFooter()}
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

  _renderFilter() {
    if (!this._config.enableFilter) {
      return nothing;
    }
    if (this.state === 'loading' || this.state === 'no-context' || this.state === 'error') {
      return nothing;
    }
    return html`
      <div class="wew-menu__filter" role="tablist" aria-label="Filter items">
        <button
          type="button"
          class="wew-menu__chip ${this.mode === 'changed' ? 'wew-menu__chip--active' : ''}"
          role="tab"
          aria-selected=${this.mode === 'changed'}
          @click=${() => this._setMode('changed')}
        >To publish</button>
        <button
          type="button"
          class="wew-menu__chip ${this.mode === 'all' ? 'wew-menu__chip--active' : ''}"
          role="tab"
          aria-selected=${this.mode === 'all'}
          @click=${() => this._setMode('all')}
        >All on page</button>
      </div>
    `;
  }

  _renderBody() {
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
    return html`
      <ul class="wew-list">
        ${this.items.map((item) => this._renderItem(item))}
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
            await this._refresh();
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
    const tooltip = item.editUrl
      ? 'Hover to locate in preview · Click to edit'
      : (this._config.hasVisualEditor ? 'Show in Visual Editor' : 'Show in preview');
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
        @click=${(e) => { e.preventDefault(); e.stopPropagation(); this._openEditModal(item); }}
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

  _renderFooter() {
    if (this.state !== 'loaded') {
      return nothing;
    }
    const changeable = this.items.filter((i) => i.isChanged);
    const selectedCount = this.selection.size;
    return html`
      <footer class="wew-menu__foot">
        <div class="wew-menu__counters">
          <button
            type="button"
            class="btn btn-link btn-sm wew-menu__select-all"
            ?disabled=${changeable.length === 0}
            @click=${() => this._selectAll(true)}
          >Select all</button>
          <span class="text-muted">·</span>
          <button
            type="button"
            class="btn btn-link btn-sm"
            ?disabled=${selectedCount === 0}
            @click=${() => this._selectAll(false)}
          >None</button>
          <span class="wew-menu__count">${selectedCount} of ${changeable.length} selected</span>
        </div>
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
    this._refresh();
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

    const query = pageUid ? { pageUid, mode: this.mode } : { newsUid, mode: this.mode };
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
