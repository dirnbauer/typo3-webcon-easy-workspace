import { html, nothing, LitElement } from 'lit';
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
import { onDeclineMessage, broadcastDeclineState } from '@webconsulting/webcon-easy-workspace/menu-decline-sync.js';
import { openEditModal, openDiffModal } from '@webconsulting/webcon-easy-workspace/menu-modals.js';
import {
  refresh,
  publish,
  discardItem,
  discardCopies,
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
  keepOpenAcrossModal,
  focusedControl,
} from '@webconsulting/webcon-easy-workspace/menu-dropdown.js';
import {
  onListFocusIn,
  onListKeydown,
  keyboardContext,
  stopsOf,
  stopOf,
  setActiveStop,
  syncRovingTabindex,
} from '@webconsulting/webcon-easy-workspace/menu-keyboard.js';
import {
  groupRows,
  findItemByKey,
  isChangeRow,
  companionGroups,
  companionLanguageTitles,
  languageInfo,
} from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { layoutFor } from '@webconsulting/webcon-easy-workspace/layouts/index.js';
import { layoutName, viaLayout, callLayout } from '@webconsulting/webcon-easy-workspace/menu-layout.js';
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
 * Labels and TSconfig arrive from PHP as JSON on the `config` attribute,
 * including the editor's chosen layout (clarity, efficiency, core; see
 * layouts/index.js). The list is fetched per page/news context; the badge is
 * owned by BadgeSync. One row per edit: the translations TYPO3 copied along
 * ride on the edited row, leftover copies sit in a cleanup section, edits in
 * languages the editor's module does not show are listed apart.
 */
export class WebconEasyWorkspaceMenu extends LitElement {
  static properties = {
    state: { type: String },
    items: { type: Array },
    languages: { type: Object },
    viewLanguages: { type: Array },
    viewModule: { type: String },
    contextRecord: { type: Object },
    stage: { type: Object },
    workspaceId: { type: Number },
    workspaceTitle: { type: String },
    pageUid: { type: Number },
    newsUid: { type: Number },
    badgeCount: { type: Number },
    contextCount: { type: Number },
    userUid: { type: Number },
    publishing: { type: Boolean },
    cleaning: { type: Boolean },
    splitOpen: { type: Boolean },
    selectionVersion: { type: Number },
    sectionState: { type: Object },
    statusMessage: { type: String },
  };

  createRenderRoot() {
    return this;
  }

  constructor() {
    super();
    this.state = 'loading';
    this.items = [];
    this.languages = {};
    this.viewLanguages = null;
    this.viewModule = '';
    this.contextRecord = null;
    this.stage = null;
    this.selection = new Set();
    this.selectionVersion = 0;
    // Sections the editor opened or closed in this context (group key +
    // section => open). Untouched sections follow their default.
    this.sectionState = new Map();
    this._foldContextKey = '';
    this._selectionContextKey = '';
    this._selectionTouched = false;
    this.context = null;
    this.workspaceTitle = '';
    this.workspaceId = 0;
    this.userUid = 0;
    this.publishing = false;
    this.cleaning = false;
    this.splitOpen = false;
    this.statusMessage = '';
    this.listVersion = 0;
    // Where focus goes once the next list is there (focusAfterRender()),
    // and the list control that had it last (onListFocusIn()).
    this._focusAfterRender = null;
    this._lastFocused = null;
    this._config = { ...DEFAULT_CONFIG };
    this.layoutProfile = layoutFor(this);
    this.pageUid = 0;
    this.newsUid = 0;
    this.badgeCount = 0;
    this.contextCount = null;
    this.badge = null;
    const id = Math.random().toString(36).slice(2, 8);
    this.titleId = `wew-title-${id}`;
    this.summaryId = `wew-summary-${id}`;
    // Capture phase, so a layout's shortcuts see a key before the list does.
    this._keydownListener = { handleEvent: (event) => this.handleDialogKeydown(event), capture: true };
  }

