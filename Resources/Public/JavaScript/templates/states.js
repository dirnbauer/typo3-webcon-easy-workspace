import { html, nothing } from 'lit';
import { label } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { changeRowCount } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

const SKELETON_ROWS = [0, 1, 2];

/**
 * First load only (a refresh keeps the list and spins the refresh icon).
 * The skeleton copies the row grid — checkbox, icon, title, badge stub at
 * the start of line 2, three action stubs — so nothing jumps when the list
 * arrives.
 */
export function renderLoading(host) {
  return html`
    <ul class="wew-skeleton" aria-busy="true" aria-label=${label(host, 'toolbar.loading')} data-wew-loading>
      ${SKELETON_ROWS.map(() => html`
        <li class="wew-skeleton__row" aria-hidden="true">
          <span class="wew-skeleton__check"></span>
          <span class="wew-skeleton__block"></span>
          <span class="wew-skeleton__line wew-skeleton__line--title"></span>
          <span class="wew-skeleton__actions"><span></span><span></span><span></span></span>
          <span class="wew-skeleton__meta">
            <span class="wew-skeleton__badge"></span>
            <span class="wew-skeleton__line wew-skeleton__line--short"></span>
          </span>
        </li>`)}
    </ul>
  `;
}

/** Nothing pending here; edits elsewhere are one click away in the module. */
export function renderEmpty(host) {
  const elsewhere = Math.max(0, (Number(host.badgeCount) || 0) - changeRowCount(host.items));
  return html`
    <div class="wew-state wew-state--empty" data-wew-empty>
      <span class="wew-state__icon" aria-hidden="true"><typo3-backend-icon identifier="actions-check" size="small"></typo3-backend-icon></span>
      <h3 class="wew-state__title" tabindex="-1" data-wew-empty-title>${label(host, 'toolbar.empty.title')}</h3>
      <p class="wew-state__text">${label(host, 'toolbar.empty.changed')}</p>
      ${elsewhere > 0 ? html`
        <button type="button" class="btn btn-default btn-sm wew-state__action" data-wew-empty-elsewhere @click=${(event) => host.handleOpenModule(event)}>
          <typo3-backend-icon identifier="wew-module" size="small"></typo3-backend-icon>
          <span>${label(host, 'toolbar.empty.elsewhere', { count: elsewhere })}</span>
        </button>` : nothing}
    </div>
  `;
}

export function renderNoContext(host) {
  return html`
    <div class="wew-state wew-state--no-context" role="status" data-wew-no-context>
      <span class="wew-state__icon" aria-hidden="true"><typo3-backend-icon identifier="apps-pagetree-page-default" size="small"></typo3-backend-icon></span>
      <p class="wew-state__text">${label(host, 'toolbar.noContext')}</p>
      <button type="button" class="btn btn-default btn-sm wew-state__action" data-wew-no-context-module @click=${(event) => host.handleOpenModule(event)}>
        <typo3-backend-icon identifier="wew-module" size="small"></typo3-backend-icon>
        <span>${label(host, 'toolbar.openModule')}</span>
      </button>
    </div>
  `;
}

export function renderError(host) {
  return html`
    <div class="wew-state wew-state--error" role="alert" data-wew-error>
      <span class="wew-state__icon" aria-hidden="true"><typo3-backend-icon identifier="actions-exclamation" size="small"></typo3-backend-icon></span>
      <p class="wew-state__title">${label(host, 'toolbar.loadError')}</p>
      <button type="button" class="btn btn-default btn-sm wew-state__action" data-wew-retry @click=${() => host.handleRefresh()}>
        <typo3-backend-icon identifier="actions-refresh" size="small"></typo3-backend-icon>
        <span>${label(host, 'toolbar.retry')}</span>
      </button>
    </div>
  `;
}
