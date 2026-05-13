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

## Architecture

```
Classes/
├── Backend/ToolbarItem/EasyWorkspaceToolbarItem.php   # Toolbar registration
├── Controller/Backend/EasyWorkspaceAjaxController.php # /ajax/items + /ajax/publish
├── Service/
│   ├── PendingItemsService.php                       # Aggregates page + ce + news
│   └── PublishSelectedService.php                    # DataHandler publish cmdmap
└── Dto/PendingItem.php

Resources/
├── Private/Templates/ToolbarItems/                   # Trigger + dropdown shell
└── Public/JavaScript/easy-workspace-menu-element.js  # Lit element with list + publish
```

The PHP side uses only public TYPO3 v14 APIs (`ConnectionPool`, `BackendUtility`, `DataHandler`, `ResourceFactory`, `TcaSchemaFactory`). The dropdown is a `LitElement` rendered into light DOM so backend Bootstrap / styleguide tokens apply automatically.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
