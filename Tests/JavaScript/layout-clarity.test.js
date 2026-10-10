import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from 'lit';
import clarity, {
  EXPLAINER_KEY,
  dismissExplainer,
  explainerGroupKey,
  explainerSeen,
  loadExplainerStorage,
  renderSummary,
  summaryLead,
  useExplainerStorage,
} from '@webconsulting/webcon-easy-workspace/layouts/clarity.js';
import { LAYOUTS, layoutFor } from '@webconsulting/webcon-easy-workspace/layouts/index.js';
import { renderRow } from '@webconsulting/webcon-easy-workspace/templates/row.js';
import { bindNumbers, renderHeader } from '@webconsulting/webcon-easy-workspace/templates/header.js';
import { renderGroup } from '@webconsulting/webcon-easy-workspace/templates/group.js';
import { groupRows, summaryText } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';
import { actionsOf, stopsOf } from '@webconsulting/webcon-easy-workspace/menu-keyboard.js';
import { GERMAN, change, companion, copy, host } from './fixtures/items.js';

function mount(template) {
  const container = document.createElement('div');
  document.body.append(container);
  render(template, container);
  return container;
}

/** A clarity host; `requestUpdate` and `updateComplete` like the Lit element's. */
function clarityHost(items, overrides = {}) {
  const updates = [];
  const h = host(items, { layoutProfile: clarity, ...overrides });
  h.requestUpdate = () => updates.push(true);
  h.updateComplete = Promise.resolve(true);
  h.updates = updates;
  return h;
}

/** Core's persistent storage, as far as the explainer uses it. */
function storage(initial = {}) {
  const data = { ...initial };
  return {
    data,
    get: vi.fn((key) => data[key]),
    set: vi.fn(async (key, value) => { data[key] = value; return data; }),
  };
}

const heroWithCopies = () => change(1, { companions: [companion(1), companion(2), companion(3)] });

/** jsdom has no matchMedia: answer the reduced-motion query. */
function reducedMotion(reduced) {
  window.matchMedia = vi.fn(() => ({ matches: reduced }));
}

beforeEach(() => {
  useExplainerStorage(storage());
});

afterEach(() => {
  delete window.matchMedia;
  document.body.innerHTML = '';
});

describe('clarity profile', () => {
  it('is the registry default with the shared flags and its renderers', () => {
    expect(LAYOUTS.clarity).toBe(clarity);
    expect(layoutFor({ _config: {} })).toBe(clarity);
    expect(clarity.name).toBe('clarity');
    expect(clarity.flags).toEqual({ selectAllMin: 2, otherLanguagesOpenUpTo: 0, cleanupOpen: 'alone', keyboardHintKey: 'toolbar.keyboardHint' });
    expect(typeof clarity.renderSummary).toBe('function');
    expect(clarity.renderHeader).toBeUndefined();
    expect(typeof clarity.renderCompanions).toBe('function');
    expect(typeof clarity.renderGroupExtras).toBe('function');
    expect(Object.isFrozen(clarity)).toBe(true);
  });
});

describe('clarity header', () => {
  it('leads with the edits and keeps the summary sentence intact', () => {
    const items = [heroWithCopies(), change(2, { languageUid: 1 }), copy(3, { companions: [companion(2)] })];
    const h = clarityHost(items, { badgeCount: 5 });
    const summary = mount(renderHeader(h)).querySelector('[data-wew-count-chip]');
    expect(summary.id).toBe('wew-summary-test');
    expect(summary.textContent).toBe(bindNumbers(summaryText(h)));
    expect(summary.querySelector('.wew-menu__summary-lead').textContent).toBe('2\u00A0Änderungen auf dieser Seite');
    expect(summary.querySelector('.wew-menu__summary-lead').classList.contains('is-quiet')).toBe(false);
    expect(Array.from(summary.querySelectorAll('.wew-menu__summary-part'), (part) => part.textContent))
      .toEqual(['2\u00A0unveränderte Kopien', '3\u00A0weitere im Workspace']);
  });

  it('says "none" as the lead and stays quiet while loading or without context', () => {
    expect(summaryLead(clarityHost([], { state: 'empty' }))).toBe('Keine Änderungen auf dieser Seite');
    expect(summaryLead(clarityHost([change(1)], { state: 'loaded', newsUid: 7 }))).toBe('1 Änderung in diesem Artikel');
    for (const state of ['loading', 'no-context', 'error']) {
      const h = clarityHost([], { state, badgeCount: 4 });
      const summary = mount(renderSummary(h, summaryText(h)));
      expect(summary.textContent).toBe(summaryText(h));
      expect(summary.querySelector('.wew-menu__summary-lead.is-quiet')).not.toBeNull();
    }
  });

  it('keeps the shared header contract: title, stage, module and refresh', () => {
    const h = clarityHost([change(1)]);
    const head = mount(renderHeader(h));
    expect(head.querySelector('h2#wew-title-test').textContent.trim()).toBe('AI Review Test');
    expect(head.querySelector('[data-wew-stage]').textContent).toBe('Stufe: Bearbeitung');
    expect(head.querySelector('[data-wew-open-module]').getAttribute('aria-label')).toBe(GERMAN['toolbar.openModule']);
    expect(head.querySelector('[data-wew-refresh]').disabled).toBe(false);
    expect(mount(renderHeader(clarityHost([], { state: 'error' }))).querySelector('[data-wew-count-chip]').hidden).toBe(true);
  });
});

