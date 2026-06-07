import { html, LitElement } from 'lit';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

import { ENDPOINTS, DEFAULT_CONFIG } from '../menu-constants.js';
import {
  readConfig,
  label,
  configBool,
  buildContextLabel,
  detectContext,
  detectLanguageUid,
} from '../menu-context.js';
import {
  highlightInIframe,
  clearIframeHighlight,
  previewDiscard,
  reloadPreviewIframes,
} from '../menu-preview-locate.js';
import {
  onBackendSaveMessage,
  registerBackendSaveSignalListeners,
  clearBackendSaveSignalListeners,
  onDeclineMessage,
  broadcastDeclineState,
} from '../menu-backend-save-sync.js';
import { openEditModal, openDiffModal } from '../menu-modals.js';
import { isCompactToolbar } from '../menu-glue.js';
import {
  discardRecordsForItem,
  readPersistedMode,
  refresh,
  refreshAfterBackendSave,
  publish,
  copyPreviewLink,
  configuredWorkspaceId,
  setMode,
  startBadgePolling,
  stopBadgePolling,
} from '../menu-actions.js';
import {
  key,
  toggle,
  selectAll,
} from '../menu-selection.js';
import {
  changedItemCount,
  footerState,
  diffTitle,
  rowClasses,
  canRevert,
  editUrl,
  panelHasItems,
  itemIsLocatable,
  itemIsEditable,
  findItem,
  findItemByKey,
} from '../menu-toolbar-helpers.js';

/**
 * Easy Workspace toolbar dropdown (Lit, light DOM).
 *
 * Labels and TSconfig arrive from PHP as JSON on the `config` attribute.
 * Pending items are fetched via AJAX and rendered client-side.
 */
export class WebconEasyWorkspaceMenu extends LitElement {
  static properties = {
    state: { type: String },
    mode: { type: String },
    items: { type: Array },
    itemGroups: { type: Array },
    changedItemGroups: { type: Array },
    workspaceId: { type: Number },
    workspaceTitle: { type: String },
    pageUid: { type: Number },
    newsUid: { type: Number },
    publishing: { type: Boolean },
    copyingPreview: { type: Boolean },
    selectionVersion: { type: Number },
  };

  createRenderRoot() {
    return this;
  }

  constructor() {
    super();
    this.state = 'loading';
    this.items = [];
    this.itemGroups = [];
    this.changedItemGroups = [];
    this.selection = new Set();
    this.selectionVersion = 0;
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
    this.pageUid = 0;
    this.newsUid = 0;
    this._refreshAfterSaveTimer = null;
    this._backendFrameLoadRefreshTimer = null;
    this._badgePollTimer = null;
    this._badgePolling = false;
    this._badgeWakeListener = null;
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
    this.mode = readPersistedMode() ?? this._config.defaultMode;
    this._refresh();

    this._navListener = () => this._refresh();
    try {
      window.top?.document.addEventListener('typo3:module-state-storage:update:web', this._navListener);
      window.top?.document.addEventListener('typo3:module-state-storage:update-with-tree-identifier:web', this._navListener);
    } catch {
      document.addEventListener('typo3:module-state-storage:update:web', this._navListener);
    }

    const dropdownHost = this.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
      || this.closest('.toolbar-item');
    if (dropdownHost) {
      const menu = this.closest('.dropdown-menu');
      const toggleEl = dropdownHost.querySelector('.dropdown-toggle');
      this._ensurePopoverDropdown(dropdownHost, toggleEl, menu);
      if (menu?.hasAttribute('popover')) {
        menu.addEventListener('toggle', (event) => {
          if (event.newState === 'open') this._refresh();
        });
      }
      dropdownHost.addEventListener('shown.bs.dropdown', () => this._refresh());
      toggleEl?.addEventListener('click', () => this._refresh());
    }

    this._declineMessageListener = (event) => onDeclineMessage(this, event);
    window.addEventListener('message', this._declineMessageListener);

    this._backendSaveMessageListener = (event) => onBackendSaveMessage(this, event);
    registerBackendSaveSignalListeners(this);

    // Guarantee the badge converges to the real pending count regardless of
    // how a record changed (save path, drag/drop, paste, another tab).
    this._startBadgePolling();
  }

