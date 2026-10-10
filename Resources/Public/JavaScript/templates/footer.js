import { html, nothing } from 'lit';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { footerState } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { callLayout, layoutFlag, viaLayout } from '@webconsulting/webcon-easy-workspace/menu-layout.js';

export function renderPreviewSplit(host) {
  const open = Boolean(host.splitOpen);
  const openLabel = label(host, 'toolbar.preview.open');
  return html`
    <div class="wew-split ${open ? 'is-open' : ''}" data-wew-split>
      <button type="button"
              class="btn btn-default btn-sm wew-split__main"
              title=${label(host, 'toolbar.preview.openTitle')}
              aria-label=${openLabel}
              data-wew-preview-open
              @click=${() => host.handlePreviewOpen()}>
        <typo3-backend-icon identifier="wew-preview" size="small"></typo3-backend-icon>
        <span>${openLabel}</span>
      </button>
      <button type="button"
              class="btn btn-default btn-sm wew-split__toggle"
              aria-haspopup="menu"
              aria-expanded=${open ? 'true' : 'false'}
              aria-label=${label(host, 'toolbar.preview.more')}
              title=${label(host, 'toolbar.preview.more')}
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
 * tokens; a bare `.form-check-input` renders without a box). The label says
 * "Alle (5)" when everything is selected and "2 von 5" otherwise; the full
 * sentence is the checkbox's name and the hover title.
 */
export function renderSelectAll(host, { total, selectedCount, allChecked, someChecked }) {
  const inputId = `${host.titleId}-select-all`;
  const action = allChecked ? label(host, 'toolbar.deselectAllChanges') : label(host, 'toolbar.selectAllChanges');
  const count = label(host, 'toolbar.selection.count', { selected: selectedCount, total });
  const visible = allChecked
    ? label(host, 'toolbar.selection.all', { total })
    : label(host, 'toolbar.selectedOf', { selected: selectedCount, total });
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
      <label class="form-check-label wew-menu__count" for=${inputId} data-wew-selection-count>${visible}</label>
    </div>
  `;
}

/**
 * The primary action: "1 Änderung veröffentlichen", or why it is disabled.
 * A layout with a publish shortcut (hook `publishShortcut` → {text, keys})
 * names it in the tooltip and in aria-keyshortcuts.
 */
export function renderPublishButton(host, { selectedCount }) {
  const hintId = `${host.titleId}-publish-hint`;
  const shortcut = callLayout(host, 'publishShortcut', [], null);
  const none = selectedCount <= 0 && !host.publishing;
  const text = host.publishing
    ? label(host, 'toolbar.publishing')
    : (selectedCount > 0 ? label(host, 'toolbar.publishCount', { count: selectedCount }) : label(host, 'toolbar.publishToLive'));
  return html`
    <button type="button"
            class="btn btn-primary btn-sm wew-menu__publish ${host.publishing ? 'is-busy' : ''}"
            data-wew-publish
            ?disabled=${selectedCount <= 0 || host.publishing}
            title=${shortcut ? label(host, 'toolbar.shortcut.publishTitle', { shortcut: shortcut.text }) : nothing}
            aria-keyshortcuts=${shortcut ? shortcut.keys : nothing}
            aria-describedby=${none ? hintId : nothing}
            aria-busy=${host.publishing ? 'true' : 'false'}
            @click=${() => host.handlePublish()}>
      <typo3-backend-icon identifier="wew-publish" size="small"></typo3-backend-icon>
      <span>${text}</span>
    </button>
    ${none ? html`<span class="visually-hidden" id=${hintId}>${label(host, 'toolbar.publish.noneSelected')}</span>` : nothing}
  `;
}

/**
 * The label of the screen-reader keyboard help: the layout's `keyboardHint`
 * hook (it may depend on the configuration), else its flag.
 */
export function keyboardHintKey(host) {
  return String(callLayout(host, 'keyboardHint', [], null) || layoutFlag(host, 'keyboardHintKey') || 'toolbar.keyboardHint');
}

/**
 * Footer: select-all (from two edits on: with one, the publish label
 * already carries the count), the preview split and the publish button.
 * Layout hook `footerExtras(host, state, ctx)` adds content before the
 * actions (shortcut hints).
 */
export function renderFooter(host) {
  const state = footerState(host);
  const { total } = state;
  const showPreview = configBool(host, 'enablePreviewLink', true) && host.pageUid > 0;
  const showSelectAll = total >= Math.max(1, Number(layoutFlag(host, 'selectAllMin')) || 2);

  return html`
    <footer class="wew-menu__foot" data-wew-footer>
      ${showSelectAll ? renderSelectAll(host, state) : nothing}
      ${viaLayout(host, 'footerExtras', [state], {}, () => nothing)}
      <div class="wew-menu__foot-actions">
        ${showPreview ? renderPreviewSplit(host) : nothing}
        ${total > 0 ? renderPublishButton(host, state) : nothing}
      </div>
      <span class="visually-hidden">${label(host, keyboardHintKey(host))}</span>
      <span class="visually-hidden" role="status" data-wew-status>${host.statusMessage || ''}</span>
    </footer>
  `;
}