describe('clarity translations foot', () => {
  it('puts the translate icon, one labelled group per kind and a chip per language on the card', () => {
    const item = change(1, { companions: [companion(3), companion(1), companion(2, 'synced', ['Startzeit'])] });
    const h = clarityHost([item]);
    const row = mount(renderRow(h, item)).querySelector('[data-wew-row]');
    const foot = row.querySelector('[data-wew-companions]');
    expect(foot.id).toBe('wew-row-tt_content-1-companions');
    expect(row.querySelector('[data-wew-row-check]').getAttribute('aria-describedby')).toContain(foot.id);
    expect(foot.querySelector('.wew-row__companions-icon typo3-backend-icon').getAttribute('identifier')).toBe('actions-translate');
    const groups = foot.querySelectorAll('[data-wew-companion-kind]');
    expect(Array.from(groups, (group) => group.getAttribute('data-wew-companion-kind'))).toEqual(['identical', 'synced']);
    expect(groups[0].querySelector('.wew-row__companions-label').textContent).toBe('Übersetzungen unverändert:');
    expect(groups[0].querySelector('.wew-row__companions-label').title).toBe(GERMAN['toolbar.companions.help']);
    expect(Array.from(groups[0].querySelectorAll('.wew-companion__code'), (code) => code.textContent)).toEqual(['DE', 'HU']);
    expect(groups[1].querySelector('.wew-row__companions-label').textContent).toBe('Gilt auch für die Übersetzungen (Startzeit):');
    expect(foot.querySelector('.visually-hidden').textContent).toBe(GERMAN['toolbar.companions.help']);
  });

  it('keeps the chips as the row\'s actions: keyboard reach and the edit modal', () => {
    const item = heroWithCopies();
    const edits = [];
    const h = clarityHost([item], { handleCompanionEdit: (owner, member) => edits.push([owner.workspaceUid, member.languageUid]) });
    const row = mount(renderRow(h, item)).querySelector('[data-wew-row]');
    const chips = row.querySelectorAll('[data-wew-companion]');
    expect(chips).toHaveLength(3);
    expect(Array.from(chips, (chip) => chip.getAttribute('tabindex'))).toEqual(['-1', '-1', '-1']);
    expect(chips[0].getAttribute('aria-label')).toBe('DE: Übersetzung „German“ bearbeiten');
    const stop = row.querySelector('[data-wew-stop]');
    expect(actionsOf(stop).filter((element) => element.hasAttribute('data-wew-companion'))).toHaveLength(3);
    chips[1].click();
    expect(edits).toEqual([[1, 2]]);
  });

  it('shows translations moved or deleted along as plain codes', () => {
    const item = change(1, { kindKey: 'delete', companions: [companion(1, 'deleted'), companion(3, 'deleted')] });
    const foot = mount(renderRow(clarityHost([item]), item)).querySelector('[data-wew-companions]');
    expect(foot.querySelector('.wew-row__companions-label').textContent).toBe('Übersetzungen werden mitgelöscht:');
    expect(foot.querySelectorAll('[data-wew-companion]')).toHaveLength(0);
    expect(foot.querySelectorAll('.wew-companion--static')).toHaveLength(2);
  });

  it('leaves a card without translations without a foot', () => {
    const item = change(1);
    const row = mount(renderRow(clarityHost([item]), item)).querySelector('[data-wew-row]');
    expect(row.querySelector('[data-wew-companions]')).toBeNull();
    expect(row.querySelector('[data-wew-row-check]').getAttribute('aria-describedby')).toBe('wew-row-tt_content-1-meta');
  });
});