  _ensurePopoverDropdown(host, toggle, menu) {
    if (!toggle || !menu) return;
    if (toggle.hasAttribute('popovertarget') || !toggle.hasAttribute('data-bs-toggle')) {
      return;
    }
    if (!menu.id) {
      menu.id = `wew-toolbar-menu-${Math.random().toString(36).slice(2, 10)}`;
    }
    menu.setAttribute('popover', '');
    (toggle.closest('.dropdown') || toggle.parentElement || host)?.classList.add('dropdown');
    toggle.setAttribute('popovertarget', menu.id);
    for (const attr of [
      'data-bs-toggle', 'data-bs-target', 'data-bs-offset', 'data-bs-auto-close',
      'data-bs-reference', 'data-bs-display', 'data-bs-boundary',
      'aria-haspopup', 'aria-expanded',
    ]) {
      toggle.removeAttribute(attr);
    }
  }

  disconnectedCallback() {
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
    this._stopBadgePolling();
    clearBackendSaveSignalListeners(this);
    super.disconnectedCallback();
  }

  requestSelectionUpdate() {
    this.selectionVersion += 1;
  }

  _readConfig() { return readConfig(this); }
  _label(key, variables = {}) { return label(this, key, variables); }
  _buildContextLabel(data) { return buildContextLabel(this, data); }
  _detectContext() { return detectContext(this); }
  _detectLanguageUid() { return detectLanguageUid(this); }
  _configBool(key, fallback = false) { return configBool(this, key, fallback); }

  _highlightInIframe(item, options) { return highlightInIframe(this, item, options); }
  _clearIframeHighlight() { return clearIframeHighlight(this); }
  _previewDiscard(item) { return previewDiscard(this, item); }
  _reloadPreviewIframes() { return reloadPreviewIframes(); }
  _openEditModal(item) { return openEditModal(this, item); }
  _openDiffModal(item) { return openDiffModal(this, item); }

  _key(item) { return key(this, item); }
  _setMode(mode) { return setMode(this, mode); }
  _configuredWorkspaceId() { return configuredWorkspaceId(this); }
  _refresh() { return refresh(this); }
  _refreshAfterBackendSave(options) { return refreshAfterBackendSave(this, options); }
  _startBadgePolling() { return startBadgePolling(this); }
  _stopBadgePolling() { return stopBadgePolling(this); }
  _publish() { return publish(this); }
  _copyPreviewLink(pageUid) { return copyPreviewLink(this, pageUid); }
  _isCompactToolbar() { return isCompactToolbar(this); }

