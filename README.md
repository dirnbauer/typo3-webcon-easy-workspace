# Easy Workspace

A TYPO3 v14 backend extension that adds a **toolbar dropdown** for one-click workspace publishing.

When an editor opens the dropdown while editing a page, they immediately see:

- the page record (if it has workspace changes)
- every changed content element on that page
- every changed news record pinned to that page
- for each news, every content element linked via `tx_news_related_news`

Each row shows a **checkbox** (checked by default), the record **title**, a state badge (New / Modified / Will be deleted / Moved), and the **first attached image** as a thumbnail. The button at the bottom — **"Publish to live"** — sends the selection to `DataHandler` in a single `version` cmdmap.

## Requirements

- TYPO3 14.3 LTS
- PHP 8.3+
- `typo3/cms-workspaces`
- *Optional:* `georgringer/news` (enables news + linked content-element bundles)

## Installation

```bash
composer require webconsulting/webcon-easy-workspace
ddev typo3 extension:setup
ddev typo3 cache:flush
```

Or as a VCS dependency in your project's `composer.json`:

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

## Usage

1. Switch into a custom workspace (sidebar workspace selector).
2. Open a page in the **Page** or **List** module.
3. Click the **Workspace publish** icon (paper-plane with an orange dot) in the top-right toolbar.
4. Untick anything you don't want to publish yet, then hit **Publish to live**.

The toolbar item is automatically hidden while you are in the live workspace.

## Configuration (TSconfig)

Every feature can be toggled via **User TSconfig** (and overridden per page via **Page TSconfig**). The extension ships a default `Configuration/user.tsconfig` that is **auto-loaded on activation** — you don't need to import it manually. To customize, copy any of these keys into your group/user TSconfig field or your project's `Configuration/user.tsconfig`.

```typoscript
options.webcon_easy_workspace {
    # Master switch. 0 hides the toolbar item entirely.
    enabled = 1

    # Show the "Preview link" button in the header.
    enablePreviewLink = 1

    # Show the filter chips ("To publish" / "All on page").
    # When 0, the dropdown always shows changes only.
    enableFilter = 1

    # Initial filter mode. Allowed: changed | all
    defaultMode = changed

    # Show the "Hidden" badge + diagonal-stripe thumbnail overlay
    # for records with hidden=1. When 0, hidden records are
    # filtered out server-side.
    showHidden = 1

    # Resolve and render the first-image thumbnail of each row.
    enableThumbnails = 1

    # Hard cap on rows returned per request.
    maxItems = 200

    # Hovering a tt_content row in the dropdown scrolls the
    # corresponding content element into view inside the Visual
    # Editor iframe (#visual-editor-iframe) and outlines it.
    enableHoverHighlight = 1

    # Show a per-row "revert" button next to the eye icon (for every
    # changed record). Clicking it opens a warning confirmation modal
    # and flushes the workspace version — the live row stays untouched
    # but the staged change is gone for good.
    enableRevert = 1
}
```

**Override precedence** (highest wins): Page TSconfig at the current page → User TSconfig on the user record → User TSconfig on the user's group → defaults shipped with the extension.

The values are read server-side by `Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider` (which calls `BackendUserAuthentication::getTSConfig()` and `BackendUtility::getPagesTSconfig()` — both public TYPO3 v14 APIs) and then handed to the dropdown's Lit element as a JSON payload on its `config` attribute. The same flags are also enforced on the backend so that, for example, hidden records are filtered out of the AJAX response entirely when `showHidden = 0` — not just hidden in CSS.

### Preview link & OS clipboard

The "Preview link" button calls `\TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder::buildUriForPage($pageUid)` (TYPO3 v14's public API) and copies the resulting URL straight to the **operating-system clipboard** via `navigator.clipboard.writeText()` (with a hidden-textarea + `document.execCommand('copy')` fallback for non-secure contexts). It does **not** use TYPO3's record clipboard.

### Eye-icon: locate CE in Visual Editor

Every **content-element** row in the dropdown has an **eye icon** (TYPO3's `actions-eye`) next to the title. Hovering or focusing the eye reaches into the Visual Editor iframe (`#visual-editor-iframe` — same-origin) via `iframe.contentDocument`, locates the rendered content element by its standard TYPO3 id (`#c{uid}`, with `[data-uid][data-table=tt_content]` and `[data-typo3-record-uid]` as fallbacks), and:

- **Scrolls the element into view** in the iframe via `scrollIntoView({ behavior: 'smooth', block: 'center' })` — great for long pages where the CE is well below the visible viewport.
- Applies an inline outline + soft glow so the editor can immediately see *which* CE the dropdown row refers to.

Both effects are reverted on `mouseleave` / `blur` and again on element disconnect. Clicking the eye triggers the same scroll-and-highlight as hovering, useful on touch devices. The eye is shown only for `tt_content` rows; the affordance can be switched off via `options.webcon_easy_workspace.enableHoverHighlight = 0`.

### Revert (discard) a single change

Next to the eye, every **changed** row also has a curved-arrow **revert** button (TYPO3 core's `actions-undo` SVG, rendered in the Bootstrap warning hue so the destructive intent is obvious before the user clicks). Clicking opens a `Modal.confirm()` with `SeverityEnum.warning` and a `btn-warning` confirm action. On confirm the toolbar POSTs to `/ajax/webcon-easy-workspace/discard`, which runs `DataHandler` with the v14-canonical command:

```php
$cmd[$table][$workspaceUid]['version'] = ['action' => 'flush'];
```

This deletes the workspace version of that record only — the **live** row stays untouched. The dropdown auto-refreshes after the discard so the row disappears (in "Changes only" mode) or its badge flips back to "Live" (in "All on page" mode).

Disable per user/group/page via `options.webcon_easy_workspace.enableRevert = 0`.

## Architecture

```
Classes/
├── Backend/ToolbarItem/EasyWorkspaceToolbarItem.php   # Toolbar registration + config injection
├── Configuration/ConfigurationProvider.php            # Reads & normalizes TSconfig
├── Controller/Backend/EasyWorkspaceAjaxController.php # /ajax/items + /ajax/publish + /ajax/preview-link
├── Service/
│   ├── PendingItemsService.php                       # Aggregates page + ce + news (honors config)
│   └── PublishSelectedService.php                    # DataHandler publish cmdmap
└── Dto/PendingItem.php

Configuration/
├── Backend/AjaxRoutes.php                            # 3 AJAX routes (items, publish, preview-link)
├── Services.yaml                                     # DI / autowiring
├── Icons.php                                         # Toolbar icon registration
├── JavaScriptModules.php                             # `@webconsulting/webcon-easy-workspace/` import map
└── user.tsconfig                                     # Auto-loaded TSconfig defaults

Resources/
├── Private/Templates/ToolbarItems/                   # Trigger + dropdown shell (JSON config attr)
└── Public/JavaScript/easy-workspace-menu-element.js  # Lit element with list + publish + OS clipboard
```

The PHP side uses only public TYPO3 v14 APIs (`ConnectionPool`, `BackendUtility`, `DataHandler`, `ResourceFactory`, `TcaSchemaFactory`). The dropdown is a `LitElement` rendered into light DOM so backend Bootstrap / styleguide tokens apply automatically.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
