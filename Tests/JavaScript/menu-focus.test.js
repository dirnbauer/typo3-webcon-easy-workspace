import { afterEach, describe, expect, it, vi } from 'vitest';
import Modal from '@typo3/backend/modal.js';
import { __notifications } from '@typo3/backend/notification.js';
import { __reset, __respond } from '@typo3/core/ajax/ajax-request.js';
import { keepOpenAcrossModal } from '@webconsulting/webcon-easy-workspace/menu-dropdown.js';
import { onDeclineMessage } from '@webconsulting/webcon-easy-workspace/menu-decline-sync.js';
import { openDiffModal, openEditModal } from '@webconsulting/webcon-easy-workspace/menu-modals.js';
import { stopsOf } from '@webconsulting/webcon-easy-workspace/menu-keyboard.js';
import { WebconEasyWorkspaceMenu } from '@webconsulting/webcon-easy-workspace/components/wew-toolbar-menu.js';
import { change, companion, copy, GERMAN, LANGUAGES } from './fixtures/items.js';

/**
 * A toolbar item as Core renders it: the toggle and the dropdown menu (a
 * popover; jsdom has none, so "open" is the `show` class and showPopover()
 * a stub that sets it).
 */
function toolbar() {
  const item = document.createElement('div');
  item.className = 'toolbar-item';
  const toggle = document.createElement('button');
  toggle.className = 'dropdown-toggle';
  const menu = document.createElement('div');
  menu.className = 'dropdown-menu show';
  menu.showPopover = vi.fn(() => menu.classList.add('show'));
  item.append(toggle, menu);
  document.body.append(item);
  return { item, toggle, menu };
}

