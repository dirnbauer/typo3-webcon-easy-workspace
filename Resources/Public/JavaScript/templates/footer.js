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

export function renderFooter(host) {
  const { total, selectedCount, allChecked, someChecked } = footerState(host);
  const showPreview = configBool(host, 'enablePreviewLink', true) && host.pageUid > 0;
  const publishLabel = host.publishing
    ? label(host, 'toolbar.publishing')
    : (selectedCount > 0 ? label(host, 'toolbar.publishCount', { count: selectedCount }) : label(host, 'toolbar.publishToLive'));
  const href = moduleHref(host);

  return html`
    <footer class="wew-menu__foot" data-wew-footer>
      <label class="wew-menu__selectall ${total === 0 ? 'is-disabled' : ''}">
        <input type="checkbox"
               class="form-check-input wew-menu__selectall-check"
               .checked=${allChecked}
               .indeterminate=${someChecked}
               ?disabled=${total === 0}
               aria-label=${allChecked ? label(host, 'toolbar.deselectAllChanges') : label(host, 'toolbar.selectAllChanges')}
               data-wew-select-all
               @change=${(event) => host.handleSelectAll(event)} />
        <span class="wew-menu__selectall-label">${allChecked ? label(host, 'toolbar.deselectAll') : label(host, 'toolbar.selectAll')}</span>
        ${total > 0 ? html`<span class="wew-menu__count" data-wew-selection-count><strong>${selectedCount}</strong>/${total}</span>` : nothing}
      </label>
      <div class="wew-menu__foot-actions">
        ${showPreview ? renderPreviewSplit(host) : nothing}
        <button type="button"
                class="btn btn-primary btn-sm wew-menu__publish"
                data-wew-publish
                ?disabled=${selectedCount <= 0 || host.publishing}
                @click=${() => host.handlePublish()}>
          <typo3-backend-icon identifier="wew-publish" size="small"></typo3-backend-icon>
          <span>${publishLabel}</span>
        </button>
      </div>
      <div class="wew-menu__foot-meta">
        <span class="wew-sr-only">${label(host, 'toolbar.keyboardHint')}</span>
        ${href ? html`
          <a class="wew-menu__module-link" href=${href} data-wew-open-module @click=${(event) => host.handleOpenModule(event)}>
            <typo3-backend-icon identifier="wew-module" size="small"></typo3-backend-icon>
            ${label(host, 'toolbar.openModule')}
          </a>` : nothing}
      </div>
    </footer>
  `;
}
