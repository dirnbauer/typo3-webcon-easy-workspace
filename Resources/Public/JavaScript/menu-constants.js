export const ENDPOINTS = {
  items: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_items || '',
  badge: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_badge || '',
  publish: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_publish || '',
  previewLink: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_preview_link || '',
  discard: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_discard || '',
  diff: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_diff || '',
  historyRollback: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_history_rollback || '',
};

// Cross-tab / cross-frame refresh channel (same origin). Messages are
// `{ type: 'refresh', reason, workspaceId, stamp, instanceId }`.
export const CHANNEL_NAME = 'webcon-easy-workspace';

// Badge synchronisation timing. Every trigger funnels through one debounce;
// the poll only runs while the tab is visible and backs off after errors.
export const BADGE_DEBOUNCE_MS = 120;
export const BADGE_POLL_INTERVAL_MS = 45_000;
export const BADGE_POLL_JITTER_MS = 5_000;
export const BADGE_POLL_BACKOFF_MS = 300_000;
export const BADGE_ERROR_BACKOFF_THRESHOLD = 3;

// Core document events that indicate a record or the navigation context
// changed. Listened to on the top document and on the toolbar's own one.
export const REFRESH_EVENTS = Object.freeze([
  'typo3:datahandler:process',
  'typo3:pagetree:refresh',
  'typo3:workspace:changed',
  'typo3:workspaces:refresh',
  'typo3:module-state-storage:update:web',
  'typo3:module-state-storage:update-with-tree-identifier:web',
]);

// Fallback defaults — overridden by the TSconfig-driven JSON the
// toolbar item attaches via the `config` attribute on this element.
export const DEFAULT_CONFIG = Object.freeze({
  enabled: true,
  enableWorkspaceChip: true,
  enablePreviewLink: true,
  enableThumbnails: true,
  enableTypeLabels: true,
  enableHiddenBadge: true,
  showHidden: true,
  maxItems: 200,
  enableNewsBundles: true,
  enableHoverHighlight: true,
  enableRevert: true,
  userEnabled: true,
  showSubelementsInToolbar: false,
  showSubelementsInModule: true,
  // Runtime-detected environment (NOT user-configurable). Set by
  // EasyWorkspaceToolbarItem::getDropDown().
  activeWorkspaceId: 0,
  pageUid: 0,
  newsUid: 0,
  hasVisualEditor: false,
  hasViewpage: false,
  moduleIdentifier: 'webcon_easy_workspace_pending',
  moduleUrl: '',
  labels: {
    'error.noPublishableRecords': 'No publishable records in selection.',
  },
});

// Inline highlight styles applied to the iframe element. Hard-coded
// colors because the iframe document has its own CSS scope and v14
// backend custom properties don't propagate there.
export const IFRAME_HIGHLIGHT_STYLE = {
  outline: '3px solid #4a90e2',
  outlineOffset: '2px',
  boxShadow: '0 0 0 6px rgba(74, 144, 226, 0.22)',
  transition: 'outline 0.15s ease, box-shadow 0.15s ease',
  scrollMarginTop: '40px',
  scrollMarginBottom: '40px',
  // Faint background tint while hovered so the entire CE area is obvious.
  backgroundColor: '',
};
