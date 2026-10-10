import { html, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { key, selectAll, toggleMany } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import {
  canRevert,
  chipKey,
  diffTitle,
  footerState,
  languageInfo,
  memberKey,
  relativeTime,
} from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { actionButton, renderRow as renderSharedRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { layoutFlag } from '@webconsulting/webcon-easy-workspace/menu-layout.js';

/**
 * "Effizient": dense, crisp and fast — a refined mail-client list.
 *
 * What it changes against the shared presentation (data, counts, selection,
 * publish, discard and cleanup stay the shared ones):
 *
 * - Row: the title has line 1 to itself — nothing reserves room beside
 *   it, so it is not cut short. Line 2 starts with the change badge (one
 *   scannable column), then type · column · time · author. With a fine
 *   pointer the actions float over the end of line 1 while the row is
 *   hovered or holds keyboard focus, as in a mail list; they stay in the
 *   tab and arrow order the whole time. On touch they stay visible.
 * - Locate sits on the type icon: hovering it swaps the icon for an eye
 *   and highlights the element in the preview, clicking scrolls to it.
 *   That leaves three actions: edit, compare and — set apart — discard.
 * - Rows that will not be published mute their title and icon; selected
 *   rows look normal, so the exception is what stands out.
 * - Translation chips carry a pencil: "DE ✎ ZH ✎ HU ✎" edits them.
 * - Folded sections say how many of their rows are selected; the cleanup
 *   section is folded and discards in one click, without a modal (the
 *   shared default: the copies equal live, nothing can be lost).
 * - Shortcuts: Delete/Backspace on a row opens the discard confirmation
 *   (never discards without it), Ctrl/Cmd+Enter presses publish, "a"
 *   selects or clears all. The footer names them in <kbd> hints.
 */

const PRESS_MS = 160;

// ---- Keys ------------------------------------------------------------------

/** Whether the editor is on a Mac (⌘ instead of Strg/Ctrl in the hints). */
export function isApplePlatform(nav = globalThis.navigator) {
  const platform = String(nav?.userAgentData?.platform || nav?.platform || '');
  return /mac|iphone|ipad|ipod/i.test(platform);
}

/**
 * The key names the hints and titles show, per platform: text and the
 * full name for the tooltip.
 */
export function shortcutKeys(host, apple = isApplePlatform()) {
  const modifier = apple ? 'cmd' : 'ctrl';
  return {
    mod: { text: label(host, `toolbar.kbd.${modifier}`), title: label(host, `toolbar.kbd.${modifier}Title`) },
    enter: { text: label(host, 'toolbar.kbd.enter'), title: label(host, 'toolbar.kbd.enterTitle') },
    del: { text: label(host, 'toolbar.kbd.delete'), title: label(host, 'toolbar.kbd.deleteTitle') },
    arrows: { text: label(host, 'toolbar.kbd.arrows'), title: label(host, 'toolbar.kbd.arrowsTitle') },
    all: { text: 'A', title: 'A' },
  };
}

/** "Strg+Enter" / "⌘+Enter", for the publish tooltip. */
export function publishShortcutText(host, apple = isApplePlatform()) {
  const keys = shortcutKeys(host, apple);
  return `${keys.mod.text}+${keys.enter.text}`;
}

/**
 * The publish button's shortcut, for its tooltip ("… veröffentlichen
 * (Strg+Enter)") and aria-keyshortcuts. templates/footer.js reads it
 * through callLayout('publishShortcut').
 */
export function publishShortcut(host) {
  return { text: publishShortcutText(host), keys: 'Control+Enter Meta+Enter' };
}

/** The word next to the "A" hint ("alle"). */
export function selectAllHint(host) {
  return label(host, 'toolbar.shortcut.selectAll');
}

/**
 * The screen-reader keyboard help of the footer: it names the Delete key
 * only while discarding is switched on (enableRevert), as the visible hints
 * and onKeydown do.
 */
export function keyboardHint(host) {
  return configBool(host, 'enableRevert')
    ? 'toolbar.keyboardHint.efficiency'
    : 'toolbar.keyboardHint.efficiencyNoDiscard';
}

function kbd(entry) {
  return html`<kbd class="wew-kbd" title=${entry.title}>${entry.text}</kbd>`;
}

function isTextEntry(element) {
  if (!element || typeof element.closest !== 'function') return false;
  if (element.isContentEditable) return true;
  if (element.matches('textarea, select')) return true;
  return element.matches('input') && !['checkbox', 'radio', 'button', 'submit', 'reset'].includes(String(element.type || '').toLowerCase());
}

/**
 * Ctrl/Cmd+Enter: press the publish button as if clicked (with a short
 * pressed look). With nothing selected the status region says why nothing
 * happened; while publishing the key is swallowed.
 */
export function pressPublish(host) {
  if (host.publishing) return true;
  const button = host.querySelector?.('[data-wew-publish]');
  if (!button) return false;
  if (button.disabled) {
    host.setStatus?.(label(host, 'toolbar.publish.noneSelected'));
    return true;
  }
  button.setAttribute('data-wew-pressed', '');
  globalThis.setTimeout?.(() => button.removeAttribute('data-wew-pressed'), PRESS_MS);
  button.click();
  return true;
}

/** "a": select every edit, or clear the selection when all are selected. */
export function toggleSelectAll(host) {
  const { total, allChecked } = footerState(host);
  if (total <= 0) return false;
  selectAll(host, !allChecked);
  host.setStatus?.(label(host, 'toolbar.selection.count', { selected: host.selection?.size ?? 0, total }));
  return true;
}

/**
 * The layout's shortcuts, before list navigation and Escape. Plain keys
 * never fire while the editor types into a field.
 */
export function onKeydown(host, event, ctx = {}) {
  if (event.defaultPrevented || event.isComposing || isTextEntry(event.target)) return false;
  if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey) {
    return pressPublish(host);
  }
  if (event.ctrlKey || event.metaKey || event.altKey) return false;
  if ((event.key === 'Delete' || event.key === 'Backspace') && ctx.item && ctx.row) {
    // Held down, the key would open the confirmation over and over.
    if (event.repeat) return true;
    if (!canRevert(host, ctx.item)) return false;
    host.handleRowAction(event, 'discard', ctx.item);
    return true;
  }
  if ((event.key === 'a' || event.key === 'A') && !event.target?.closest?.('[role="menu"]')) {
    // Held down, it would flip the selection back and forth.
    if (event.repeat) return footerState(host).total > 0;
    return toggleSelectAll(host);
  }
  return false;
}

// ---- Row -------------------------------------------------------------------

function changedAt(item) {
  return Number(item.latestChangeAt || item.tstamp) || 0;
}

// One formatter per backend language: building one costs about as much as
// formatting a few dozen dates, and every row of every render asks.
const exactTimeFormatters = new Map();

export function exactTimeFormatter() {
  const locale = globalThis.document?.documentElement?.lang || '';
  if (!exactTimeFormatters.has(locale)) {
    let formatter = null;
    try {
      formatter = new Intl.DateTimeFormat(locale || undefined, { dateStyle: 'medium', timeStyle: 'short' });
    } catch {
      // An invalid language tag: rows show no exact time.
    }
    exactTimeFormatters.set(locale, formatter);
  }
  return exactTimeFormatters.get(locale);
}

function absoluteTime(timestamp) {
  if (timestamp <= 0) return '';
  const formatter = exactTimeFormatter();
  if (!formatter) return '';
  try {
    return formatter.format(new Date(timestamp * 1000));
  } catch {
    return '';
  }
}

/**
 * The type icon, and the locate button on it: hover or focus swaps it for
 * an eye and highlights the element in the preview, a click scrolls to it.
 * A record that cannot be located keeps a plain icon.
 */
export function renderLocateIcon(host, item, row) {
  const visual = item.thumbnailUrl
    ? html`<img src=${item.thumbnailUrl} alt="" loading="lazy" />`
    : html`<typo3-backend-icon identifier=${item.iconIdentifier || 'mimetypes-other-other'} size="small"
                               overlay=${item.isHidden ? 'overlay-hidden' : nothing}></typo3-backend-icon>`;
  const kind = item.thumbnailUrl ? 'wew-row__thumb' : 'wew-row__icon';
  if (!row.locatable) {
    return html`<span class="${kind} wew-row__type-tile" aria-hidden="true">${visual}</span>`;
  }
  const name = label(host, 'toolbar.row.preview');
  const hover = row.hoverLocate || (() => {});
  return html`
    <button type="button"
            class="${kind} wew-row__type-tile wew-row__locate"
            tabindex="-1"
            title=${name}
            aria-label=${name}
            data-wew-action="preview"
            @click=${(event) => host.handleRowAction(event, 'preview', item)}
            @mouseenter=${() => hover(true)}
            @mouseleave=${() => hover(false)}
            @focus=${() => hover(true)}
            @blur=${() => hover(false)}>
      <span class="wew-row__type" aria-hidden="true">${visual}</span>
      <span class="wew-row__eye" aria-hidden="true"><typo3-backend-icon identifier="wew-preview" size="small"></typo3-backend-icon></span>
    </button>
  `;
}

const actionSlot = html`<span class="wew-row__action-slot" aria-hidden="true"></span>`;

/** Edit, compare — and discard, set apart, its tooltip naming the Delete key. */
export function renderActions(host, item, row) {
  const discardTitle = row.revert
    ? label(host, 'toolbar.shortcut.discardTitle', { shortcut: label(host, 'toolbar.kbd.delete') })
    : label(host, 'discard.button.disabledTitle');
  return html`
    <span class="wew-row__actions">
      ${row.editable ? actionButton(host, item, 'edit', 'actions-open', label(host, 'toolbar.row.edit')) : actionSlot}
      ${item.historyUrl ? actionButton(host, item, 'diff', 'wew-diff', diffTitle(host, item)) : actionSlot}
      ${actionButton(host, item, 'discard', 'wew-discard', discardTitle, { danger: true, disabled: !row.revert, hover: row.hoverDiscard })}
    </span>
  `;
}

/** A meta part's classes: later parts are units, time and column are named (a narrow dropdown drops the column first). */
function partClass(item, part, index, when) {
  return [
    'wew-row__part',
    index > 0 ? 'wew-row__part--unit' : '',
    index > 0 && part === when ? 'wew-row__part--time' : '',
    index > 0 && item.colPosLabel && part === item.colPosLabel ? 'wew-row__part--column' : '',
  ].filter(Boolean).join(' ');
}

/**
 * Line 2: badges first, then type · column · part-of · time · author (time
 * before author, so it survives when the line is short). Every part after
 * the first carries its separator as one unit: a part that does not fit
 * leaves the line whole, never as a dangling "·…". The time names the
 * exact date on hover.
 */
export function renderMeta(host, item, row, parts) {
  const timestamp = changedAt(item);
  const when = relativeTime(host, timestamp);
  const exact = when ? absoluteTime(timestamp) : '';
  return html`
    <span class="wew-row__meta" id=${`${row.id}-meta`}>
      ${parts.changeBadge(host, item, row)}${parts.hiddenBadge(host, item, row)}${parts.languageTag(host, item, row)}
      ${row.meta.length > 0 ? html`
        <span class="wew-row__meta-text" title=${row.meta.join(' · ')}>${row.meta.map((part, index) => html`<span class=${partClass(item, part, index, when)} title=${part === when && exact ? exact : nothing}>${index > 0 ? html`<span class="wew-row__sep" aria-hidden="true"> · </span>` : nothing}${part}</span>`)}</span>` : nothing}
    </span>
  `;
}

export function renderRow(host, item, ctx) {
  const { row, parts, shell } = ctx;
  return shell(html`
    ${parts.check(host, item, row)}
    ${renderLocateIcon(host, item, row)}
    ${parts.title(host, item, row)}
    ${renderActions(host, item, row)}
    ${renderMeta(host, item, row, parts)}
    ${parts.children(host, item, row)}
    ${parts.companions(host, item, row)}
  `);
}

export function rowClasses(host, item, row) {
  return [
    'wew-row--dense',
    row.locatable ? 'wew-row--locatable' : '',
    row.selected ? '' : 'wew-row--unselected',
  ].filter(Boolean);
}

// ---- Translation chips -----------------------------------------------------

function chipFace(language, editable) {
  return html`
    ${language.flag ? html`<typo3-backend-icon class="wew-companion__flag" identifier=${language.flag} size="small" aria-hidden="true"></typo3-backend-icon>` : nothing}
    <span class="wew-companion__code">${language.code}</span>
    ${editable ? html`<span class="wew-companion__pen" aria-hidden="true"><typo3-backend-icon identifier="actions-open" size="small"></typo3-backend-icon></span>` : nothing}
  `;
}

export function renderCompanionChip(host, item, chip, group) {
  const { companion, language } = chip;
  if (!group.editable) {
    return html`<span class="wew-companion wew-companion--static" title=${language.title} data-wew-companion-language=${language.uid}>${chipFace(language, false)}</span>`;
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
            @click=${(event) => { event.stopPropagation(); host.handleCompanionEdit?.(item, companion); }}>${chipFace(language, true)}</button>
  `;
}

/**
 * "Übersetzungen unverändert: DE ✎ ZH ✎ HU ✎" — the answer to "did my
 * translations change?" right on the edited row. The label's title and the
 * hidden text carry the long explanation.
 */
export function renderCompanions(host, item, groups, ctx) {
  const { row } = ctx;
  const help = label(host, 'toolbar.companions.help');
  return html`
    <span class="wew-row__companions" id=${`${row.id}-companions`} data-wew-companions>
      ${groups.map((group) => html`
        <span class="wew-row__companion-group" data-wew-companion-kind=${group.kind}>
          <span class="wew-row__companions-label" title=${help}>${label(host, group.labelKey, { fields: group.fields })}</span>
          <span class="wew-row__chips">${repeat(group.chips, chipKey, (chip) => renderCompanionChip(host, item, chip, group))}</span>
        </span>`)}
      <span class="visually-hidden">${help}</span>
    </span>
  `;
}

// ---- Sections --------------------------------------------------------------

function chevron() {
  return html`<span class="wew-section__chevron" aria-hidden="true"><typo3-backend-icon identifier="actions-chevron-right" size="small"></typo3-backend-icon></span>`;
}

function rowList(host, rows) {
  return html`<ul class="wew-rows" role="list">${repeat(rows, (item) => key(host, item), (item) => renderSharedRow(host, item))}</ul>`;
}

/**
 * Other languages: a tri-state checkbox and a toggle whose status says
 * where the rows are when open ("in English nicht sichtbar") and how many
 * of them go live when folded ("2 von 2 ausgewählt").
 */
export function renderSection(host, group, ctx) {
  const { section } = ctx;
  const total = section.rows.length;
  const all = total > 0 && section.selected === total;
  const some = section.selected > 0 && !all;
  const selection = label(host, 'toolbar.selection.count', { selected: section.selected, total });
  const count = label(host, 'toolbar.languages.count', { count: total });
  return html`
    <div class="wew-section wew-section--other ${section.open ? 'is-open' : ''}" data-wew-section="other-languages">
      <div class="wew-section__head" data-wew-section-head>
        <span class="form-check wew-section__check-wrap">
          <input type="checkbox"
                 class="form-check-input"
                 tabindex="-1"
                 .checked=${all}
                 .indeterminate=${some}
                 aria-label=${label(host, 'toolbar.section.select', { section: section.title })}
                 title=${selection}
                 data-wew-section-check
                 @change=${(event) => toggleMany(host, section.rows, event.currentTarget.checked)} />
        </span>
        <h4 class="wew-section__heading">
          <button type="button"
                  class="wew-section__toggle"
                  tabindex="-1"
                  aria-expanded=${section.open ? 'true' : 'false'}
                  aria-controls=${`${section.domId}-body`}
                  title=${section.hint}
                  data-wew-section-toggle=${section.sectionId}
                  data-wew-stop
                  @click=${() => host.toggleSection(section.sectionId)}>
            ${chevron()}
            <span class="wew-section__title">${section.title}</span>
            <span class="wew-section__count wew-section__count--pill" aria-hidden="true">${total}</span>
            <span class="wew-section__meta ${section.open ? '' : 'wew-section__meta--selection'}" aria-hidden="true">${section.open ? section.shortHint : selection}</span>
            <span class="visually-hidden">${count}. ${section.open ? '' : `${selection}. `}${section.hint}</span>
          </button>
        </h4>
      </div>
      ${section.open ? html`
        <div class="wew-section__body" id=${`${section.domId}-body`} data-wew-section-body>
          ${group.otherLanguages.map((entry) => html`
            <h5 class="wew-language" data-wew-language=${entry.language.uid}>
              <typo3-backend-icon identifier=${entry.language.flag || 'flags-multiple'} size="small" aria-hidden="true"></typo3-backend-icon>
              <span class="wew-language__title">${entry.language.title}</span>
              <span class="wew-language__count">${entry.rows.length}</span>
            </h5>
            ${rowList(host, entry.rows)}`)}
        </div>` : nothing}
    </div>
  `;
}

/**
 * Leftover copies: folded, "Inhalt wie live" right in the heading, one
 * quiet action that discards them all at once.
 */
export function renderCleanup(host, group, ctx) {
  const { section } = ctx;
  const copies = label(host, 'toolbar.summary.copies', { count: section.records });
  const sameAsLive = label(host, 'toolbar.cleanup.draftsShort');
  const discardName = label(host, 'toolbar.cleanup.discard');
  // "Verwerfen" next to the section title says enough; its name stays
  // "Kopien verwerfen" (the visible word is part of it).
  const discardText = label(host, 'toolbar.cleanup.confirm.button');
  return html`
    <div class="wew-section wew-section--cleanup ${section.open ? 'is-open' : ''}" data-wew-cleanup data-wew-section="copies">
      <div class="wew-section__head" data-wew-section-head>
        <span class="wew-section__lead" aria-hidden="true"></span>
        <h4 class="wew-section__heading">
          <button type="button"
                  class="wew-section__toggle"
                  tabindex="-1"
                  aria-expanded=${section.open ? 'true' : 'false'}
                  aria-controls=${`${section.domId}-body`}
                  title=${label(host, 'toolbar.cleanup.hint')}
                  data-wew-section-toggle=${section.sectionId}
                  data-wew-stop
                  @click=${() => host.toggleSection(section.sectionId)}>
            ${chevron()}
            <span class="wew-section__title">${label(host, 'toolbar.cleanup.title')}</span>
            <span class="wew-section__count wew-section__count--pill" aria-hidden="true">${section.records}</span>
            ${sameAsLive && !section.open ? html`<span class="wew-section__meta" aria-hidden="true">${sameAsLive}</span>` : nothing}
            <span class="visually-hidden">${copies}</span>
          </button>
        </h4>
        <button type="button"
                class="btn btn-borderless btn-sm wew-cleanup__discard"
                tabindex="-1"
                ?disabled=${!section.revert || section.busy}
                title=${section.revert ? `${discardName} (${copies})` : label(host, 'discard.button.disabledTitle')}
                aria-label=${section.busy ? nothing : discardName}
                aria-describedby=${`${section.domId}-hint`}
                aria-busy=${section.busy ? 'true' : 'false'}
                data-wew-discard-copies
                @click=${() => host.handleDiscardCopies?.(group)}>
          <span class="wew-cleanup__icon" aria-hidden="true"><typo3-backend-icon identifier="wew-discard" size="small"></typo3-backend-icon></span>
          <span>${section.busy ? label(host, 'toolbar.cleanup.discarding') : discardText}</span>
        </button>
      </div>
      <p id=${`${section.domId}-hint`} class="wew-section__help ${section.open ? '' : 'visually-hidden'}">${label(host, 'toolbar.cleanup.hint')}</p>
      ${section.open ? html`
        <ul class="wew-copies" id=${`${section.domId}-body`} role="list" data-wew-section-body>
          ${repeat(group.copies, (copy) => copy.key, (copy) => renderCopy(host, group, copy))}
        </ul>` : nothing}
    </div>
  `;
}

function copyChip(host, copy, member, index) {
  const language = languageInfo(host, member.languageUid)
    || { uid: member.languageUid, title: String(member.languageUid), flag: '', code: String(member.languageUid) };
  const name = label(host, String(layoutFlag(host, 'copyEditKey') || 'toolbar.cleanup.edit'), { code: language.code, language: language.title });
  return html`
    <button type="button"
            class="btn btn-borderless btn-sm wew-companion"
            tabindex="-1"
            aria-label=${name}
            title=${name}
            data-wew-companion=${`${member.table}:${member.workspaceUid}`}
            data-wew-companion-language=${language.uid}
            ?data-wew-stop=${index === 0}
            @click=${() => host.handleCompanionEdit?.({ title: copy.title }, member)}>${chipFace(language, true)}</button>
  `;
}

/** One line per element: icon, title, type, a chip per copy (edits it). */
export function renderCopy(host, group, copy) {
  const title = copy.title || label(host, 'diff.noTitle');
  return html`
    <li class="wew-copy" data-wew-copy-group=${copy.key}>
      <span class="wew-copy__icon" aria-hidden="true">
        <typo3-backend-icon identifier=${copy.iconIdentifier} size="small"></typo3-backend-icon>
      </span>
      <span class="wew-copy__text">
        <span class="wew-copy__title" title=${title}>${title}</span>
        ${copy.typeLabel ? html`<span class="wew-copy__type">${copy.typeLabel}</span>` : nothing}
      </span>
      <span class="wew-copy__chips">${repeat(copy.members, memberKey, (member, index) => copyChip(host, copy, member, index))}</span>
    </li>
  `;
}

// ---- Footer ----------------------------------------------------------------

/**
 * The shortcuts as quiet <kbd> hints under the footer actions. Visual
 * only: screen readers get the full sentence (keyboardHint()).
 */
export function footerExtras(host, state) {
  if (!state || state.total <= 0) return nothing;
  const keys = shortcutKeys(host);
  const revert = configBool(host, 'enableRevert');
  return html`
    <p class="wew-shortcuts" aria-hidden="true" data-wew-shortcuts>
      <span class="wew-shortcut wew-shortcut--publish">${kbd(keys.mod)}${kbd(keys.enter)}<span>${label(host, 'toolbar.shortcut.publish')}</span></span>
      ${revert ? html`<span class="wew-shortcut wew-shortcut--discard">${kbd(keys.del)}<span>${label(host, 'toolbar.shortcut.discard')}</span></span>` : nothing}
      ${state.total >= 2 ? html`<span class="wew-shortcut wew-shortcut--all">${kbd(keys.all)}<span>${selectAllHint(host)}</span></span>` : nothing}
      <span class="wew-shortcut wew-shortcut--move">${kbd(keys.arrows)}<span>${label(host, 'toolbar.shortcut.move')}</span></span>
    </p>
  `;
}

export default Object.freeze({
  name: 'efficiency',
  flags: Object.freeze({
    selectAllMin: 2,
    // Short translation sections start open: their rows go live with
    // "publish all", so they are shown rather than counted.
    otherLanguagesOpenUpTo: 5,
    cleanupOpen: 'never',
    keyboardHintKey: 'toolbar.keyboardHint.efficiency',
  }),
  renderRow,
  rowClasses,
  renderCompanions,
  renderSection,
  renderCleanup,
  renderCopy,
  footerExtras,
  onKeydown,
  publishShortcut,
  keyboardHint,
});