/** The component inside an active workspace's dropdown. */
function menuElement(menu, extra = {}) {
  const element = new WebconEasyWorkspaceMenu();
  element._config = { ...element._config, labels: GERMAN, enableRevert: true, pageUid: 2228 };
  element.setAttribute('config', JSON.stringify({ ...element._config, activeWorkspaceId: 1 }));
  element.languages = LANGUAGES;
  element.state = 'loaded';
  Object.assign(element, extra);
  menu?.append(element);
  return element;
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

/** A message Core's contextual record form posts to the top window. */
function formMessage(actionName, extra = {}) {
  window.dispatchEvent(new MessageEvent('message', { data: { actionName, ...extra }, origin: window.location.origin }));
}

/**
 * The component rendered in an open dropdown, with a list; BadgeSync is
 * replaced by `request`, which plays the server's next list.
 */
async function renderedMenu(menu, items, request = () => {}) {
  const element = new WebconEasyWorkspaceMenu();
  element.setAttribute('config', JSON.stringify({ pageUid: 2228, activeWorkspaceId: 1, enableRevert: true, labels: GERMAN }));
  element.languages = LANGUAGES;
  element.items = items;
  element.state = 'loaded';
  element.listVersion = 1;
  menu.append(element);
  element.badge?.stop();
  element.badge = {
    // Like refresh(): the list and its version arrive together.
    request: vi.fn(async (...args) => {
      request(element, ...args);
      element.listVersion += 1;
      await element.updateComplete;
    }),
    stop() {},
  };
  await element.updateComplete;
  return element;
}

afterEach(() => {
  document.body.innerHTML = '';
  __notifications.length = 0;
  __reset();
});

describe('keeping the dropdown open across a Core modal', () => {
  it('opens the dropdown again when the modal is gone and gives focus back to the control that opened it', () => {
    const { menu } = toolbar();
    const host = document.createElement('div');
    const opener = document.createElement('button');
    host.append(opener);
    menu.append(host);
    const modal = document.createElement('div');

    keepOpenAcrossModal(host, modal, opener);
    // Core's showModal() hides every popover; the dialog's own focus return fails.
    menu.classList.remove('show');
    modal.dispatchEvent(new Event('typo3-modal-hidden'));

    expect(menu.showPopover).toHaveBeenCalledOnce();
    expect(host._reopeningAfterModal).toBe(true);
    expect(document.activeElement).toBe(opener);
  });

  it('hands focus to the action\'s request instead, and does nothing for a dropdown that was closed', () => {
    const { menu, toggle } = toolbar();
    const host = document.createElement('div');
    menu.append(host);
    host._focusAfterRender = { key: 'tt_content:2', listVersion: 1 };
    host._restoreFocus = vi.fn();
    const modal = document.createElement('div');
    keepOpenAcrossModal(host, modal, null);
    menu.classList.remove('show');
    modal.dispatchEvent(new Event('typo3-modal-hidden'));
    expect(host._restoreFocus).toHaveBeenCalledOnce();
    // The request's list has not arrived: focus waits on the toolbar button
    // (no opener) instead of the page body.
    expect(document.activeElement).toBe(toggle);
    toggle.blur();

    // A modal opened while the dropdown was closed (the Visual Editor's
    // decline button) leaves it closed.
    const later = document.createElement('div');
    menu.showPopover.mockClear();
    menu.classList.remove('show');
    keepOpenAcrossModal(host, later, null);
    later.dispatchEvent(new Event('typo3-modal-hidden'));
    expect(menu.showPopover).not.toHaveBeenCalled();
    expect(document.activeElement).not.toBe(toggle);
  });

  it('keeps a focus request while the modal holds the dropdown closed, and skips the refresh of the reopening', () => {
    const { menu } = toolbar();
    const element = menuElement(menu, { listVersion: 1 });
    element.focusAfterRender({ index: 0 });
    element.listVersion = 2;
    menu.classList.remove('show');

    element._restoreFocus();
    expect(element._focusAfterRender).not.toBeNull();

    const refresh = vi.spyOn(element, '_refreshList').mockResolvedValue(undefined);
    element._reopeningAfterModal = true;
    element._onOpen(new Event('toggle'));
    expect(refresh).not.toHaveBeenCalled();
    expect(element._reopeningAfterModal).toBe(false);
    element._onOpen(new Event('toggle'));
    expect(refresh).toHaveBeenCalledWith('open');
  });

  it('wires the discard confirmation: cancelling reopens the dropdown on the discard button', async () => {
    const { menu } = toolbar();
    const element = menuElement(menu);
    const discardButton = document.createElement('button');
    element.append(discardButton);
    const modal = document.createElement('div');
    modal.hideModal = () => modal.dispatchEvent(new Event('typo3-modal-hidden'));
    vi.spyOn(Modal, 'confirm').mockReturnValue(modal);

    const answer = element._confirmAndDiscard(change(2), discardButton);
    menu.classList.remove('show');
    const cancel = document.createElement('button');
    cancel.setAttribute('name', 'cancel');
    modal.append(cancel);
    cancel.dispatchEvent(new Event('button.clicked', { bubbles: true }));
    modal.hideModal();

    await expect(answer).resolves.toBe(false);
    expect(menu.showPopover).toHaveBeenCalledOnce();
    expect(document.activeElement).toBe(discardButton);
  });
});

describe('a discard that takes translations along', () => {
  async function discardWith(response) {
    const { menu } = toolbar();
    const element = await renderedMenu(menu, [change(2), change(3)]);
    vi.spyOn(element, '_refreshList').mockResolvedValue(undefined);
    vi.spyOn(element, '_animateRowsLeaving').mockResolvedValue(undefined);
    const modal = document.createElement('div');
    modal.hideModal = () => modal.dispatchEvent(new Event('typo3-modal-hidden'));
    vi.spyOn(Modal, 'confirm').mockReturnValue(modal);
    __respond(async () => response);

    const answer = element._confirmAndDiscard(change(2, { title: 'Second', kindKey: 'move' }));
    const discard = document.createElement('button');
    discard.setAttribute('name', 'discard');
    modal.append(discard);
    discard.dispatchEvent(new Event('button.clicked', { bubbles: true }));
    modal.hideModal();
    await answer;

    return __notifications.filter((notification) => notification.severity === 'success').at(-1);
  }

  it('says in the success message how many translations Core discarded with it', async () => {
    const toast = await discardWith({ success: true, discarded: 1, companionsDiscarded: 2, dependentsDiscarded: 2, warnings: [] });
    expect(toast.message).toBe('Vorgemerkte Verschiebung von "Second" wurde aufgehoben. 2 Übersetzungen wurden mit verworfen.');
  });

  it('keeps the plain success message when none went along', async () => {
    const toast = await discardWith({ success: true, discarded: 1, companionsDiscarded: 0, dependentsDiscarded: 0, warnings: [] });
    expect(toast.message).toBe('Vorgemerkte Verschiebung von "Second" wurde aufgehoben.');
  });
});

describe('modals that change records', () => {
  it('asks for the list when the record form opened from the history closes: Core\'s plain form reports no save', async () => {
    vi.useFakeTimers();
    try {
      const { menu } = toolbar();
      const element = menuElement(menu, { items: [change(2)] });
      const opener = document.createElement('button');
      element.append(opener);
      const refresh = vi.spyOn(element, '_refreshList').mockResolvedValue(undefined);
      const modals = [];
      vi.spyOn(Modal, 'advanced').mockImplementation((config) => {
        const modal = document.createElement('div');
        modal.hideModal = () => modal.dispatchEvent(new Event('typo3-modal-hidden'));
        modals.push({ config, modal });
        return modal;
      });

      openDiffModal(element, change(2), { opener });
      menu.classList.remove('show');
      const history = modals[0].modal;
      const edit = document.createElement('a');
      edit.className = 'wew-diff-modal__edit';
      edit.setAttribute('data-edit-url', '#record-edit');
      history.append(edit);
      modals[0].config.ajaxCallback(history);
      edit.click();
      vi.advanceTimersByTime(100);
      expect(menu.showPopover).not.toHaveBeenCalled();
      expect(modals).toHaveLength(2);

      // The editor saves in Core's record form and closes it.
      modals[1].modal.dispatchEvent(new Event('typo3-modal-hidden'));

      expect(menu.showPopover).toHaveBeenCalledOnce();
      expect(refresh).toHaveBeenCalledWith('modal-closed');
      expect(document.activeElement).toBe(opener);
    } finally {
      vi.useRealTimers();
    }
  });

  it('asks for the list after a record form without the contextual one, and not after a contextual one closed unsaved', () => {
    const { menu } = toolbar();
    const element = menuElement(menu, { items: [change(2)] });
    const refresh = vi.spyOn(element, '_refreshList').mockResolvedValue(undefined);
    const plain = document.createElement('div');
    vi.spyOn(Modal, 'advanced').mockReturnValueOnce(plain);

    openEditModal(element, { ...change(2), contextualEditUrl: '' }, { opener: null });
    menu.classList.remove('show');
    plain.dispatchEvent(new Event('typo3-modal-hidden'));
    expect(refresh).toHaveBeenCalledWith('modal-closed');

    refresh.mockClear();
    const contextual = document.createElement('div');
    vi.spyOn(Modal, 'advanced').mockReturnValueOnce(contextual);
    openEditModal(element, { ...change(2), contextualEditUrl: '#contextual' }, { opener: null });
    menu.classList.remove('show');
    contextual.dispatchEvent(new Event('typo3-modal-hidden'));
    expect(refresh).not.toHaveBeenCalled();
  });

  it('leaves the dropdown closed and focus alone when the editor opens the full form', () => {
    const { menu } = toolbar();
    const element = menuElement(menu, { items: [change(2)] });
    const opener = document.createElement('button');
    element.append(opener);
    const modal = document.createElement('div');
    modal.hideModal = () => modal.dispatchEvent(new Event('typo3-modal-hidden'));
    vi.spyOn(Modal, 'advanced').mockReturnValue(modal);
    element.focusAfterRender({ index: 0 });

    openEditModal(element, { ...change(2), contextualEditUrl: '#contextual' }, { opener });
    menu.classList.remove('show');
    // Core's sheet: "open in full form" loads the form in the content frame.
    formMessage('typo3:editform:navigate');

    expect(menu.showPopover).not.toHaveBeenCalled();
    expect(element.contains(document.activeElement)).toBe(false);
    expect(element._focusAfterRender).toBeNull();
  });

  it('opens the dropdown again when the sheet is closed', () => {
    const { menu } = toolbar();
    const element = menuElement(menu, { items: [change(2)] });
    const opener = document.createElement('button');
    element.append(opener);
    const modal = document.createElement('div');
    modal.hideModal = () => modal.dispatchEvent(new Event('typo3-modal-hidden'));
    vi.spyOn(Modal, 'advanced').mockReturnValue(modal);

    openEditModal(element, { ...change(2), contextualEditUrl: '#contextual' }, { opener });
    menu.classList.remove('show');
    formMessage('typo3:editform:closed');

    expect(menu.showPopover).toHaveBeenCalledOnce();
    expect(document.activeElement).toBe(opener);
  });
});

describe('a list that removes the focused control', () => {
  it('hands focus to the stop now at its place when an edit made the row a leftover copy', async () => {
    const { menu } = toolbar();
    const element = await renderedMenu(menu, [change(2), change(3), change(4)]);
    const third = element.querySelector('[data-wew-row][data-wew-key="tt_content:3"] [data-wew-stop]');
    third.focus();
    expect(stopsOf(element).indexOf(third)).toBe(1);

    // The edit (or a rollback) made the record equal to live: the next
    // list shows it in the cleanup section.
    element.items = [change(2), copy(3), change(4)];
    element.listVersion += 1;
    await element.updateComplete;

    expect(third.isConnected).toBe(false);
    expect(document.activeElement).not.toBe(document.body);
    expect(document.activeElement).toBe(stopsOf(element)[1]);
  });

  it('leaves focus alone while the dropdown is closed', async () => {
    const { menu } = toolbar();
    const element = await renderedMenu(menu, [change(2), change(3)]);
    element.querySelector('[data-wew-row][data-wew-key="tt_content:3"] [data-wew-stop]').focus();
    menu.classList.remove('show');

    element.items = [change(2)];
    element.listVersion += 1;
    await element.updateComplete;

    expect(element.contains(document.activeElement)).toBe(false);
  });
});

describe('a discard whose request fails', () => {
  it('leaves focus on the control that opened the confirmation, not on the page body', async () => {
    const { menu } = toolbar();
    const element = menuElement(menu, { items: [change(2), change(3)] });
    const discardButton = document.createElement('button');
    element.append(discardButton);
    const modal = document.createElement('div');
    modal.hideModal = () => modal.dispatchEvent(new Event('typo3-modal-hidden'));
    vi.spyOn(Modal, 'confirm').mockReturnValue(modal);
    let fail;
    __respond(() => new Promise((resolve, reject) => { fail = () => reject(new Error('HTTP 500')); }));

    const answer = element._confirmAndDiscard(change(2), discardButton);
    menu.classList.remove('show');
    document.activeElement?.blur?.();
    const discard = document.createElement('button');
    discard.setAttribute('name', 'discard');
    modal.append(discard);
    // Core: the button's trigger hides the modal, `button.clicked` follows,
    // the dialog is gone after its animation — the request still running.
    discard.dispatchEvent(new Event('button.clicked', { bubbles: true }));
    modal.hideModal();
    expect(menu.showPopover).toHaveBeenCalledOnce();
    expect(document.activeElement).toBe(discardButton);

    fail();
    await answer;

    expect(element._focusAfterRender).toBeNull();
    expect(document.activeElement).toBe(discardButton);
    expect(__notifications.at(-1).severity).toBe('error');
  });
});

describe('a translation edited from its chip', () => {
  function saveFromTheModal({ saved }) {
    const modal = document.createElement('div');
    vi.spyOn(Modal, 'advanced').mockReturnValue(modal);
    return {
      modal,
      close: async () => {
        if (saved) {
          window.dispatchEvent(new MessageEvent('message', {
            data: { actionName: 'typo3:editform:saved', recordTitle: 'Held' },
            origin: window.location.origin,
          }));
        }
        modal.dispatchEvent(new Event('typo3-modal-hidden'));
        await flush();
      },
    };
  }

  it('says in the save\'s notification that it is now an edit of its own when the dropdown is closed', async () => {
    const element = menuElement(null, { items: [change(161266, { companions: [companion(1)] })] });
    vi.spyOn(element, '_isDropdownOpen').mockReturnValue(false);
    element.badge = { request: vi.fn(async () => { element.items = [change(161266), change(161267, { languageUid: 1 })]; }) };
    const setStatus = vi.spyOn(element, 'setStatus');
    const { close } = saveFromTheModal({ saved: true });

    element.handleCompanionEdit({ title: 'Hero' }, companion(1));
    await close();

    const nowOwn = GERMAN['toolbar.companions.nowOwn'].replace('{language}', 'German');
    expect(__notifications.at(-1).severity).toBe('success');
    expect(__notifications.at(-1).message).toContain(nowOwn);
    expect(setStatus).not.toHaveBeenCalled();
  });

  it('uses the status region while the dropdown is open', async () => {
    const element = menuElement(null, { items: [change(161266, { companions: [companion(1)] })] });
    vi.spyOn(element, '_isDropdownOpen').mockReturnValue(true);
    element.badge = { request: vi.fn(async () => { element.items = [change(161266), change(161267, { languageUid: 1 })]; }) };
    const setStatus = vi.spyOn(element, 'setStatus');
    const { close } = saveFromTheModal({ saved: true });

    element.handleCompanionEdit({ title: 'Hero' }, companion(1));
    await close();

    expect(setStatus).toHaveBeenCalledWith(GERMAN['toolbar.companions.nowOwn'].replace('{language}', 'German'));
    expect(__notifications.at(-1).message).not.toContain('German');
  });

  it('moves focus to the translation\'s own row when the save made it an edit of its own', async () => {
    const { menu } = toolbar();
    const element = await renderedMenu(menu, [change(161266, { companions: [companion(1)] })], (host) => {
      host.items = [change(161266), change(161267, { languageUid: 1 })];
    });
    const chip = element.querySelector('[data-wew-companion="tt_content:161267"]');
    expect(chip).not.toBeNull();
    chip.focus();
    const modal = document.createElement('div');
    vi.spyOn(Modal, 'advanced').mockReturnValue(modal);

    element.handleCompanionEdit({ title: 'Hero' }, companion(1));
    menu.classList.remove('show');
    formMessage('typo3:editform:saved', { recordTitle: 'Held' });
    modal.dispatchEvent(new Event('typo3-modal-hidden'));
    await flush();
    await element.updateComplete;

    expect(element.querySelector('[data-wew-companion="tt_content:161267"]')).toBeNull();
    const row = element.querySelector('[data-wew-row][data-wew-key="tt_content:161267"]');
    expect(row).not.toBeNull();
    expect(document.activeElement).toBe(row.querySelector('[data-wew-stop]'));
  });

  it('gives focus back to the chip when the translation is still a copy after the save', async () => {
    const { menu } = toolbar();
    const element = await renderedMenu(menu, [change(161266, { companions: [companion(1), companion(2)] })], (host) => {
      host.items = [change(161266, { companions: [companion(1), companion(2)] })];
    });
    element.querySelector('[data-wew-companion="tt_content:161268"]').focus();
    const modal = document.createElement('div');
    vi.spyOn(Modal, 'advanced').mockReturnValue(modal);

    element.handleCompanionEdit({ title: 'Hero' }, companion(2));
    menu.classList.remove('show');
    formMessage('typo3:editform:saved', { recordTitle: '英雄' });
    modal.dispatchEvent(new Event('typo3-modal-hidden'));
    await flush();
    await element.updateComplete;

    expect(document.activeElement).toBe(element.querySelector('[data-wew-companion="tt_content:161268"]'));
  });

  it('leaves nothing behind when the modal closes without a save', async () => {
    const element = menuElement(null, { items: [change(161266, { companions: [companion(1)] })] });
    element.badge = { request: vi.fn() };
    const setStatus = vi.spyOn(element, 'setStatus');
    const { close } = saveFromTheModal({ saved: false });

    element.handleCompanionEdit({ title: 'Hero' }, companion(1));
    await close();
    // Later the German translation is edited elsewhere and the list shows it.
    element.items = [change(161266), change(161267, { languageUid: 1 })];
    element._afterRefresh();

    expect(element.badge.request).not.toHaveBeenCalled();
    expect(setStatus).not.toHaveBeenCalled();
    expect(__notifications).toHaveLength(0);
  });
});

describe('one-shot requests without a list to use them', () => {
  it('drops the focus request of a cleanup the server refused', async () => {
    const element = menuElement(null, { items: [change(1), copy(3)], listVersion: 1 });
    __respond(async (request) => {
      if (request.url.includes('discard-copies')) throw new Error('HTTP 400');
      return {};
    });

    await element.handleDiscardCopies({ key: 'context', copies: [{ key: 'tt_content:158035', records: [{ table: 'tt_content', workspaceUid: 3 }] }] });

    expect(element._focusAfterRender).toBeNull();
    expect(element.cleaning).toBe(false);
  });

  it('forgets a manual refresh that failed: the next refresh is not announced as one', async () => {
    const element = menuElement(null, { items: [change(1)] });
    element.setAttribute('config', JSON.stringify({ pageUid: 2228, enableRevert: true, labels: GERMAN }));
    const setStatus = vi.spyOn(element, 'setStatus');
    __respond(async () => { throw new Error('network'); });
    element._manualRefresh = true;
    element.focusAfterRender({ index: 1 });

    await element._refresh({ quiet: false });
    expect(element.state).toBe('error');
    expect(element._manualRefresh).toBe(false);
    expect(element._focusAfterRender).toBeNull();

    __respond(async () => ({ context: 'page', items: [change(2)], languages: [{ uid: 0, title: 'English' }], workspaceId: 4, workspaceTitle: 'WS', userUid: 1 }));
    await element._refresh({ quiet: true });
    expect(element.state).toBe('loaded');
    expect(setStatus).not.toHaveBeenCalledWith(GERMAN['toolbar.status.refreshed']);
  });
});

describe('the Visual Editor\'s decline button', () => {
  function preview() {
    const iframe = document.createElement('iframe');
    iframe.id = 'visual-editor-iframe';
    document.body.append(iframe);
    return iframe.contentWindow;
  }

  it('asks for the current list before the confirmation, so it names the translations discarded along', async () => {
    const source = preview();
    const element = menuElement(null, { items: [] });
    const hero = change(161266, { liveUid: 158035, companions: [companion(1), companion(2)] });
    element.badge = {};
    const refresh = vi.spyOn(element, '_refreshList').mockImplementation(async () => { element.items = [hero]; });
    const confirm = vi.spyOn(element, '_confirmAndDiscard').mockResolvedValue(true);

    await onDeclineMessage(element, { data: { type: 'wew-decline', table: 'tt_content', uid: 158035 }, source });

    expect(refresh).toHaveBeenCalledWith('decline');
    expect(confirm).toHaveBeenCalledWith(hero);
    expect(element.discardConfirmContent(hero).querySelectorAll('p').length).toBeGreaterThanOrEqual(2);
  });

  it('arms no focus request while the dropdown is closed: the next open is the editor\'s own', async () => {
    const source = preview();
    const { menu, toggle } = toolbar();
    menu.classList.remove('show');
    const hero = change(161266, { liveUid: 158035 });
    const element = menuElement(menu, { items: [hero, change(7)], listVersion: 1 });
    element.badge = { stop() {} };
    vi.spyOn(element, '_refreshList').mockImplementation(async () => { element.listVersion += 1; });
    const modal = document.createElement('div');
    modal.hideModal = () => modal.dispatchEvent(new Event('typo3-modal-hidden'));
    vi.spyOn(Modal, 'confirm').mockReturnValue(modal);
    __respond(async () => ({ success: true, warnings: [] }));

    await onDeclineMessage(element, { data: { type: 'wew-decline', table: 'tt_content', uid: 158035 }, source });
    const discard = document.createElement('button');
    discard.setAttribute('name', 'discard');
    modal.append(discard);
    discard.dispatchEvent(new Event('button.clicked', { bubbles: true }));
    modal.hideModal();
    await flush();

    expect(menu.showPopover).not.toHaveBeenCalled();
    expect(element._focusAfterRender).toBeNull();

    // Minutes later the editor opens the dropdown with the mouse.
    toggle.focus();
    menu.classList.add('show');
    element._onOpen(new Event('toggle'));
    element.listVersion += 1;
    element._restoreFocus();
    expect(document.activeElement).toBe(toggle);
  });

  it('drops a stale focus request on an open of the editor\'s own', () => {
    const { menu } = toolbar();
    const element = menuElement(menu, { items: [change(2)], listVersion: 1 });
    vi.spyOn(element, '_refreshList').mockResolvedValue(undefined);
    element.focusAfterRender({ key: 'tt_content:2', index: 0 });

    element._onOpen(new Event('toggle'));

    expect(element._focusAfterRender).toBeNull();
  });

  it('opens one confirmation for a double click', async () => {
    const source = preview();
    const element = menuElement(null, { items: [] });
    element.badge = {};
    let release;
    vi.spyOn(element, '_refreshList').mockImplementation(() => new Promise((resolve) => { release = resolve; }));
    const confirm = vi.spyOn(element, '_confirmAndDiscard').mockResolvedValue(true);
    const message = { data: { type: 'wew-decline', table: 'tt_content', uid: 158035 }, source };

    const first = onDeclineMessage(element, message);
    await onDeclineMessage(element, message);
    release();
    await first;

    expect(confirm).toHaveBeenCalledOnce();
  });
});
