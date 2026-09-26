import { html, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { label } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { key } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { viewLanguages } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';

/**
 * Number of rows a group renders, other languages included.
 */
export function groupRowCount(group) {
  return group.rows.length + (group.otherLanguages || []).reduce((sum, entry) => sum + entry.rows.length, 0);
}

/**
 * A group header (page or news record: icon, title, rootline path,
 * count) followed by its rows: the rows of the current view, then — set
 * apart under their language — the rows the page shows only after a
 * language switch. Rendered inside the `role="list"` <ul>, so the headers
 * are presentational <li>s. Rows are keyed so a row that animates out is
 * never recycled for another record.
 */
export function renderGroup(host, group, rowOffset = 0) {
  const other = group.otherLanguages || [];
  const view = viewLanguages(host);
  const viewLanguage = view !== null && view.length === 1 ? (host.languages?.[view[0]] || null) : null;
  const otherCount = other.reduce((sum, entry) => sum + entry.rows.length, 0);
  // One short line in the list; the full explanation on hover and for
  // screen readers.
  const hint = viewLanguage
    ? label(host, 'toolbar.languages.otherHint', { language: viewLanguage.title })
    : label(host, 'toolbar.languages.otherHintMany');
  const shortHint = viewLanguage
    ? label(host, 'toolbar.languages.otherHintShort', { language: viewLanguage.title })
    : label(host, 'toolbar.languages.otherHintShortMany');
  let offset = rowOffset;
  const rowsOf = (rows) => {
    const start = offset;
    offset += rows.length;
    return repeat(rows, (item) => key(host, item), (item, index) => renderRow(host, item, start + index));
  };

  return html`
    <li class="wew-group" role="presentation" data-wew-group=${group.key}>
      <span class="wew-group__icon" aria-hidden="true">
        <typo3-backend-icon identifier=${group.icon} size="small"></typo3-backend-icon>
      </span>
      <span class="wew-group__text">
        <span class="wew-group__title" title=${group.title}>${group.title}</span>
        ${group.path ? html`<span class="wew-group__path" title=${group.path}>${group.path}</span>` : nothing}
      </span>
      <span class="wew-group__count">${groupRowCount(group)}</span>
    </li>
    ${other.length > 0 && group.rows.length > 0 ? html`
      <li class="wew-section" role="presentation" data-wew-section="in-view">
        ${viewLanguage?.flag ? html`<typo3-backend-icon identifier=${viewLanguage.flag} size="small"></typo3-backend-icon>` : nothing}
        <span class="wew-section__title">
          ${viewLanguage ? label(host, 'toolbar.languages.inView', { language: viewLanguage.title }) : label(host, 'toolbar.languages.inViewMany')}
        </span>
        <span class="wew-section__count">${group.rows.length}</span>
      </li>` : nothing}
    ${rowsOf(group.rows)}
    ${other.length > 0 ? html`
      <li class="wew-section wew-section--other"
          role="presentation"
          title=${hint}
          data-wew-section="other-languages">
        <typo3-backend-icon identifier="actions-info-circle" size="small"></typo3-backend-icon>
        <span class="wew-section__title">${label(host, 'toolbar.languages.other')}</span>
        <span class="wew-section__count">${label(host, 'toolbar.languages.count', { count: otherCount })}</span>
        <span class="wew-section__hint">${shortHint}</span>
        <span class="visually-hidden">${hint}</span>
      </li>
      ${other.map((entry) => html`
        <li class="wew-language" role="presentation" data-wew-language=${entry.language.uid}>
          <typo3-backend-icon identifier=${entry.language.flag || 'flags-multiple'} size="small"></typo3-backend-icon>
          <span class="wew-language__title">${entry.language.title}</span>
          <span class="wew-language__count">${entry.rows.length}</span>
        </li>
        ${rowsOf(entry.rows)}
      `)}` : nothing}
  `;
}
