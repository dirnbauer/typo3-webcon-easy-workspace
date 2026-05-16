# Easy Workspace

A TYPO3 v14 backend extension that adds a **toolbar dropdown** for one-click workspace publishing.

When an editor opens the dropdown while editing a page, they immediately see:

- the page record (if it has workspace changes)
- every changed content element on that page
- every changed news record stored on that page
- for each news, every content element linked via `tx_news_related_news`

Each row shows a **checkbox** (checked by default), the record **title**, a state badge (New / Modified / Will be deleted / Moved), and the first supported FAL image as a thumbnail (`pages.media`, `tt_content.image/assets/media`, `tx_news.fal_media/fal_related_files`). The button at the bottom — **"Publish to live"** — sends the selection to `DataHandler` in a single publish cmdmap.

## Requirements

- TYPO3 14.3 LTS
- PHP 8.3+
- `typo3/cms-workspaces`
- `typo3/cms-fluid`
- `typo3/cms-frontend`
- *Optional:* `georgringer/news` — enables news + linked content-element bundles
- *Optional:* `friendsoftypo3/visual-editor` — enables the per-row eye icon to scroll & outline a content element inside the rendered page. **The dropdown works without it** — the eye gracefully falls back to `typo3/cms-viewpage`'s preview iframe (`#tx_viewpage_iframe`), and if no iframe is reachable at all the eye click shows a TYPO3 Notification telling the editor which module to open.

## Installation

This package is installed from this Git repository. Add it as a VCS repository in your project's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/dirnbauer/typo3-webcon-easy-workspace.git"
    }
  ],
  "require": {
    "webconsulting/webcon-easy-workspace": "dev-main"
  }
}
```

Then install and set up the extension:

```bash
composer update webconsulting/webcon-easy-workspace --with-dependencies
ddev typo3 extension:setup
ddev typo3 cache:flush
```

If the package is published to Packagist later, the equivalent install command is:

```bash
composer require webconsulting/webcon-easy-workspace
```

## Usage

1. Switch into a custom workspace (sidebar workspace selector).
2. Open a page in the **Page** or **List** module.
3. Click the **Workspace publish** icon (paper-plane with an orange dot) in the top-right toolbar.
4. Untick anything you don't want to publish yet, then hit **Publish to live**.

The toolbar item is automatically hidden while you are in the live workspace.

## Configuration (TSconfig)

Every visible affordance is gated by a TSconfig flag — defaults ON, switch to `0` per **user / group / page** when a role doesn't need that feature. The extension ships [`Configuration/user.tsconfig`](Configuration/user.tsconfig), which TYPO3 v14 auto-loads from every active extension; no manual import.

| Group | Key | Default | Purpose |
|---|---|---|---|
| Master | `enabled` | `1` | Hides the toolbar item entirely when `0` (AJAX endpoints also respond `403`). |
| Header | `enableWorkspaceChip` | `1` | Workspace-name chip next to the title ("Staging"). |
| Header | `enablePreviewLink` | `1` | "Preview link" button (OS clipboard copy via `Workspaces\Preview\PreviewUriBuilder`). |
| Filter | `enableFilter` | `1` | Filter chip row ("To publish" / "All on page"). |
| Filter | `defaultMode` | `changed` | Initial mode: `changed` or `all`. |
| Rendering | `enableThumbnails` | `1` | First-image thumbnail lookup + column. |
| Rendering | `enableTypeLabels` | `1` | Second meta line ("Page · Blog Post"). |
| Rendering | `enableHiddenBadge` | `1` | "Hidden" badge + diagonal-stripe thumbnail overlay. |
| Rendering | `showHidden` | `1` | When `0`, hidden records are filtered out **server-side**. |
| Rendering | `maxItems` | `200` | Hard cap on rows per request. |
| Scope | `enableNewsBundles` | `1` | Also list news on the page + their linked content elements. |
| Per-row | `enableHoverHighlight` | `1` | Eye icon → scroll + outline the CE in Visual Editor, Viewpage, or another same-origin preview iframe. |
| Per-row | `enableRevert` | `1` | Discard button using TYPO3 v14's DataHandler `discard` cmd. |

**Override precedence** (highest wins): Page TSconfig → User TSconfig on user → User TSconfig on group → defaults.

**Server-side enforcement.** Every boolean flag is read by `Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider` (via `BackendUserAuthentication::getTSConfig()` and `BackendUtility::getPagesTSconfig()` — public v14 APIs) and *also* checked in `EasyWorkspaceAjaxController`: e.g. when `showHidden = 0` the hidden rows never reach the response payload, and toggling `enableRevert = 0` returns `403` on the discard endpoint so DevTools can't bypass it.

📘 **[Full configuration reference → `Documentation/Configuration.md`](Documentation/Configuration.md)** — every key with its server-side consequences, plus four ready-made profiles (junior editor, reviewer, performance setup, news-free site).

### Preview link & OS clipboard

The "Preview link" button calls `\TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder::buildUriForPage($pageUid)` (TYPO3 v14's public API) and copies the resulting URL straight to the **operating-system clipboard** via `navigator.clipboard.writeText()` (with a hidden-textarea + `document.execCommand('copy')` fallback for non-secure contexts). It does **not** use TYPO3's record clipboard.

### Eye-icon: locate CE in the rendered preview

Every **content-element** row in the dropdown has an **eye icon** (TYPO3's `actions-eye`) next to the title. Hovering or focusing the eye reaches into the rendered page iframe via `iframe.contentDocument`, locates the rendered content element by its standard TYPO3 id (`#c{uid}`, with `[data-uid][data-table=tt_content]` and `[data-typo3-record-uid]` as fallbacks), and:

