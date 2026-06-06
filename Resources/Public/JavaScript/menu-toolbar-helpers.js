import { label, configBool } from './menu-context.js';
import { isLocatable, isEditable } from './menu-preview-locate.js';
import { key } from './menu-selection.js';

export { key };

export function changedItemCount(items) {
  return Array.isArray(items) ? items.filter((item) => item.isChanged).length : 0;
}

export function footerState(host) {
  const changeable = (host.items || []).filter((item) => item.isChanged);
  const total = changeable.length;
  const selectedCount = host.selection?.size ?? 0;
  const allChecked = total > 0 && selectedCount === total;
  const someChecked = selectedCount > 0 && selectedCount < total;
  return { total, selectedCount, allChecked, someChecked };
}

export function diffTitle(host, item) {
  if (item.kindKey === 'new' && item.historyDiffCount > 0) {
    return label(host, 'diff.title.newWithChanges');
  }
  if (item.kindKey === 'new') {
    return label(host, 'diff.title.newDetails');
  }
  if (item.kindKey === 'delete') {
    return label(host, 'diff.title.removal');
  }
  if (item.kindKey === 'move') {
    return label(host, 'diff.title.move');
  }
  if (item.kindKey === 'modified') {
    return label(host, 'diff.title.changed');
  }
  return label(host, 'diff.title.history');
}

export function rowClasses(item) {
  const classes = ['wew-list__row'];
  if (item.isPrimary) classes.push('wew-list__row--primary');
  classes.push(item.isChanged ? 'wew-list__row--changed' : 'wew-list__row--unchanged');
  if (item.isHidden) classes.push('wew-list__row--hidden');
  return classes.join(' ');
}

export function canRevert(host, item) {
  return configBool(host, 'enableRevert') && item.isChanged;
}

export function editUrl(item) {
  return item.contextualEditUrl || item.editUrl || '';
}

export function panelHasItems(groups) {
  if (!Array.isArray(groups)) {
    return false;
  }
  return groups.some((group) => Array.isArray(group.items) && group.items.length > 0);
}

export function itemIsLocatable(host, item) {
  return isLocatable(host, item);
}

export function itemIsEditable(item) {
  return isEditable(item);
}

export function findItem(host, table, workspaceUid) {
  return (host.items || []).find(
    (entry) => entry.table === table && entry.workspaceUid === workspaceUid,
  ) || null;
}

export function findItemByKey(host, itemKey) {
  return (host.items || []).find((item) => key(host, item) === itemKey) || null;
}
