import { html, nothing } from 'lit';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { summaryText } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { moduleHref } from '@webconsulting/webcon-easy-workspace/menu-actions.js';
import { viaLayout } from '@webconsulting/webcon-easy-workspace/menu-layout.js';

export { summaryText };

/**
 * Keep a number on the line of its noun ("4 unveränderte Kopien"): at phone
 * width the summary wraps, and a line ending in "· 4" reads as a stray
 * figure. Only the visible text is bound; the meaning is the same.
 */
export function bindNumbers(text) {
  return String(text).replace(/(\d) (?=\S)/g, '$1\u00A0');
}

/**
 * Compact header: one row with the workspace (Core's workspace icon names
 * what the title is), its stage and the two header actions, one line with
 * the numbers below. The summary describes the dialog; it is no live region
 * (it would re-announce on every refresh) — the footer's status region and
 * Core's notifications speak instead.
 */
export function renderHeader(host) {
  const showName = configBool(host, 'enableWorkspaceChip', true) && host.workspaceTitle;
  const refreshing = host.state === 'loading';
  const refreshLabel = label(host, refreshing ? 'toolbar.refreshing' : 'toolbar.refresh');
  const moduleLabel = label(host, 'toolbar.openModule');
  const href = moduleHref(host);
  const stage = host.stage?.label || '';
  const summary = bindNumbers(summaryText(host));

  return html`
    <header class="wew-menu__head">
      <div class="wew-menu__titles">
        <div class="wew-menu__title-row">
          <typo3-backend-icon class="wew-menu__title-icon" identifier="apps-toolbar-menu-workspace" size="small" aria-hidden="true"></typo3-backend-icon>
          <h2 class="wew-menu__title" id=${host.titleId} title=${label(host, 'toolbar.activeWorkspace')}>
            ${showName ? host.workspaceTitle : label(host, 'toolbar.title')}
          </h2>
          ${stage ? html`
            <span class="badge badge-secondary wew-menu__stage" title=${label(host, 'toolbar.stage.label', { stage })} data-wew-stage><span class="visually-hidden">${label(host, 'toolbar.stage.prefix')} </span>${stage}</span>` : nothing}
        </div>
        <p class="wew-menu__summary" id=${host.summaryId || nothing} ?hidden=${summary === ''} data-wew-count-chip>${viaLayout(host, 'renderSummary', [summary], {}, () => summary)}</p>
      </div>
      <div class="wew-menu__head-side">
        ${href ? html`
          <a class="btn btn-borderless btn-sm wew-menu__module"
             href=${href}
             title=${moduleLabel}
             aria-label=${moduleLabel}
             data-wew-open-module
             @click=${(event) => host.handleOpenModule(event)}>
            <typo3-backend-icon identifier="wew-module" size="small"></typo3-backend-icon>
          </a>` : nothing}
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
