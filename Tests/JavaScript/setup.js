// Globals the toolbar modules expect from the TYPO3 backend page.
globalThis.TYPO3 = {
  settings: {
    ajaxUrls: {
      webcon_easy_workspace_items: 'https://backend.test/typo3/ajax/webcon-easy-workspace/items?token=t',
      webcon_easy_workspace_badge: 'https://backend.test/typo3/ajax/webcon-easy-workspace/badge?token=t',
      webcon_easy_workspace_publish: 'https://backend.test/typo3/ajax/webcon-easy-workspace/publish?token=t',
      webcon_easy_workspace_preview_link: 'https://backend.test/typo3/ajax/webcon-easy-workspace/preview-link?token=t',
      webcon_easy_workspace_discard: 'https://backend.test/typo3/ajax/webcon-easy-workspace/discard?token=t',
      webcon_easy_workspace_diff: 'https://backend.test/typo3/ajax/webcon-easy-workspace/diff?token=t',
      webcon_easy_workspace_history_rollback: 'https://backend.test/typo3/ajax/webcon-easy-workspace/history-rollback?token=t',
    },
  },
};

if (typeof globalThis.CSS === 'undefined' || typeof globalThis.CSS.escape !== 'function') {
  globalThis.CSS = { ...(globalThis.CSS || {}), escape: (value) => String(value).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`) };
}