describe('clarity explainer', () => {
  it('belongs to the first group whose cards in view carry unchanged copies', () => {
    expect(explainerGroupKey(clarityHost([heroWithCopies()]))).toBe('context');
    expect(explainerGroupKey(clarityHost([change(1, { companions: [companion(1, 'moved')] })]))).toBeNull();
    // A German edit is not in the English view; its copies explain themselves by the label's tooltip.
    expect(explainerGroupKey(clarityHost([change(1, { languageUid: 1, companions: [companion(2)] })]))).toBeNull();
    const file = change(9, { table: 'sys_file_metadata', companions: [companion(1)] });
    expect(explainerGroupKey(clarityHost([change(1), file]))).toBe('files');
    expect(explainerGroupKey(clarityHost([heroWithCopies(), file]))).toBe('context');
    // Leftover copies have their own explanation in the cleanup section.
    expect(explainerGroupKey(clarityHost([copy(3, { companions: [companion(1)] })]))).toBeNull();
  });

  it('shows once, after the cards of the view, as a note with Core\'s info icon', () => {
    const h = clarityHost([heroWithCopies(), change(2, { languageUid: 1 })]);
    const group = mount(renderGroup(h, groupRows(h)[0]));
    const note = group.querySelector('[data-wew-explainer]');
    expect(note.getAttribute('role')).toBe('note');
    expect(document.getElementById(note.getAttribute('aria-labelledby')).textContent).toBe(GERMAN['toolbar.explainer.title']);
    expect(note.querySelector('.wew-explainer__text').textContent).toBe(GERMAN['toolbar.explainer.text']);
    expect(note.querySelector('.wew-explainer__icon typo3-backend-icon').getAttribute('identifier')).toBe('actions-info-circle');
    const dismiss = note.querySelector('[data-wew-explainer-dismiss]');
    expect(dismiss.textContent.trim()).toBe('Verstanden');
    expect(dismiss.title).toBe(GERMAN['toolbar.explainer.dismissTitle']);
    // Between the rows of the view and the other languages, outside every keyboard stop.
    expect(note.previousElementSibling.matches('.wew-rows')).toBe(true);
    expect(note.nextElementSibling.matches('[data-wew-section="other-languages"]')).toBe(true);
    expect(note.querySelector('[data-wew-stop]')).toBeNull();
  });

  it('stays away once the editor\'s uc says it was seen', () => {
    useExplainerStorage(storage({ [EXPLAINER_KEY]: '1' }));
    expect(explainerSeen()).toBe(true);
    const h = clarityHost([heroWithCopies()]);
    expect(mount(renderGroup(h, groupRows(h)[0])).querySelector('[data-wew-explainer]')).toBeNull();
  });

  it('shows without Core\'s storage and when reading the uc fails', () => {
    useExplainerStorage(null);
    expect(explainerSeen()).toBe(false);
    useExplainerStorage({ get: () => { throw new Error('no uc'); } });
    expect(explainerSeen()).toBe(false);
    const h = clarityHost([heroWithCopies()]);
    expect(mount(renderGroup(h, groupRows(h)[0])).querySelector('[data-wew-explainer]')).not.toBeNull();
  });

  it('waits for Core\'s storage before it shows, then renders the host again', async () => {
    useExplainerStorage(undefined);
    expect(explainerSeen()).toBeNull();
    const h = clarityHost([heroWithCopies()]);
    expect(mount(renderGroup(h, groupRows(h)[0])).querySelector('[data-wew-explainer]')).toBeNull();
    // The test runner does not map the backend's module: no storage, the note shows.
    await expect(loadExplainerStorage(h)).resolves.toBeNull();
    expect(h.updates.length).toBeGreaterThan(0);
    expect(explainerSeen()).toBe(false);
    expect(mount(renderGroup(h, groupRows(h)[0])).querySelector('[data-wew-explainer]')).not.toBeNull();
  });

  it('"Verstanden" stores the dismissal in the uc and hands focus to the group\'s first card', async () => {
    const uc = storage();
    useExplainerStorage(uc);
    reducedMotion(true);
    const h = clarityHost([heroWithCopies(), change(2, { companions: [companion(1)] })]);
    const container = mount(renderGroup(h, groupRows(h)[0]));
    const button = container.querySelector('[data-wew-explainer-dismiss]');
    button.focus();
    h.requestUpdate = () => { h.updates.push(true); render(renderGroup(h, groupRows(h)[0]), container); };
    h.querySelectorAll = (selector) => container.querySelectorAll(selector);
    await dismissExplainer(h, container.querySelector('[data-wew-explainer]'));
    expect(uc.set).toHaveBeenCalledWith(EXPLAINER_KEY, 1);
    expect(explainerSeen()).toBe(true);
    expect(container.querySelector('[data-wew-explainer]')).toBeNull();
    const first = stopsOf(h)[0];
    expect(document.activeElement).toBe(first);
    expect(first.getAttribute('tabindex')).toBe('0');
    expect(first.closest('[data-wew-row]').getAttribute('data-wew-key')).toBe('tt_content:1');
  });

  it('stays dismissed for the page load when the uc cannot be written', async () => {
    useExplainerStorage({ get: () => undefined, set: () => Promise.reject(new Error('offline')) });
    reducedMotion(true);
    const h = clarityHost([heroWithCopies()]);
    await dismissExplainer(h, null);
    expect(explainerSeen()).toBe(true);
    expect(h.updates).toHaveLength(1);
  });

  it('folds away first when motion is allowed', async () => {
    vi.useFakeTimers();
    try {
      reducedMotion(false);
      const h = clarityHost([heroWithCopies()]);
      const container = mount(renderGroup(h, groupRows(h)[0]));
      const note = container.querySelector('[data-wew-explainer]');
      const done = dismissExplainer(h, note);
      await vi.advanceTimersByTimeAsync(0);
      expect(note.classList.contains('is-leaving')).toBe(true);
      expect(h.updates).toHaveLength(0);
      await vi.advanceTimersByTimeAsync(200);
      await done;
      expect(h.updates).toHaveLength(1);
    } finally {
      vi.useRealTimers();
    }
  });
});
