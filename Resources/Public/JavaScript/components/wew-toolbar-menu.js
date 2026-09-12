import { html, LitElement } from 'lit';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import '@typo3/backend/element/icon-element.js';

import { ENDPOINTS, DEFAULT_CONFIG } from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import {
  readConfig,
  label,
  configBool,
  discardMessageKey,
  discardSuccessMessageKey,
} from '@webconsulting/webcon-easy-workspace/menu-context.js';
import {
  highlightInIframe,
  clearIframeHighlight,
  previewDiscard,
  reloadPreviewAndRefocus,
} from '@webconsulting/webcon-easy-workspace/menu-preview-locate.js';
import { onDeclineMessage } from '@webconsulting/webcon-easy-workspace/menu-decline-sync.js';
import { openEditModal, openDiffModal } from '@webconsulting/webcon-easy-workspace/menu-modals.js';
import {
  refresh,
  publish,
  discardItem,
  copyPreviewLink,
  openPreview,
  openModule,
  configuredWorkspaceId,
} from '@webconsulting/webcon-easy-workspace/menu-actions.js';
import { BadgeSync } from '@webconsulting/webcon-easy-workspace/menu-badge.js';
import { key, toggle, selectAll } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import {
  toolbarHost,
  ensurePopoverDropdown,
  isDropdownOpen,
  closeDropdown,
} from '@webconsulting/webcon-easy-workspace/menu-dropdown.js';
import { onListFocusIn, onListKeydown } from '@webconsulting/webcon-easy-workspace/menu-keyboard.js';
import { groupRows, findItemByKey } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { renderHeader } from '@webconsulting/webcon-easy-workspace/templates/header.js';
import { renderGroup } from '@webconsulting/webcon-easy-workspace/templates/group.js';
import { renderFooter } from '@webconsulting/webcon-easy-workspace/templates/footer.js';
import {
  renderLoading,
  renderEmpty,
  renderError,
  renderNoContext,
} from '@webconsulting/webcon-easy-workspace/templates/states.js';

const ROW_EXIT_MS = 200;

/**
 * Easy Workspace toolbar dropdown (Lit, light DOM).
 *
 * Labels and TSconfig arrive from PHP as JSON on the `config` attribute.
 * The list is fetched per page/news context; the badge is owned by
 * BadgeSync and always reflects the whole workspace.
 */
export class WebconEasyWorkspaceMenu extends LitElement {
  static properties = {
    state: { type: String },
    items: { type: Array },
    changedItemGroups: { type: Array },
    contextRecord: { type: Object },
    stage: { type: Object },
    workspaceId: { type: Number },
    workspaceTitle: { type: String },
    pageUid: { type: Number },
    newsUid: { type: Number },
    badgeCount: { type: Number },
    publishing: { type: Boolean },
    splitOpen: { type: Boolean },
    selectionVersion: { type: Number },
  };

  createRenderRoot() {
    return this;
  }

  constructor() {
    super();
    this.state = 'loading';
    this.items = [];
    this.changedItemGroups = [];
    this.contextRecord = null;
    this.stage = null;
    this.selection = new Set();
    this.selectionVersion = 0;
    this._selectionContextKey = '';
    this._selectionTouched = false;
    this.context = null;
    this.workspaceTitle = '';
    this.workspaceId = 0;
    this.publishing = false;
    this.splitOpen = false;
    this._config = { ...DEFAULT_CONFIG };
    this.pageUid = 0;
    this.newsUid = 0;
    this.badgeCount = 0;
    this.badge = null;
    this.titleId = `wew-title-${Math.random().toString(36).slice(2, 8)}`;
  }

