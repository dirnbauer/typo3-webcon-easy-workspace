# Easy Workspace

## What it is

Easy Workspace adds a workspace publishing dropdown to the TYPO3 backend toolbar and a **Content → Easy Workspace** module. Editors see the pending changes of the page (or news article) they are working on, review diffs and history, and publish or discard selected records without leaving their context. Publishing, staging, discarding and rollback use TYPO3 Core APIs (DataHandler, RecordHistory).

The toolbar badge counts every pending change of the active workspace — like the Workspaces module — and stays current after saves in any frame, other tabs, CLI or MCP edits. See [Documentation/Badge.rst](Documentation/Badge.rst) for how the count is computed and refreshed.

## Requirements

- TYPO3 **14.3.6 or newer** on the 14.3 LTS line
- PHP **8.4 or 8.5**
- Core Backend, Fluid, Frontend and Workspaces extensions (installed by Composer)

Optional: `georgringer/news` for per-article publishing, `friendsoftypo3/visual-editor` for locating and discarding content elements in the preview.

## Install

The package is distributed through GitHub tags only (not on Packagist):

```bash
composer config repositories.webcon-easy-workspace vcs https://github.com/dirnbauer/typo3-webcon-easy-workspace.git
composer require webconsulting/webcon-easy-workspace:^1.5
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

Use `^1.5`: the tags `v14.0.0`–`v14.0.2` predate `v1.3.9` and do not match a `^1.x` constraint. Existing installations should read the [upgrade notes](Documentation/Upgrading.rst) — 1.4.0 renames the CSS files and requires PHP 8.4.

## Configure

`Configuration/user.tsconfig` is loaded automatically. Override any key below `options.webcon_easy_workspace` in user, group or page TSconfig:

```typoscript
options.webcon_easy_workspace {
    enablePreviewLink = 0
    enableRevert = 0
    approvalStageId = 1
    publishStageId = -10
}
```

Editors get personal switches (use the toolbar, show related records) in **User Settings**. The [configuration reference](Documentation/Configuration.rst) lists every option; feature flags are enforced server-side.

## Use

1. Switch to a custom workspace and open a page or news article.
2. Open **Workspace publish** in the toolbar. The header shows the workspace, the stage and "N on this page · M elsewhere".
3. Select rows (Space), open the editor (Enter), inspect changes and history, discard a draft, or locate the element in the preview.
4. **Publish N** publishes the selection; **Preview** opens or copies a workspace preview link; **Open module** switches to the full review queue with request-review / approve-and-publish stages.

The toolbar is hidden in Live. Related inline records and file references are published together with their parent even when their details are hidden. Discard only resolves drafts of the active workspace.

## Develop

```bash
composer install
composer test          # lint, cgl (dry run), PHPStan level 8, unit, functional (SQLite)
npm ci && npm test     # vitest for the toolbar JavaScript
composer cgl:fix       # apply the TYPO3 coding standards
```

The toolbar is a Lit element served through TYPO3's import map — there is no JavaScript build step. `vitest` aliases the import-map prefixes to the sources and to small mocks in `Tests/JavaScript/mocks`. Functional tests default to SQLite; CI runs them on MariaDB 10.11 with PHP 8.4 and 8.5. A disposable browser installation can be created with DDEV as described in [Documentation/Testing.rst](Documentation/Testing.rst).

## Docs

- [Manual and architecture](Documentation/Index.rst)
- [Toolbar dropdown](Documentation/Toolbar.rst) · [Badge and refresh](Documentation/Badge.rst)
- [Configuration](Documentation/Configuration.rst) · [Upgrading](Documentation/Upgrading.rst)
- [Testing](Documentation/Testing.rst) · [Diagnostics](Documentation/Diagnostics.rst) · [Contributing](Documentation/Contributing.rst)
- [Changelog](CHANGELOG.md)

[Workspace ChatOps](Extensions/webcon_workspace_chatops/README.md) is a separate, optional extension in this repository. Report vulnerabilities through [private GitHub Security Advisories](https://github.com/dirnbauer/typo3-webcon-easy-workspace/security/advisories/new).

## License

GPL-2.0-or-later.