  connectedCallback() {
    super.connectedCallback();
    this._config = this._readConfig();
    this.layoutProfile = layoutFor(this);
    this.workspaceId = this._configuredWorkspaceId();
    this.classList.toggle('wew-menu-host--compact-toolbar', !configBool(this, 'showSubelementsInToolbar'));

    // BadgeSync decides when to ask the server. While the list is wanted
    // (dropdown open, refresh button, after an action) it fetches the list
    // instead of /badge — the list response carries the badge block, so one
    // request serves both. The list itself is never fetched in the
    // background: the Visual Editor's decline buttons are drawn from the
    // badge's `records`.
    this._badgeListener = () => broadcastDeclineState(this);
    this.addEventListener('wew:badge', this._badgeListener);
    this.badge = new BadgeSync(this, {
      initialWorkspaceId: this.workspaceId,
      fetchList: ({ reason }) => this._refresh({ quiet: reason !== 'manual' }),
    });
    this.badge.start();

    const dropdownHost = toolbarHost(this);
    if (dropdownHost) {
      const menu = this.closest('.dropdown-menu');
      const toggleEl = dropdownHost.querySelector('.dropdown-toggle');
      ensurePopoverDropdown(dropdownHost, toggleEl, menu);
      this._openListener = () => this._onOpen();
      menu?.addEventListener('toggle', (event) => {
        if (event.newState === 'open') {
          this._onOpen(event);
          return;
        }
        // Closed by the editor (not by a Core modal opened from it, which
        // gives it back, see keepOpenAcrossModal): a pending focus request
        // would jump into the next list they open.
        if (!document.querySelector('typo3-backend-modal')) this._forgetFocus();
      });
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
    if (this._badgeListener) {
      this.removeEventListener('wew:badge', this._badgeListener);
    }
    if (this._declineMessageListener) {
      window.removeEventListener('message', this._declineMessageListener);
    }
    if (this._outsideClickListener) {
      document.removeEventListener('click', this._outsideClickListener, true);
    }
    super.disconnectedCallback();
  }

  /**
   * After every render: one stop of the list stays tabbable, and focus
   * that an action took away (a discarded row, a published list) lands on
   * the next sensible place once the new list is there — also when nothing
   * asked for it (a row that moved to the cleanup section after an edit).
   */
  updated() {
    syncRovingTabindex(this);
    this._restoreFocus();
    this._recoverLostFocus();
  }

  _onOpen(event = null) {
    // The dropdown reopening after a Core modal (keepOpenAcrossModal): the
    // action that opened the modal refreshes the list itself.
    if (event?.type === 'toggle' && this._reopeningAfterModal) {
      this._reopeningAfterModal = false;
      return;
    }
    // A fresh open has no action whose focus it should restore.
    this._forgetFocus();
    this._refreshList('open');
  }

  /** Drop every pending focus request and the memory of the focused control. */
  _forgetFocus() {
    this._focusAfterRender = null;
    this._lastFocused = null;
  }

  /**
   * Refresh the list through BadgeSync, so it shares the debounce and the
   * single in-flight request with every other trigger.
   */
  _refreshList(reason) {
    return this.badge ? this.badge.request(reason, { list: true }) : this._refresh({ quiet: reason !== 'manual' });
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
  _highlightInIframe(item, options) { return highlightInIframe(this, item, options); }
  _clearIframeHighlight() { return clearIframeHighlight(this); }
  _previewDiscard(item) { return previewDiscard(this, item); }

  async _refresh(options) {
    const badge = await refresh(this, options);
    this._clearLeavingRows();
    if (this.state === 'error' || this.state === 'no-context') {
      // No list arrived: nothing to focus, nothing to announce as updated.
      this._focusAfterRender = null;
      this._manualRefresh = false;
    }
    this._afterRefresh();
    return badge;
  }

  /**
   * Lit rewrites a row's class attribute only when its computed value
   * changes, so a row that survives the refresh (a failed discard, a publish
   * the server refused) would keep the exit class and stay hidden.
   */
  _clearLeavingRows() {
    this.querySelectorAll('.wew-row--leaving').forEach((row) => row.classList.remove('wew-row--leaving'));
  }

  /**
   * Silent outcome of a refresh, for the status region: the list the editor
   * asked for is there.
   */
  _afterRefresh() {
    if (this.state !== 'loaded' && this.state !== 'empty') return;
    if (this._manualRefresh) {
      this._manualRefresh = false;
      this.setStatus(label(this, 'toolbar.status.refreshed'));
    }
  }

  /**
   * Say something to screen readers through the footer's status region.
   * Cleared first and set a frame later, so the same message twice is
   * announced twice.
   */
  setStatus(message) {
    this.statusMessage = '';
    if (!message) return;
    const set = () => { this.statusMessage = message; };
    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(set);
    } else {
      set();
    }
  }

  /**
   * Where focus goes after an action removed what had it. Set before the
   * action as `{key?, index}`; resolved once a new list has rendered:
   * the stop of the row or cleanup line with `key`, else the stop at
   * `index`, else the empty state's heading, else the summary.
   */
  _restoreFocus() {
    const request = this._focusAfterRender;
    // A list that arrived but is not rendered yet: the render's updated()
    // comes back here (keepOpenAcrossModal may ask in between).
    if (!request || this.isUpdatePending || this.listVersion === request.listVersion || (this.state !== 'loaded' && this.state !== 'empty')) return;
    // A closed dropdown keeps the request: a Core modal opened from it hid
    // it and opens it again once it is gone (keepOpenAcrossModal); closing
    // it on purpose drops the request (the toggle listener).
    if (!this._isDropdownOpen()) return;
    this._focusAfterRender = null;
    const stops = stopsOf(this);
    let target = null;
    if (request.key) {
      const scope = this.querySelector(`[data-wew-key="${CSS.escape(request.key)}"], [data-wew-copy-group="${CSS.escape(request.key)}"]`);
      // A row or cleanup line of that record, else its translation chip.
      target = scope?.querySelector('[data-wew-stop]')
        || this.querySelector(`[data-wew-companion="${CSS.escape(request.key)}"]`)
        || null;
    }
    if (!target && Number.isInteger(request.index) && stops.length > 0) {
      target = stops[Math.min(Math.max(0, request.index), stops.length - 1)];
    }
    this._focusTarget(target, stops);
  }

  /** Focus a control of the list (its stop becomes the tabbable one), else the empty state or the summary. */
  _focusTarget(target, stops = stopsOf(this)) {
    const element = target || this.querySelector('[data-wew-empty-title]') || this.querySelector(`#${CSS.escape(this.summaryId)}`);
    if (!element) return;
    const stop = stopOf(element);
    if (stop && stops.includes(stop)) setActiveStop(this, stop);
    element.focus({ preventScroll: false });
  }

  /**
   * A render removed the control that had focus while no action asked for
   * a place (a row whose edit made it equal to live moved to the cleanup
   * section, a chip whose translation became its own row): focus moves to
   * the stop now at its place in the list, never to the page body.
   */
  _recoverLostFocus() {
    const lost = this._lastFocused;
    if (!lost || lost.isConnected || this._focusAfterRender) return;
    // A list still loading has nothing to offer yet.
    if (this.state === 'loading') return;
    this._lastFocused = null;
    const active = this.ownerDocument?.activeElement;
    if ((active && active !== this.ownerDocument.body) || !this._isDropdownOpen()) return;
    if (this.state !== 'loaded' && this.state !== 'empty') return;
    const stops = stopsOf(this);
    const index = Math.max(0, Number(this._lastFocusIndex) || 0);
    this._focusTarget(stops.length > 0 ? stops[Math.min(index, stops.length - 1)] : null, stops);
  }

  /** Remember where focus should go once the list after an action is there. */
  focusAfterRender(request) {
    this._focusAfterRender = { ...request, listVersion: this.listVersion };
  }

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
    this._manualRefresh = true;
    this._refreshList('manual');
  }