  connectedCallback() {
    super.connectedCallback();
    this._config = this._readConfig();
    this.workspaceId = this._configuredWorkspaceId();
    this.classList.toggle('wew-menu-host--compact-toolbar', !configBool(this, 'showSubelementsInToolbar'));

    this.badge = new BadgeSync(this, { initialWorkspaceId: this.workspaceId });
    this.badge.start();
    this._refresh();

    this._navListener = () => this._refresh({ quiet: true });
    for (const targetDocument of this._documents()) {
      targetDocument.addEventListener('typo3:module-state-storage:update:web', this._navListener);
      targetDocument.addEventListener('typo3:module-state-storage:update-with-tree-identifier:web', this._navListener);
    }

    const dropdownHost = toolbarHost(this);
    if (dropdownHost) {
      const menu = this.closest('.dropdown-menu');
      const toggleEl = dropdownHost.querySelector('.dropdown-toggle');
      ensurePopoverDropdown(dropdownHost, toggleEl, menu);
      this._openListener = () => this._onOpen();
      menu?.addEventListener('toggle', (event) => { if (event.newState === 'open') this._onOpen(); });
      dropdownHost.addEventListener('shown.bs.dropdown', this._openListener);
      toggleEl?.addEventListener('click', this._openListener);
    }

    this._declineMessageListener = (event) => onDeclineMessage(this, event);
    window.addEventListener('message', this._declineMessageListener);
    this._outsideClickListener = (event) => {
      if (this.splitOpen && !event.composedPath().some((node) => node?.hasAttribute?.('data-wew-split'))) {
        this.splitOpen = false;
      }
    };
    document.addEventListener('click', this._outsideClickListener, true);
  }

  disconnectedCallback() {
    this._clearIframeHighlight();
    this.badge?.stop();
    this.badge = null;
    if (this._navListener) {
      for (const targetDocument of this._documents()) {
        targetDocument.removeEventListener('typo3:module-state-storage:update:web', this._navListener);
        targetDocument.removeEventListener('typo3:module-state-storage:update-with-tree-identifier:web', this._navListener);
      }
    }
    if (this._declineMessageListener) {
      window.removeEventListener('message', this._declineMessageListener);
    }
    if (this._outsideClickListener) {
      document.removeEventListener('click', this._outsideClickListener, true);
    }
    super.disconnectedCallback();
  }

  _documents() {
    const documents = new Set([document]);
    try { if (window.top?.document) documents.add(window.top.document); } catch { /* cross-origin */ }
    return documents;
  }

  _onOpen() {
    this.badge?.request('open');
    this._refresh({ quiet: true });
  }

  _isDropdownOpen() { return isDropdownOpen(this); }
  _closeDropdown() { return closeDropdown(this); }
  handleListFocusIn(event) { onListFocusIn(this, event); }
  handleListKeydown(event) { onListKeydown(this, event); }

  requestSelectionUpdate() {
    this.selectionVersion += 1;
  }

  _readConfig() { return readConfig(this); }
  _label(labelKey, variables = {}) { return label(this, labelKey, variables); }
  _configuredWorkspaceId() { return configuredWorkspaceId(this); }
  _refresh(options) { return refresh(this, options); }
  _highlightInIframe(item, options) { return highlightInIframe(this, item, options); }
  _clearIframeHighlight() { return clearIframeHighlight(this); }
  _previewDiscard(item) { return previewDiscard(this, item); }

  /**
   * Row exit animation (CSS `display` transition with allow-discrete).
   * Resolves once the rows are hidden or after the motion budget elapsed.
   */
  async _animateRowsLeaving(keys) {
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
    const rows = keys
      .map((itemKey) => this.querySelector(`[data-wew-row][data-wew-key="${CSS.escape(itemKey)}"]`))
      .filter(Boolean);
    if (rows.length === 0) return;
    rows.forEach((row) => row.classList.add('wew-row--leaving'));
    await new Promise((resolve) => window.setTimeout(resolve, ROW_EXIT_MS));
  }

  // ---- Event handlers used by the templates ------------------------------

  handleRefresh() {
    this.badge?.request('manual');
    this._refresh();
  }

  handleRowCheck(event) {
    const row = event.currentTarget.closest('[data-wew-row]');
    const item = findItemByKey(this, row?.getAttribute('data-wew-key') || '');
    if (item) toggle(this, item, event.currentTarget.checked);
  }

  handleSelectAll(event) {
    selectAll(this, event.currentTarget.checked);
  }

  handlePublish() {
    publish(this);
  }