  #onModeClick(event) {
    const button = event.currentTarget;
    const mode = button?.getAttribute('data-wew-mode');
    if (mode === 'all' || mode === 'changed') {
      event.preventDefault();
      this._setMode(mode);
    }
  }

  #onFilterKeydown(event) {
    const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
    if (!keys.includes(event.key)) return;
    event.preventDefault();
    const tabs = Array.from(this.querySelectorAll('[data-wew-mode]'));
    if (tabs.length === 0) return;
    const currentIdx = tabs.indexOf(document.activeElement);
    let nextIdx;
    switch (event.key) {
      case 'ArrowLeft': nextIdx = currentIdx <= 0 ? tabs.length - 1 : currentIdx - 1; break;
      case 'ArrowRight': nextIdx = currentIdx >= tabs.length - 1 ? 0 : currentIdx + 1; break;
      case 'Home': nextIdx = 0; break;
      case 'End': nextIdx = tabs.length - 1; break;
      default: return;
    }
    tabs[nextIdx].focus();
    tabs[nextIdx].click();
  }

  #onRowCheckChange(event) {
    const checkbox = event.currentTarget;
    const row = checkbox.closest('[data-wew-key]');
    const item = findItemByKey(this, row?.getAttribute('data-wew-key') || '');
    if (item) {
      toggle(this, item, checkbox.checked);
    }
  }

  #onSelectAllChange(event) {
    const checked = event.currentTarget.checked;
    selectAll(this, checked);
  }

  #onPreviewClick(event) {
    event.preventDefault();
    const pageUid = parseInt(event.currentTarget.getAttribute('data-wew-page-uid') || '0', 10);
    if (pageUid > 0) {
      this._copyPreviewLink(pageUid);
    }
  }

  #onPublishClick(event) {
    event.preventDefault();
    this._publish();
  }

  #onDiffClick(event) {
    event.preventDefault();
    event.stopPropagation();
    const table = event.currentTarget.getAttribute('data-wew-table') || '';
    const workspaceUid = parseInt(event.currentTarget.getAttribute('data-wew-workspace-uid') || '0', 10);
    const item = findItem(this, table, workspaceUid);
    if (item) {
      this._openDiffModal(item);
    }
  }

  #onDiscardClick(event) {
    event.preventDefault();
    event.stopPropagation();
    if (event.currentTarget.getAttribute('data-wew-can-revert') !== '1') return;
    this._clearIframeHighlight();
    const table = event.currentTarget.getAttribute('data-wew-table') || '';
    const workspaceUid = parseInt(event.currentTarget.getAttribute('data-wew-workspace-uid') || '0', 10);
    const item = findItem(this, table, workspaceUid);
    if (item) {
      this._confirmAndDiscard(item);
    }
  }

  #onEditClick(event) {
    event.preventDefault();
    event.stopPropagation();
    this._clearIframeHighlight();
    const row = event.currentTarget.closest('[data-wew-key]');
    const item = findItemByKey(this, row?.getAttribute('data-wew-key') || '');
    if (item?.editUrl || item?.contextualEditUrl) {
      this._openEditModal(item);
    } else {
      Notification.info(this._label('edit.title'), this._label('edit.noForm'));
    }
  }

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
          for (const record of discardRecordsForItem(this, item)) {
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

  render() {
    void this.selectionVersion;

    switch (this.state) {
      case 'loading':
        return this.#renderLoading();
      case 'no-context':
        return this.#renderNoContext();
      case 'error':
        return this.#renderError();
      default:
        return this.#renderMenu();
    }
  }

  #renderLoading() {
    return html`
      <div class="wew-menu wew-menu--loading" data-wew-menu>
        <div class="wew-menu__loading">
          <typo3-backend-spinner size="default"></typo3-backend-spinner>
          <span>${this._label('toolbar.loading')}</span>
        </div>
      </div>
    `;
  }

  #renderNoContext() {
    return html`
      <div class="wew-menu" data-wew-menu>
        <div class="alert alert-info wew-menu__alert" role="status">
          ${this._label('toolbar.noContext')}
        </div>
      </div>
    `;
  }

  #renderError() {
    return html`
      <div class="wew-menu" data-wew-menu>
        <div class="alert alert-danger wew-menu__alert">
          ${this._label('toolbar.loadError')}
        </div>
      </div>
    `;
  }

  #renderMenu() {
    const compactClass = this._isCompactToolbar() ? ' wew-menu--compact-toolbar' : '';
    const changedCount = changedItemCount(this.items);
    const totalCount = this.items.length;
    const showSubelements = this._configBool('showSubelementsInToolbar');

    return html`
      <div class="wew-menu${compactClass}" data-wew-menu>
        ${this.#renderHeader()}
        ${this.#renderFilter(changedCount, totalCount)}
        <div id="wew-tabpanel"
             role="${this._configBool('enableFilter') ? 'tabpanel' : ''}"
             data-wew-tabpanel>
          ${this.#renderListPanel('changed', this.changedItemGroups, showSubelements, 'toolbar.empty.changed')}
          ${this.#renderListPanel('all', this.itemGroups, showSubelements, 'toolbar.empty.all')}
        </div>
        ${this.#renderFooter(changedCount)}
        ${this.#renderContextFootnote()}
      </div>
    `;
  }

  #renderHeader() {
    const showChip = this._configBool('enableWorkspaceChip') && this.workspaceTitle;
    const showPreview = this._configBool('enablePreviewLink') && this.pageUid > 0;

    return html`
      <header class="wew-menu__head">
        <div class="wew-menu__icon" aria-hidden="true">
          <svg viewBox="0 0 64 64" width="22" height="22">
            <path fill="currentColor" d="M36 20v20H16V20h20m2-4H14c-1.1 0-2 .9-2 2v24c0 1.1.9 2 2 2h24c1.1 0 2-.9 2-2V18c0-1.1-.9-2-2-2Z"/>
            <path fill="var(--icon-color-accent, #ff8700)" d="M50 24H40v4h8v20H28v-4h-4v6c0 1.1.9 2 2 2h24c1.1 0 2-.9 2-2V26c0-1.1-.9-2-2-2Z"/>
            <path fill="currentColor" opacity=".4" d="M40 18v6h8V14c0-1.1-.9-2-2-2H22c-1.1 0-2 .9-2 2v2h18c1.1 0 2 .9 2 2Z"/>
          </svg>
        </div>
        <div class="wew-menu__title-wrap">
          <h3 class="wew-menu__title">
            <span>${this._label('toolbar.title')}</span>
            ${showChip ? html`
              <span class="wew-menu__ws-chip" title="${this._label('toolbar.activeWorkspace')}">
                ${this.workspaceTitle}
              </span>
            ` : ''}
          </h3>
        </div>
        ${showPreview ? html`
          <button type="button"
                  class="wew-menu__preview"
                  data-wew-preview-link
                  data-wew-page-uid="${this.pageUid}"
                  title="${this._label('preview.button.title')}"
                  @click=${this.#onPreviewClick}>
            <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
              <rect x="3" y="3" width="8" height="9" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.3"/>
              <path d="M5.5 5h.7M5.5 7h3M5.5 9h3" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" fill="none"/>
              <rect x="6" y="6" width="7" height="8" rx="1.2" fill="var(--typo3-state-default-bg, currentColor)" stroke="currentColor" stroke-width="1.3"/>
            </svg>
            <span class="wew-menu__preview-label">${this._label('preview.button.preview')}</span>
          </button>
        ` : ''}
      </header>
    `;
  }

  #renderFilter(changedCount, totalCount) {
    if (!this._configBool('enableFilter')) {
      return '';
    }

    return html`
      <div class="wew-menu__filter"
           role="tablist"
           aria-label="${this._label('toolbar.filter.aria')}"
           data-wew-filter
           @keydown=${this.#onFilterKeydown}>
        ${this.#renderFilterTab('changed', changedCount, 'wew-tab-changed')}
        ${this.#renderFilterTab('all', totalCount, 'wew-tab-all')}
      </div>
    `;
  }

  #renderFilterTab(mode, count, id) {
    const active = this.mode === mode;
    return html`
      <button type="button"
              id="${id}"
              class="wew-menu__chip${active ? ' wew-menu__chip--active' : ''}"
              role="tab"
              aria-selected="${active ? 'true' : 'false'}"
              aria-controls="wew-tabpanel"
              tabindex="${active ? '0' : '-1'}"
              data-wew-mode="${mode}"
              @click=${this.#onModeClick}>
        ${this._label(mode === 'changed' ? 'toolbar.tab.changed' : 'toolbar.tab.all')}
        <span class="wew-menu__chip-count"
              aria-label="${this._label('toolbar.records', { count })}">${count}</span>
      </button>
    `;
  }

  #renderListPanel(panelMode, groups, showSubelements, emptyMessageKey) {
    const active = this.mode === panelMode;
    const hiddenClass = active ? '' : ' hidden';
    const ariaHidden = active ? 'false' : 'true';

    if (!panelHasItems(groups)) {
      return html`
        <div class="wew-menu__empty${hiddenClass}"
             data-wew-mode-panel="${panelMode}"
             aria-hidden="${ariaHidden}">
          <span class="wew-menu__empty-icon" aria-hidden="true">✓</span>
          <p>${this._label(emptyMessageKey)}</p>
        </div>
      `;
    }

    return html`
      <ul class="wew-list${hiddenClass}"
          data-wew-mode-panel="${panelMode}"
          aria-hidden="${ariaHidden}">
        ${groups.map((group) => this.#renderGroup(group, showSubelements))}
      </ul>
    `;
  }

  #renderGroup(group, showSubelements) {
    return html`
      ${group.label ? html`
        <li class="wew-list__colheader" role="presentation">
          <span class="wew-list__colheader-label">${group.label}</span>
        </li>
      ` : ''}
      ${(group.items || []).map((item) => this.#renderListItem(item, showSubelements))}
    `;
  }

  #renderListItem(item, showSubelements) {
    const rowId = `wew-${item.table}-${item.workspaceUid}`;
    const itemKey = this._key(item);
    const locatable = itemIsLocatable(this, item);
    const editable = itemIsEditable(item);
    const revert = canRevert(this, item);
    const url = editUrl(item);
    const checked = this.selection.has(itemKey);

    return html`
      <li class="${rowClasses(item)}"
          data-table="${item.table}"
          data-wew-key="${itemKey}">
        <label class="wew-list__label" for="${item.isChanged ? rowId : ''}">
          ${item.isChanged ? html`
            <input type="checkbox"
                   id="${rowId}"
                   class="form-check-input wew-list__check visually-hidden"
                   .checked=${checked}
                   data-wew-row-check
                   @change=${this.#onRowCheckChange} />
            <span class="wew-list__mark" aria-hidden="true">
              <svg viewBox="0 0 16 16" width="10" height="10" aria-hidden="true">
                <path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M3 8.5l3 3 7-7"/>
              </svg>
            </span>
          ` : ''}

          ${item.thumbnailUrl ? html`
            <span class="wew-list__thumb">
              <img src="${item.thumbnailUrl}" alt="" loading="lazy" />
            </span>
          ` : ''}

          <span class="wew-list__body">
            ${item.isPrimary && item.table === 'pages' ? html`
              <span class="wew-list__primary-kicker">${this._label('record.pageRecord')}</span>
            ` : ''}
            ${item.isPrimary && item.table === 'tx_news_domain_model_news' ? html`
              <span class="wew-list__primary-kicker">${this._label('record.newsRecord')}</span>
            ` : ''}

            <span class="wew-list__head">
              <span class="wew-list__title-text" title="${item.title}">${item.title}</span>
            </span>

            ${this._configBool('enableTypeLabels') ? html`
              <span class="wew-list__sub">
                <span class="wew-list__table">
                  ${item.tableLabel}
                  ${item.typeLabel && item.typeLabel !== item.tableLabel ? html`
                    <span class="wew-list__sep">·</span> ${item.typeLabel}
                  ` : ''}
                </span>
              </span>
            ` : ''}

            ${item.isChanged ? html`
              <span class="wew-list__change-actions">
                ${item.historyUrl ? html`
                  <button type="button"
                          class="wew-list__diff-trigger"
                          title="${diffTitle(this, item)}"
                          data-wew-diff
                          data-wew-table="${item.table}"
                          data-wew-workspace-uid="${item.workspaceUid}"
                          @click=${this.#onDiffClick}>
                    <span class="wew-list__diff-trigger-icon" aria-hidden="true">⇄</span>
                    <span class="wew-list__diff-trigger-label">${this._label('diff.viewHistory')}</span>
                  </button>
                ` : ''}

                ${showSubelements ? html`
                  <span class="wew-list__badges">
                    ${(item.changeBadges || []).map((badge) => html`
                      <span class="wew-state-pill wew-state-pill--${badge.badge} wew-state-pill--inline">${badge.kindLabel}</span>
                    `)}
                  </span>
                  ${item.isHidden && this._configBool('enableHiddenBadge') ? html`
                    <span class="wew-state-pill wew-state-pill--secondary wew-state-pill--inline"
                          title="${this._label('toolbar.hidden.title')}">
                      ${this._label('toolbar.hidden')}
                    </span>
                  ` : ''}
                ` : ''}
              </span>
            ` : ''}

            ${showSubelements && (item.childChanges || []).length > 0 ? html`
              <span class="wew-list__children">
                ${(item.childChanges || []).map((child) => this.#renderChildChange(child))}
              </span>
            ` : ''}
          </span>

          ${item.isChanged || locatable || editable ? html`
            <span class="wew-list__actions">
              ${item.isChanged ? html`
                <button type="button"
                        class="wew-list__discard${revert ? '' : ' wew-list__discard--disabled'}"
                        title="${this._label('discard.button.title')}"
                        aria-label="${this._label('discard.button.aria')}"
                        data-wew-discard
                        data-wew-table="${item.table}"
                        data-wew-workspace-uid="${item.workspaceUid}"
                        data-wew-can-revert="${revert ? '1' : '0'}"
                        ?disabled=${!revert}
                        @click=${this.#onDiscardClick}
                        @mouseenter=${revert ? () => this._previewDiscard(item) : undefined}
                        @mouseleave=${revert ? () => this._clearIframeHighlight() : undefined}
                        @focus=${revert ? () => this._previewDiscard(item) : undefined}
                        @blur=${revert ? () => this._clearIframeHighlight() : undefined}>
                  <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
                    <path fill="currentColor" d="M8 2c-1.8 0-3.4.8-4.5 2l-1-1c-.2-.2-.4-.1-.4.1l-.9 3.8c0 .2.1.3.3.3l3.8-.9c.2 0 .3-.3.1-.4l-1-1c.9-1 2.2-1.7 3.7-1.7 2.7 0 4.9 2.2 4.9 4.9S10.8 13 8.1 13c-1.5 0-2.8-.7-3.7-1.7l-.9.7c1.1 1.2 2.7 2 4.5 2 3.3 0 6-2.7 6-6s-2.7-6-6-6z"/>
                  </svg>
                </button>
              ` : ''}
              ${locatable || editable ? html`
                <button type="button"
                        class="wew-list__locate${locatable ? '' : ' wew-list__locate--edit-only'}"
                        title="${this._label('edit.modalTitle', { title: item.title })}"
                        aria-label="${this._label('edit.modalTitle', { title: item.title })}"
                        data-wew-edit
                        data-wew-locate="${locatable ? '1' : '0'}"
                        data-wew-edit-url="${url}"
                        @click=${this.#onEditClick}
                        @mouseenter=${locatable ? () => this._highlightInIframe(item) : undefined}
                        @mouseleave=${locatable ? () => this._clearIframeHighlight() : undefined}
                        @focus=${locatable ? () => this._highlightInIframe(item) : undefined}
                        @blur=${locatable ? () => this._clearIframeHighlight() : undefined}>
                  <svg class="wew-list__locate-icon wew-list__locate-icon--eye" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
                    <path fill="currentColor" d="M8.07 3C4.112 3 1 5.286 1 8s2.97 5 7 5c3.889 0 7-2.286 7-4.93C15 5.285 11.889 3.142 8.212 3h-.141Zm-.025 1.127c.141 0 .423.141.423.282s-.14.282-.423.282c-.845 0-1.69.704-1.69 1.55 0 .14-.141.282-.423.282-.282 0-.423-.141-.423-.282.141-1.127 1.268-2.114 2.536-2.114ZM2 8.03c0-1.298 1.017-2.591 2.647-3.312-.296.432-.296 1.01-.296 1.587 0 2.02 1.63 3.606 3.703 3.606 2.074 0 3.704-1.587 3.704-3.606 0-.577-.148-1.01-.296-1.443C12.943 5.582 14 6.875 14 8.029c-.148 2.02-2.841 3.924-6 3.971-3.36-.047-6-1.95-6-3.97Z"/>
                  </svg>
                  <svg class="wew-list__locate-icon wew-list__locate-icon--pencil" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
                    <path fill="currentColor" d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l10-10ZM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207 11.207 2.5ZM12.793 5.5 10.5 3.207 3 10.707V11h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293L12.793 5.5Zm-9.761 5.175-.215.541.659.659.541-.215-.215-.215a.5.5 0 0 1-.137-.275.5.5 0 0 1-.275-.137l-.358-.358Z"/>
                  </svg>
                </button>
              ` : ''}
            </span>
          ` : ''}
        </label>
      </li>
    `;
  }

  #renderChildChange(child) {
    const childTitle = child.title || child.tableLabel || child.table || '';

    return html`
      <span class="wew-list__child-change">
        ${child.thumbnailUrl ? html`
          <span class="wew-list__child-thumb"><img src="${child.thumbnailUrl}" alt="" loading="lazy" /></span>
        ` : html`
          <span class="wew-list__child-icon" aria-hidden="true"></span>
        `}
        <span class="wew-list__child-body">
          <span class="wew-list__child-title" title="${child.title || ''}">${childTitle}</span>
          <span class="wew-list__child-meta">
            ${child.tableLabel}${child.typeLabel ? html`<span class="wew-list__sep">·</span> ${child.typeLabel}` : ''}
          </span>
        </span>
        <span class="wew-state-pill wew-state-pill--${child.badge} wew-state-pill--inline">${child.kindLabel}</span>
      </span>
    `;
  }

  #renderFooter(changedCount) {
    const { total, selectedCount, allChecked, someChecked } = footerState(this);
    const publishBase = this._label('toolbar.publishToLive');
    const publishLabel = selectedCount > 0 ? `${publishBase} (${selectedCount})` : publishBase;
    const selectAllLabel = allChecked
      ? this._label('toolbar.deselectAll')
      : (someChecked ? this._label('toolbar.someSelected') : this._label('toolbar.selectAll'));
    const selectAllAria = allChecked
      ? this._label('toolbar.deselectAllChanges')
      : this._label('toolbar.selectAllChanges');

    return html`
      <footer class="wew-menu__foot" data-wew-footer>
        <div class="wew-menu__foot-selection">
          ${changedCount > 0 ? html`
            <label class="wew-menu__selectall">
              <input type="checkbox"
                     class="wew-menu__selectall-check"
                     .checked=${allChecked}
                     .indeterminate=${someChecked}
                     aria-checked="${allChecked ? 'true' : (someChecked ? 'mixed' : 'false')}"
                     aria-label="${selectAllAria}"
                     data-wew-select-all
                     @change=${this.#onSelectAllChange} />
              <span class="wew-menu__selectall-label" data-wew-select-all-label>${selectAllLabel}</span>
            </label>
            <span class="wew-menu__count" aria-live="polite" data-wew-selection-count>
              <strong data-wew-selected-count>${selectedCount}</strong>
              ${this._label('toolbar.of')}
              <span data-wew-total-count>${total}</span>
            </span>
          ` : ''}
        </div>
        <div class="wew-menu__foot-action">
          <button type="button"
                  class="btn btn-primary wew-menu__publish"
                  data-wew-publish
                  ?disabled=${selectedCount <= 0 || this.publishing}
                  @click=${this.#onPublishClick}>
            ${publishLabel}
          </button>
        </div>
      </footer>
    `;
  }

  #renderContextFootnote() {
    if (this.newsUid > 0) {
      return html`
        <div class="wew-menu__context" role="note">
          ${this._label('context.news', { uid: this.newsUid })}
        </div>
      `;
    }
    if (this.pageUid > 0) {
      return html`
        <div class="wew-menu__context" role="note">
          ${this._label('context.page', { uid: this.pageUid })}
        </div>
      `;
    }
    return '';
  }
}

customElements.define('webcon-easy-workspace-menu', WebconEasyWorkspaceMenu);
