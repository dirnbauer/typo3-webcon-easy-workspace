import { html, nothing } from 'lit';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { changedItemCount } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

/**
 * One sentence about the numbers: what is pending here, and how much more
 * waits elsewhere in the workspace.
 */
export function summaryText(host, onPage, total) {
  if (host.state === 'no-context') {
    return total > 0 ? label(host, 'toolbar.count.workspace', { count: total }) : label(host, 'toolbar.count.none');
  }
  if (onPage <= 0 && total <= 0) return label(host, 'toolbar.count.none');
  const parts = [label(host, host.newsUid > 0 ? 'toolbar.summary.news' : 'toolbar.summary.page', { count: onPage })];
  const elsewhere = Math.max(0, total - onPage);
  if (elsewhere > 0) parts.push(label(host, 'toolbar.summary.elsewhere', { count: elsewhere }));
  return parts.join(' · ');
}

export function renderHeader(host) {
  const onPage = changedItemCount(host.items);
  const total = Math.max(0, Number(host.badgeCount) || 0);
  const showName = configBool(host, 'enableWorkspaceChip', true) && host.workspaceTitle;
  const refreshing = host.state === 'loading';
  const refreshLabel = label(host, 'toolbar.refresh');

  return html`
    <header class="wew-menu__head">
      <div class="wew-menu__titles">
        ${showName ? html`<span class="wew-menu__eyebrow">${label(host, 'toolbar.title')}</span>` : nothing}
        <h2 class="wew-menu__title" id=${host.titleId}>${showName ? host.workspaceTitle : label(host, 'toolbar.title')}</h2>
        <p class="wew-menu__summary" aria-live="polite" data-wew-count-chip>${summaryText(host, onPage, total)}</p>
      </div>
      <div class="wew-menu__head-side">
        ${host.stage?.label ? html`
          <span class="badge badge-secondary wew-menu__stage" title=${label(host, 'toolbar.stage.title')} data-wew-stage>
            ${label(host, 'toolbar.stage.label', { stage: host.stage.label })}
          </span>` : nothing}
        <button type="button"
                class="btn btn-borderless btn-sm wew-menu__refresh ${refreshing ? 'is-busy' : ''}"
                title=${refreshLabel}
                aria-label=${refreshLabel}
                ?disabled=${refreshing}
                data-wew-refresh
                @click=${() => host.handleRefresh()}>
          <typo3-backend-icon identifier="actions-refresh" size="small"></typo3-backend-icon>
        </button>
      </div>
    </header>
  `;
}
