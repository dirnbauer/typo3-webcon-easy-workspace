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
  const menu = host.closest('.dropdown-menu');
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
