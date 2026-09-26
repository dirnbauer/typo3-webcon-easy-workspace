import { html, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { label } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { key } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { hasContentChange, primaryViewLanguage, viewLanguages } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';

/** Sections a group can fold away; collapsed until the editor opens them. */
const UNCHANGED = 'unchanged';
const OTHER_LANGUAGES = 'other';

export function sectionKey(group, section) {
  return `${group.key}:${section}`;
}

function isOpen(host, sectionId) {
  return Boolean(host.openSections?.has?.(sectionId));
}

/**
 * The rows of a group in the order they render, as far as their sections
 * are open: the edited rows of the current view, then — once opened — the
 * rows of this view TYPO3 only versioned along, and the rows of the other
 * languages.
 */
export function visibleRows(host, group) {
  const rows = group.rows.filter(hasContentChange);
  if (isOpen(host, sectionKey(group, UNCHANGED))) {
    rows.push(...group.rows.filter((row) => !hasContentChange(row)));
  }
  if (isOpen(host, sectionKey(group, OTHER_LANGUAGES))) {
    for (const entry of group.otherLanguages || []) rows.push(...entry.rows);
  }
  return rows;
}

/** Number of rows a group renders (for the roving tabindex offset). */
export function groupRowCount(host, group) {
  return visibleRows(host, group).length;
}

/**
 * A header row that folds a section in or out. The button carries the
 * state (aria-expanded); the full explanation is its title and
 * screen-reader text, one short line on screen.
 */
function renderToggle(host, { sectionId, section, title, count, hint, shortHint }) {
  const open = isOpen(host, sectionId);
  return html`
    <li class="wew-section wew-section--toggle wew-section--${section}" role="presentation" data-wew-section=${section}>
      <button type="button"
              class="wew-section__toggle"
              aria-expanded=${open ? 'true' : 'false'}
              title=${hint}
              data-wew-section-toggle=${sectionId}
              @click=${() => host.toggleSection(sectionId)}>
        <typo3-backend-icon identifier=${open ? 'actions-chevron-down' : 'actions-chevron-right'} size="small"></typo3-backend-icon>
        <span class="wew-section__title">${title}</span>
        <span class="wew-section__count">${count}</span>
        ${shortHint ? html`<span class="wew-section__hint">${shortHint}</span>` : nothing}
        <span class="visually-hidden">${hint}</span>
      </button>
    </li>
  `;
}

/**
 * A group header (page or news record: icon, title, rootline path, the
 * number of edited rows) followed by its rows, the most important first:
 *
 * 1. the rows of the current view whose content someone edited;
 * 2. folded: rows of this view TYPO3 only versioned along (their content
 *    matches live — a collection item, a translation of an edited element);
 * 3. folded: the other languages, one header per language, edited rows
 *    first. The page shows them only after a language switch.
 *
 * Folded rows still count, publish and discard with the rest; the section
 * header says how many there are and how many of them changed.
 */
export function renderGroup(host, group, rowOffset = 0) {
  const other = group.otherLanguages || [];
  const view = viewLanguages(host);
  const viewLanguage = view !== null && view.length === 1 ? (host.languages?.[view[0]] || null) : null;
  const edited = group.rows.filter(hasContentChange);
  const unchanged = group.rows.filter((row) => !hasContentChange(row));
  const otherRows = other.flatMap((entry) => entry.rows);
  const allRows = [...group.rows, ...otherRows];
  const editedTotal = allRows.filter(hasContentChange).length;
  // In the default-language view the other languages are its translations.
  const otherTitle = viewLanguage !== null && primaryViewLanguage(host) === 0
    ? label(host, 'toolbar.languages.translations')
    : label(host, 'toolbar.languages.other');
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
      <span class="wew-group__count" title=${label(host, 'toolbar.languages.changedCount', { changed: editedTotal, total: allRows.length })}>${editedTotal}</span>
    </li>
    ${other.length > 0 && edited.length > 0 ? html`
      <li class="wew-section" role="presentation" data-wew-section="in-view">
        ${viewLanguage?.flag ? html`<typo3-backend-icon identifier=${viewLanguage.flag} size="small"></typo3-backend-icon>` : nothing}
        <span class="wew-section__title">
          ${viewLanguage ? label(host, 'toolbar.languages.inView', { language: viewLanguage.title }) : label(host, 'toolbar.languages.inViewMany')}
        </span>
        <span class="wew-section__count">${edited.length}</span>
      </li>` : nothing}
    ${rowsOf(edited)}
    ${unchanged.length > 0 ? html`
      ${renderToggle(host, {
        sectionId: sectionKey(group, UNCHANGED),
        section: 'unchanged',
        title: label(host, 'toolbar.section.unchanged'),
        count: String(unchanged.length),
        hint: label(host, 'toolbar.row.unchangedTitle'),
        shortHint: label(host, 'toolbar.section.unchangedHint'),
      })}
      ${isOpen(host, sectionKey(group, UNCHANGED)) ? rowsOf(unchanged) : nothing}` : nothing}
    ${other.length > 0 ? html`
      ${renderToggle(host, {
        sectionId: sectionKey(group, OTHER_LANGUAGES),
        section: 'other-languages',
        title: otherTitle,
        count: label(host, 'toolbar.languages.changedCount', {
          changed: otherRows.filter(hasContentChange).length,
          total: otherRows.length,
        }),
        hint,
        shortHint,
      })}
      ${isOpen(host, sectionKey(group, OTHER_LANGUAGES)) ? other.map((entry) => html`
        <li class="wew-language" role="presentation" data-wew-language=${entry.language.uid}>
          <typo3-backend-icon identifier=${entry.language.flag || 'flags-multiple'} size="small"></typo3-backend-icon>
          <span class="wew-language__title">${entry.language.title}</span>
          <span class="wew-language__count">${entry.rows.length}</span>
        </li>
        ${rowsOf(entry.rows)}
      `) : nothing}` : nothing}
  `;
}
