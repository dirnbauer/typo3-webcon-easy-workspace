import { html, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { key } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { isLocatable, isEditable } from '@webconsulting/webcon-easy-workspace/menu-preview-locate.js';
import { viaLayout, callLayout } from '@webconsulting/webcon-easy-workspace/menu-layout.js';
import {
  changeType,
  diffTitle,
  canRevert,
  companionGroups,
  metaParts,
  rowLanguage,
  isInView,
  primaryViewLanguage,
  chipKey,
} from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

export { metaParts };

/** Core badge variant per change type — the same the backend module uses. */
const BADGE_CLASS = { new: 'badge-success', changed: 'badge-info', deleted: 'badge-danger', moved: 'badge-warning' };

export function changeBadgeClass(type) {
  return BADGE_CLASS[type] || 'badge-info';
}

/** DOM id prefix of a row; the meta and companion lines describe its checkbox. */
export function rowId(item) {
  return `wew-row-${String(item.table).replace(/[^a-zA-Z0-9_-]/g, '_')}-${item.workspaceUid}`;
}

/**
 * A row action: a Core borderless button, icon only, out of the tab order
 * until its row is the active one (menu-keyboard.js).
 */
export function actionButton(host, item, action, icon, title, { danger = false, disabled = false, hover = null } = {}) {
  return html`
    <button type="button"
            class="btn btn-borderless btn-sm wew-row__action wew-row__action--${action} ${danger ? 'wew-row__action--danger' : ''}"
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
 * Related changes (a changed element's collection items and file
 * references), when the editor's user setting asks for them: one line naming
 * the records, a record that is new, deleted or moved marked as such, and
 * the full list with every change type on hover.
 */
export function childSummary(host, children) {
  const name = (child) => child.title || child.tableLabel || child.table;
  const kind = (child) => label(host, `change.${changeType(child)}`);
  return {
    count: label(host, 'toolbar.row.children', { count: children.length }),
    list: children.map((child) => (changeType(child) === 'changed' ? name(child) : `${name(child)} (${kind(child)})`)).join(' · '),
    full: children.map((child) => `${name(child)}: ${kind(child)}`).join('\n'),
  };
}

/**
 * The language tag is shown where a row's language is not obvious: in a
 * view of several or no particular language, every row that is not in the
 * language the view is about. Rows of other languages sit under a header
 * that names their language already.
 */
export function showsLanguage(host, item) {
  const language = rowLanguage(host, item);
  return language !== null && isInView(host, item) && language.uid !== primaryViewLanguage(host);
}

/**
 * Everything a row renderer needs, computed once per row. Layout profiles
 * receive it as `ctx.row` and can hand it to the part renderers below.
 */
export function rowContext(host, item) {
  const itemKey = key(host, item);
  const inView = isInView(host, item);
  const revert = canRevert(host, item);
  const locatable = inView && isLocatable(host, item);
  const showSubelements = configBool(host, 'showSubelementsInToolbar', false);
  return {
    key: itemKey,
    id: rowId(item),
    type: changeType(item),
    selected: host.selection?.has?.(itemKey) ?? false,
    inView,
    language: rowLanguage(host, item),
    revert,
    locatable,
    editable: isEditable(item),
    meta: metaParts(host, item),
    // Related records TYPO3 only versioned along are not changes to name.
    children: showSubelements ? (item.childChanges || []).filter((child) => child.contentChanged !== false) : [],
    companions: companionGroups(host, item),
    hoverLocate: locatable ? (on) => (on ? host._highlightInIframe?.(item) : host._clearIframeHighlight?.()) : null,
    hoverDiscard: revert ? (on) => (on ? host._previewDiscard?.(item) : host._clearIframeHighlight?.()) : null,
  };
}

// ---- Parts ---------------------------------------------------------------

/** Column 1: the checkbox, the row's keyboard stop. */
export function renderRowCheck(host, item, row) {
  const describedBy = [`${row.id}-meta`, row.companions.length > 0 ? `${row.id}-companions` : ''].filter(Boolean).join(' ');
  return html`
    <span class="wew-row__select">
      <span class="form-check wew-row__check-wrap">
        <input type="checkbox"
               class="form-check-input wew-row__check"
               tabindex="-1"
               .checked=${row.selected}
               aria-label=${label(host, 'toolbar.row.select', { title: rowTitle(host, item) })}
               aria-describedby=${describedBy}
               data-wew-row-check
               data-wew-stop
               @change=${(event) => host.handleRowCheck(event)} />
      </span>
    </span>
  `;
}

/** Column 2: the record's type icon (Core's hidden overlay when hidden) or its thumbnail. */
export function renderRowIcon(host, item) {
  if (item.thumbnailUrl) {
    return html`<span class="wew-row__thumb"><img src=${item.thumbnailUrl} alt="" loading="lazy" /></span>`;
  }
  return html`
    <span class="wew-row__icon" aria-hidden="true">
      <typo3-backend-icon identifier=${item.iconIdentifier || 'mimetypes-other-other'} size="small"
                          overlay=${item.isHidden ? 'overlay-hidden' : nothing}></typo3-backend-icon>
    </span>
  `;
}

export function rowTitle(host, item) {
  return item.title || label(host, 'diff.noTitle');
}

/** Line 1: the title alone, tagged with the record's language. */
export function renderRowTitle(host, item, row) {
  const title = rowTitle(host, item);
  return html`<span class="wew-row__title" id=${`${row.id}-title`} lang=${row.language?.hreflang || nothing} title=${title}>${title}</span>`;
}

/** An empty action place, so the icons of all rows line up in columns. */
const actionSlot = html`<span class="wew-row__action-slot" aria-hidden="true"></span>`;

/** Edit, compare, locate — and discard last, set apart. */
export function renderRowActions(host, item, row) {
  return html`
    <span class="wew-row__actions">
      ${row.editable ? actionButton(host, item, 'edit', 'actions-open', label(host, 'toolbar.row.edit')) : actionSlot}
      ${item.historyUrl ? actionButton(host, item, 'diff', 'wew-diff', diffTitle(host, item)) : actionSlot}
      ${row.locatable ? actionButton(host, item, 'preview', 'wew-preview', label(host, 'toolbar.row.preview'), { hover: row.hoverLocate }) : actionSlot}
      ${actionButton(host, item, 'discard', 'wew-discard', label(host, row.revert ? 'discard.button.title' : 'discard.button.disabledTitle'), { danger: true, disabled: !row.revert, hover: row.hoverDiscard })}
    </span>
  `;
}

/** The change badge Core's module uses for this change type. */
export function renderChangeBadge(host, item, row) {
  return html`<span class="badge ${changeBadgeClass(row.type)} wew-row__badge" data-wew-change-badge=${row.type}>${label(host, `change.${row.type}`)}</span>`;
}

export function renderHiddenBadge(host, item) {
  if (!item.isHidden || !configBool(host, 'enableHiddenBadge', true)) return nothing;
  return html`<span class="badge badge-secondary wew-row__badge wew-row__badge--hidden" title=${label(host, 'toolbar.hidden.title')} data-wew-hidden-badge>${label(host, 'toolbar.hidden')}</span>`;
}

/** Flag and code ("DE") where the row's language is not obvious. */
export function renderLanguageTag(host, item, row) {
  if (!showsLanguage(host, item)) return nothing;
  const name = label(host, 'toolbar.row.language', { language: row.language.title });
  return html`
    <span class="wew-row__language" title=${name} data-wew-row-language=${row.language.uid}>
      ${row.language.flag ? html`<typo3-backend-icon identifier=${row.language.flag} size="small" aria-hidden="true"></typo3-backend-icon>` : nothing}
      <span aria-hidden="true">${row.language.code}</span>
      <span class="visually-hidden">${name}</span>
    </span>
  `;
}

/**
 * Meta text: type · column · part-of · time · author, on one line of whole
 * parts. Each part carries its own leading "·", so a part that does not fit
 * leaves with its separator (it wraps onto a clipped second line) instead
 * of being cut mid-word ("von Ann…"); the full text stays the title.
 */
export function renderMetaText(host, item, row) {
  if (row.meta.length === 0) return nothing;
  return html`<span class="wew-row__meta-text" title=${row.meta.join(' · ')}>${row.meta.map((part, index) => html`<span class="wew-row__part">${index > 0 ? html`<span class="wew-row__sep" aria-hidden="true"> · </span>` : nothing}${part}</span>`)}</span>`;
}

/** Line 2: badges first (one scannable column), then the meta text. */
export function renderRowMeta(host, item, row) {
  return html`
    <span class="wew-row__meta" id=${`${row.id}-meta`}>
      ${renderChangeBadge(host, item, row)}${renderHiddenBadge(host, item)}${renderLanguageTag(host, item, row)}${renderMetaText(host, item, row)}
    </span>
  `;
}

/** Line 3 (user setting showSubelementsInToolbar): the related changes. */
export function renderRowChildren(host, item, row) {
  if (row.children.length === 0) return nothing;
  const summary = childSummary(host, row.children);
  return html`
    <span class="wew-row__children" title=${summary.full} data-wew-children>
      <span class="wew-row__children-count">${summary.count}:</span>
      <span class="wew-row__children-list">${summary.list}</span>
    </span>
  `;
}

/**
 * One chip per translation. Copies (identical or with a shared field taken
 * over) open that translation in the edit modal; translations moved or
 * deleted along are plain codes, there is nothing to edit.
 */
export function renderCompanionChip(host, item, chip, group) {
  const { companion, language } = chip;
  const flag = language.flag ? html`<typo3-backend-icon identifier=${language.flag} size="small" aria-hidden="true"></typo3-backend-icon>` : nothing;
  if (!group.editable) {
    return html`<span class="wew-companion wew-companion--static" title=${language.title} data-wew-companion-language=${language.uid}>${flag}<span class="wew-companion__code">${language.code}</span></span>`;
  }
  const name = label(host, 'toolbar.companions.edit', { code: language.code, language: language.title });
  return html`
    <button type="button"
            class="btn btn-borderless btn-sm wew-companion"
            tabindex="-1"
            aria-label=${name}
            title=${name}
            data-wew-companion=${`${companion.table}:${companion.workspaceUid}`}
            data-wew-companion-language=${language.uid}
            @click=${(event) => { event.stopPropagation(); host.handleCompanionEdit?.(item, companion); }}>
      ${flag}<span class="wew-companion__code">${language.code}</span>
    </button>
  `;
}

/**
 * The last line: the translations TYPO3 carried along with this edit, one
 * group per kind ("Übersetzungen unverändert: DE ZH HU"). The label is the
 * explanation; its long form is the label's title and screen-reader text.
 */
export function renderRowCompanions(host, item, row) {
  if (row.companions.length === 0) return nothing;
  const help = label(host, 'toolbar.companions.help');
  return viaLayout(host, 'renderCompanions', [item, row.companions], { row }, () => html`
    <span class="wew-row__companions" id=${`${row.id}-companions`} data-wew-companions>
      ${row.companions.map((group) => html`
        <span class="wew-row__companion-group" data-wew-companion-kind=${group.kind}>
          <span class="wew-row__companions-label" title=${help}>${label(host, group.labelKey, { fields: group.fields })}</span>
          <span class="wew-row__chips">${repeat(group.chips, chipKey, (chip) => renderCompanionChip(host, item, chip, group))}</span>
        </span>`)}
      <span class="visually-hidden">${help}</span>
    </span>
  `);
}

/** The part renderers, handed to layout profiles as `ctx.parts`. */
export const rowParts = Object.freeze({
  check: renderRowCheck,
  icon: renderRowIcon,
  title: renderRowTitle,
  actions: renderRowActions,
  meta: renderRowMeta,
  changeBadge: renderChangeBadge,
  hiddenBadge: renderHiddenBadge,
  languageTag: renderLanguageTag,
  metaText: renderMetaText,
  children: renderRowChildren,
  companions: renderRowCompanions,
});

export function rowClasses(host, item, row) {
  return [
    'wew-row',
    `wew-row--${row.type}`,
    row.selected ? 'wew-row--selected' : '',
    item.isHidden ? 'wew-row--hidden' : '',
    item.isPrimary ? 'wew-row--primary' : '',
    row.companions.length > 0 ? 'wew-row--has-companions' : '',
    ...(callLayout(host, 'rowClasses', [item, row], []) || []),
  ].filter(Boolean).join(' ');
}

/**
 * The `<li>` every change row lives in: its classes, the data attributes
 * the keyboard, the tests and the Visual Editor bridge rely on, and the
 * row-wide click that toggles the selection. Layout profiles that rebuild a
 * row put their own parts inside it.
 */
export function rowShell(host, item, row, content) {
  return html`
    <li class=${rowClasses(host, item, row)}
        title=${!row.inView && row.language ? label(host, 'toolbar.row.notInView', { language: row.language.title }) : nothing}
        data-wew-row
        data-wew-key=${row.key}
        data-table=${item.table}
        data-change=${row.type}
        data-wew-in-view=${row.inView ? '1' : '0'}
        data-wew-language=${row.language ? row.language.uid : nothing}
        @click=${(event) => host.handleRowClick(event, item)}>${content}</li>
  `;
}

/** The default anatomy (grid areas in toolbar-menu.css). */
export function defaultRowContent(host, item, row) {
  return html`${renderRowCheck(host, item, row)}${renderRowIcon(host, item, row)}${renderRowTitle(host, item, row)}${renderRowActions(host, item, row)}${renderRowMeta(host, item, row)}${renderRowChildren(host, item, row)}${renderRowCompanions(host, item, row)}`;
}

/**
 * A change row. Layout hook `renderRow(host, item, ctx)` with
 * `ctx = { row, parts, shell, render }`.
 */
export function renderRow(host, item) {
  const row = rowContext(host, item);
  return viaLayout(host, 'renderRow', [item], { row, parts: rowParts, shell: (content) => rowShell(host, item, row, content) },
    () => rowShell(host, item, row, defaultRowContent(host, item, row)));
}

