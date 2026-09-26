# Changelog

All notable changes to Easy Workspace are documented in this file.

## [Unreleased]

### Fixed

- Discarding a selection that holds records together with records that
  depend on them no longer fails in the Development context, in this
  module's batch actions and in Core's Workspaces module alike. TYPO3 v14's
  workspaces CommandMap rebuilds such a batch and gives each record an
  empty `version` command next to its `discard`; the workspaces hook then
  reads a missing `action` ("Undefined array key "action"", an exception
  in Development). The new DiscardDependencyCommandHook, registered after
  Core's, drops the empty commands, so the discards run as they already do
  in Production. BatchDiscardTest pins the Core behaviour: when it starts
  to fail, Core handles the map itself and the hook can go.

## [1.9.1] — 2026-09-26

A more compact dropdown: the same information in about half the height.

### Changed

- The header takes two lines: workspace icon, name, stage and two icon
  buttons (open the module, refresh), then the numbers. The "Workspace"
  eyebrow is gone, and "Open module" moved from its own footer row into
  the header.
- The footer is one row: select-all shows `selected/total` (the full
  sentence is its accessible name and hover title), then Preview and
  Publish.
- Rows are tighter (22px icons, one ellipsised meta line), a page group's
  title and path share one line, and the language headers are single lines.
  The other-languages hint is one short line ("Not shown in German ·
  published with the rest"); the full explanation is on hover and read to
  screen readers.
- With "Show subelement details in the toolbar menu" on, a row names its
  related changes on one line instead of one line per record; the full list
  with each change type is on hover.

## [1.9.0] — 2026-09-25

The dropdown tells the changes of the language the editor is looking at
from the ones of other languages, reads as one page instead of a stack of
chips, and asks the server a lot less.

### Added

- **Languages.** Every row carries its language. The list is split into the
  rows the page shows in the editor's current view — the languages selected
  in the page module or the Visual Editor, read from core's module data —
  and, set apart under their language, the rows the page only shows after
  a language switch. Those stay selected and publish with the rest; the
  section says why they are not to be seen. In a module without a language
  (a record list) every row is listed, the rows of other languages with a
  flag chip. `/items` takes `module` (the identifier of the module in the
  content frame) and returns `languages` (the site's) and `viewLanguages`.
- A collection item or file reference listed on its own names the element
  it belongs to ("in …"), from core's reference index.

### Changed

- **Header.** The workspace name is the title, one sentence sums up the
  numbers ("3 changes on this page · 12 more elsewhere in the workspace"),
  the stage is a labelled badge.
- **Rows.** Change types are core badges (as in the module), actions are
  core's borderless buttons, the empty and error states use core icons; no
  own pills, frames, mask gradients or shimmer.
- The type line of a Content Blocks collection row shows the table's title
  instead of the raw type value.
- `/items` returns the items once, in what the dropdown renders; the
  module's groups and the per-record change lists with their URLs stay with
  the module (a page of five changes: 8 KB instead of 50 KB).

### Performance

- **One core scan per revision.** `WorkspaceService::selectVersionsInWorkspace()`
  runs for the whole workspace once per editor and revision; the workspace
  total, a page's count and a page's list are taken from that scan (a page's
  rows are the subset core selects for the page: the page the version lives
  on, the page record and its translations, root-level records of tables
  that ignore the root-level restriction). A page's nested tree is cached
  as well. Lab, page with 10 changes in four languages: badge after a write
  1,801 → 1,332 queries (about 550 → 450 ms), list on open 1,044 → 280
  queries (286 → 130 ms); a cached badge stays at 6 queries.
- The list's per-row history reads the change log once without formatting
  or diffing every field of every entry (`RecordHistoryTimelineService::summary()`),
  and backend user names are read once per request.

## [1.8.0] — 2026-09-25

Easy Workspace now shows the Workspaces module's data instead of collecting
its own. The two disagreed: a change that only touched a collection item or
a file reference of an unchanged element — a new picture in a Desiderio
feature item, say — was missing from the workspace count, so the header chip
said "nothing pending" on every other page while the change waited to be
published.

### Changed

- **Changes come from core.** `WorkspaceService::selectVersionsInWorkspace()`
  finds the workspace's versions with the editor's table and page
  permissions, and `CollectionService` nests the records that depend on
  another one below it — the Workspaces module's own code path
  (`CoreWorkspaceChanges`). The badge, the dropdown and the module list the
  module's rows; the header's total is the module's count.
  `WorkspacesModuleParityTest` checks both against the module's grid.
- A changed collection item or file reference of an **unchanged** element is
  a row of its own, as in the module, instead of being shown under its
  element. A changed element still carries its changed children.
- A version whose fields equal the live record's is listed, as in the
  module. It used to be hidden as "no editor-visible change".
- Records stored on the page that are neither pages nor content elements
  (a news record in a page, for instance) are listed for that page.
- A move is listed on the page the record was moved to.
- Publish, discard, diff and history accept every workspace-aware table; the
  editor's rights are checked by DataHandler and core.
- The whole-workspace count and the page summary are cached per editor,
  because core's list depends on the editor's permissions.

### Removed

- The own collection and counting: `InlineChildResolver`,
  `WorkspaceVersionPresence`, the page and news scopes, the standalone-row
  lookups, `WorkspaceVersionConstraint` and the per-table count queries
  (3,400 lines).

### Performance

Core asks every workspace-aware table, and a Content Blocks installation has
hundreds. On the lab (Desiderio home page) an uncached badge takes 150 to
460 ms and the list 180 to 250 ms, against about 30 ms with 1.7.3's own
queries; a cached badge takes 3 to 9 ms. The cache is valid until the next
DataHandler write in the workspace.

## [1.7.3] — 2026-09-24

Editing in the Visual Editor was slow on production because of this
extension: every badge request of a Content Blocks page took 2.3 s of server
time, and while an editor worked the toolbar sent one every three to four
seconds per open tab — enough to keep one of six PHP workers busy for good.

### Fixed

- **A page count cost 15,000 queries.** Content Blocks defines every
  collection field as a base `tt_content` column, so each element carries a
  few hundred inline fields whatever its CType, and each of them was asked
  for changed children, per element — 15,393 queries and 2.3 s for the badge
  of a 40-element page without a single change; the list (`/items`) cost the
  same. The collection now resolves the field configuration once per type,
  fetches the children of all elements with one query per relation, skips
  every table that holds no row of the workspace (one `UNION ALL` finds
  those), overlays only rows that have a version, and does not build items
  for rows that cannot be listed. On that page: badge 30 queries and ~30 ms
  (6 ms from the cache), list ~35 ms. Results are unchanged — a new snapshot
  test pins every list, group and count of a scenario with edits,
  new/delete/move placeholders, translations, hidden elements, discarded
  drafts, collection children and file references to what 1.7.2 returned,
  and a comparison of 115 payloads (2,628 rows) from the production copy
  found no difference.
- `TcaUtility::table()` copied the whole `$GLOBALS['TCA']` on every call and
  `hasColumn()` all columns of the table; on a Content Blocks site that was
  most of the remaining PHP time. Both look the entry up directly.
- The whole-workspace count scanned `tt_content` (80 ms on 940 columns): no
  index starts with `t3ver_wsid`. The workspace condition is now phrased for
  Core's `(t3ver_oid, t3ver_wsid)` index (0.4 ms); IN lists stay below
  MySQL's `eq_range_index_dive_limit` for the same reason.
- The badge stamp did not move when a collection item, a file reference or
  file metadata was edited, so other tabs were not told and an open list did
  not refresh. A DataHandler hook now moves a per-workspace revision (in
  `sys_registry`) after every write to a workspace-aware table, and the stamp
  includes it.

### Changed

- **The badge no longer polls.** It asks the server when something was saved
  (Core DataHandler, page-tree and workspace events, a FormEngine save, a
  Visual Editor save, a module finishing to load), when the editor moves to
  another page or news article, and when another tab of the browser reports
  a change — never on a timer, on focus or on tab visibility. Changes made by
  other editors, the CLI or an MCP client appear at the next navigation or
  save in the open backend, not by themselves.
- One request at a time: every trigger goes through the debounce, at most
  one request is in flight per tab, and signals arriving meanwhile become a
  single follow-up — a Visual Editor save (several signals) costs one
  request. A scripted five-minute editing session now makes 13 requests;
  1.7.2 made 98.
- While the dropdown is open, a refresh fetches the list instead of
  `/badge`; the `/items` response now carries the same `badge` block, so
  list and count come from one request. The list is no longer fetched in the
  background on every navigation.
- The badge payload has `records` — the changed rows of the page — and the
  Visual Editor's decline buttons are drawn from it instead of from the list.
- A tab that noticed a change hands its answer to the other tabs over the
  `BroadcastChannel`; a tab on the same page applies it without asking the
  server. The Visual Editor preview script and the backend module no longer
  post to the channel themselves (each made every open tab ask the server);
  the preview bridges VE's `ve_saveEnded`, which VE sends only into its
  preview frames, as one message to the toolbar of its own tab.
- The page count is cached per page under the workspace stamp (cache
  `webcon_easy_workspace`, file backend, group `system`), so module switches
  and returning to a page cost a cache read.

### Added

- `Tests/Functional/Fixtures/Extensions/inline_stub` (a Content Blocks-shaped
  `tt_content` with 26 inline fields), `PendingItemsServicePageSnapshotTest`,
  `PageCollectionCostTest` (query counts through a Doctrine driver
  middleware), `WorkspaceRevisionTest`; vitest for the trigger matrix and the
  five-minute session.

## [1.7.2] — 2026-09-23

### Fixed

- The Visual Editor's decline button never loaded: its middleware read the
  backend user from a `backend.user` request attribute, which TYPO3 never
  sets. It now asks `BackendAccessGuard`, which falls back to
  `$GLOBALS['BE_USER']`, where the frontend authenticator puts the user.
  New functional tests build the request as TYPO3 does (no attribute) and
  cover workspace, live, no edit mode and no backend user.

## [1.7.1] — 2026-09-23

### Removed

- The embedded copy of the Workspace ChatOps extension (`Extensions/webcon_workspace_chatops`) and its unit test. It was a June 2026 snapshot that Composer never installed; the extension lives on as the separate `webconsulting/webcon-mcp-chat-bridge` package. The three later changes made to the copy here (input normalization with the `Value` utility, the 14.3.6 constraint, `#[\Override]` attributes) were handed over to that repository on the branch `import-from-easy-workspace`, with their history, before the removal.
- The copy's entries in `composer.json` (`autoload-dev`, `lint`), `phpstan.neon` and `.php-cs-fixer.dist.php`.

## [1.7.0] — 2026-09-23

### Fixed

- **The selection checkboxes in the toolbar dropdown were invisible.** Core's `.form-check-input` takes its box, border and check mark from tokens that only a `.form-check` wrapper defines; the dropdown used the input bare, so rows and "Select all" showed an empty gap. Both now use Core's `.form-check` markup.
- The dropdown's error state painted its message in the danger *badge* text colour — white on the light surface. It uses `--typo3-text-color-danger`.
- The row actions (changes, discard, show in preview) could not be reached without a mouse: they had `tabindex="-1"` and no key led to them. The active row's buttons now join the tab order, and ArrowRight/ArrowLeft step into and out of them.
- The module's selection summary reached the page as raw ICU (`{count, plural, …}`) until the script replaced it; the template passed a positional argument where the message needs `count`.
- Publish, review and discard messages in the module are rendered by Core's `Module` layout again (with icons and Core's markup), instead of being flushed by the controller and redrawn as bare Bootstrap alerts.

### Changed

- A selected row keeps only a light tint; with every changed row preselected, the old full tint turned the list into one coloured block. Without anything to select, the footer hides "Select all" and "Publish N" instead of showing them disabled.
- No hard-coded colour is left: `tokens.css` lost its hex fallbacks, and the outline, confirmation flash and discard tag drawn into preview frames use the backend's primary, success and danger colours, resolved in the toolbar's document and handed over as computed values.
- German labels say "Workspace" throughout (seven still said "Arbeitsbereich").
- PHP 8.4 idioms: typed class constants, `new Foo()->bar()`, `array_any()`, first-class callables, and `#[\Override]` on every implementing/overriding method (PHPStan now enforces it). The module's three sections are a `ModuleSection` enum instead of a string list, a map and two `match` blocks.
- Development: vitest 5, jsdom 30, Playwright 1.63, PHPStan ^2.2, testing-framework ^9.7; CI uses actions/checkout and setup-node v7 and Node 24; `.gitattributes` keeps development files out of the Composer archive.

### Added

- Functional test for the module controller (rendered through `ModuleTemplate`: heading, checkboxes, flash messages, formatted summary, records section) and JavaScript tests for the keyboard handling and the footer and row templates.

### Removed

- The Git tags `v14.0.0`–`v14.0.2` and the GitHub release "Easy Workspace 14.0.0". They predate the renumbering to 1.x and made Composer report 14.0.2 as the newest version.

## [1.6.1] — 2026-09-19

### Fixed

- Resolving a record's type label no longer goes through `BackendUtility::getLabelFromItemlist()`, deprecated for removal in TYPO3 v15. The functional suite fails on deprecations, and 1.6.0's new tests were the first to cover that code path, so the release tag went out red. The call is now `SchemaLabelResolver->getLabelForFieldValue()`, injected rather than fetched statically. Core's method was a thin forwarder to exactly that call, so behaviour is unchanged except that the record row is passed along, which lets an itemsProcFunc-driven type field resolve where the forwarder's empty default could not.

**Use 1.6.1, not 1.6.0** — same features, green gates.

## [1.6.0] — 2026-09-19

### Fixed

- **Workspace probes and soft-delete filters were dead code on TYPO3 v14.** `t3ver_wsid`, `t3ver_oid`, `deleted` and `tstamp` are no longer TCA `columns` in v14 — they are schema capabilities — so every guard written as `TcaUtility::hasColumn($table, 't3ver_wsid')` evaluated to false forever. Consequences: `/has-changes` reported "no changes" for every page and every news article; standalone `sys_file_metadata` drafts were never listed; and each `deleted = 0` constraint that sat behind the same check was silently dropped, so discarded (soft-deleted) drafts counted as pending. A new `RecordSchemaInspector` answers these questions through `TcaSchemaFactory`, and `WorkspaceRecordQuery`, `InlineChildResolver` and `WorkspaceDiagnosticsService` use it. `Tests/Functional/Service/RecordSchemaInspectorTest` pins the trap so the old form cannot come back.
- A news article's content elements no longer claim a backend layout column. They are addressed through `tx_news_related_news`, not through a layout, so their `colPos` is a leftover — it produced a meaningless "Column 0" in the meta line and split one article's elements into per-column groups.

### Added

- `Tests/Functional/Service/PendingItemsServiceNewsTest` covers a news article with several content elements end to end: all of them are listed (changed and, in All mode, unchanged), all reach the publish selection, the count matches, discarded drafts are excluded, and an article without content elements still lists its own record. The `news_stub` test extension gained the real `content_elements` inline relation so the relation is actually exercised.

### Changed

- The toolbar badge counts the current page or news article instead of the whole workspace. Editors read the badge as "what is pending here", so a workspace-wide number made every page look busy. The server now returns both counts (`contextCount` next to `changedCount`); the dropdown header keeps naming both as "N on this page · M elsewhere", and the badge falls back to the workspace total only where no page can be resolved at all (a module outside the Web group). `publish` and `discard` post their page/news context so the badge they echo back is already scoped.
- The dropdown is smaller: 440 px instead of 540 px wide (min-width 320 px), max-height 62 vh, tighter header, rows, footer and empty state, and the decorative extension tile in the header is gone. The toolbar icon — two offset squares with an arrow, for "a workspace version being pushed to Live" — now carries its own tooltip, so it no longer has to be guessed.
- Row actions (edit, changes, discard, show in preview) are always visible and drawn as buttons with a border. They used to fade in on hover, which hid from an editor what a row can do until they pointed at it.
- The selection checkbox in front of a row is bigger, uses the backend's accent colour and has a real hit area, and clicking anywhere on a row that is not a button toggles it.

## [1.5.0] — 2026-09-19

### Fixed

- The badge is right after a FormEngine save inside the module iframe. A classic save posts the whole form in the iframe, so Core emits neither a DataHandler event nor a `BroadcastChannel` message — the count stayed stale until the next poll (up to 45 s). `BadgeSync` now also listens for `typo3-module-loaded`, which every module and every iframe reload raises in the top document.
- The badge is right after an action inside a Core module that dispatches its events on its own `document` (for example publishing or discarding in the Workspaces module). `BadgeSync` attaches the same listeners inside the module iframe and re-attaches them on every module load.
- The badge no longer blinks to empty on every backend page load, and a failing `/badge` request can no longer blank it or hide the toolbar item: the count is rendered into the toolbar markup by the server (`data-wew-count`, `data-wew-workspace`) and `BadgeSync.seed()` adopts it before the first request.
- A change one tab noticed is announced to the others. Only the extension's own publish and discard posted on the `BroadcastChannel`, so a save Core announces locally left every other tab waiting for its 45 s poll. A tab that already holds the new stamp ignores the message, so it cannot bounce back and forth.
- In Live the toolbar item is no longer briefly visible before the script runs — the server ships `webcon-easy-workspace-toolbar--live` (`display: none`), and `BadgeSync` keeps it in sync with the `hidden` property.

### Added

- `Tests/E2E` — a Playwright scenario against a running installation, one reproduction per stale-count report: markup count, FormEngine save in the module iframe (and in a second tab), dashboard/Records/file list, module navigation without a page reload, Live and back, a change by another actor, discarding in Core's Workspaces module and, opt-in, publishing from the dropdown. `dropdown.spec.js` covers light/dark, keyboard navigation, accessible names and the loading/empty/error states, and writes the screenshots. Environment variables are documented in `Documentation/Testing.rst`; `npm run test:e2e`.
- `Tests/Functional/Backend/EasyWorkspaceToolbarItemTest` for the server-rendered count, plus vitest coverage for the seed, the module-load trigger and the in-frame listeners.

### Changed

- PHPStan configuration moved to `phpstan.neon` in the repository root and gained `phpstan/phpstan-phpunit`. `composer phpstan` needs no `--configuration`.

## [1.4.0] — 2026-09-12

### Fixed

- The toolbar badge now always shows the right number. Its count comes from one place — `WorkspaceChangeCounter` runs a single `COUNT(*)`/`MAX(tstamp)` aggregate per counted table for the user's active workspace — instead of being written by both the list refresh and the badge request. Outside page/news contexts (dashboard, list, file and records modules, other tabs) the badge no longer drops to 0: it counts the whole workspace, like the Workspaces module.
- Saves are picked up from every source: Core DataHandler broadcasts on the top frame, FormEngine save messages, Visual Editor saves (the preview script announces them on a `BroadcastChannel`), the backend module frame, other browser tabs, and — for CLI, MCP and other users' edits — a jittered 45 s poll while the tab is visible (paused when hidden, backing off to 5 min after three consecutive errors). Iframe enumeration at bind time is gone.
- List and badge refreshes no longer race: a request-id guard drops stale responses, every trigger goes through one 120 ms debounce, and the server `stamp` decides whether an open dropdown re-fetches its list.
- The toolbar item is hidden only when the server reports workspace `0`, not when the config attribute fails to parse.
- `/badge` and `/has-changes` no longer materialise the pending-items collection just to count.

### Added

- `Classes/Service/WorkspaceChangeCounter` and the `WorkspaceChangeCount` DTO (`total`, `byTable`, `byState`, `stamp`); `/badge`, `/has-changes` and the `badge` block of publish/discard responses share the payload `{context, workspaceId, workspaceTitle, changedCount, byTable, byState, latestChangeAt, stamp}`.
- Redesigned toolbar dropdown: workspace, stage and count chips ("N on this page · M elsewhere"), refresh button, page group header with icon and rootline path, rows with record icon, change-type pill, author and relative time, hover actions (edit, changes, discard, preview), sticky footer with select-all, `Publish N`, a Preview split button (open / copy link) and an "Open module" link. Empty, loading-skeleton and error states; `role="dialog"`, `role="list"`, roving tabindex (Arrow keys), Space selects, Enter edits, Escape closes; badge pulse on increment, row enter/exit transitions, `prefers-reduced-motion` honoured; dark mode through TYPO3 backend tokens.
- Icon set in the TYPO3 v14 line-art style: `wew-toolbar`, `wew-module`, `wew-change-new|changed|deleted|moved`, `wew-publish`, `wew-discard`, `wew-diff`, `wew-preview`, plus a redrawn `Extension.svg`.
- JavaScript unit tests with vitest (`npm test`), a `.php-cs-fixer.dist.php` with the TYPO3 ruleset, and a single CI workflow (lint, cgl, PHPStan level 8, unit, functional on MariaDB 10.11, vitest).
- Manual pages `Documentation/Badge.rst` and `Documentation/Toolbar.rst`.

### Changed

- **Breaking: CSS files renamed.** `Resources/Public/Css/easy-workspace.css` was replaced by `tokens.css`, `toolbar-menu.css`, `module.css` and `diff.css`. Custom overrides that referenced the old file or its `.wew-list__*` / `.wew-menu__*` class names must be updated.
- **Breaking: PHP 8.4 or newer is required** (`"php": "^8.4"`).
- The JavaScript module `menu-backend-save-sync.js` was renamed to `menu-decline-sync.js`; the new `menu-badge.js` owns the badge. Lit templates live in `Resources/Public/JavaScript/templates/`.
- PHPStan runs at level 8 with `phpVersion: 80400`; the CI matrix covers PHP 8.4 and 8.5.
- The toolbar badge label reads "N pending workspace changes" (no longer "… on this page").

### Notes

- Git tags `v14.0.0`–`v14.0.2` predate `v1.3.9` and are invisible to a `^1.x` constraint; use `^1.4` to receive this release.

### Also in this release (unreleased since 1.3.9)

#### Fixed

- Discard resolves live record IDs only within the acting user's active workspace and rejects foreign-workspace drafts, including for administrators. Live mode cannot discard workspace changes.
- DataHandler edits from command-line or ChatOps users no longer require an initialized browser session.
- Internal JavaScript imports now use TYPO3's import map, so cache invalidation covers every toolbar module.
- The toolbar badge follows Core's module-loaded event after backend publishing and navigation, replacing fragile iframe-load and URL tracking.
- Restored readable module introductions in the dark backend theme, an accessible toolbar name with an empty queue, and removed duplicate module overview descriptions.

#### Changed

- Raised the minimum TYPO3 version to 14.3.6 for both Easy Workspace and the optional ChatOps package.
- Removed the unused Fluid toolbar renderer and templates, versioned JavaScript entry wrappers, session change-stamp hook, duplicate test runner, unused UI state and obsolete CSS.
- Replaced outdated architecture and audit notes with current installation, configuration, contributor and upgrade documentation.
- Added functional regressions for workspace isolation, live-ID discard, table permissions and edits without a browser session.

## [1.3.9] — 2026-06-29

### Fixed

- Kept preview discard tags inside the visible iframe viewport when the target content element is near the top edge.
- Preserved preview context when locating or discarding content elements, avoiding toolbar overlap and unnecessary smooth scrolling.

### Changed

- Clarified primary page and news records in the toolbar as page properties and news articles.
- Added a short primary-record intent hint and adjusted the toolbar hierarchy styling so child content elements read as belonging to the page or article.

## [1.3.8] — 2026-06-27

### Changed

- Removed the last stale wording from the versioned JavaScript entrypoint.

## [1.3.7] — 2026-06-27

### Changed

- Removed the toolbar filter tabs ("To publish" / "All on page") and language scope controls ("Current" / "All languages"). The toolbar now shows publishable workspace changes directly.
- Removed the related toolbar TSconfig options, labels, Fluid partials, CSS, and Lit state/persistence code.

## [1.3.2] — 2026-06-18

### Fixed

- The backend no longer crashes with *"Typed property
  `EasyWorkspaceToolbarItem::$request` must not be accessed before
  initialization"* when rendering the topbar. TYPO3's
  `BackendController::getToolbarItems()` calls every toolbar item's
  `checkAccess()` (inside `array_filter`) *before* it calls `setRequest()`
  (inside the surrounding `array_map`), so `$this->request` is never
  initialized at access-check time. `checkAccess()`/`userCanUseWorkspaces()`
  now resolve the backend user through `BackendAccessGuard` with no request
  argument, falling back to the `BE_USER` global exactly as the guard is
  documented to do for toolbar rendering — mirroring how every core toolbar
  item performs its access check. The request-dependent render methods
  (`getItem()`, `getDropDown()`) are unchanged; they run after `setRequest()`.

## [1.3.1] — 2026-06-16

### Fixed

- The per-element "decline workspace change" button no longer fails to load.
  `VisualEditorDeclineButtonMiddleware` registered the frontend module as
  `@webconsulting/webcon-easy-workspace/visual-editor-decline-button` without
  the `.js` extension, so the importmap resolved it to a URL that returned
  **404** and the discard button silently never appeared in the Visual Editor.
  Added the missing `.js` (matching every other module load in the extension).

## [1.3.0] — 2026-06-15

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
