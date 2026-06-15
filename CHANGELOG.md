# Changelog

All notable changes to Easy Workspace are documented in this file.

## [Unreleased]

### Fixed

- The toolbar badge (and toolbar visibility) now update without a full page reload after content is added to the workspace. The toolbar listened for `typo3:pagetree:refresh` / `workspace:changed` / `workspaces:refresh` but not for `typo3:datahandler:process` — TYPO3's canonical "content changed" signal, fired by `ajax-data-handler.js` and re-dispatched across frames on the top document by `BroadcastService` — so add/hide/delete/move operations left the count stale. Adding that one event to the existing listener fixes it event-driven, with no polling. A single cheap safety net remains: the badge refreshes when the backend tab regains visibility (catches changes made in another tab or any signal missed while hidden).
- The toolbar item is now rendered as a hidden marker for any user who can use workspaces, even while they are in Live (`checkAccess()` no longer requires an active non-live workspace). This keeps the element in the DOM so it can be revealed and filled the moment the user enters or populates a workspace, instead of only appearing after a topbar re-render or full reload. Users with no workspace access still get nothing.
- `WorkspaceChangeInvalidationHook::processCmdmap_postProcess()` no longer fatals when DataHandler invokes it with `$pasteUpdate = false` (which core passes for every non-paste command, i.e. every publish and discard). Caught by the new functional test suite.
- After a discard ("Diese Änderung verwerfen"), edit-save, or history rollback, the Visual Editor preview no longer reloads back to the page header. The affected content element is re-centered in the viewport with a brief green confirmation flash; if it was removed (a new record discarded, a pending delete cancelled) the prior scroll position is restored instead of snapping to the top.
- The toolbar "show" button now reliably reveals a content element's Visual Editor icon bar: the `ve-drag-handle` toolbar is forced visible in the element's shadow root (synthetic mouse events do not trigger its CSS `:hover`), and restored on mouse-out.

### Added

- PHPUnit test infrastructure via `typo3/testing-framework` (`Build/phpunit/`, `Tests/Unit/`, `Tests/Functional/`): 50 unit tests covering `Value`, `PublishSelectionNormalizer`, `WorkspaceTablePolicy`, `BackendAccessGuard` and `ConfigurationProvider`, plus 6 functional tests running real DataHandler publish/discard round-trips (including permission-denial and foreign-workspace rejection) against sqlite.
- `Build/Scripts/runTests.sh` now supports `-s unit|functional|lint|phpstan|ci` and `-p <php-version>`; `composer test` runs the full chain.
- The CI matrix (PHP 8.2–8.5) executes lint, PHPStan, unit and functional suites as separate steps.

### Security

- All toolbar AJAX read endpoints (`items`, `has-changes`, `badge`, `diff`, `preview-link`) now verify backend page-show access (`readPageAccess`) for the requested page or news storage page before returning workspace data.
- The diff endpoint additionally restricts workspace rows to the user's active workspace (admins may inspect any workspace).
- History rollback now requires `tables_modify` permission and page access for the affected record, and failures are logged.
- Publish/discard run pre-flight permission checks (`tables_modify`, workspace membership) and pass the acting backend user to DataHandler explicitly; DataHandler remains the enforcement layer.
- The "Tests & diagnostics" module section (schema scan details, repair SQL) is now admin-only, both via module registration (`access: admin`) and a controller-side section guard.

### Changed

- The pending-items mode (`changed`/`all`) and toolbar context (`none`/`page`/`news`) are now native backed enums (`Enum\PendingItemsMode`, `Enum\ToolbarContext`) instead of loose string constants; all service signatures are type-safe and the AJAX wire format is unchanged. `PendingItemsService` lost ~70 lines of duplicated empty-payload/context plumbing in the process.
- The Visual Editor decline button builds its icon with `createElement` instead of `innerHTML`.
- Removed 10 orphaned labels (each from both `locallang.xlf` and `de.locallang.xlf`) that were no longer referenced by any template, service or JS module.
- New `Security\BackendAccessGuard` centralizes backend-user resolution (PSR-7 `backend.user` attribute first, `BE_USER` global only as fallback) and shared permission checks; scattered `$GLOBALS['BE_USER']`/`$GLOBALS['LANG']` access across controllers, services, middleware and the toolbar item was removed.
- `LocalizationService::resolveLabel()` replaces four copy-pasted per-service `getLanguageService()` helpers.
- `PublishSelectedService` validates workspace membership and resolves live uids in one query per table (previously one query per record) and derives workspace/soft-delete columns from the TCA schema (`TcaSchemaFactory`) instead of live database schema introspection.
- `WorkspaceTablePolicy::isAllowed()` results are memoized per request.
- Fluid templates render timestamps with Fluid 5.3 ICU patterns (`f:format.date pattern="…"`, request-locale aware) instead of pre-formatted PHP strings.
- Duplicated history-tab and rollback-button wiring in the backend module JS now reuses the shared implementations from `menu-modals.js`.
- The Visual Editor decline button label is localized (served via inline labels from the middleware) instead of hardcoded English.

### Accessibility

- Diff trigger buttons expose an `aria-label`; the module placeholder announces itself via `role="status"`.

## [1.2.9] - 2026-06-08

### Fixed

- Discard target resolution now checks TYPO3 workspace system fields (`t3ver_*`) against the database schema instead of normal TCA columns. This avoids falsely rejecting valid workspace rows such as `tt_content` with "record does not belong to the active workspace".

## [1.2.8] - 2026-06-08

### Fixed

- Discard now treats TYPO3 DataHandler as the source of truth for resolved workspace rows instead of failing on a preflight workspace-access check. Live UIDs are resolved by unique workspace overlay, and the service verifies that the workspace row was actually removed before reporting success.