  handlePreviewOpen() {
    this.splitOpen = false;
    if (this.pageUid > 0) openPreview(this, this.pageUid);
  }

  handlePreviewCopy() {
    this.splitOpen = false;
    if (this.pageUid > 0) copyPreviewLink(this, this.pageUid);
  }

  handleSplitToggle() {
    this.splitOpen = !this.splitOpen;
  }

  handleOpenModule(event) {
    if (openModule(this)) {
      event.preventDefault();
      this._closeDropdown();
    }
  }

  handleRowAction(event, action, item) {
    event.preventDefault();
    event.stopPropagation();
    this._clearIframeHighlight();
    switch (action) {
      case 'edit':
        if (item.editUrl || item.contextualEditUrl) {
          openEditModal(this, item);
        } else {
          Notification.info(this._label('edit.title'), this._label('edit.noForm'));
        }
        break;
      case 'diff':
        openDiffModal(this, item);
        break;
      case 'discard':
        this._confirmAndDiscard(item);
        break;
      case 'preview':
        this._highlightInIframe(item, { announce: true });
        break;
      default:
        break;
    }
  }

  handleDialogKeydown(event) {
    if (event.key === 'Escape') {
      if (this.splitOpen) {
        this.splitOpen = false;
        event.stopPropagation();
        return;
      }
      this._closeDropdown();
    }
  }

  async _confirmAndDiscard(item) {
    if (!ENDPOINTS.discard) return;

    const modal = Modal.confirm(
      this._label('discard.modal.title'),
      this._label(discardMessageKey(item), { title: item.title, table: item.tableLabel || item.table }),
      SeverityEnum.warning,
      [
        { text: this._label('discard.modal.cancel'), btnClass: 'btn-default', name: 'cancel', trigger: () => modal.hideModal() },
        { text: this._label('discard.modal.confirm'), btnClass: 'btn-warning', name: 'discard', active: true, trigger: () => modal.hideModal() },
      ],
    );

    return new Promise((resolve) => {
      modal.addEventListener('button.clicked', async (event) => {
        if (event.target?.getAttribute('name') !== 'discard') {
          resolve(false);
          return;
        }
        try {
          const result = await discardItem(this, item);
          if (result.success) {
            Notification.success(this._label('discard.success.title'), this._label(discardSuccessMessageKey(item), { title: item.title }), 4);
            await this._animateRowsLeaving([key(this, item)]);
            this.badge?.broadcast('discard');
            await this._refresh({ quiet: true });
            reloadPreviewAndRefocus(this, item);
          } else {
            Notification.error(this._label('discard.error.title'), result.errors.join(' / '));
          }
        } catch (error) {
          Notification.error(this._label('discard.error.failedTitle'), error?.message || this._label('error.unexpected'));
        } finally {
          resolve(true);
        }
      });
    });
  }

  // ---- Rendering ---------------------------------------------------------

  render() {
    void this.selectionVersion;
    return html`
      <div class="wew-menu wew-menu--${this.state}"
           role="dialog"
           aria-modal="false"
           aria-labelledby=${this.titleId}
           @keydown=${this.handleDialogKeydown}>
        ${renderHeader(this)}
        <div class="wew-menu__body" data-wew-body>${this.#renderBody()}</div>
        ${renderFooter(this)}
      </div>
    `;
  }

  #renderBody() {
    switch (this.state) {
      case 'loading':
        return renderLoading(this);
      case 'error':
        return renderError(this);
      case 'no-context':
        return renderNoContext(this);
      default: {
        const groups = groupRows(this);
        if (groups.length === 0) return renderEmpty(this);
        let offset = 0;
        return html`
          <ul class="wew-list" role="list" @keydown=${this.handleListKeydown} @focusin=${this.handleListFocusIn}>
            ${groups.map((group) => {
              const rendered = renderGroup(this, group, offset);
              offset += group.rows.length;
              return rendered;
            })}
          </ul>
        `;
      }
    }
  }
}

if (!customElements.get('webcon-easy-workspace-menu-v2')) {
  customElements.define('webcon-easy-workspace-menu-v2', WebconEasyWorkspaceMenu);
}
