import { html, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { label, configBool } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { languageInfo, memberKey } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { changeBadgeClass, rowTitle } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { renderCopyChip, renderCopyLine } from '@webconsulting/webcon-easy-workspace/templates/group.js';
import { keepOpenAcrossModal, focusedControl } from '@webconsulting/webcon-easy-workspace/menu-dropdown.js';

/**
 * "TYPO3 Core": the dropdown in the backend's own idiom, accessible first.
 *
 * Rows read like a Core list group: full-width lines separated by hairlines,
 * no tinted selection (the checkbox carries the state). Every row has the
 * same anatomy, so the eye finds things in the same place on every line:
 *
 *   [x] ▤  Title, up to two lines, lang-tagged          [Geändert]
 *          [Verborgen] DE · Hero-Bereich · vor 5 Min.   ✎ ⇄ ◎ │ ↺
 *       ⟁  Übersetzungen unverändert:  [⚑ DE] [⚑ ZH] [⚑ HU]
 *
 * The title gets the full width of line 1 next to its change badge; the
 * badges end where the actions end, so they form one column above them.
 * Line two starts with what changes how the row is read (hidden, the
 * language) before the quiet meta text. The translations line gets Core's
 * translate glyph in the icon column.
 *
 * The cleanup section is "Entwürfe ohne Änderung": a header action that
 * discards them all and one discard per element, both behind a Core confirm
 * modal (nothing differs from live, but a discard cannot be undone).
 *
 * Styles: Css/layouts/core.css. Keyboard stops and test hooks follow the
 * contract in layouts/index.js; the checkbox stays the row's stop.
 */

/**
 * Column 1: the row's checkbox and its keyboard stop. Its description names
 * the change badge (which here is not part of the meta line), the meta line
 * and the translations line, in reading order.
 */
export function renderCheck(host, item, row) {
  const describedBy = [`${row.id}-badge`, `${row.id}-meta`, row.companions.length > 0 ? `${row.id}-companions` : '']
    .filter(Boolean).join(' ');
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

/** Line 1, right of the title: the change badge. */
export function renderBadge(host, item, row) {
  return html`<span class="badge ${changeBadgeClass(row.type)} wew-row__badge" id=${`${row.id}-badge`} data-wew-change-badge=${row.type}>${label(host, `change.${row.type}`)}</span>`;
}

/**
 * Meta text as whole parts: type · column · part-of · time · author. Where
 * the line is short, the last parts give way entirely (they wrap onto a
 * hidden second line) instead of being cut mid-word; the separators are
 * drawn by CSS so a part and its "·" leave together. The full text stays
 * the title and, for screen readers, the checkbox's description.
 */
export function renderMetaText(host, item, row) {
  if (row.meta.length === 0) return nothing;
  return html`<span class="wew-row__meta-text wew-row__meta-text--parts" title=${row.meta.join(' · ')}>${row.meta.map((part) => html`<span class="wew-row__part">${part}</span>`)}</span>`;
}

/** Line 2: hidden and language first (they change how the row is read), then the meta text. */
export function renderMeta(host, item, row, parts) {
  return html`<span class="wew-row__meta" id=${`${row.id}-meta`}>${parts.hiddenBadge(host, item, row)}${parts.languageTag(host, item, row)}${renderMetaText(host, item, row)}</span>`;
}

/** Core's translate glyph in the icon column of the translations line. */
export function renderTranslationsGlyph(row) {
  if (row.companions.length === 0) return nothing;
  return html`<span class="wew-row__glyph" aria-hidden="true"><typo3-backend-icon identifier="actions-translate" size="small"></typo3-backend-icon></span>`;
}

/**
 * The row: line 1 holds the title and the badge (the badge ends where the
 * actions end, so all badges form one column), line 2 the meta and the
 * actions, then related changes and the translations line.
 */
export function renderRow(host, item, ctx) {
  const { row, parts } = ctx;
  return ctx.shell(html`${renderCheck(host, item, row)}${parts.icon(host, item, row)}<span class="wew-row__head">${parts.title(host, item, row)}${renderBadge(host, item, row)}</span>${renderMeta(host, item, row, parts)}${parts.actions(host, item, row)}${parts.children(host, item, row)}${renderTranslationsGlyph(row)}${parts.companions(host, item, row)}`);
}

/** Records a set of cleanup lines stands for: each copy and the translations folded into it. */
export function copyRecordTotal(copies) {
  return (Array.isArray(copies) ? copies : []).reduce((sum, copy) => sum + (copy?.members?.length || copy?.records?.length || 0), 0);
}

/**
 * One element of "Entwürfe ohne Änderung": icon, title, "type · Inhalt wie
 * live", a chip per language (edits that copy; the first chip is the
 * line's keyboard stop) and a discard for just this element, last — last
 * in the DOM too, so arrows and Tab reach the chips first; the grid draws
 * it on line 2 at the right.
 */
export function renderCopy(host, group, copy) {
  const title = copy.title || label(host, 'diff.noTitle');
  const lead = copy.members?.[0] ? languageInfo(host, Number(copy.members[0].languageUid)) : null;
  const revert = configBool(host, 'enableRevert');
  const discardName = revert ? label(host, 'toolbar.cleanup.discardOne', { title }) : label(host, 'discard.button.disabledTitle');
  return html`
    <li class="wew-copy" data-wew-copy-group=${copy.key}>
      <span class="wew-copy__icon" aria-hidden="true">
        <typo3-backend-icon identifier=${copy.iconIdentifier || 'mimetypes-other-other'} size="small"></typo3-backend-icon>
      </span>
      <span class="wew-copy__title" lang=${lead?.hreflang || nothing} title=${title}>${title}</span>
      <span class="wew-copy__meta">${[copy.typeLabel, label(host, 'toolbar.cleanup.draftsShort')].filter(Boolean).join(' · ')}</span>
      <span class="wew-copy__chips">${repeat(copy.members || [], memberKey, (member, index) => renderCopyChip(host, copy, member, index))}</span>
      <span class="wew-copy__actions">
        <button type="button"
                class="btn btn-borderless btn-sm wew-row__action wew-row__action--danger wew-copy__discard"
                tabindex="-1"
                title=${discardName}
                aria-label=${discardName}
                ?disabled=${!revert || Boolean(host.cleaning)}
                data-wew-action="discard-copy"
                data-wew-discard-copy
                @click=${(event) => { event.stopPropagation(); host.handleDiscardCopies?.(group, [copy]); }}>
          <typo3-backend-icon identifier="wew-discard" size="small"></typo3-backend-icon>
        </button>
      </span>
    </li>
  `;
}

function chevron() {
  return html`<span class="wew-section__chevron" aria-hidden="true"><typo3-backend-icon identifier="actions-chevron-right" size="small"></typo3-backend-icon></span>`;
}

/**
 * "Entwürfe ohne Änderung": a heading with a toggle and the count, one
 * discard for the whole section, the explanation, then one line per element.
 */
export function renderCleanup(host, group, ctx) {
  const { section } = ctx;
  const title = label(host, 'toolbar.cleanup.titleDrafts');
  const help = label(host, 'toolbar.cleanup.draftsHelp');
  const discard = label(host, 'toolbar.cleanup.confirm.button');
  const count = label(host, 'toolbar.summary.drafts', { count: section.records });
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
                  title=${help}
                  data-wew-section-toggle=${section.sectionId}
                  data-wew-stop
                  @click=${() => host.toggleSection(section.sectionId)}>
            ${chevron()}
            <span class="wew-section__title">${title}</span>
            <span class="wew-section__count wew-section__count--pill" title=${count}>
              <span aria-hidden="true">${section.records}</span>
              <span class="visually-hidden">${count}</span>
            </span>
          </button>
        </h4>
        <button type="button"
                class="btn btn-default btn-sm wew-cleanup__discard"
                tabindex="-1"
                ?disabled=${!section.revert || section.busy}
                aria-label=${section.busy ? label(host, 'toolbar.cleanup.discarding') : `${discard}: ${title}`}
                title=${section.revert ? `${discard}: ${title}` : label(host, 'discard.button.disabledTitle')}
                aria-describedby=${`${section.domId}-hint`}
                aria-busy=${section.busy ? 'true' : 'false'}
                data-wew-discard-copies
                @click=${() => host.handleDiscardCopies?.(group)}>
          <typo3-backend-icon identifier="wew-discard" size="small"></typo3-backend-icon>
          <span>${section.busy ? label(host, 'toolbar.cleanup.discarding') : discard}</span>
        </button>
      </div>
      <p id=${`${section.domId}-hint`} class="wew-section__help ${section.open ? '' : 'visually-hidden'}">${help}</p>
      ${section.open ? html`
        <ul class="wew-copies" id=${`${section.domId}-body`} role="list" data-wew-section-body>
          ${repeat(group.copies, (copy) => copy.key, (copy) => renderCopyLine(host, group, copy))}
        </ul>` : nothing}
    </div>
  `;
}

/**
 * Ask before discarding drafts without changes: Core's confirm modal, info
 * severity (nothing on the website changes), the number of records named.
 * Resolves false when the editor cancels or closes the modal.
 */
export function confirmDiscardCopies(host, group, copies) {
  const count = copyRecordTotal(copies);
  return new Promise((resolve) => {
    let settled = false;
    const settle = (value) => {
      if (settled) return;
      settled = true;
      resolve(value);
    };
    const modal = Modal.confirm(
      label(host, 'toolbar.cleanup.confirm.title'),
      label(host, 'toolbar.cleanup.confirm.message', { count }),
      SeverityEnum.info,
      [
        { text: label(host, 'discard.modal.cancel'), btnClass: 'btn-default', name: 'cancel', trigger: () => { settle(false); modal?.hideModal?.(); } },
        { text: label(host, 'toolbar.cleanup.confirm.button'), btnClass: 'btn-info', name: 'discard', active: true, trigger: () => { settle(true); modal?.hideModal?.(); } },
      ],
    );
    modal?.addEventListener?.('typo3-modal-hidden', () => settle(false));
    // The modal hides the dropdown; it opens again when the modal is gone.
    keepOpenAcrossModal(host, modal, focusedControl(host));
  });
}

export default Object.freeze({
  name: 'core',
  flags: Object.freeze({
    selectAllMin: 2,
    otherLanguagesOpenUpTo: 0,
    cleanupOpen: 'alone',
    keyboardHintKey: 'toolbar.keyboardHint',
    // The cleanup records are "Entwürfe" here, wherever they are named.
    copiesSummaryKey: 'toolbar.summary.drafts',
    copyEditKey: 'toolbar.cleanup.editDraft',
    cleanupSuccessKey: 'toolbar.cleanup.successDrafts',
    cleanupSkippedKey: 'toolbar.cleanup.skippedDrafts',
  }),
  renderRow,
  renderCleanup,
  renderCopy,
  beforeDiscardCopies: confirmDiscardCopies,
});
