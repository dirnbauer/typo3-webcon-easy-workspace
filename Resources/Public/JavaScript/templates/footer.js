import { html, nothing } from 'lit';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { footerState } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { moduleHref } from '@webconsulting/webcon-easy-workspace/menu-actions.js';

function renderPreviewSplit(host) {
  const open = Boolean(host.splitOpen);
  return html`
    <div class="wew-split ${open ? 'is-open' : ''}" data-wew-split>
      <button type="button"
              class="btn btn-default btn-sm wew-split__main"
              title=${label(host, 'toolbar.preview.openTitle')}
              data-wew-preview-open
              @click=${() => host.handlePreviewOpen()}>
        <typo3-backend-icon identifier="wew-preview" size="small"></typo3-backend-icon>
        <span>${label(host, 'toolbar.preview.open')}</span>
      </button>
      <button type="button"
              class="btn btn-default btn-sm wew-split__toggle"
              aria-haspopup="menu"
              aria-expanded=${open ? 'true' : 'false'}
              aria-label=${label(host, 'toolbar.preview.more')}
              data-wew-split-toggle
              @click=${() => host.handleSplitToggle()}>
        <typo3-backend-icon identifier="actions-chevron-up" size="small"></typo3-backend-icon>
      </button>
      <ul class="wew-split__menu" role="menu" ?hidden=${!open}>
        <li role="none">
          <button type="button" role="menuitem" class="wew-split__item" data-wew-preview-copy @click=${() => host.handlePreviewCopy()}>
            <typo3-backend-icon identifier="actions-link" size="small"></typo3-backend-icon>
            <span>${label(host, 'toolbar.preview.copyLink')}</span>
          </button>
        </li>
      </ul>
    </div>
  `;
}

/**
 * Select-all as a Core `.form-check` (the wrapper defines the checkbox
 * tokens; a bare `.form-check-input` renders without a box).
 */
function renderSelectAll(host, { total, selectedCount, allChecked, someChecked }) {
  const inputId = `${host.titleId}-select-all`;
  return html`
    <div class="form-check wew-menu__selectall">
      <input type="checkbox"
             class="form-check-input"
             id=${inputId}
             .checked=${allChecked}
             .indeterminate=${someChecked}
             aria-label=${allChecked ? label(host, 'toolbar.deselectAllChanges') : label(host, 'toolbar.selectAllChanges')}
             data-wew-select-all
             @change=${(event) => host.handleSelectAll(event)} />
      <label class="form-check-label" for=${inputId}>
        ${allChecked ? label(host, 'toolbar.deselectAll') : label(host, 'toolbar.selectAll')}
        <span class="wew-menu__count" data-wew-selection-count><strong>${selectedCount}</strong>/${total}</span>
      </label>
    </div>
  `;
}

export function renderFooter(host) {
  const state = footerState(host);
  const { total, selectedCount } = state;
  const showPreview = configBool(host, 'enablePreviewLink', true) && host.pageUid > 0;
  // Nothing to select (empty page, no page, failed request): the selection
  // controls would only be disabled noise next to the empty state.
  const showSelection = total > 0;
  const publishLabel = host.publishing
    ? label(host, 'toolbar.publishing')
    : (selectedCount > 0 ? label(host, 'toolbar.publishCount', { count: selectedCount }) : label(host, 'toolbar.publishToLive'));
  const href = moduleHref(host);

  return html`
    <footer class="wew-menu__foot" data-wew-footer>
      ${showSelection ? renderSelectAll(host, state) : html`<span></span>`}
      <div class="wew-menu__foot-actions">
        ${showPreview ? renderPreviewSplit(host) : nothing}
        ${showSelection ? html`
          <button type="button"
                  class="btn btn-primary btn-sm wew-menu__publish"
                  data-wew-publish
                  ?disabled=${selectedCount <= 0 || host.publishing}
                  @click=${() => host.handlePublish()}>
            <typo3-backend-icon identifier="wew-publish" size="small"></typo3-backend-icon>
            <span>${publishLabel}</span>
          </button>` : nothing}
      </div>
      <div class="wew-menu__foot-meta">
        <span class="visually-hidden">${label(host, 'toolbar.keyboardHint')}</span>
        ${href ? html`
          <a class="wew-menu__module-link" href=${href} data-wew-open-module @click=${(event) => host.handleOpenModule(event)}>
            <typo3-backend-icon identifier="wew-module" size="small"></typo3-backend-icon>
            ${label(host, 'toolbar.openModule')}
          </a>` : nothing}
      </div>
    </footer>
  `;
}
