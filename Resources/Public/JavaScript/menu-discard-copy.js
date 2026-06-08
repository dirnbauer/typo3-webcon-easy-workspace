export function discardMessageKey(itemOrKind) {
  return `discard.modal.message.${normalizeDiscardKind(itemOrKind)}`;
}

export function discardSuccessMessageKey(itemOrKind) {
  return `discard.success.message.${normalizeDiscardKind(itemOrKind)}`;
}

export function discardTagTitleKey(itemOrKind) {
  return `discardTag.title.${normalizeDiscardKind(itemOrKind)}`;
}

export function discardTagSubtitleKey(itemOrKind) {
  return `discardTag.subtitle.${normalizeDiscardKind(itemOrKind)}`;
}

function normalizeDiscardKind(itemOrKind) {
  const rawKind = typeof itemOrKind === 'string'
    ? itemOrKind
    : itemOrKind?.kindKey;
  switch (String(rawKind || '').toLowerCase()) {
    case 'new':
    case 'created':
      return 'new';
    case 'delete':
    case 'deleted':
    case 'removed':
      return 'delete';
    case 'move':
    case 'moved':
      return 'move';
    default:
      return 'modified';
  }
}
