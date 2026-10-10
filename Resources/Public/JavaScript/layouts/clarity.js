import { html, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { label } from '@webconsulting/webcon-easy-workspace/menu-context.js';
import { stopsOf, setActiveStop } from '@webconsulting/webcon-easy-workspace/menu-keyboard.js';
import { groupRows, changeRowCount, chipKey } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { renderCompanionChip } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { bindNumbers } from '@webconsulting/webcon-easy-workspace/templates/header.js';

/**
 * "Klar" (default): explains what goes live.
 *
 * One number everywhere, and it always means edits: the header leads with
 * it, the rest of the summary stays quiet. Every edit is a small card — a
 * slim bar in its change-type colour, the title with the full first line,
 * the meta line beneath, and at the foot of the card the translations TYPO3
 * copied along ("Übersetzungen unverändert: DE ZH HU"), each language a chip
 * that opens that translation. Selected cards stand raised; a card that
 * stays in the workspace lies flat, so what goes live is visible at a
 * glance. Once per list a callout explains, in plain words, why TYPO3 made
 * those copies; "Verstanden" hides it for good (the editor's uc, through
 * Core's persistent storage — the same store Core's own panels use).
 *
 * Everything else (sections, cleanup, footer, states) is the shared markup,
 * styled by Css/layouts/clarity.css.
 */

/** uc key of the explainer's dismissal (Core's persistent storage, dotted path). */
export const EXPLAINER_KEY = 'webconEasyWorkspace.copiesNoteSeen';

// Loaded on demand and by name, so test runners and the harness, which do
// not map the backend's module, simply go without it (the note is then
// dismissed for the page load only).
const PERSISTENT_MODULE = '@typo3/backend/storage/persistent.js';

// Matches the --wew-motion token of the collapse in clarity.css.
const EXPLAINER_EXIT_MS = 180;

/**
 * The explainer's memory. `storage` is undefined until Core's persistent
 * storage was asked for, null when it is not available.
 */
const explainer = {
  storage: undefined,
  loading: null,
  dismissed: false,
  waiting: new Set(),
};

/**
 * Use this storage (Core's persistent storage or a stand-in; null = none,
 * undefined = ask Core again) and forget a dismissal of this page load.
 */
export function useExplainerStorage(storage) {
  explainer.storage = storage;
  explainer.loading = null;
  explainer.dismissed = false;
  explainer.waiting.clear();
}

/**
 * Ask for Core's persistent storage once. Hosts that rendered before it
 * arrived render again when it is there; until then the note stays away,
 * so an editor who dismissed it never sees it flash up.
 */
export function loadExplainerStorage(host) {
  if (explainer.storage !== undefined) return Promise.resolve(explainer.storage);
  if (host) explainer.waiting.add(host);
  if (!explainer.loading) {
    const specifier = PERSISTENT_MODULE;
    explainer.loading = import(/* @vite-ignore */ specifier)
      .then((module) => module?.default ?? null, () => null)
      .then((storage) => {
        if (explainer.storage === undefined) explainer.storage = storage;
        for (const waiting of explainer.waiting) waiting.requestUpdate?.();
        explainer.waiting.clear();
        return explainer.storage;
      });
  }
  return explainer.loading;
}

/** True once the editor said "Verstanden" (now or in an earlier session); null while unknown. */
export function explainerSeen() {
  if (explainer.dismissed) return true;
  if (explainer.storage === undefined) return null;
  if (explainer.storage === null) return false;
  try {
    const value = explainer.storage.get(EXPLAINER_KEY);
    return value === true || Number(value) === 1;
  } catch {
    return false;
  }
}

/** Translations TYPO3 copied along unchanged — what the explainer is about. */
function hasIdenticalCopies(item) {
  return Array.isArray(item?.companions) && item.companions.some((companion) => (companion?.kind ?? 'identical') === 'identical');
}

/**
 * The group that shows the explainer: the first whose rows in view carry
 * unchanged translation copies. Rows of other languages explain themselves
 * through the label's tooltip.
 */
export function explainerGroupKey(host) {
  return groupRows(host).find((group) => group.rows.some(hasIdenticalCopies))?.key ?? null;
}

function reducedMotion() {
  return Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);
}

/**
 * "Verstanden": remember it in the uc, let the note fold away, and hand
 * focus to the group's first row (the note's button is gone).
 */
export async function dismissExplainer(host, note = null) {
  explainer.dismissed = true;
  try {
    await explainer.storage?.set?.(EXPLAINER_KEY, 1);
  } catch {
    // Not stored: the note stays away for this page load and may come back.
  }
  const group = note?.closest?.('[data-wew-group]') || null;
  const hadFocus = Boolean(note?.contains?.(document.activeElement));
  if (note?.classList && !reducedMotion()) {
    note.classList.add('is-leaving');
    await new Promise((resolve) => window.setTimeout(resolve, EXPLAINER_EXIT_MS));
  }
  host.requestUpdate?.();
  await host.updateComplete;
  if (!hadFocus) return;
  const stop = group?.querySelector('[data-wew-stop]') || stopsOf(host)[0] || null;
  if (stop) {
    setActiveStop(host, stop);
    stop.focus();
  }
}