## [1.2.7] - 2026-06-08

### Fixed

- Discard no longer rejects admin users before TYPO3 DataHandler runs when the AJAX bootstrap cannot resolve the workspace through Core's internal `checkWorkspace()` helper. The temporary DataHandler workspace state now carries the matching workspace record and is restored after the command.

## [1.2.6] - 2026-06-08

### Fixed

- Discard is now idempotent for stale toolbar requests: if the workspace row was already removed or the live record no longer has an accessible workspace overlay, the endpoint returns success instead of a misleading workspace mismatch error.

## [1.2.5] - 2026-06-08

### Fixed

- Discard now resolves posted live record UIDs to their unique accessible workspace overlay when the backend request has no reliable active workspace state.

## [1.2.4] - 2026-06-08

### Fixed

- Discard now resolves the target workspace from the workspace record itself and runs TYPO3 DataHandler inside that workspace context, so valid rows such as `tt_content#26304` are no longer rejected when request workspace state is stale or incomplete.

## [1.2.3] - 2026-06-08

### Fixed

- Discard and publish actions now resolve the active workspace from the backend user before running DataHandler commands, avoiding false "record does not belong to the active workspace" errors when Context and backend-user workspace state diverge.
- Discard confirmation and preview copy now explains the actual workspace outcome for modified, new, delete-placeholder, and move-placeholder records.

## [1.2.2] - 2026-06-08

### Fixed

- Toolbar footer now uses short action labels for all and partial selection states, avoiding truncated German helper text beside the publish button.

## [1.2.1] - 2026-06-08

### Fixed

- Toolbar dropdown selection now preserves explicit user choices across AJAX refreshes and badge polling. Choosing "Alle abwählen" no longer reselects all changed records after the next refresh.

## [1.1.1] - 2026-06-07

### Fixed

- Visual Editor saves now refresh the Easy Workspace toolbar reliably: resolve page context from backend module iframe URLs (`?id=`), force-refresh after `ve_saveEnded`, accept same-origin save signals when iframe discovery is briefly stale, and stop attaching save listeners inside the preview iframe (avoids interfering with Visual Editor postMessage).
- News context detection scans only Visual Editor / preview iframes, not every backend iframe (FormEngine modals no longer steal scope).

### Changed

- **Toolbar UI:** The dropdown menu is now a Lit component (`components/wew-toolbar-menu.js`, Visual Editor style) with light DOM so existing CSS keeps working. AJAX returns JSON item data only; labels still come from PHP via the `config` attribute.
- **Distribution:** GitHub/VCS install only; removed Packagist-oriented install instructions from README and manual. Package is not published on Packagist.

## [1.1.0] - 2026-06-06

### Changed

- News is scoped to a single article on its **detail view** (Visual Editor / preview page, or FormEngine edit form): the news record plus linked content elements, instead of scanning every news record on the selected page or folder. Driven by `tx_news_pi1[news]` in the preview iframe and the news edit-form URL; gated by `enableNewsBundles`.
- **Refactor:** Extracted `PublishSelectionNormalizer`, `ModuleSectionViewDataFactory`, and `EasyWorkspaceModuleDocHeaderBuilder`; added `PendingItemsService` context dispatchers (`toolbarCollectionForContext`, `hasChangesForContext`, `listForContext`). `EasyWorkspaceModuleController` reduced from ~736 to ~456 lines.
- **Documentation:** Rewrote `README.md` and the TYPO3 manual for the Fluid toolbar + glue JS architecture; added `Documentation/Contributing.rst` with layer model, thermo-nuclear review outcome, measured file-size inventory, watch list, and PR blocker checklist; removed screenshot assets and `Documentation/Screenshots.rst`.
- **Security:** Removed standalone `SECURITY.md`; security reporting and audit summary now live in the README and manual.

### Fixed

- Toolbar dropdown was rendered behind the module content iframe after switching into a workspace without reloading. The menu is now (re)converted to a native top-layer popover when its toolbar item is injected by a topbar re-render.

### Security (audit 2026-06-03)

- **Verified:** Table allow-list (`WorkspaceTablePolicy`), active-workspace checks before publish/discard cmdmaps, `enabled` / `enableRevert` / `enablePreviewLink` server-side gates, parameterized SQL, generic AJAX error messages, TYPO3 v14 `discard` command, stale workspace dependency listener, POST-only mutating routes, backend authentication and route tokens on all endpoints.
- **Accepted backend scope:** `items` / `hasChanges` / `diff` do not call `readPageAccess()` explicitly; TYPO3 backend session and record-level APIs still apply. Optional hardening: add page/news access checks before listing or diffing if your site requires stricter IDOR prevention than Core defaults.
- **Report vulnerabilities:** [GitHub Security Advisories](https://github.com/dirnbauer/typo3-webcon-easy-workspace/security/advisories/new) (private) — not public issues.

## [14.0.0] - 2026-05-24

### Added

- First stable TYPO3 14.3+ release of Easy Workspace.
- Backend toolbar dropdown and server-rendered Easy Workspace module for page-scoped workspace publishing.
- Publishing support for page records, content elements, inline child records, file references, file metadata, and optional EXT:news bundles.
- Checks and diagnostics submodule for workspace integrity issues and manual release risks.
- English and German XLIFF 2.0 labels for backend UI, diagnostics, and health-check output.

### Changed

- Composer metadata is the release source of truth for TYPO3 14.3+ classic-mode compatibility.
- Installation documentation now targets the tagged `^14.0` release.

### Security

- Publishing and discard actions use TYPO3 backend routes, route tokens, and TYPO3 DataHandler commands.
- Workspace dependency handling ignores stale references only after verifying missing source or target records.
