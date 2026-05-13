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
import { copyToClipboard } from '@typo3/backend/copy-to-clipboard.js';

const ENDPOINTS = {
  items: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_items || '',
  publish: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_publish || '',
  previewLink: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_preview_link || '',
};

class WebconEasyWorkspaceMenu extends LitElement {
  static properties = {
    state: { state: true },
    items: { state: true },
    selection: { state: true },
    context: { state: true },
    publishing: { state: true },
    contextLabel: { state: true },
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
    this.publishing = false;
    this.mode = 'changed';
    this.copyingPreview = false;
  }

  connectedCallback() {
    super.connectedCallback();
    this._refresh();
    const dropdownHost = this.closest('[id^="typo3-cms-backend-backend-toolbaritems"]')
      || this.closest('.toolbar-item');
    if (dropdownHost) {
      dropdownHost.addEventListener('shown.bs.dropdown', () => this._refresh());
    }
  }

  render() {
    const { pageUid } = this._detectContext();
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
          ${pageUid > 0
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
              ${item.isHidden
                ? html`<span class="badge badge-dark wew-list__hidden-badge" title="Record is hidden (won't show on the live site)">Hidden</span>`
                : nothing}
              <span class="wew-list__table">${item.typeLabel || this._friendlyTable(item.table)}</span>
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
      // Routes the copy through TYPO3's helper so the standard
      // "Copied" notification appears in the editor's language.
      copyToClipboard(data.url);
    } catch (error) {
      Notification.error('Preview link', error?.message || 'Unexpected error.');
    } finally {
      this.copyingPreview = false;
    }
  }
}

customElements.define('webcon-easy-workspace-menu', WebconEasyWorkspaceMenu);
