export const ENDPOINTS = {
  items: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_items || '',
  badge: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_badge || '',
  publish: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_publish || '',
  previewLink: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_preview_link || '',
  discard: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_discard || '',
  diff: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_diff || '',
  historyRollback: TYPO3.settings.ajaxUrls?.webcon_easy_workspace_history_rollback || '',
};

// Cross-tab refresh channel (same origin). Messages are
// `{ type: 'refresh', reason, workspaceId, stamp, instanceId, contextKey, payload }`;
// a tab on the same page adopts `payload` instead of asking the server.
export const CHANNEL_NAME = 'webcon-easy-workspace';

// Badge synchronisation. Event-driven only — no poll, no focus or
// visibility trigger (see BadgeSync). Every trigger funnels through one
// debounce, so a burst of save signals becomes one request.
export const BADGE_DEBOUNCE_MS = 120;
// After a page-tree or module-state navigation, how long to wait for the
// module to finish loading (its `typo3-module-loaded` asks at once) before
// asking anyway — the Visual Editor changes pages inside its own frame.
export const BADGE_NAVIGATION_SETTLE_MS = 1500;

// Core events after which the count may have changed: a save, a publish,
// a workspace switch, or a module that finished loading. That last one
// matters most: a classic FormEngine save posts the whole form inside the
// iframe, so it emits no DataHandler event and no BroadcastChannel message.
// The iframe load is the only signal there is.
//
// Listened to on the top document, on the toolbar's own one and — see
// BadgeSync.attachFrame() — inside the module iframe.
export const CHANGE_EVENTS = Object.freeze([
  'typo3:datahandler:process',
  'typo3:pagetree:refresh',
  'typo3:workspace:changed',
  'typo3:workspaces:refresh',
  'typo3-module-loaded',
]);

// Core events that mean "the editor selected something": they only cause a
// request when the page or news article actually changed.
export const NAVIGATION_EVENTS = Object.freeze([
  'typo3:module-state-storage:update:web',
  'typo3:module-state-storage:update-with-tree-identifier:web',
]);

// Core's module iframe. Events dispatched on *its* document (Core's own
// modules use `document`, not `top.document`) never reach the toolbar, so
// BadgeSync re-attaches to it whenever a module finishes loading.
export const MODULE_IFRAME_SELECTOR = '#typo3-contentIframe';

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

