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

function emptyArt() {
  return html`
    <svg class="wew-state__art" viewBox="0 0 64 64" aria-hidden="true">
      <g fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 26v20a4 4 0 0 0 4 4h32a4 4 0 0 0 4-4V26"/>
        <path d="M12 36h10l3 6h14l3-6h10"/>
        <g opacity=".4"><path d="M18 26v-8a4 4 0 0 1 4-4h20a4 4 0 0 1 4 4v8"/></g>
      </g>
      <path class="accent" d="m24 24 5 5 11-12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  `;
}

function errorArt() {
  return html`
    <svg class="wew-state__art" viewBox="0 0 64 64" aria-hidden="true">
      <g fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="32" cy="32" r="18"/>
        <path d="M32 22v12M32 40v.5"/>
      </g>
    </svg>
  `;
}

export function renderEmpty(host) {
  return html`
    <div class="wew-state wew-state--empty" role="status" data-wew-empty>
      ${emptyArt()}
      <p class="wew-state__title">${label(host, 'toolbar.empty.title')}</p>
      <p class="wew-state__text">${label(host, 'toolbar.empty.changed')}</p>
    </div>
  `;
}

export function renderNoContext(host) {
  return html`
    <div class="wew-state wew-state--no-context" role="status" data-wew-no-context>
      ${emptyArt()}
      <p class="wew-state__text">${label(host, 'toolbar.noContext')}</p>
    </div>
  `;
}

export function renderError(host) {
  return html`
    <div class="wew-state wew-state--error" role="alert" data-wew-error>
      ${errorArt()}
      <p class="wew-state__title">${label(host, 'toolbar.loadError')}</p>
      <button type="button" class="btn btn-default btn-sm wew-state__action" data-wew-retry @click=${() => host.handleRefresh()}>
        ${label(host, 'toolbar.retry')}
      </button>
    </div>
  `;
}