- **Scrolls the element into view** in the iframe via `scrollIntoView({ behavior: 'smooth', block: 'center' })` — great for long pages where the CE is well below the visible viewport.
- Applies an inline outline + soft glow so the editor can immediately see *which* CE the dropdown row refers to.

**Iframe lookup order** (first hit wins): `#visual-editor-iframe` (friendsoftypo3/visual-editor) → `#tx_viewpage_iframe` (typo3/cms-viewpage) → any iframe with a name/id matching `*page-preview*` / `*pagepreview*` / `*preview*` → any same-origin iframe with a readable `contentDocument`.

**Adaptive labeling.** The tooltip reads *"Show in Visual Editor"*, *"Show in page preview"*, or *"Show in preview"* depending on which extensions are detected on the server side (`ExtensionManagementUtility::isLoaded('visual_editor')` / `'viewpage'`). The notification shown when no iframe can be located also adapts and points the editor to the right module.

Both effects are reverted on `mouseleave` / `blur` and again on element disconnect. Clicking the eye triggers the same scroll-and-highlight as hovering, useful on touch devices. The eye is shown only for `tt_content` rows; the affordance can be switched off via `options.webcon_easy_workspace.enableHoverHighlight = 0`.

### Discard a single change

Next to the eye, every **changed** row also has a curved-arrow **discard** button (TYPO3 core's `actions-undo` SVG, rendered in the Bootstrap warning hue so the destructive intent is obvious before the user clicks). *Discard* is TYPO3's own term for this operation and maps directly to TYPO3 v14's DataHandler `discard` command, which removes the workspace version. Clicking opens a `Modal.confirm()` with `SeverityEnum.warning` and a `btn-warning` confirm action. On confirm the toolbar POSTs to the `webcon_easy_workspace_discard` backend AJAX route (`/webcon-easy-workspace/discard`), which runs `DataHandler` with the v14 command:

```php
$cmd[$table][$workspaceUid]['discard'] = true;
```

This deletes the workspace version of that record only — the **live** row stays untouched. The dropdown auto-refreshes after the discard so the row disappears (in "Changes only" mode) or its badge flips back to "Live" (in "All on page" mode).

Disable per user/group/page via `options.webcon_easy_workspace.enableRevert = 0`.

### Latest changes, diff and history

The dropdown also includes a **Latest changes** accordion. It lazy-loads the most recent records in the active workspace through the `webcon_easy_workspace_latest` backend AJAX route (`/webcon-easy-workspace/latest`), scoped to the backend user's current workspace and capped at 50 rows server-side.

Changed rows can open a server-rendered diff/history modal through the `webcon_easy_workspace_diff` backend AJAX route (`/webcon-easy-workspace/diff`). The modal shows the record's workspace diff and recent `sys_history` entries. Its rollback buttons post to `webcon_easy_workspace_history_rollback` (`/webcon-easy-workspace/history-rollback`); TYPO3's own DataHandler and backend permission checks still decide whether a rollback is allowed.

## Architecture

```
Classes/
├── Backend/ToolbarItem/EasyWorkspaceToolbarItem.php   # Toolbar registration + config injection
├── Configuration/ConfigurationProvider.php            # Reads & normalizes TSconfig
├── Controller/Backend/EasyWorkspaceAjaxController.php # Backend AJAX endpoints
├── Service/
│   ├── PendingItemsService.php                       # Aggregates page + ce + news (honors config)
│   └── PublishSelectedService.php                    # DataHandler publish cmdmap
└── Dto/PendingItem.php

Configuration/
├── Backend/AjaxRoutes.php                            # items, publish, preview, discard, latest, diff, rollback
├── JavaScriptModules.php                             # `@webconsulting/webcon-easy-workspace/` import map
├── RequestMiddlewares.php                            # TYPO3 backend middleware registration
├── Services.yaml                                     # DI / autowiring
└── user.tsconfig                                     # Auto-loaded TSconfig defaults

Resources/
├── Private/Templates/ToolbarItems/                   # Trigger + dropdown shell (JSON config attr)
├── Private/Templates/Diff/Record.html                # Workspace diff/history modal
└── Public/JavaScript/                                # Lit menu + eye/decline helpers

Build/
├── Scripts/runTests.sh                               # Local quality runner
└── phpstan/phpstan.neon                              # TYPO3 PHPStan config at level max
```

The PHP side uses only public TYPO3 v14 APIs (`ConnectionPool`, `BackendUtility`, `DataHandler`, `ResourceFactory`, `TcaSchemaFactory`). The dropdown is a `LitElement` rendered into light DOM so backend Bootstrap / styleguide tokens apply automatically.

### Template boundary

This extension owns backend toolbar, modal, and iframe-helper templates only. It does not render frontend page content areas and should not use Visual Editor page ViewHelpers such as `f:render.contentArea` or `f:mark.contentArea`.

The Visual Editor integration is intentionally limited to detecting the preview iframe and highlighting already-rendered `tt_content` elements. Bootstrap 5.3, shadcn/ui, and other frontend design-system templates belong in the consuming sitepackage, not in this backend workspace toolbar extension.

## Quality checks

```bash
composer test
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s lint
```

PHPStan runs with `level: max` through `Build/phpstan/phpstan.neon`.

### Content Blocks collection tables

Content Blocks collection child tables are valid TYPO3 records even when their
TCA has no subtype discriminator such as `ctrl[type]`. Examples are generated
tables like `accordion_items`, `feature_grid_3_items`, or other inline child
tables that have one fixed record shape.

TYPO3's `TcaSchema::getSubSchemaTypeInformation()` is only safe for schemas
that actually define subtype/type information. Calling it unconditionally on an
untyped collection table raises `InvalidSchemaTypeException` ("The schema ...
has no type information."). Easy Workspace treats that as a normal untyped
schema case: `PendingItemsService` catches the exception and falls back to the
table's TCA title when building the row type label. The parent `tt_content`
record and its workspace/version data remain valid; this is not a seed-data
repair case.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
