import { html } from 'lit';
import { label } from '@webconsulting/webcon-easy-workspace/menu-context.js';

const SKELETON_ROWS = [0, 1, 2];

export function renderLoading(host) {
  return html`
    <ul class="wew-skeleton" aria-busy="true" aria-label=${label(host, 'toolbar.loading')} data-wew-loading>
      ${SKELETON_ROWS.map(() => html`
        <li class="wew-skeleton__row" aria-hidden="true">
          <span class="wew-skeleton__check"></span>
          <span class="wew-skeleton__block"></span>
          <span class="wew-skeleton__lines">
            <span class="wew-skeleton__line"></span>
            <span class="wew-skeleton__line wew-skeleton__line--short"></span>
          </span>
        </li>`)}
    </ul>
  `;
}

export function renderEmpty(host) {
  return html`
    <div class="wew-state wew-state--empty" role="status" data-wew-empty>
      <span class="wew-state__icon" aria-hidden="true"><typo3-backend-icon identifier="actions-check-circle" size="large"></typo3-backend-icon></span>
      <p class="wew-state__title">${label(host, 'toolbar.empty.title')}</p>
      <p class="wew-state__text">${label(host, 'toolbar.empty.changed')}</p>
    </div>
  `;
}

export function renderNoContext(host) {
  return html`
    <div class="wew-state wew-state--no-context" role="status" data-wew-no-context>
      <span class="wew-state__icon" aria-hidden="true"><typo3-backend-icon identifier="apps-pagetree-page-default" size="large"></typo3-backend-icon></span>
      <p class="wew-state__text">${label(host, 'toolbar.noContext')}</p>
    </div>
  `;
}

export function renderError(host) {
  return html`
    <div class="wew-state wew-state--error" role="alert" data-wew-error>
      <span class="wew-state__icon" aria-hidden="true"><typo3-backend-icon identifier="actions-exclamation-circle" size="large"></typo3-backend-icon></span>
      <p class="wew-state__title">${label(host, 'toolbar.loadError')}</p>
      <button type="button" class="btn btn-default btn-sm wew-state__action" data-wew-retry @click=${() => host.handleRefresh()}>
        ${label(host, 'toolbar.retry')}
      </button>
    </div>
  `;
}