  handleRowCheck(event) {
    const row = event.currentTarget.closest('[data-wew-row]');
    const item = findItemByKey(this, row?.getAttribute('data-wew-key') || '');
    if (item) toggle(this, item, event.currentTarget.checked);
  }

  /**
   * Clicking anywhere on an edit row toggles its checkbox — the row-level
   * click target editors expect from a publish list. The checkbox itself,
   * buttons, chips and links keep their own handlers.
   */
  handleRowClick(event, item) {
    if (!isChangeRow(item)) return;
    if (event.target.closest('[data-wew-row-check], button, a, label, input')) return;
    toggle(this, item, !this.selection.has(key(this, item)));
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
      event?.preventDefault?.();
      this._closeDropdown();
    }
  }

  handleRowAction(event, action, item) {
    event.preventDefault();
    event.stopPropagation();
    this._clearIframeHighlight();
    // What gets focus back after the modal: the clicked button, or the
    // control a keyboard shortcut was pressed on.
    const opener = event.type === 'click' && event.currentTarget instanceof Element ? event.currentTarget : focusedControl(this);
    switch (action) {
      case 'edit':
        if (item.editUrl || item.contextualEditUrl) {
          openEditModal(this, item, { opener });
        } else {
          Notification.info(this._label('edit.title'), this._label('edit.noForm'));
        }
        break;
      case 'diff':
        openDiffModal(this, item, { opener });
        break;
      case 'discard':
        this._confirmAndDiscard(item, opener);
        break;
      case 'preview':
        this._highlightInIframe(item, { announce: true });
        break;
      default:
        break;
    }
  }

  /**
   * A translation chip (on an edited row or a cleanup line): open that
   * translation's version in the edit modal. When a save made it differ,
   * the list after the save shows it as an edit of its own, and the editor
   * hears so: in the status region while the dropdown is open, else in the
   * save's notification. Closing the modal without saving says nothing.
   */
  handleCompanionEdit(owner, member) {
    const language = languageInfo(this, Number(member.languageUid));
    const languageTitle = language?.title || String(member.languageUid);
    if (!member.editUrl && !member.contextualEditUrl) {
      Notification.info(this._label('edit.title'), this._label('edit.noForm'));
      return;
    }
    this._clearIframeHighlight();
    const itemKey = `${member.table}:${member.workspaceUid}`;
    const opener = focusedControl(this);
    openEditModal(this, {
      table: member.table,
      liveUid: member.liveUid,
      workspaceUid: member.workspaceUid,
      title: `${owner?.title || ''} (${languageTitle})`.trim(),
      editUrl: member.editUrl,
      contextualEditUrl: member.contextualEditUrl,
      isChanged: true,
    }, {
      opener,
      // After a save the chip may be gone (the translation is an edit of its
      // own now, its row elsewhere): focus goes to that row, else back to
      // the chip, else to the stop the chip belonged to.
      beforeRefresh: () => this.focusAfterRender({
        key: itemKey,
        index: Math.max(0, stopsOf(this).indexOf(stopOf(opener))),
      }),
      afterSave: () => {
        const now = findItemByKey(this, itemKey);
        if (!now || !isChangeRow(now)) return null;
        const message = label(this, 'toolbar.companions.nowOwn', { language: languageTitle });
        if (this._isDropdownOpen()) {
          this.setStatus(message);
          return null;
        }
        return message;
      },
    });
  }

  /**
   * Discard leftover copies — a whole cleanup section by default, or the
   * copy lines a layout passes. No confirmation by default (nothing differs
   * from live); a layout may ask first (`beforeDiscardCopies`).
   */
  async handleDiscardCopies(group, copies = group?.copies || []) {
    if (this.cleaning || copies.length === 0) return;
    const proceed = await callLayout(this, 'beforeDiscardCopies', [group, copies], true);
    if (!proceed) return;
    const toggleEl = this.querySelector(`[data-wew-section-toggle="${CSS.escape(`${group.key}:copies`)}"]`);
    this.focusAfterRender({ index: Math.max(0, stopsOf(this).indexOf(toggleEl)) });
    const armedFor = this.listVersion;
    this.cleaning = true;
    try {
      await discardCopies(this, copies.flatMap((copy) => copy.records || []));
    } finally {
      this.cleaning = false;
      // A failed request brings no list: the request must not fire on the
      // next, unrelated one.
      if (this._focusAfterRender?.listVersion === armedFor && this.listVersion === armedFor) {
        this._focusAfterRender = null;
      }
    }
  }

  /**
   * Keys on the whole dialog, capture phase: the layout's shortcuts first
   * (`onKeydown`, true = handled), then Escape closes the preview menu and
   * after that the dropdown. List navigation is menu-keyboard.js.
   */
  handleDialogKeydown(event) {
    if (callLayout(this, 'onKeydown', [event, keyboardContext(this, event)], false) === true) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    if (event.key === 'Escape') {
      if (this.splitOpen) {
        this.splitOpen = false;
        event.stopPropagation();
        return;
      }
      this._closeDropdown();
    }
  }

  /**
   * The confirm text: what discarding this edit does, what happens to the
   * translations TYPO3 copied along with it, and which other translations
   * Core's discard removes with it (the item's dependents): new ones, and —
   * named apart — ones with edits of their own, which Core discards because
   * they point at this edit's version (a moved or deleted element). An item
   * without dependents (or a payload that does not know them) gets the
   * plain text. Built from text nodes only.
   */
  discardConfirmContent(item) {
    const paragraphs = [label(this, discardMessageKey(item), { title: item.title, table: item.tableLabel || item.table })];
    for (const group of companionGroups(this, item)) {
      paragraphs.push(label(this, `discard.modal.companions.${group.kind}`, {
        languages: group.chips.map((chip) => chip.language.title).join(', '),
      }));
    }
    const dependents = Array.isArray(item.dependents) ? item.dependents.filter((dependent) => dependent && typeof dependent === 'object') : [];
    const added = dependents.filter((dependent) => dependent.edited !== true);
    const edited = dependents.filter((dependent) => dependent.edited === true);
    if (added.length > 0) {
      paragraphs.push(label(this, 'discard.modal.dependents', { languages: companionLanguageTitles(this, added) }));
    }
    if (edited.length > 0) {
      paragraphs.push(label(this, 'discard.modal.dependentEdits', { languages: companionLanguageTitles(this, edited) }));
    }
    const content = document.createElement('div');
    content.className = 'wew-confirm';
    for (const text of paragraphs) {
      const paragraph = document.createElement('p');
      paragraph.textContent = text;
      content.append(paragraph);
    }
    return content;
  }

  /** Focus after a row discard: the next row, else the previous one. */
  _neighbourKey(item) {
    const rows = Array.from(this.querySelectorAll('[data-wew-row]'));
    const index = rows.findIndex((row) => row.getAttribute('data-wew-key') === key(this, item));
    if (index < 0) return null;
    return (rows[index + 1] || rows[index - 1])?.getAttribute('data-wew-key') || null;
  }

  /**
   * Discard one edit after Core's confirm modal. The dropdown, which the
   * modal hides, opens again when it is gone: focus returns to `opener`
   * on cancel, and to the neighbouring row after a discard.
   */
  async _confirmAndDiscard(item, opener = focusedControl(this)) {
    if (!ENDPOINTS.discard) return;

    const modal = Modal.confirm(
      this._label('discard.modal.title'),
      this.discardConfirmContent(item),
      SeverityEnum.warning,
      [
        { text: this._label('discard.modal.cancel'), btnClass: 'btn-default', name: 'cancel', trigger: () => modal.hideModal() },
        { text: this._label('discard.modal.confirm'), btnClass: 'btn-warning', name: 'discard', active: true, trigger: () => modal.hideModal() },
      ],
    );
    const keep = keepOpenAcrossModal(this, modal, opener);

    return new Promise((resolve) => {
      modal.addEventListener('button.clicked', async (event) => {
        if (event.target?.getAttribute('name') !== 'discard') {
          resolve(false);
          return;
        }
        try {
          // Only a dropdown that opens again has a place to focus; a closed
          // one (the Visual Editor's decline button) must not carry the
          // request into the next, unrelated open.
          if (keep.wasOpen) this.focusAfterRender({ key: this._neighbourKey(item), index: 0 });
          const result = await discardItem(this, item);
          if (result.success) {
            const message = this._label(discardSuccessMessageKey(item), { title: item.title });
            const along = result.dependentsDiscarded > 0
              ? ` ${this._label('discard.success.dependents', { count: result.dependentsDiscarded })}`
              : '';
            Notification.success(this._label('discard.success.title'), `${message}${along}`, 4);
            if (result.warnings.length > 0) {
              Notification.warning(this._label('discard.warning.title'), result.warnings.join(' / '));
            }
            await this._animateRowsLeaving([key(this, item)]);
            await this._refreshList('discard');
            reloadPreviewAndRefocus(this, item);
          } else {
            this._focusAfterRender = null;
            Notification.error(this._label('discard.error.title'), result.errors.join(' / '));
          }
        } catch (error) {
          this._focusAfterRender = null;
          Notification.error(this._label('discard.error.failedTitle'), error?.message || this._label('error.unexpected'));
        } finally {
          resolve(true);
        }
      });
    });
  }

  /** Open or close a section against its current effective state. */
  toggleSection(sectionId) {
    const open = this._effectiveSections instanceof Map ? Boolean(this._effectiveSections.get(sectionId)) : false;
    const next = new Map(this.sectionState);
    next.set(sectionId, !open);
    this.sectionState = next;
  }

  // ---- Rendering ---------------------------------------------------------

  render() {
    void this.selectionVersion;
    return html`
      <div class="wew-menu wew-menu--${this.state}"
           data-wew-layout=${layoutName(this)}
           role="dialog"
           aria-modal="false"
           aria-labelledby=${this.titleId}
           aria-describedby=${this.summaryId}
           @keydown=${this._keydownListener}>
        ${viaLayout(this, 'renderHeader', [], {}, () => renderHeader(this))}
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
        if (groups.length === 0) return viaLayout(this, 'renderEmpty', [], {}, () => renderEmpty(this));
        return html`
          <div class="wew-list"
               aria-busy=${this.publishing || this.cleaning ? 'true' : 'false'}
               @keydown=${this.handleListKeydown}
               @focusin=${this.handleListFocusIn}>
            ${viaLayout(this, 'renderIntro', [groups], {}, () => nothing)}
            ${groups.map((group, index) => renderGroup(this, group, { groupCount: groups.length, index }))}
          </div>
        `;
      }
    }
  }
}

if (!customElements.get('webcon-easy-workspace-menu-v2')) {
  customElements.define('webcon-easy-workspace-menu-v2', WebconEasyWorkspaceMenu);
}
