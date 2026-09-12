import { html, nothing } from 'lit';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { key } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { isLocatable, isEditable } from '@webconsulting/webcon-easy-workspace/menu-preview-locate.js';
import {
  changeType,
  relativeTime,
  diffTitle,
  canRevert,
} from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

function actionButton(host, item, action, icon, title, { danger = false, disabled = false, hover = null } = {}) {
  return html`
    <button type="button"
            class="wew-iconbtn ${danger ? 'wew-iconbtn--danger' : ''}"
            title=${title}
            aria-label=${title}
            tabindex="-1"
            ?disabled=${disabled}
            data-wew-action=${action}
            @click=${(event) => host.handleRowAction(event, action, item)}
            @mouseenter=${hover ? () => hover(true) : nothing}
            @mouseleave=${hover ? () => hover(false) : nothing}
            @focus=${hover ? () => hover(true) : nothing}
            @blur=${hover ? () => hover(false) : nothing}>
      <typo3-backend-icon identifier=${icon} size="small"></typo3-backend-icon>
    </button>
  `;
}

function metaParts(host, item) {
  const parts = [];
  if (configBool(host, 'enableTypeLabels', true)) {
    parts.push(item.typeLabel && item.typeLabel !== item.tableLabel ? item.typeLabel : item.tableLabel);
  }
  if (item.colPosLabel) parts.push(item.colPosLabel);
  if (item.latestChangeUser) parts.push(item.latestChangeUser);
  const when = relativeTime(host, item.latestChangeAt || item.tstamp);
  if (when) parts.push(when);
  return parts.filter(Boolean);
}

export function renderRow(host, item, index) {
  const itemKey = key(host, item);
  const type = changeType(item);
  const selected = host.selection.has(itemKey);
  const revert = canRevert(host, item);
  const locatable = isLocatable(host, item);
  const editable = isEditable(item);
  const showSubelements = configBool(host, 'showSubelementsInToolbar', false);
  const classes = [
    'wew-row',
    item.isChanged ? 'wew-row--changed' : 'wew-row--unchanged',
    selected ? 'wew-row--selected' : '',
    item.isHidden ? 'wew-row--hidden' : '',
    item.isPrimary ? 'wew-row--primary' : '',
  ].filter(Boolean).join(' ');
  const meta = metaParts(host, item);
  const hoverLocate = locatable ? (on) => (on ? host._highlightInIframe(item) : host._clearIframeHighlight()) : null;
  const hoverDiscard = revert ? (on) => (on ? host._previewDiscard(item) : host._clearIframeHighlight()) : null;

  return html`
    <li class=${classes}
        role="listitem"
        tabindex=${index === 0 ? '0' : '-1'}
        data-wew-row
        data-wew-key=${itemKey}
        data-table=${item.table}
        data-change=${item.isChanged ? type : nothing}>
      <span class="wew-row__select">
        ${item.isChanged ? html`
          <input type="checkbox"
                 class="form-check-input wew-row__check"
                 tabindex="-1"
                 .checked=${selected}
                 aria-label=${label(host, 'toolbar.row.select', { title: item.title })}
                 data-wew-row-check
                 @change=${(event) => host.handleRowCheck(event)} />` : nothing}
      </span>
      ${item.thumbnailUrl ? html`
        <span class="wew-row__thumb"><img src=${item.thumbnailUrl} alt="" loading="lazy" /></span>` : html`
        <span class="wew-row__icon" aria-hidden="true">
          <typo3-backend-icon identifier=${item.iconIdentifier || 'mimetypes-other-other'} size="small"></typo3-backend-icon>
        </span>`}
      <span class="wew-row__body">
        <span class="wew-row__head">
          <span class="wew-row__title" title=${item.title}>${item.title}</span>
          ${item.isChanged ? html`
            <span class="wew-pill wew-pill--${type}">
              <typo3-backend-icon identifier="wew-change-${type}" size="small"></typo3-backend-icon>${label(host, `change.${type}`)}
            </span>` : nothing}
          ${item.isHidden && configBool(host, 'enableHiddenBadge', true) ? html`
            <span class="wew-pill wew-pill--notice" title=${label(host, 'toolbar.hidden.title')}>${label(host, 'toolbar.hidden')}</span>` : nothing}
        </span>
        ${meta.length > 0 ? html`
          <span class="wew-row__meta">
            ${meta.map((part, partIndex) => html`${partIndex > 0 ? html`<span class="wew-row__meta-sep" aria-hidden="true">·</span>` : nothing}<span>${part}</span>`)}
          </span>` : nothing}
        ${showSubelements && (item.childChanges || []).length > 0 ? html`
          <span class="wew-row__children">
            ${item.childChanges.map((child) => html`
              <span class="wew-row__child">
                <span class="wew-row__child-title" title=${child.title || ''}>${child.title || child.tableLabel || child.table}</span>
                <span class="wew-pill wew-pill--${changeType(child)} wew-pill--mini">${child.kindLabel}</span>
              </span>`)}
          </span>` : nothing}
      </span>
      <span class="wew-row__actions">
        ${editable ? actionButton(host, item, 'edit', 'actions-open', label(host, 'toolbar.row.edit')) : nothing}
        ${item.isChanged && item.historyUrl ? actionButton(host, item, 'diff', 'wew-diff', diffTitle(host, item)) : nothing}
        ${item.isChanged ? actionButton(host, item, 'discard', 'wew-discard', label(host, 'discard.button.title'), { danger: true, disabled: !revert, hover: hoverDiscard }) : nothing}
        ${locatable ? actionButton(host, item, 'preview', 'wew-preview', label(host, 'toolbar.row.preview'), { hover: hoverLocate }) : nothing}
      </span>
    </li>
  `;
}
