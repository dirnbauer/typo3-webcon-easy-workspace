# Easy Workspace

TYPO3 14.3 LTS backend extension for **one-click workspace publishing** from the top bar and from a full **Easy Workspace** module. Editors review pending workspace changes for the current page (or a single news article), select rows, and publish or discard through TYPO3’s `DataHandler` — no custom versioning layer.

**Version:** 1.1.0 (see `composer.json` `extra.typo3/cms.version`)  
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

Distributed via **GitHub tags only** — not published on Packagist. Add the VCS repository to your project `composer.json`, then require the package:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/dirnbauer/typo3-webcon-easy-workspace.git"
    }
  ],
  "require": {
    "webconsulting/webcon-easy-workspace": "^1.1"
  }
}
```

```bash
composer update webconsulting/webcon-easy-workspace
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
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
| 1 | Backend toolbar item | Dropdown in the top bar; Lit-rendered menu (light DOM) with glue modules for context, preview, and save sync |
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
| 9 | Backend language scope | Defaults to the detected backend page language; toolbar control can include all page languages; server filtering uses TCA `languageField` / `l10n_parent` |
| 10 | Localization metadata filter | Workspace rows changed only by localization metadata (`l10n_diffsource` / `l18n_diffsource`, `l10n_state`, etc.) stay out of the changed queue |
| 11 | Standalone file metadata | Pending `sys_file_metadata` in the active workspace (global to workspace, not page language) |
| 12 | Duplicate suppression | One row per logical record (live key or workspace-only key) |
| 13 | Parent context rows | Child-only changes still show parent row with nested children |
| 14 | EXT:news per-article scope | On news detail preview or news edit form: article + `tx_news_related_news` CEs |
| 15 | News auto-detection (toolbar) | `tx_news_pi1[news]` in iframe URL or `edit[tx_news_domain_model_news][N]` |
| 16 | Explicit `newsUid` (module/AJAX) | Module route and AJAX accept `newsUid` |
| 17 | `maxItems` cap | Server-side limit (default 200) per request |
| 18 | Hidden records | `showHidden` filters server-side; optional Hidden badge + thumbnail stripe |

### Row presentation

| # | Feature | Description |
|---|---------|-------------|
| 19 | Title + type label | TCA-based type line (`enableTypeLabels`) |
| 20 | Workspace state badge | Changed vs live indicator |
| 21 | Thumbnails | TYPO3-processed previews (`enableThumbnails`) |
| 22 | Related child disclosures | Toolbar/module toggles via user settings (publish still includes children) |
| 23 | Table grouping | `itemGroups` / `changedItemGroups` in API and module |
| 24 | Multi-select table state | TYPO3 styleguide selected/info row styling in module |
| 25 | Filter modes | **To publish** / **All on page** (`enableFilter`, `defaultMode`) |
| 26 | Workspace name chip | Header chip with workspace title (`enableWorkspaceChip`) |

### Actions per row / batch

| # | Feature | Description |
|---|---------|-------------|
| 27 | Bulk publish | `DataHandler` version publish cmdmap; parent-before-child order |
| 28 | Per-row discard | TYPO3 v14 `discard` command (`enableRevert`) |
| 29 | Diff modal | Field-level diff + `sys_history` timeline (AJAX HTML) |
| 30 | History rollback | Linear or single-field rollback via `RecordHistoryRollback` |
| 31 | Record edit | Contextual / full FormEngine links from module and diff |
| 32 | Eye icon (locate in preview) | Scroll/highlight `#c{uid}` in Visual Editor, Viewpage, or same-origin iframe |
| 33 | Inline locate target | Child rows target parent `tt_content` for eye icon |
| 34 | Preview link | `PreviewUriBuilder` → OS clipboard (`enablePreviewLink`) |
| 35 | Module doc-header actions | Show page, preview link, edit page properties (with permission checks on page) |
| 36 | Visual Editor decline control | Optional FE `editMode` middleware loads discard helper in workspace |

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

Last full extension audit: **2026-06-03** (see [CHANGELOG](CHANGELOG.md) [1.1.0] → Security).

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

## Maintainability (thermo-nuclear review 2026-06-06, release 1.1.0)

**Verdict: pass.** `composer test` and PHPStan level max are green. No file exceeds 1000 lines; canonical layers are respected. Release 1.1.0 ships with no open structural blockers from this review.

### Layer health

| Area | Status | Largest unit | Note |
|------|--------|--------------|------|
| Pending items pipeline | Pass | `InlineChildResolver` (530) | `PendingItemsService` → `PendingItemsCollector` → factory + resolvers + `PendingItemAggregator` |
| Diagnostics | Pass | `WorkspaceDiagnosticsService` (513) | Scan rules stay together; health reports via `WorkspaceTestingReportService` (426) |
| Module controller | Pass | `EasyWorkspaceModuleController` (456) | Stats → `ModuleSectionViewDataFactory`; doc-header → `EasyWorkspaceModuleDocHeaderBuilder` |
| AJAX controller | Pass | `EasyWorkspaceAjaxController` (385) | Context via `PendingItemsService`; `diffAction` still inline (~70 lines) |
| Publish selection | Pass | `PublishSelectionNormalizer` (77) | Shared module POST + toolbar AJAX parsing |
| Table policy | Pass | `WorkspaceTablePolicy` (72) | Single allow-list for publish, discard, diff, rollback |
| JavaScript | Pass | `components/wew-toolbar-menu.js` (~780) | Lit toolbar dropdown; glue modules (`menu-*.js`) for context, preview, modals |

### Watch list (decompose before ~700 lines)

| File | Lines | Trigger for extraction |
|------|-------|------------------------|
| `InlineChildResolver.php` | 530 | New table-specific traversal → helper inside `PendingItems/`, not collector |
| `WorkspaceDiagnosticsService.php` | 513 | New scan category → dedicated checker class |
| `easy-workspace-module.js` | 543 | New module interaction → separate init module |
| `PendingItemAggregator.php` | 428 | New grouping rule → small strategy helper |

### Optional backlog (not blockers)

- Extract `RecordDiffModalViewDataFactory` from `EasyWorkspaceAjaxController::diffAction` when diff labels or timeline assembly grow
- Extract module `buildJsLabelMap()` when client strings multiply
- Collapse `PendingItemsService` empty-payload helpers when a third collection context appears

### PR blockers (from review bar)

Do not merge changes that push any file past **1000 lines**, scatter page/news `if` chains into collectors or query helpers, or duplicate `WorkspaceTablePolicy` gates.

Contributors: [Documentation/Contributing.rst](Documentation/Contributing.rst) — full review outcome, file-size inventory, anti-patterns, quality bar.

## Architecture

PHP collects pending workspace records and exposes AJAX endpoints. The toolbar Lit component fetches JSON, renders the dropdown client-side (ICU labels from PHP `config`), and uses glue modules for context detection, preview iframe highlight, and TYPO3 modals. Workspace rows that only differ in ignored system/localization fields are rendered as normal records in all-record views, not as changed rows with publish checkboxes.

```
Toolbar (Lit custom element + glue modules) ──AJAX──► EasyWorkspaceAjaxController
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
