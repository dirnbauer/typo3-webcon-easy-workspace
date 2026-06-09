import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal, { Sizes as ModalSizes, Types as ModalTypes, Positions as ModalPositions } from '@typo3/backend/modal.js';
import { ENDPOINTS } from './menu-constants.js';
import { label } from './menu-context.js';
import { reloadPreviewIframes } from './menu-preview-locate.js';

export function openEditModal(host, item) {
  const url = item.contextualEditUrl || item.editUrl;
  if (!url) return;
  const isContextual = Boolean(item.contextualEditUrl);
  const modal = Modal.advanced({
    title: isContextual ? '' : label(host, 'edit.modalTitle', { title: item.title || label(host, 'diff.noTitle') }),
    type: ModalTypes.iframe,
    content: url,
    size: isContextual ? ModalSizes.expand : ModalSizes.large,
    position: ModalPositions.sheet,
    hideHeader: isContextual,
    additionalCssClasses: ['wew-edit-modal-shell'],
  });
  if (isContextual) {
    wireContextualEditModal(host, modal, item);
  }
}

export function wireContextualEditModal(host, modal, item) {
  const topWindow = window.top || window;
  let savedTitle = '';
  let saved = false;
  let closedExplicitly = false;
  const onMessage = (event) => {
    if (event.origin !== window.location.origin) return;
    const action = event.data?.actionName;
    if (action === 'typo3:editform:saved') {
      saved = true;
      savedTitle = event.data.recordTitle ?? '';
    } else if (action === 'typo3:editform:closed' || action === 'typo3:editform:navigate') {
      closedExplicitly = true;
      modal.hideModal();
    }
  };
  topWindow.addEventListener('message', onMessage);
  modal.addEventListener('typo3-modal-hide', (event) => {
    if (closedExplicitly) return;
    event.preventDefault();
    modal.querySelector('iframe')?.contentWindow?.postMessage(
      { actionName: 'typo3:editform:requestclose' },
      window.location.origin,
    );
  });
  modal.addEventListener('typo3-modal-hidden', async () => {
    topWindow.removeEventListener('message', onMessage);
    if (!saved) return;
    await host._refresh();
    reloadPreviewIframes();
    Notification.success(
      label(host, 'edit.saved.title'),
      savedTitle ? label(host, 'edit.saved.messageWithTitle', { title: savedTitle }) : label(host, 'edit.saved.message'),
      4,
    );
  });
}

export function openDiffModal(host, item) {
  if (!ENDPOINTS.diff) return;
  const url = `${ENDPOINTS.diff}&table=${encodeURIComponent(item.table)}&workspaceUid=${encodeURIComponent(item.workspaceUid)}`;
  const recordTitle = item.title || label(host, 'diff.noTitle');
  const modal = Modal.advanced({
    title: label(host, 'diff.modal.historyTitle', { title: recordTitle }),
    type: ModalTypes.ajax,
    content: url,
    size: ModalSizes.expand,
    additionalCssClasses: ['wew-diff-modal-shell'],
    ajaxCallback: (m) => {
      wireHistoryTabs(m);
      wireRollbackButtons(m, item, {
        endpoint: ENDPOINTS.historyRollback,
        translate: (key, vars) => label(host, key, vars),
        onSuccess: async () => {
          await host._refresh();
          reloadPreviewIframes();
        },
      });
      const editBtn = m.querySelector('.wew-diff-modal__edit');
      if (editBtn) {
        editBtn.addEventListener('click', (e) => {
          e.preventDefault();
          const editUrl = editBtn.getAttribute('data-edit-url');
          if (!editUrl) return;
          m.hideModal();
          setTimeout(() => {
            Modal.advanced({
              title: label(host, 'diff.modal.editTitle', { title: recordTitle }),
              type: ModalTypes.iframe,
              content: editUrl,
              size: ModalSizes.large,
              position: ModalPositions.sheet,
              additionalCssClasses: ['wew-edit-modal-shell'],
            });
          }, 60);
        });
      }
    },
  });
  return modal;
}