/** The callout: why TYPO3 copied the translations, and that only edits go live. */
export function renderExplainer(host, group) {
  const titleId = `${host.titleId || 'wew'}-${group.key}-explainer`;
  return html`
    <div class="wew-explainer" role="note" aria-labelledby=${titleId} data-wew-explainer>
      <span class="wew-explainer__icon" aria-hidden="true">
        <typo3-backend-icon identifier="actions-info-circle" size="small"></typo3-backend-icon>
      </span>
      <p class="wew-explainer__title" id=${titleId}>${label(host, 'toolbar.explainer.title')}</p>
      <p class="wew-explainer__text">${label(host, 'toolbar.explainer.text')}</p>
      <button type="button"
              class="btn btn-default btn-sm wew-explainer__dismiss"
              title=${label(host, 'toolbar.explainer.dismissTitle')}
              data-wew-explainer-dismiss
              @click=${(event) => {
                event.stopPropagation();
                dismissExplainer(host, event.currentTarget.closest('[data-wew-explainer]'));
              }}>
        <typo3-backend-icon identifier="actions-check" size="small"></typo3-backend-icon>
        <span>${label(host, 'toolbar.explainer.dismiss')}</span>
      </button>
    </div>
  `;
}

/**
 * The statement the summary leads with: how many edits this page or article
 * has ("1 Änderung auf dieser Seite"), or that it has none. Empty in the
 * states that have no such statement (loading, error, no context).
 */
export function summaryLead(host) {
  if (host.state !== 'loaded' && host.state !== 'empty') return '';
  const count = changeRowCount(host.items);
  const isNews = host.newsUid > 0;
  return count > 0
    ? label(host, isNews ? 'toolbar.summary.news' : 'toolbar.summary.page', { count })
    : label(host, isNews ? 'toolbar.summary.noneNews' : 'toolbar.summary.nonePage');
}

/**
 * The summary with its lead (layout hook `renderSummary`): the edits here
 * in full ink, the rest — the other languages' share, copies, elsewhere —
 * quiet, and wrapping only between its parts. The text reads exactly as
 * summaryText(), so screen readers get one sentence.
 */
export function renderSummary(host, summary) {
  // The header asks for the explainer's storage early, so the note is
  // decided by the time the cards render.
  loadExplainerStorage(host);
  const text = String(summary);
  const lead = bindNumbers(summaryLead(host));
  if (lead === '' || !text.startsWith(lead)) {
    return html`<span class="wew-menu__summary-lead is-quiet">${text}</span>`;
  }
  const tail = text.slice(lead.length);
  // ", 2 davon in anderen Sprachen" runs on after the lead; every " · "
  // starts a part that never breaks inside, and a line ends with the dot.
  const [attached, ...parts] = tail.split(' · ');
  return html`<span class="wew-menu__summary-lead">${lead}</span>${attached}${parts.map((part) => html`<span class="wew-menu__summary-sep"> ·</span> <span class="wew-menu__summary-part">${part}</span>`)}`;
}

/**
 * The foot of an edit card: the translations TYPO3 carried along, behind
 * Core's translate icon in the icon column, one group per kind with its
 * label ("Übersetzungen unverändert:") and a chip per language. The label's
 * tooltip and a hidden sentence carry the long explanation.
 */
export function renderCompanions(host, item, groups, ctx) {
  const help = label(host, 'toolbar.companions.help');
  return html`
    <span class="wew-row__companions" id=${`${ctx.row.id}-companions`} data-wew-companions>
      <span class="wew-row__companions-icon" aria-hidden="true">
        <typo3-backend-icon identifier="actions-translate" size="small"></typo3-backend-icon>
      </span>
      <span class="wew-row__companions-groups">
        ${groups.map((group) => html`
          <span class="wew-row__companion-group" data-wew-companion-kind=${group.kind}>
            <span class="wew-row__companions-label" title=${help}>${label(host, group.labelKey, { fields: group.fields })}</span>
            <span class="wew-row__chips">${repeat(group.chips, chipKey, (chip) => renderCompanionChip(host, item, chip, group))}</span>
          </span>`)}
      </span>
      <span class="visually-hidden">${help}</span>
    </span>
  `;
}

/** After the cards of the view: the explainer, in the one group it belongs to, until dismissed. */
export function renderGroupExtras(host, group) {
  const seen = explainerSeen();
  if (seen === null) {
    loadExplainerStorage(host);
    return nothing;
  }
  if (seen || explainerGroupKey(host) !== group.key) return nothing;
  return renderExplainer(host, group);
}

export default Object.freeze({
  name: 'clarity',
  flags: Object.freeze({
    selectAllMin: 2,
    otherLanguagesOpenUpTo: 0,
    cleanupOpen: 'alone',
    keyboardHintKey: 'toolbar.keyboardHint',
  }),
  renderSummary,
  renderCompanions,
  renderGroupExtras,
});
