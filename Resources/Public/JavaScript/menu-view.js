import Notification from '@typo3/backend/notification.js';

import { label } from './menu-context.js';
import { key, toggle, selectAll } from './menu-selection.js';
import {
  highlightInIframe,
  clearIframeHighlight,
  previewDiscard,
} from './menu-preview-locate.js';

export function findItemByKey(host, itemKey) {
  return (host.items || []).find((item) => key(host, item) === itemKey) || null;
}

export function mountMenuHtml(host, html) {
  const wrapper = document.createElement('div');
  wrapper.innerHTML = html;
  const menu = wrapper.querySelector('[data-wew-menu]');
  if (!menu) {
    host.innerHTML = html;
    return;
  }
  host.replaceChildren(menu);
  wireMenuEvents(host);
  syncSelectionFromDom(host);
  updateFooterState(host);
}

export function applyViewMode(host, mode) {
  host.mode = mode;
  const root = host.querySelector('[data-wew-menu]');
  if (!root) return;

  for (const panel of root.querySelectorAll('[data-wew-mode-panel]')) {
    const active = panel.getAttribute('data-wew-mode-panel') === mode;
    panel.hidden = !active;
    panel.classList.toggle('hidden', !active);
  }

  for (const tab of root.querySelectorAll('[data-wew-mode]')) {
    const active = tab.getAttribute('data-wew-mode') === mode;
    tab.classList.toggle('wew-menu__chip--active', active);
    tab.setAttribute('aria-selected', active ? 'true' : 'false');
    tab.tabIndex = active ? 0 : -1;
  }
}

export function syncSelectionFromDom(host) {
  const next = new Set();
  for (const checkbox of host.querySelectorAll('[data-wew-row-check]')) {
    const row = checkbox.closest('[data-wew-key]');
    const itemKey = row?.getAttribute('data-wew-key');
    if (itemKey && checkbox.checked) {
      next.add(itemKey);
    }
  }
  host.selection = next;
}

export function updateFooterState(host) {
  const footer = host.querySelector('[data-wew-footer]');
  if (!footer) return;

  const changeable = (host.items || []).filter((item) => item.isChanged);
  const total = changeable.length;
  const selectedCount = host.selection.size;
  const allChecked = total > 0 && selectedCount === total;
  const someChecked = selectedCount > 0 && selectedCount < total;

  const selectAllCheck = footer.querySelector('[data-wew-select-all]');
  if (selectAllCheck) {
    selectAllCheck.checked = allChecked;
    selectAllCheck.indeterminate = someChecked;
    selectAllCheck.setAttribute('aria-checked', allChecked ? 'true' : (someChecked ? 'mixed' : 'false'));
    selectAllCheck.setAttribute(
      'aria-label',
      allChecked ? label(host, 'toolbar.deselectAllChanges') : label(host, 'toolbar.selectAllChanges'),
    );
  }

  const selectAllLabel = footer.querySelector('[data-wew-select-all-label]');
  if (selectAllLabel) {
    selectAllLabel.textContent = allChecked
      ? label(host, 'toolbar.deselectAll')
      : (someChecked ? label(host, 'toolbar.someSelected') : label(host, 'toolbar.selectAll'));
  }

  const selectedEl = footer.querySelector('[data-wew-selected-count]');
  const totalEl = footer.querySelector('[data-wew-total-count]');
  if (selectedEl) selectedEl.textContent = String(selectedCount);
  if (totalEl) totalEl.textContent = String(total);

  const publishBtn = footer.querySelector('[data-wew-publish]');
  if (publishBtn) {
    publishBtn.disabled = selectedCount <= 0 || host.publishing;
    const base = label(host, 'toolbar.publishToLive');
    publishBtn.textContent = selectedCount > 0 ? `${base} (${selectedCount})` : base;
  }
}

export function wireMenuEvents(host) {
  if (host._glueClickHandler) {
    host.removeEventListener('click', host._glueClickHandler);
  }
  if (host._glueChangeHandler) {
    host.removeEventListener('change', host._glueChangeHandler);
  }
  if (host._glueKeydownHandler) {
    host.removeEventListener('keydown', host._glueKeydownHandler);
  }

  host._glueClickHandler = (event) => handleMenuClick(host, event);
  host._glueChangeHandler = (event) => handleMenuChange(host, event);
  host._glueKeydownHandler = (event) => handleFilterKeydown(host, event);

  host.addEventListener('click', host._glueClickHandler);
  host.addEventListener('change', host._glueChangeHandler);
  host.addEventListener('keydown', host._glueKeydownHandler);

  wireLocateHover(host);
}

