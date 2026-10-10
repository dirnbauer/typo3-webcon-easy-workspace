/**
 * Bootstrap-dropdown / native-popover plumbing for the toolbar menu.
 *
 * Core renders toolbar dropdowns with Bootstrap. The menu is converted to a
 * native top-layer popover so it is never covered by the module iframe;
 * both variants are supported for open-state checks and closing.
 */
export function toolbarHost(host) {
  return host.closest('[id^="typo3-cms-backend-backend-toolbaritems"]') || host.closest('.toolbar-item');
}

export function ensurePopoverDropdown(host, toggleEl, menu) {
  if (!toggleEl || !menu) return;
  if (toggleEl.hasAttribute('popovertarget') || !toggleEl.hasAttribute('data-bs-toggle')) {
    return;
  }
  if (!menu.id) {
    menu.id = `wew-toolbar-menu-${Math.random().toString(36).slice(2, 10)}`;
  }
  menu.setAttribute('popover', '');
  (toggleEl.closest('.dropdown') || toggleEl.parentElement || host)?.classList.add('dropdown');
  toggleEl.setAttribute('popovertarget', menu.id);
  for (const attr of [
    'data-bs-toggle', 'data-bs-target', 'data-bs-offset', 'data-bs-auto-close',
    'data-bs-reference', 'data-bs-display', 'data-bs-boundary',
    'aria-haspopup', 'aria-expanded',
  ]) {
    toggleEl.removeAttribute(attr);
  }
}

export function isDropdownOpen(host) {
  const menu = host?.closest?.('.dropdown-menu');
  if (!menu) return false;
  try {
    if (menu.matches(':popover-open')) return true;
  } catch { /* :popover-open unsupported */ }
  return menu.classList.contains('show');
}

export async function closeDropdown(host) {
  const menu = host.closest('.dropdown-menu');
  const toggleEl = toolbarHost(host)?.querySelector('.dropdown-toggle');
  try {
    if (menu?.matches(':popover-open')) {
      menu.hidePopover();
      toggleEl?.focus();
      return;
    }
  } catch { /* popover unsupported */ }
  if (toggleEl && menu?.classList.contains('show')) {
    try {
      const { Dropdown } = await import('bootstrap');
      Dropdown.getOrCreateInstance(toggleEl).hide();
    } catch {
      toggleEl.click();
    }
    toggleEl.focus();
  }
}

/**
 * Keep the dropdown alive across a Core modal opened from it.
 *
 * Core's Modal opens with `dialog.showModal()`, which hides every popover —
 * the dropdown is one — and when the modal closes, the dialog's own focus
 * return fails on the hidden control. Once the modal is gone the dropdown
 * opens again (without the list refresh opening it normally triggers) and
 * focus goes where the action asked for (host.focusAfterRender()), else
 * back to the control that opened the modal, else to the toolbar button.
 * While the action's request is still pending (its list has not arrived),
 * focus waits on that control too: the list moves it on, and a request that
 * fails leaves it there instead of on the page body.
 *
 * `wasOpen` names the state before a chain of modals (one closing to open
 * the next); the returned `cancel()` drops the reopen of a modal whose
 * successor takes over. `refresh` asks for the list once the modal is gone:
 * for a modal that changes records without telling (Core's plain record
 * form reports no save), so the dropdown never reopens on a stale list.
 */
export function keepOpenAcrossModal(host, modal, opener = null, { wasOpen = isDropdownOpen(host), refresh = false } = {}) {
  const handle = { wasOpen, cancel() { this.cancelled = true; }, cancelled: false };
  const menu = host?.closest?.('.dropdown-menu');
  if (!menu || !modal || typeof modal.addEventListener !== 'function' || !wasOpen) return handle;
  modal.addEventListener('typo3-modal-hidden', () => {
    if (handle.cancelled || !host.isConnected) return;
    if (!isDropdownOpen(host)) {
      try {
        // The toggle event of this reopen must not refresh the list again.
        host._reopeningAfterModal = true;
        menu.showPopover();
      } catch {
        host._reopeningAfterModal = false;
        return;
      }
    }
    if (refresh) host._refreshList?.('modal-closed');
    if (host._focusAfterRender) host._restoreFocus?.();
    const doc = host.ownerDocument;
    const active = doc?.activeElement;
    const bar = toolbarHost(host);
    if (!active || active === doc.body || !(host.contains(active) || bar?.contains(active))) {
      (opener?.isConnected && host.contains(opener) ? opener : bar?.querySelector('.dropdown-toggle'))?.focus();
    }
  }, { once: true });
  return handle;
}

/** The control inside the dropdown that has focus (what opened a modal); null otherwise. */
export function focusedControl(host) {
  const active = host?.ownerDocument?.activeElement;
  return active && active !== host && host.contains?.(active) ? active : null;
}
