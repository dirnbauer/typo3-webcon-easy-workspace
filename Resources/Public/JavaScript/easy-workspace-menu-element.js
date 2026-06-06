/**
 * Easy Workspace toolbar dropdown.
 *
 * PHP/Fluid renders the menu markup; this custom element is glue only:
 * context detection, AJAX refresh, event delegation, and TYPO3 modals.
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

import { ENDPOINTS, DEFAULT_CONFIG } from './menu-constants.js';
import {
  readConfig,
  label,
  configBool,
  buildContextLabel,
  detectContext,
  detectLanguageUid,
} from './menu-context.js';
import {
  highlightInIframe,
  clearIframeHighlight,
  isLocatable,
  isEditable,
  previewDiscard,
  reloadPreviewIframes,
} from './menu-preview-locate.js';
import {
  onBackendSaveMessage,
  registerBackendSaveSignalListeners,
  clearBackendSaveSignalListeners,
  onDeclineMessage,
  broadcastDeclineState,
} from './menu-backend-save-sync.js';
import { openEditModal, openDiffModal } from './menu-modals.js';
import { isCompactToolbar, friendlyTable } from './menu-glue.js';
import {
  discardRecordsForItem,
  readPersistedMode,
  refresh,
  refreshAfterBackendSave,
  publish,
  copyPreviewLink,
  configuredWorkspaceId,
  setMode,
} from './menu-actions.js';
import { key } from './menu-selection.js';

class WebconEasyWorkspaceMenu extends HTMLElement {
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
    clearBackendSaveSignalListeners(this);
  }

  _readConfig() { return readConfig(this); }
  _label(key, variables = {}) { return label(this, key, variables); }
  _buildContextLabel(data) { return buildContextLabel(this, data); }
  _detectContext() { return detectContext(this); }
  _detectLanguageUid() { return detectLanguageUid(this); }
  _configBool(key, fallback = false) { return configBool(this, key, fallback); }

  _highlightInIframe(item, options) { return highlightInIframe(this, item, options); }
  _clearIframeHighlight() { return clearIframeHighlight(this); }
  _isLocatable(item) { return isLocatable(this, item); }
  _isEditable(item) { return isEditable(item); }
  _previewDiscard(item) { return previewDiscard(this, item); }
  _reloadPreviewIframes() { return reloadPreviewIframes(); }
  _openEditModal(item) { return openEditModal(this, item); }
  _openDiffModal(item) { return openDiffModal(this, item); }
  _broadcastDeclineState() { return broadcastDeclineState(this); }

  _key(item) { return key(this, item); }
  _setMode(mode) { return setMode(this, mode); }
  _configuredWorkspaceId() { return configuredWorkspaceId(this); }
  _refresh() { return refresh(this); }
  _refreshAfterBackendSave(options) { return refreshAfterBackendSave(this, options); }
  _publish() { return publish(this); }
  _copyPreviewLink(pageUid) { return copyPreviewLink(this, pageUid); }
  _isCompactToolbar() { return isCompactToolbar(this); }
  _friendlyTable(table) { return friendlyTable(this, table); }

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
}

customElements.define('webcon-easy-workspace-menu', WebconEasyWorkspaceMenu);
