import { beforeAll, describe, expect, it, vi } from 'vitest';
import Modal from '@typo3/backend/modal.js';
import { moduleLabelKeys, xliffLabels } from './fixtures/labels.js';

/**
 * The backend module's discard confirmation (easy-workspace-module.js): the
 * module renders the row server-side and hands the dependents' languages to
 * the discard button; the confirm names them as the toolbar's does, with
 * the labels the module's label map really carries.
 */
let module;

beforeAll(async () => {
  const german = xliffLabels('de');
  const root = document.createElement('div');
  root.setAttribute('data-wew-module', '');
  root.dataset.wewLabels = JSON.stringify(Object.fromEntries(moduleLabelKeys().map((key) => [key, german[key] ?? key])));
  document.body.append(root);
  module = await import('@webconsulting/webcon-easy-workspace/easy-workspace-module.js');
});

/** A row's discard button as RecordRow.html renders it, clicked. */
function clickDiscard(attributes) {
  const root = document.querySelector('[data-wew-module]');
  const button = document.createElement('button');
  button.type = 'button';
  for (const [name, value] of Object.entries({
    'data-wew-discard': '',
    'data-wew-table': 'tt_content',
    'data-wew-workspace-uid': '161266',
    'data-wew-title': 'Second',
    'data-wew-kind-key': 'move',
    ...attributes,
  })) {
    button.setAttribute(name, value);
  }
  root.replaceChildren(button);
  const confirm = vi.spyOn(Modal, 'confirm');
  button.click();
  expect(confirm).toHaveBeenCalledOnce();
  const content = confirm.mock.calls[0][1];
  return Array.from(content.querySelectorAll('p'), (paragraph) => paragraph.textContent);
}

describe('the module\'s discard confirmation', () => {
  it('names the translations Core discards with a move, their own edits included', () => {
    expect(clickDiscard({ 'data-wew-dependents': '', 'data-wew-dependent-edits': 'German, Hungarian' })).toEqual([
      'Vorgemerkte Verschiebung von "Second" (tt_content) aufheben? Die veröffentlichte Position bleibt unverändert.',
      'TYPO3 verwirft dabei auch diese Übersetzungen, samt ihren eigenen Änderungen: German, Hungarian.',
    ]);
  });

  it('names the new translations of a new element apart', () => {
    expect(clickDiscard({ 'data-wew-kind-key': 'new', 'data-wew-dependents': 'German', 'data-wew-dependent-edits': '' }).slice(1)).toEqual([
      'Neue Übersetzungen dieses Elements werden ebenfalls entfernt: German.',
    ]);
  });

  it('keeps the plain text for a button without dependents', () => {
    expect(clickDiscard({})).toHaveLength(1);
    expect(module.discardConfirmContent(document.querySelector('[data-wew-discard]')).querySelectorAll('p')).toHaveLength(1);
  });
});
