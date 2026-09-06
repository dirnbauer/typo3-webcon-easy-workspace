# Easy Workspace

Review and publish TYPO3 workspace changes from the backend toolbar or **Content → Easy Workspace**. Publishing, approval stages, discarding and history rollback use TYPO3 Core APIs.

## Requirements

- TYPO3 **14.3.6 or newer on the 14.3 LTS line**.
- PHP **8.2–8.5**; CI tests each supported version.
- Core Backend, Fluid, Frontend and Workspaces extensions (installed by Composer).

Optional integrations: `georgringer/news` for individual news articles and `friendsoftypo3/visual-editor` for locating content and discarding drafts in preview. The toolbar also works with Core Viewpage.

## Installation

The package is distributed through GitHub. Add the repository, then install it in your TYPO3 project:

```bash
composer config repositories.webcon-easy-workspace vcs https://github.com/dirnbauer/typo3-webcon-easy-workspace.git
composer require webconsulting/webcon-easy-workspace:^1.3
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

For an existing installation, update the extension and the TYPO3 packages together. See the [upgrade notes](Documentation/Upgrading.rst), including the browser reload required after the JavaScript cleanup.

## Usage

1. Switch to a custom workspace and open a page or news article.
2. Open **Workspace publish** in the toolbar, or **Content → Easy Workspace**.
3. Review changes and their history. Select the records to publish.
4. Publish directly from the toolbar, or request review / approve and publish from the module.

The module has **Review queue**, **All records**, and an administrator-only **Checks and diagnostics** section. Configure `approvalStageId` to match the custom review stage in your workspace; `publishStageId` defaults to TYPO3's ready-to-publish stage (`-10`). Core permissions still determine which actions are allowed.

The toolbar is hidden in Live. It shows pending changes across languages for the selected context, with no language selector. Changes consisting only of localization metadata are excluded from the queue. Related inline records and file references are included in publishing even when their visual details are hidden.

Discard operates only in the active workspace. A preview control may supply a live record ID; it is resolved to a draft in that workspace. A repeated discard is harmless, and another workspace's draft is never selected as a fallback.

## Features

- Page content, workspace-aware inline children, file references and standalone file metadata.
- Per-article news context from a preview URL or open news edit form.
- Diff and history modals, record editing, preview links and content highlighting.
- Event-driven toolbar refresh after edits, publishing, navigation and returning to the browser tab.
- English and German labels; per-user visibility and detail preferences.
- Read-only workspace diagnostics and a CLI command for seeding disposable diagnostic data.

Physical FAL file overwrites are not versioned. Standalone file metadata is scoped to the workspace, not the current page. Slug-only news URLs need an open edit form or explicit `newsUid`. See the [manual](Documentation/Index.rst) for scope and API details.

## Configuration

TYPO3 loads `Configuration/user.tsconfig` automatically. Override `options.webcon_easy_workspace.*` in user/group TSconfig, or page TSconfig for requests carrying a page context:

```typoscript
options.webcon_easy_workspace {
    enablePreviewLink = 0
    enableRevert = 0
}
```

The [configuration reference](Documentation/Configuration.rst) lists all options and personal settings.

## Development

```bash
composer install
composer test
composer audit
```

`composer test` runs PHP lint, PHPStan at maximum level, unit tests and functional tests using SQLite. Individual suites are `composer lint`, `composer phpstan`, `composer test:unit` and `composer test:functional`. PHP version selection belongs to the runtime or CI matrix; there is no separate shell test runner.

The local DDEV configuration uses PHP 8.4. Run the same commands with `ddev exec`, for example `ddev exec composer test`. The disposable browser installation lives in the ignored `.Build/Smoke/` directory.

To create that browser installation in a fresh checkout:

```bash
mkdir -p .Build/Smoke
cp Build/Smoke/composer.json .Build/Smoke/composer.json
ddev start
ddev exec -d /var/www/html/.Build/Smoke composer install
ddev exec -d /var/www/html/.Build/Smoke vendor/bin/typo3 setup --create-site=https://webcon-easy-workspace.ddev.site
```

Use the DDEV database settings (`mysqli`, host/database/user/password `db`) and choose a local backend account during setup. Set `$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '^webcon-easy-workspace\\.ddev\\.site$';` in `.Build/Smoke/config/system/additional.php`. The backend is at [webcon-easy-workspace.ddev.site/typo3/](https://webcon-easy-workspace.ddev.site/typo3/). Run host CLI commands with the same `ddev exec -d` prefix; the extension checkout itself is not a TYPO3 site.

## Documentation

- [Manual and architecture](Documentation/Index.rst)
- [Configuration](Documentation/Configuration.rst)
- [Upgrade notes](Documentation/Upgrading.rst)
- [Diagnostics](Documentation/Diagnostics.rst)
- [Testing and health checks](Documentation/Testing.rst)
- [Contributing](Documentation/Contributing.rst)
- [Changelog](CHANGELOG.md)

[Workspace ChatOps](Extensions/webcon_workspace_chatops/README.md) is a separate, optional extension in this repository. Installing Easy Workspace does not enable its API or notification providers.

Report vulnerabilities through [private GitHub Security Advisories](https://github.com/dirnbauer/typo3-webcon-easy-workspace/security/advisories/new).

License: GPL-2.0-or-later.
