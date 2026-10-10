import { html, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { key, toggleMany } from '@webconsulting/webcon-easy-workspace/menu-selection.js';
import { viaLayout, layoutFlag } from '@webconsulting/webcon-easy-workspace/menu-layout.js';
import {
  groupChangeRows,
  groupCopyRecordCount,
  languageInfo,
  primaryViewLanguage,
  singleViewLanguage,
  memberKey,
} from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';

/** Sections a group can fold: the other languages and the leftover copies. */
export const OTHER_LANGUAGES = 'other';
export const COPIES = 'copies';

export function sectionKey(group, section) {
  return `${group.key}:${section}`;
}

/** DOM id prefix of a group or one of its sections. */
export function groupDomId(host, group, section = '') {
  return `${host.titleId || 'wew'}-${group.key}${section ? `-${section}` : ''}`;
}

/**
 * Whether a section is open: what the editor last chose for it in this
 * context (`host.sectionState`), else its default. The effective state is
 * remembered for toggleSection() and the keyboard.
 */
export function isOpen(host, sectionId, fallback) {
  const state = host.sectionState;
  const open = state instanceof Map && state.has(sectionId) ? Boolean(state.get(sectionId)) : Boolean(fallback);
  if (!(host._effectiveSections instanceof Map)) host._effectiveSections = new Map();
  host._effectiveSections.set(sectionId, open);
  return open;
}

/** Other languages start open when nothing of the group is in view (or, per layout, while short). */
export function otherLanguagesOpenByDefault(host, group) {
  if (group.rows.length === 0) return true;
  const upTo = Number(layoutFlag(host, 'otherLanguagesOpenUpTo')) || 0;
  return upTo > 0 && groupChangeRows(group).length - group.rows.length <= upTo;
}

/** The cleanup section starts open when it is all the group has (per layout: never or always). */
export function cleanupOpenByDefault(host, group) {
  switch (layoutFlag(host, 'cleanupOpen')) {
    case 'always': return true;
    case 'never': return false;
    default: return group.rows.length === 0 && group.otherLanguages.length === 0;
  }
}

function chevron() {
  return html`<span class="wew-section__chevron" aria-hidden="true"><typo3-backend-icon identifier="actions-chevron-right" size="small"></typo3-backend-icon></span>`;
}

function rowList(host, rows) {
  return html`<ul class="wew-rows" role="list">${repeat(rows, (item) => key(host, item), (item) => renderRow(host, item))}</ul>`;
}

/** Icon, title (h3), rootline path; the count only when there are two groups to tell apart. */
export function renderGroupHead(host, group, ctx = {}) {
  const count = groupChangeRows(group).length;
  return viaLayout(host, 'renderGroupHead', [group], ctx, () => html`
    <div class="wew-group__head">
      <span class="wew-group__icon" aria-hidden="true">
        <typo3-backend-icon identifier=${group.icon} size="small"></typo3-backend-icon>
      </span>
      <span class="wew-group__text">
        <h3 class="wew-group__title" id=${groupDomId(host, group)} title=${group.title}>${group.title}</h3>
        ${group.path ? html`<span class="wew-group__path" title=${group.path}>${group.path}</span>` : nothing}
      </span>
      ${(ctx.groupCount || 1) >= 2 && count > 0 ? html`
        <span class="wew-group__count" title=${label(host, 'toolbar.languages.count', { count })}>${count}</span>` : nothing}
    </div>
  `);
}

/** "English · in dieser Ansicht" above the rows of the view, when other languages follow. */
export function renderInViewHead(host, group) {
  if (group.otherLanguages.length === 0 || group.rows.length === 0) return nothing;
  const viewLanguage = singleViewLanguage(host);
  return html`
    <h4 class="wew-section wew-section--in-view" data-wew-section="in-view">
      ${viewLanguage?.flag ? html`<typo3-backend-icon identifier=${viewLanguage.flag} size="small" aria-hidden="true"></typo3-backend-icon>` : nothing}
      <span class="wew-section__title">
        ${viewLanguage ? label(host, 'toolbar.languages.inView', { language: viewLanguage.title }) : label(host, 'toolbar.languages.inViewMany')}
      </span>
      <span class="wew-section__count">${group.rows.length}</span>
    </h4>
  `;
}

/** Everything the other-languages section shows, for its renderer and layout hooks. */
export function otherLanguagesContext(host, group) {
  const sectionId = sectionKey(group, OTHER_LANGUAGES);
  const rows = groupChangeRows(group).slice(group.rows.length);
  const viewLanguage = singleViewLanguage(host);
  const selected = rows.filter((row) => host.selection?.has?.(key(host, row))).length;
  return {
    sectionId,
    domId: groupDomId(host, group, OTHER_LANGUAGES),
    open: isOpen(host, sectionId, otherLanguagesOpenByDefault(host, group)),
    rows,
    selected,
    // In the default-language view the other languages are its translations.
    title: viewLanguage !== null && primaryViewLanguage(host) === 0
      ? label(host, 'toolbar.languages.translations')
      : label(host, 'toolbar.languages.other'),
    hint: viewLanguage
      ? label(host, 'toolbar.languages.otherHint', { language: viewLanguage.title })
      : label(host, 'toolbar.languages.otherHintMany'),
    shortHint: viewLanguage
      ? label(host, 'toolbar.languages.otherHintShort', { language: viewLanguage.title })
      : label(host, 'toolbar.languages.otherHintShortMany'),
  };
}

/**
 * Edits in languages the view does not show: a heading with a toggle, a
 * tri-state checkbox (so a folded section still says whether its rows go
 * live), and per language a sub-heading and the rows.
 */
export function renderOtherLanguages(host, group) {
  if (group.otherLanguages.length === 0) return nothing;
  const section = otherLanguagesContext(host, group);
  const all = section.rows.length > 0 && section.selected === section.rows.length;
  const some = section.selected > 0 && !all;
  return viaLayout(host, 'renderSection', [group], { section }, () => html`
    <div class="wew-section wew-section--other ${section.open ? 'is-open' : ''}" data-wew-section="other-languages">
      <div class="wew-section__head" data-wew-section-head>
        <span class="form-check wew-section__check-wrap">
          <input type="checkbox"
                 class="form-check-input"
                 tabindex="-1"
                 .checked=${all}
                 .indeterminate=${some}
                 aria-label=${label(host, 'toolbar.section.select', { section: section.title })}
                 title=${label(host, 'toolbar.selection.count', { selected: section.selected, total: section.rows.length })}
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
            <span class="wew-section__count">${label(host, 'toolbar.languages.count', { count: section.rows.length })}</span>
            <span class="wew-section__meta" aria-hidden="true">${section.shortHint}</span>
            <span class="visually-hidden">${section.hint}</span>
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
  `);
}

/** A language chip of a copy line: opens that copy in the edit modal. */
export function renderCopyChip(host, copy, member, index) {
  const language = languageInfo(host, member.languageUid) || { uid: member.languageUid, title: String(member.languageUid), flag: '', code: String(member.languageUid) };
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
            @click=${() => host.handleCompanionEdit?.({ title: copy.title }, member)}>
      ${language.flag ? html`<typo3-backend-icon identifier=${language.flag} size="small" aria-hidden="true"></typo3-backend-icon>` : nothing}
      <span class="wew-companion__code">${language.code}</span>
    </button>
  `;
}

/** One element of the cleanup section: icon, title, a chip per copy. */
export function renderCopyLine(host, group, copy) {
  return viaLayout(host, 'renderCopy', [group, copy], {}, () => html`
    <li class="wew-copy" data-wew-copy-group=${copy.key}>
      <span class="wew-copy__icon" aria-hidden="true">
        <typo3-backend-icon identifier=${copy.iconIdentifier} size="small"></typo3-backend-icon>
      </span>
      <span class="wew-copy__title" title=${copy.title}>${copy.title || label(host, 'diff.noTitle')}</span>
      <span class="wew-copy__chips">${repeat(copy.members, memberKey, (member, index) => renderCopyChip(host, copy, member, index))}</span>
    </li>
  `);
}

/** Everything the cleanup section shows, for its renderer and layout hooks. */
export function cleanupContext(host, group) {
  const sectionId = sectionKey(group, COPIES);
  const revert = configBool(host, 'enableRevert');
  return {
    sectionId,
    domId: groupDomId(host, group, COPIES),
    open: isOpen(host, sectionId, cleanupOpenByDefault(host, group)),
    records: groupCopyRecordCount(group),
    revert,
    busy: Boolean(host.cleaning),
  };
}

/**
 * Copies TYPO3 left behind (their edit is no longer pending): same content
 * as live, never counted or published. One action discards them; the
 * server checks each one again first.
 */
export function renderCleanup(host, group) {
  if (!Array.isArray(group.copies) || group.copies.length === 0) return nothing;
  const section = cleanupContext(host, group);
  return viaLayout(host, 'renderCleanup', [group], { section }, () => html`
    <div class="wew-section wew-section--cleanup ${section.open ? 'is-open' : ''}" data-wew-cleanup data-wew-section="copies">
      <div class="wew-section__head" data-wew-section-head>
        <span class="wew-section__lead" aria-hidden="true"></span>
        <h4 class="wew-section__heading">
          <button type="button"
                  class="wew-section__toggle"
                  tabindex="-1"
                  aria-expanded=${section.open ? 'true' : 'false'}
                  aria-controls=${`${section.domId}-body`}
                  data-wew-section-toggle=${section.sectionId}
                  data-wew-stop
                  @click=${() => host.toggleSection(section.sectionId)}>
            ${chevron()}
            <span class="wew-section__title">${label(host, 'toolbar.cleanup.title')}</span>
            <span class="wew-section__count wew-section__count--pill" title=${label(host, 'toolbar.summary.copies', { count: section.records })}>
              <span aria-hidden="true">${section.records}</span>
              <span class="visually-hidden">${label(host, 'toolbar.summary.copies', { count: section.records })}</span>
            </span>
          </button>
        </h4>
        <button type="button"
                class="btn btn-default btn-sm wew-cleanup__discard"
                tabindex="-1"
                ?disabled=${!section.revert || section.busy}
                title=${section.revert ? nothing : label(host, 'discard.button.disabledTitle')}
                aria-describedby=${`${section.domId}-hint`}
                aria-busy=${section.busy ? 'true' : 'false'}
                data-wew-discard-copies
                @click=${() => host.handleDiscardCopies?.(group)}>
          <typo3-backend-icon identifier="wew-discard" size="small"></typo3-backend-icon>
          <span>${label(host, section.busy ? 'toolbar.cleanup.discarding' : 'toolbar.cleanup.discard')}</span>
        </button>
      </div>
      <p id=${`${section.domId}-hint`} class="wew-section__help ${section.open ? '' : 'visually-hidden'}">${label(host, 'toolbar.cleanup.hint')}</p>
      ${section.open ? html`
        <ul class="wew-copies" id=${`${section.domId}-body`} role="list" data-wew-section-body>
          ${repeat(group.copies, (copy) => copy.key, (copy) => renderCopyLine(host, group, copy))}
        </ul>` : nothing}
    </div>
  `);
}

/**
 * A group: the page or news record (or the workspace's files), its edits in
 * the current view, the edits in other languages, the leftover copies.
 *
 * Layout hooks: renderGroupHead, renderGroupExtras (after the rows of the
 * view), renderSection (other languages), renderCleanup, renderCopy.
 *
 * @param {{groupCount?: number, index?: number}} ctx
 */
export function renderGroup(host, group, ctx = {}) {
  return html`
    <section class="wew-group" aria-labelledby=${groupDomId(host, group)} data-wew-group=${group.key}>
      ${renderGroupHead(host, group, ctx)}
      ${renderInViewHead(host, group)}
      ${group.rows.length > 0 ? rowList(host, group.rows) : nothing}
      ${viaLayout(host, 'renderGroupExtras', [group], ctx, () => nothing)}
      ${renderOtherLanguages(host, group)}
      ${renderCleanup(host, group)}
    </section>
  `;
}
