import { html, nothing } from 'lit';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { key } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { isLocatable, isEditable } from '@webconsulting/webcon-easy-workspace/menu-preview-locate.js';
import {
  changeType,
  relativeTime,
  diffTitle,
  canRevert,
  rowLanguage,
  isInView,
  primaryViewLanguage,
} from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

/** Core badge variant per change type — the same the backend module uses. */
const BADGE_CLASS = { new: 'badge-success', changed: 'badge-info', deleted: 'badge-danger', moved: 'badge-warning' };

export function changeBadgeClass(type) {
  return BADGE_CLASS[type] || 'badge-info';
}

function actionButton(host, item, action, icon, title, { danger = false, disabled = false, hover = null } = {}) {
  return html`
    <button type="button"
            class="btn btn-borderless btn-sm wew-row__action ${danger ? 'wew-row__action--danger' : ''}"
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

/**
 * The line below the title: what the record is, where it sits (its
 * column, or the element it is part of), who changed it last and when.
 */
export function metaParts(host, item) {
  const parts = [];
  if (configBool(host, 'enableTypeLabels', true)) {
    parts.push(item.typeLabel && item.typeLabel !== item.tableLabel ? item.typeLabel : item.tableLabel);
  }
  if (item.colPosLabel) parts.push(item.colPosLabel);
  if (item.parent?.title) parts.push(label(host, 'toolbar.row.partOf', { title: item.parent.title }));
  if (item.latestChangeUser) parts.push(item.latestChangeUser);
  const when = relativeTime(host, item.latestChangeAt || item.tstamp);
  if (when) parts.push(when);
  return parts.filter(Boolean);
}

/**
 * The language chip is shown where a row's language is not obvious: in a
 * view of several or no particular language, every row that is not in the
 * language the view is about. Rows of other languages sit under a header
 * that names their language already.
 */
export function showsLanguage(host, item) {
  const language = rowLanguage(host, item);
  return language !== null && isInView(host, item) && language.uid !== primaryViewLanguage(host);
}

export function renderRow(host, item, index) {
  const itemKey = key(host, item);
  const type = changeType(item);
  const selected = host.selection.has(itemKey);
  const revert = canRevert(host, item);
  const inView = isInView(host, item);
  const language = rowLanguage(host, item);
  // A row the page does not show in the current language cannot be located in the preview.
  const locatable = inView && isLocatable(host, item);
  const editable = isEditable(item);
  const showSubelements = configBool(host, 'showSubelementsInToolbar', false);
  const classes = [
    'wew-row',
    item.isChanged ? 'wew-row--changed' : 'wew-row--unchanged',
    selected ? 'wew-row--selected' : '',
    item.isHidden ? 'wew-row--hidden' : '',
    item.isPrimary ? 'wew-row--primary' : '',
    inView ? '' : 'wew-row--other-language',
  ].filter(Boolean).join(' ');
  const meta = metaParts(host, item);
  const hoverLocate = locatable ? (on) => (on ? host._highlightInIframe(item) : host._clearIframeHighlight()) : null;
  const hoverDiscard = revert ? (on) => (on ? host._previewDiscard(item) : host._clearIframeHighlight()) : null;
  const children = showSubelements ? (item.childChanges || []) : [];

  return html`
    <li class=${classes}
        role="listitem"
        tabindex=${index === 0 ? '0' : '-1'}
        title=${!inView && language ? label(host, 'toolbar.row.notInView', { language: language.title }) : nothing}
        data-wew-row
        data-wew-key=${itemKey}
        data-table=${item.table}
        data-change=${item.isChanged ? type : nothing}
        data-wew-in-view=${inView ? '1' : '0'}
        data-wew-language=${language ? language.uid : nothing}
        @click=${(event) => host.handleRowClick(event, item)}>
      <span class="wew-row__select">
        ${item.isChanged ? html`
          <span class="form-check wew-row__check-wrap">
            <input type="checkbox"
                   class="form-check-input wew-row__check"
                   tabindex="-1"
                   .checked=${selected}
                   aria-label=${label(host, 'toolbar.row.select', { title: item.title })}
                   data-wew-row-check
                   @change=${(event) => host.handleRowCheck(event)} />
          </span>` : nothing}
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
            <span class="badge ${changeBadgeClass(type)} wew-row__badge" data-wew-change-badge>${label(host, `change.${type}`)}</span>` : nothing}
          ${item.isHidden && configBool(host, 'enableHiddenBadge', true) ? html`
            <span class="badge badge-secondary wew-row__badge" title=${label(host, 'toolbar.hidden.title')}>${label(host, 'toolbar.hidden')}</span>` : nothing}
          ${showsLanguage(host, item) ? html`
            <span class="wew-row__language" title=${label(host, 'toolbar.row.language', { language: language.title })} data-wew-row-language>
              ${language.flag ? html`<typo3-backend-icon identifier=${language.flag} size="small"></typo3-backend-icon>` : nothing}
              <span>${language.title}</span>
            </span>` : nothing}
        </span>
        ${meta.length > 0 ? html`
          <span class="wew-row__meta">
            ${meta.map((part, partIndex) => html`${partIndex > 0 ? html`<span class="wew-row__meta-sep" aria-hidden="true">·</span>` : nothing}<span>${part}</span>`)}
          </span>` : nothing}
        ${children.length > 0 ? html`
          <span class="wew-row__children" data-wew-children>
            <span class="wew-row__children-title">${label(host, 'toolbar.row.children', { count: children.length })}</span>
            ${children.map((child) => html`
              <span class="wew-row__child">
                <span class="wew-row__child-title" title=${child.title || ''}>${child.title || child.tableLabel || child.table}</span>
                <span class="badge ${changeBadgeClass(changeType(child))} wew-row__badge wew-row__badge--child">${child.kindLabel || label(host, `change.${changeType(child)}`)}</span>
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
