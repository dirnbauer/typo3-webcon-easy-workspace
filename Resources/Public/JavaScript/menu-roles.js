/**
 * What a list item is, as the server classified it (`role`).
 *
 * An edit is a "change": counted, selectable and published. A version TYPO3
 * only made along with an edit that is no longer pending is a "copy": listed
 * in the cleanup section, never counted or published. The translations TYPO3
 * copies along with an edit are no items at all; they travel on the edited
 * item as `companions`.
 *
 * A server without `role` (an older payload, a cached list) falls back to
 * the 1.10 content flag: every version counts unless it says it carries no
 * content change.
 */
export function hasContentChange(item) {
  if (item?.role) return item.role === 'change';
  return Boolean(item?.isChanged) && item.contentChanged !== false;
}

/** An edit row (see hasContentChange). */
export function isChangeRow(item) {
  return hasContentChange(item);
}

/** A leftover copy for the cleanup section. */
export function isCopyRow(item) {
  return item?.role === 'copy';
}
