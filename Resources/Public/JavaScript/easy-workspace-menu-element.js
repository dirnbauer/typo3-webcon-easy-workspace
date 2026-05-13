/**
 * Easy Workspace toolbar dropdown.
 *
 * Renders into light DOM so backend Bootstrap classes apply. Reads the
 * current page / news context from window.location, fetches the list of
 * pending workspace changes for that context, and lets the editor publish
 * the selected items in one DataHandler call.
 */

import { LitElement, html, nothing } from 'lit';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

const ENDPOINTS = {
  items: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_items || '',
  publish: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_publish || '',
};

class WebconEasyWorkspaceMenu extends LitElement {
  static properties = {
    state: { state: true },
    items: { state: true },
    selection: { state: true },
    context: { state: true },
    publishing: { state: true },
    contextLabel: { state: true },
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
    this.publishing = false;
  }

  connectedCallback() {
    super.connectedCallback();
    // Toolbar dropdowns are lazy-rendered when opened. We refresh data
    // each time the host <li> is shown so the count is always current.
    this._refresh();
    // Listen for the surrounding dropdown opening event.
    const dropdownHost = this.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
      || this.closest('.toolbar-item');
    if (dropdownHost) {
      dropdownHost.addEventListener('shown.bs.dropdown', () => this._refresh());
    }
  }

  render() {
    return html`
      <div class="wew-menu">
        <header class="wew-menu__head">
          <div class="wew-menu__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20">
              <path d="M3 12l18-9-4 9 4 9-18-9Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
              <path d="M3 12l9-3 9 3" fill="none" stroke="currentColor" stroke-width="1.4" opacity=".5"/>
            </svg>
          </div>
          <div class="wew-menu__title-wrap">
            <h3 class="wew-menu__title">Workspace publish</h3>
            <p class="wew-menu__subtitle">${this.contextLabel || 'Loading…'}</p>
          </div>
        </header>
        ${this._renderBody()}
        ${this._renderFooter()}
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
      return html`
        <div class="wew-menu__empty">
          <span class="wew-menu__empty-icon" aria-hidden="true">✓</span>
          <p>Nothing pending on this page.</p>
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
    const checked = this.selection.has(this._key(item));
    return html`
      <li class="wew-list__row ${item.isPrimary ? 'wew-list__row--primary' : ''}" data-table=${item.table}>
        <label class="wew-list__label" for=${id}>
          <input
            type="checkbox"
            id=${id}
            class="form-check-input wew-list__check"
            .checked=${checked}
            @change=${(e) => this._toggle(item, e.target.checked)}
          />
          <span class="wew-list__title">
            <span class="wew-list__title-text">${item.title}</span>
            <span class="wew-list__meta">
              <span class="badge badge-${item.badge || 'info'}">${item.kindLabel}</span>
              <span class="wew-list__table">${this._friendlyTable(item.table)}</span>
            </span>
          </span>
          ${item.thumbnailUrl
            ? html`<span class="wew-list__thumb"><img src=${item.thumbnailUrl} alt="" loading="lazy"/></span>`
            : html`<span class="wew-list__thumb wew-list__thumb--placeholder" aria-hidden="true">·</span>`}
        </label>
      </li>
    `;
  }

  _renderFooter() {
    if (this.state !== 'loaded') {
      return nothing;
    }
    const selectedCount = this.selection.size;
    return html`
      <footer class="wew-menu__foot">
        <div class="wew-menu__counters">
          <button type="button" class="btn btn-link btn-sm" @click=${() => this._selectAll(true)}>Select all</button>
          <span class="text-muted">·</span>
          <button type="button" class="btn btn-link btn-sm" @click=${() => this._selectAll(false)}>None</button>
          <span class="wew-menu__count">${selectedCount} of ${this.items.length} selected</span>
        </div>
        <button
          type="button"
          class="btn btn-primary"
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
    this.selection = new Set(this.items.map((i) => this._key(i)));
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

    const query = pageUid ? { pageUid } : { newsUid };
    try {
      const response = await new AjaxRequest(ENDPOINTS.items).withQueryArguments(query).get();
      const data = await response.resolve();
      this.context = data.context;
      this.items = Array.isArray(data.items) ? data.items : [];
      this.contextLabel = this._buildContextLabel(data);
      // Default: every item is selected.
      this.selection = new Set(this.items.map((i) => this._key(i)));
      this.state = this.items.length === 0 ? 'empty' : 'loaded';
    } catch (error) {
      console.error('[easy-workspace] items request failed', error);
      this.state = 'error';
    }
  }

  _buildContextLabel(data) {
    if (data.context === 'news' && data.newsUid) {
      return `News record #${data.newsUid} + linked content elements`;
    }
    if (data.context === 'page' && data.pageUid) {
      return `Page #${data.pageUid} + its content elements${data.hasNews ? ' & news' : ''}`;
    }
    return 'Current workspace';
  }

  _detectContext() {
    const params = new URLSearchParams(window.location.search);
    const pageUid = parseInt(params.get('id') || '0', 10);
    let newsUid = 0;
    for (const key of params.keys()) {
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
}

customElements.define('webcon-easy-workspace-menu', WebconEasyWorkspaceMenu);