export function wireHistoryTabs(modal) {
  const tabsRoot = modal.querySelector('[data-wew-history-tabs]');
  if (!tabsRoot) return;

  const tabs = Array.from(tabsRoot.querySelectorAll('[data-wew-history-tab]'));
  const panels = Array.from(tabsRoot.querySelectorAll('[data-wew-history-panel]'));
  if (tabs.length === 0 || panels.length === 0) return;

  const activate = (name, focus = false) => {
    tabs.forEach((tab) => {
      const active = tab.dataset.wewHistoryTab === name;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.tabIndex = active ? 0 : -1;
      if (active && focus) {
        tab.focus();
      }
    });

    panels.forEach((panel) => {
      const active = panel.dataset.wewHistoryPanel === name;
      panel.classList.toggle('active', active);
      panel.classList.toggle('show', active);
      panel.hidden = !active;
      if (active) {
        const frame = panel.querySelector('iframe[data-src]');
        if (frame && (!frame.getAttribute('src') || frame.getAttribute('src') === 'about:blank')) {
          frame.setAttribute('src', frame.dataset.src || 'about:blank');
        }
      }
    });
  };

  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(tab.dataset.wewHistoryTab || 'record'));
    tab.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      event.preventDefault();
      const direction = event.key === 'ArrowRight' ? 1 : -1;
      const nextIndex = (index + direction + tabs.length) % tabs.length;
      activate(tabs[nextIndex].dataset.wewHistoryTab || 'record', true);
    });
  });

  activate(tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.wewHistoryTab || 'record');
}

/**
 * Wire the rollback buttons inside a history/diff modal.
 *
 * Shared by the toolbar dropdown and the backend module, which differ
 * only in label lookup and post-rollback refresh behaviour:
 *
 * @param {HTMLElement} modal     The modal element returned by Modal.advanced().
 * @param {object}      item      `{ table, workspaceUid, title }` of the record.
 * @param {object}      options
 * @param {string}      options.endpoint   AJAX endpoint for the rollback POST; no-op when empty.
 * @param {Function}    options.translate  `(key, vars?) => string` label lookup.
 * @param {Function}    options.onSuccess  Invoked (and awaited) after a successful rollback.
 */
export function wireRollbackButtons(modal, item, { endpoint, translate, onSuccess }) {
  if (!endpoint) return;
  modal.addEventListener('click', async (event) => {
    const btn = event.target instanceof Element ? event.target.closest('[data-wew-rollback]') : null;
    if (!btn || !modal.contains(btn)) return;
    event.preventDefault();
    event.stopPropagation();

    const mode = btn.dataset.wewRollback;
    const historyUid = parseInt(btn.dataset.historyUid || '0', 10);
    const field = btn.dataset.field || '';
    if ((mode !== 'linear' && mode !== 'field') || !Number.isFinite(historyUid) || historyUid <= 0) {
      Notification.error(translate('rollback.failedTitle'), translate('rollback.missingData', { mode: mode || '-', historyUid: btn.dataset.historyUid || '-' }));
      return;
    }
    if (mode === 'field' && field === '') {
      Notification.error(translate('rollback.failedTitle'), translate('rollback.noField'));
      return;
    }

    const confirmMessage = mode === 'field'
      ? translate('rollback.confirmField', { title: item.title || item.workspaceUid })
      : translate('rollback.confirmLinear', { title: item.title || item.workspaceUid });
    if (!window.confirm(confirmMessage)) return;

    btn.disabled = true;
    try {
      const response = await new AjaxRequest(endpoint).post(
        {
          table: item.table,
          uid: item.workspaceUid,
          historyUid,
          mode,
          field,
        },
        { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
      );
      const result = await response.resolve();
      if (result?.success) {
        Notification.success(translate('rollback.successTitle'), mode === 'field' ? translate('rollback.successField', { field }) : translate('rollback.successLinear'), 4);
        modal.hideModal();
        await onSuccess();
        return;
      }
      Notification.error(translate('rollback.errorTitle'), result?.error || translate('error.unknown'));
      btn.disabled = false;
    } catch (error) {
      Notification.error(translate('rollback.failedTitle'), error?.message || translate('error.unexpected'));
      btn.disabled = false;
    }
  });
}
