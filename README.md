# Easy Workspace

TYPO3 14.3 LTS backend extension for **one-click workspace publishing** from the top bar and from a full **Easy Workspace** module. Editors review pending workspace changes for the current page (or a single news article), select rows, and publish or discard through TYPO3’s `DataHandler` — no custom versioning layer.

**Version:** 1.0.3 (see `composer.json` `extra.typo3/cms.version`)  
**Package:** `webconsulting/webcon-easy-workspace`  
**License:** GPL-2.0-or-later

## Requirements

| Requirement | Version |
|-------------|---------|
| TYPO3 | `^14.3` (14.3 LTS only) |
| PHP | `^8.2` – `^8.5` |
| Core extensions | `typo3/cms-workspaces`, `typo3/cms-backend`, `typo3/cms-fluid`, `typo3/cms-frontend` |

**Suggested (optional):**

- [`georgringer/news`](https://github.com/georgringer/news) — per-article news scope on a news detail view
- [`friendsoftypo3/visual-editor`](https://github.com/FriendsOfTYPO3/visual-editor) — eye icon scroll/highlight in the Visual Editor iframe (falls back to Viewpage)

## Installation

```bash
composer require webconsulting/webcon-easy-workspace:^14.0
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

VCS install (before Packagist):

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/dirnbauer/typo3-webcon-easy-workspace.git"
    }
  ],
  "require": {
    "webconsulting/webcon-easy-workspace": "^14.0"
  }
}
```

## Quick start

1. Switch to a **custom workspace** (not Live).
2. Open a page in **Page** or **List**, or open a **news** record / its detail preview.
3. Use either entry point:
   - **Toolbar:** paper-plane icon (orange dot when changes exist) → dropdown
   - **Module:** **Content → Easy Workspace** (below Workspaces publish)
4. Adjust checkboxes (all changed rows selected by default), then **Publish to live**.

The toolbar is hidden in the Live workspace. The module requires `workspaces: offline` (same as Core workspace modules).

## Feature list (complete)

### Entry points

| # | Feature | Description |
|---|---------|-------------|
| 1 | Backend toolbar item | Dropdown in the top bar; Fluid-rendered menu with glue JS for refresh and actions |
| 2 | Toolbar change badge | Count of pending changes; `has-changes` AJAX polling |
| 3 | Backend module (parent) | **Content → Easy Workspace** with page tree navigation |
| 4 | Submodule **Open items** | Changed-only publish queue; POST publish form |
| 5 | Submodule **All records** | Read-only inventory of all scoped records on the page |
| 6 | Submodule **Checks and diagnostics** | DB integrity scan + grouped health reports + manual risk list |

### Record scope

| # | Feature | Description |
|---|---------|-------------|
| 7 | Page scope | Page record, `tt_content` on that page, inline children, file references |
| 8 | Active workspace only | `WorkspaceRestriction` + `t3ver_wsid` on queries |
| 9 | Backend language filter | `languageUid` from module state / URL; TCA `languageField` / `l10n_parent` |
| 10 | Standalone file metadata | Pending `sys_file_metadata` in the active workspace (global to workspace, not page language) |
| 11 | Duplicate suppression | One row per logical record (live key or workspace-only key) |
| 12 | Parent context rows | Child-only changes still show parent row with nested children |
| 13 | EXT:news per-article scope | On news detail preview or news edit form: article + `tx_news_related_news` CEs |
| 14 | News auto-detection (toolbar) | `tx_news_pi1[news]` in iframe URL or `edit[tx_news_domain_model_news][N]` |
| 15 | Explicit `newsUid` (module/AJAX) | Module route and AJAX accept `newsUid` |
| 16 | `maxItems` cap | Server-side limit (default 200) per request |
| 17 | Hidden records | `showHidden` filters server-side; optional Hidden badge + thumbnail stripe |

### Row presentation

| # | Feature | Description |
|---|---------|-------------|
| 18 | Title + type label | TCA-based type line (`enableTypeLabels`) |
| 19 | Workspace state badge | Changed vs live indicator |
| 20 | Thumbnails | TYPO3-processed previews (`enableThumbnails`) |
| 21 | Related child disclosures | Toolbar/module toggles via user settings (publish still includes children) |
| 22 | Table grouping | `itemGroups` / `changedItemGroups` in API and module |
| 23 | Multi-select table state | TYPO3 styleguide selected/info row styling in module |
| 24 | Filter modes | **To publish** / **All on page** (`enableFilter`, `defaultMode`) |
| 25 | Workspace name chip | Header chip with workspace title (`enableWorkspaceChip`) |

### Actions per row / batch

| # | Feature | Description |
|---|---------|-------------|
| 26 | Bulk publish | `DataHandler` version publish cmdmap; parent-before-child order |
| 27 | Per-row discard | TYPO3 v14 `discard` command (`enableRevert`) |
| 28 | Diff modal | Field-level diff + `sys_history` timeline (AJAX HTML) |
| 29 | History rollback | Linear or single-field rollback via `RecordHistoryRollback` |
| 30 | Record edit | Contextual / full FormEngine links from module and diff |
| 31 | Eye icon (locate in preview) | Scroll/highlight `#c{uid}` in Visual Editor, Viewpage, or same-origin iframe |
| 32 | Inline locate target | Child rows target parent `tt_content` for eye icon |
| 33 | Preview link | `PreviewUriBuilder` → OS clipboard (`enablePreviewLink`) |
| 34 | Module doc-header actions | Show page, preview link, edit page properties (with permission checks on page) |
| 35 | Visual Editor decline control | Optional FE `editMode` middleware loads discard helper in workspace |

### AJAX API (backend, authenticated)

| Route | Method | Purpose |
|-------|--------|---------|
| `webcon_easy_workspace_items` | GET | Payload + server-rendered dropdown HTML |
| `webcon_easy_workspace_has_changes` | GET | Lightweight change detection |
| `webcon_easy_workspace_publish` | POST | Publish selection |
| `webcon_easy_workspace_discard` | POST | Discard one record |
| `webcon_easy_workspace_preview_link` | GET | Workspace preview URL for page |
| `webcon_easy_workspace_diff` | GET | Diff/history modal HTML |
| `webcon_easy_workspace_history_rollback` | POST | History rollback |

Paths: `/typo3/ajax/webcon-easy-workspace/...` (with route tokens).

### Configuration

| # | Feature | Description |
|---|---------|-------------|
| 36 | Auto-loaded User TSconfig | `Configuration/user.tsconfig` |
| 37 | Page / user / group overrides | Standard TSconfig precedence |
| 38 | Personal user settings | Use Easy Workspace; show subelements in toolbar/module |
| 39 | Server-side enforcement | Disabled flags return HTTP 403 on AJAX; hidden rows omitted from payload |

See [Documentation/Configuration.rst](Documentation/Configuration.rst) for every `options.webcon_easy_workspace.*` key.

### Diagnostics and CLI

| # | Feature | Description |
|---|---------|-------------|
| 40 | Workspace DB scan | Invalid version fields, orphan versions, duplicate drafts, missing inline parents |
| 41 | Health check groups | TYPO3 Reports-style grouped status from scan |
| 42 | Manual-only risk list | FAL overwrite, caches, editor intent, etc. |
| 43 | Seed command | `vendor/bin/typo3 webcon-easy-workspace:seed-diagnostics` for local test data |

### Reliability

| # | Feature | Description |
|---|---------|-------------|
| 44 | Stale dependency guard | PSR-14 `IsReferenceConsideredForDependencyEvent` when refindex points to deleted rows |
| 45 | Content Blocks untyped tables | Safe TCA title fallback when schema has no type information |
| 46 | Popover z-index fix | Toolbar menu uses top-layer popover after topbar re-render |

### Localization

| # | Feature | Description |
|---|---------|-------------|
| 47 | English + German | `locallang.xlf`, `de.locallang.xlf`, module labels |

## Security

All endpoints require an authenticated backend user, TYPO3 route tokens, and (for mutations) `POST`. Publish/discard use `WorkspaceTablePolicy` (primary tables + workspace-aware inline children only) and verify `t3ver_wsid` before building cmdmaps. Error responses avoid leaking stack traces.

**Report security issues privately:** [GitHub Security Advisories](https://github.com/dirnbauer/typo3-webcon-easy-workspace/security/advisories/new) — do not use public issues.

Last full extension audit: **2026-06-03** (see [CHANGELOG](CHANGELOG.md) Unreleased → Security).

## Documentation

| Document | Content |
|----------|---------|
| [Documentation/Index.rst](Documentation/Index.rst) | TYPO3 manual — architecture, feature inventory, security |
| [Documentation/Configuration.rst](Documentation/Configuration.rst) | TSconfig and user settings |
| [Documentation/Diagnostics.rst](Documentation/Diagnostics.rst) | Checks and diagnostics submodule |
| [Documentation/Testing.rst](Documentation/Testing.rst) | Health checks and seed command |
| [Documentation/Contributing.rst](Documentation/Contributing.rst) | Layer model, maintainability bar, contributor checklist |
| [CHANGELOG.md](CHANGELOG.md) | Release and security notes |

Rendered manual: [docs.typo3.org](https://docs.typo3.org/p/webconsulting/webcon-easy-workspace/main/en-us/) (when published).

## Quality checks

```bash
composer test
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s lint
```

PHPStan level **max** (`Build/phpstan/phpstan.neon`). CI runs PHP 8.2–8.5.

## Maintainability (thermo-nuclear review 2026-06-06)

**Verdict: pass (post-refactor).** No file exceeds 1000 lines; canonical layers are respected; PHPStan max is green.

| Area | Status | Largest unit | Note |
|------|--------|--------------|------|
| Pending items pipeline | Pass | `InlineChildResolver` (~530) | `PendingItemsService` → `PendingItemsCollector` → factory + resolvers + `PendingItemAggregator` |
| Module controller | Pass | `EasyWorkspaceModuleController` (~456) | Stats → `ModuleSectionViewDataFactory`; doc-header → `EasyWorkspaceModuleDocHeaderBuilder` |
| AJAX controller | Pass | `EasyWorkspaceAjaxController` (~385) | Context via `PendingItemsService`; extract diff/history renderer only if it grows |
| Publish selection | Pass | `PublishSelectionNormalizer` (~77) | Shared module POST + toolbar AJAX parsing |
| Table policy | Pass | `WorkspaceTablePolicy` (~72) | Single allow-list for publish, discard, diff, rollback |
| JavaScript | Pass | `easy-workspace-module.js` (~543) | Toolbar split into focused glue modules |

**Optional backlog** (not blockers): diff/history modal extraction in AJAX controller; module JS label-map helper if strings grow; collapse `PendingItemsService` empty-payload duplication when a third context appears.

Contributors: [Documentation/Contributing.rst](Documentation/Contributing.rst) — layer model, file-size inventory, anti-patterns, quality bar.

## Architecture

PHP collects pending workspace records, renders toolbar markup with Fluid (ICU labels), and exposes AJAX endpoints. JavaScript is glue only: context detection, menu refresh, checkbox selection, TYPO3 modals, and preview iframe highlight.

```
Toolbar (custom element + glue JS) ──AJAX──► EasyWorkspaceAjaxController
         │                                          │
         │                              PendingItemsToolbarRenderer (Fluid)
         │
Module (Fluid) ──POST──► EasyWorkspaceModuleController
         │                        │
         │            ModuleSectionViewDataFactory
         │            EasyWorkspaceModuleDocHeaderBuilder
         ▼
PendingItemsService  ── resolveContext / *ForContext()
         │
         ├── PublishSelectionNormalizer ──► PublishSelectedService ──► DataHandler
         │
         ▼
PendingItemsCollector
         │
         ▼
PendingItemFactory + resolvers + InlineChildResolver + PendingItemAggregator
```

Canonical utilities: `WorkspaceRecordQuery`, `WorkspaceTablePolicy`, `ConfigurationProvider`, `Value`.

## Support

- Issues: https://github.com/dirnbauer/typo3-webcon-easy-workspace/issues
- Source: https://github.com/dirnbauer/typo3-webcon-easy-workspace