function wireLocateHover(host) {
  for (const button of host.querySelectorAll('[data-wew-locate="1"]')) {
    const row = button.closest('[data-wew-key]');
    const item = findItemByKey(host, row?.getAttribute('data-wew-key') || '');
    if (!item) continue;
    button.addEventListener('mouseenter', () => highlightInIframe(host, item));
    button.addEventListener('mouseleave', () => clearIframeHighlight(host));
    button.addEventListener('focus', () => highlightInIframe(host, item));
    button.addEventListener('blur', () => clearIframeHighlight(host));
  }

  for (const button of host.querySelectorAll('[data-wew-discard][data-wew-can-revert="1"]')) {
    const row = button.closest('[data-wew-key]');
    const item = findItemByKey(host, row?.getAttribute('data-wew-key') || '');
    if (!item) continue;
    button.addEventListener('mouseenter', () => previewDiscard(host, item));
    button.addEventListener('mouseleave', () => clearIframeHighlight(host));
    button.addEventListener('focus', () => previewDiscard(host, item));
    button.addEventListener('blur', () => clearIframeHighlight(host));
  }
}

function handleMenuChange(host, event) {
  const target = event.target;
  if (!(target instanceof HTMLInputElement)) return;

  if (target.matches('[data-wew-row-check]')) {
    const row = target.closest('[data-wew-key]');
    const item = findItemByKey(host, row?.getAttribute('data-wew-key') || '');
    if (item) {
      toggle(host, item, target.checked);
      updateFooterState(host);
    }
    return;
  }

  if (target.matches('[data-wew-select-all]')) {
    selectAll(host, target.checked);
    for (const checkbox of host.querySelectorAll('[data-wew-row-check]')) {
      checkbox.checked = target.checked;
    }
    updateFooterState(host);
  }
}

function handleMenuClick(host, event) {
  const target = event.target instanceof Element ? event.target : null;
  if (!target) return;

  const modeButton = target.closest('[data-wew-mode]');
  if (modeButton) {
    event.preventDefault();
    const mode = modeButton.getAttribute('data-wew-mode');
    if (mode === 'all' || mode === 'changed') {
      host._setMode(mode);
    }
    return;
  }

  const previewButton = target.closest('[data-wew-preview-link]');
  if (previewButton) {
    event.preventDefault();
    const pageUid = parseInt(previewButton.getAttribute('data-wew-page-uid') || '0', 10);
    if (pageUid > 0) host._copyPreviewLink(pageUid);
    return;
  }

  const publishButton = target.closest('[data-wew-publish]');
  if (publishButton) {
    event.preventDefault();
    host._publish();
    return;
  }

  const diffButton = target.closest('[data-wew-diff]');
  if (diffButton) {
    event.preventDefault();
    event.stopPropagation();
    const table = diffButton.getAttribute('data-wew-table') || '';
    const workspaceUid = parseInt(diffButton.getAttribute('data-wew-workspace-uid') || '0', 10);
    const item = (host.items || []).find((entry) => entry.table === table && entry.workspaceUid === workspaceUid);
    if (item) host._openDiffModal(item);
    return;
  }

  const discardButton = target.closest('[data-wew-discard]');
  if (discardButton) {
    event.preventDefault();
    event.stopPropagation();
    if (discardButton.getAttribute('data-wew-can-revert') !== '1') return;
    clearIframeHighlight(host);
    const table = discardButton.getAttribute('data-wew-table') || '';
    const workspaceUid = parseInt(discardButton.getAttribute('data-wew-workspace-uid') || '0', 10);
    const item = (host.items || []).find((entry) => entry.table === table && entry.workspaceUid === workspaceUid);
    if (item) host._confirmAndDiscard(item);
    return;
  }

  const editButton = target.closest('[data-wew-edit]');
  if (editButton) {
    event.preventDefault();
    event.stopPropagation();
    clearIframeHighlight(host);
    const row = editButton.closest('[data-wew-key]');
    const item = findItemByKey(host, row?.getAttribute('data-wew-key') || '');
    if (item?.editUrl || item?.contextualEditUrl) {
      host._openEditModal(item);
    } else {
      Notification.info(label(host, 'edit.title'), label(host, 'edit.noForm'));
    }
  }
}

function handleFilterKeydown(host, event) {
  const filter = event.target?.closest?.('[data-wew-filter]');
  if (!filter) return;
  const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
  if (!keys.includes(event.key)) return;
  event.preventDefault();
  const tabs = Array.from(host.querySelectorAll('[data-wew-mode]'));
  if (tabs.length === 0) return;
  const currentIdx = tabs.indexOf(document.activeElement);
  let nextIdx;
  switch (event.key) {
    case 'ArrowLeft': nextIdx = currentIdx <= 0 ? tabs.length - 1 : currentIdx - 1; break;
    case 'ArrowRight': nextIdx = currentIdx >= tabs.length - 1 ? 0 : currentIdx + 1; break;
    case 'Home': nextIdx = 0; break;
    case 'End': nextIdx = tabs.length - 1; break;
    default: return;
  }
  tabs[nextIdx].focus();
  tabs[nextIdx].click();
}

export { key };
