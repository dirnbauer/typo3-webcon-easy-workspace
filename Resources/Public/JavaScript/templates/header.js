import { html, nothing } from 'lit';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { changedItemCount } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

function countText(host, onPage, total) {
  if (total <= 0) return label(host, 'toolbar.count.none');
  if (host.state === 'no-context') return label(host, 'toolbar.count.workspace', { count: total });
  const parts = [label(host, 'toolbar.count.onPage', { count: onPage })];
  const elsewhere = Math.max(0, total - onPage);
  if (elsewhere > 0) parts.push(label(host, 'toolbar.count.elsewhere', { count: elsewhere }));
  return parts.join(' · ');
}

export function renderHeader(host) {
  const onPage = changedItemCount(host.items);
  const total = Math.max(0, Number(host.badgeCount) || 0);
  const showChip = configBool(host, 'enableWorkspaceChip', true) && host.workspaceTitle;
  const refreshing = host.state === 'loading';
  const refreshLabel = label(host, 'toolbar.refresh');

  return html`
    <header class="wew-menu__head">
      <div class="wew-menu__titles">
        <h2 class="wew-menu__title" id=${host.titleId}>${label(host, 'toolbar.title')}</h2>
        <div class="wew-menu__chips">
          ${showChip ? html`
            <span class="wew-chip wew-chip--workspace" title=${label(host, 'toolbar.activeWorkspace')}>
              <span class="wew-chip__dot" aria-hidden="true"></span>${host.workspaceTitle}
            </span>` : nothing}
          ${host.stage?.label ? html`
            <span class="wew-chip wew-chip--stage" title=${label(host, 'toolbar.stage.title')}>${host.stage.label}</span>` : nothing}
          <span class="wew-chip wew-chip--count ${total > 0 ? 'has-changes' : ''}" aria-live="polite" data-wew-count-chip>
            ${countText(host, onPage, total)}
          </span>
        </div>
      </div>
      <button type="button"
              class="wew-iconbtn wew-menu__refresh ${refreshing ? 'is-busy' : ''}"
              title=${refreshLabel}
              aria-label=${refreshLabel}
              ?disabled=${refreshing}
              data-wew-refresh
              @click=${() => host.handleRefresh()}>
        <typo3-backend-icon identifier="actions-refresh" size="small"></typo3-backend-icon>
      </button>
    </header>
  `;
}
