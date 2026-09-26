import { html, nothing } from 'lit';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { footerState } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

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
 * tokens; a bare `.form-check-input` renders without a box). Compact: the
 * label shows "selected/total", the full sentence is the checkbox's name and
 * the hover title.
 */
function renderSelectAll(host, { total, selectedCount, allChecked, someChecked }) {
  const inputId = `${host.titleId}-select-all`;
  const action = allChecked ? label(host, 'toolbar.deselectAllChanges') : label(host, 'toolbar.selectAllChanges');
  const count = label(host, 'toolbar.selection.count', { selected: selectedCount, total });
  return html`
    <div class="wew-menu__selectall form-check" title=${`${action} · ${count}`}>
      <input type="checkbox"
             class="form-check-input"
             id=${inputId}
             .checked=${allChecked}
             .indeterminate=${someChecked}
             aria-label=${`${action} (${count})`}
             data-wew-select-all
             @change=${(event) => host.handleSelectAll(event)} />
      <label class="form-check-label wew-menu__count" for=${inputId} data-wew-selection-count>${selectedCount}/${total}</label>
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
      <span class="visually-hidden">${label(host, 'toolbar.keyboardHint')}</span>
    </footer>
  `;
}
