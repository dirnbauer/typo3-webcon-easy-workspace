# Easy Workspace

A TYPO3 v14 backend extension that adds a **toolbar dropdown** and a
TYPO3-module-selector based **backend module** for one-click workspace
publishing.

When an editor opens the toolbar dropdown or the Easy Workspace module while editing a page, they immediately see:

- the page record (if it has workspace changes)
- every changed content element on that page
- every changed inline child record attached to the page or a content element
- every changed file reference attached to the page or a content element
- every changed news record stored on that page, when EXT:news is installed
- for each news, every linked content element, including workspace changes on those content elements
- standalone file metadata records (`sys_file_metadata`) pending in the active workspace
- protection against stale workspace dependency references such as missing Content Blocks child rows

Each pending row is optimized for bulk publishing: a **checkbox** (checked by
default), record **title**, record type, workspace state, and quiet
right-aligned actions. The full backend module renders those rows as TYPO3
styleguide / Bootstrap 5 tables inside standard cards, so long lists use the
available width instead of becoming a custom stacked layout. Inline children,
file references and related records stay collapsed below their parent until the
editor opens the detail disclosure, so the main list remains scannable.
Image-bearing rows show a small TYPO3-processed preview image (`pages.media`,
`tt_content.image/assets/media`, `tx_news.fal_media/fal_related_files`,
changed `sys_file_reference` children, and standalone `sys_file_metadata`
rows). The **"Publish to live"** action sends the selection to `DataHandler`
in a single publish cmdmap.

The backend module lives in the left TYPO3 navigation below the workspace
publish module. It is fully server-rendered with **Fluid templates** and the
TYPO3 backend styleguide (`<core:icon>`, `<f:translate>`, Bootstrap 5 cards,
tables, list groups, badges and button groups), and it exposes three TYPO3
submodules (**Open items**, **All records**, **Checks and diagnostics**) through the
native module selector instead of custom in-page navigation. A small companion
JS file (`easy-workspace-module.js`) only wires
the rendered DOM and doc-header buttons into TYPO3 Core's `Modal` /
`AjaxRequest` / `Notification` APIs — no client-side rendering. The same
service layer powers the toolbar and the module; the toolbar Easy Workspace
element remains a separate compact entry point.

## Requirements

- Easy Workspace 14.0.0
- TYPO3 14.3 LTS only (`^14.3`)
- PHP 8.2-8.5
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
3. Click the **Workspace publish** icon (paper-plane with an orange dot) in the top-right toolbar, or open **Easy Workspace** in the left backend navigation.
4. Untick anything you don't want to publish yet, then hit **Publish to live**.

The toolbar item is automatically hidden while you are in the live workspace.
In a custom workspace it stays visible, even when the current page has no
pending changes.

The backend module follows the selected page from the page tree. If it is
opened for a news context, it can also request the existing news bundle API via
`newsUid`, so news records and their related content elements use the same
selection and publish behavior as page content.

### Backend module layout

The **Easy Workspace** backend module is the spacious, server-rendered view for
editors who need to review more than a compact toolbar dropdown can comfortably
show. TYPO3 registers it as a parent module with three submodules:

- **Open items** — the changed-only publish queue for the current page or news
  record. Rows are rendered in a
  `table-fit` Bootstrap table: select, scan title/type/state, then use the
  right-aligned history, edit and discard button group when needed. Related
  child records are hidden behind a disclosure instead of being spread across
  the list.
  Publishing submits the selection as a standard ``<form method="post">`` and
  the controller redirects back with a TYPO3 flash message.
- **All records** — every record on the page, with the same Bootstrap table row
  UI; selection is disabled here (read-only inventory view).
- **Checks and diagnostics** — automatic database scans for broken workspace
  records plus grouped health checks with suggested next steps.

The doc header carries the standard TYPO3 page breadcrumb, page title, TYPO3's
module selector, and the "show page", "copy preview link" and "edit page
properties" buttons registered via the ``ModuleTemplate`` button bar. Submodule
navigation uses TYPO3 backend module routes such as
`webcon_easy_workspace_pending` and preserves the current page/news context
through route parameters. There is no extra top-left navigation inside the
module body. The module uses TYPO3's icon API (``<core:icon>``), Bootstrap 5
tables, list groups, button groups, badges and ``--typo3-*`` design tokens so
it follows the backend styleguide and dark/light mode without custom layout
classes or custom theming in the module body.
It does not depend on Solr or any search extension.

The pending table also uses TYPO3 table state tokens for multi-selection:
checked rows render with the styleguide's selected/info table state, while
unchecked rows are softly de-emphasized only while a selection exists. The
selection treatment stays scoped to the Easy Workspace publish form and follows
TYPO3's dark/light mode variables.

### Personal user settings

Backend users get an **Easy Workspace** section in TYPO3's **User Settings**
module:

- **Use Easy Workspace** switches the toolbar and module off for that user even
  when the administrator TSconfig master switch is enabled. *(default on)*
- **Show related records in toolbar** hides or shows child detail rows in the
  top-right dropdown. *(default off — the toolbar stays compact unless the
  editor opts in)*
- **Show related records in module** controls whether child detail disclosures
  are available in the full backend module. *(default on)*

The two related-record switches only affect visibility. The server still
collects related child records, and the publish payload still includes them
when their parent row is selected.

## What the dropdown shows

The toolbar dropdown and backend module are intentionally scoped to the
editor's current backend context: the selected page or the currently edited
news record, the active workspace, and the currently chosen backend page
language.

Standalone file metadata is the one deliberate global addition: TYPO3 stores
uploaded files in live FAL immediately, but the publishable workspace record is
the associated `sys_file_metadata` row. These rows have no page/content parent,
so Easy Workspace appends pending metadata records from the active workspace to
the publish list and renders them with the underlying file name and thumbnail.

### One row per workspace record

TYPO3 stores workspace versions as extra rows in the same table as the live
record. For a modified record the workspace row points back to the live row via
`t3ver_oid`; for a newly created workspace-only record the workspace row itself
is the publishable record. When a page contains nested inline records, such as
Content Blocks collection items, the same logical item can be reachable through
both the live parent UID and the workspace parent UID.

Easy Workspace now normalizes the response before it reaches the Lit dropdown:

- existing records are keyed by `table + liveUid`
- new workspace-only records are keyed by `table + workspaceUid`
- duplicate matches from live/workspace parent lookup are dropped

This means one changed accordion item is rendered once, selected once, counted
once in the toolbar badge, and sent once to the publish endpoint.

### Related inline records and files

Changed inline records are listed with the record that owns them. This covers
TYPO3 file references (`sys_file_reference`) and other workspace-aware inline
child tables, for example Content Blocks collection items or custom address
records attached to a content element.

If only a child record changed and the parent content element itself has no
workspace row, Easy Workspace still adds the parent row as context and nests the
child change below it. The same rule applies to page properties: files or other
inline records attached to the page record appear below the page row.

Child changes are counted in the toolbar badge, included in the "Publish to
live" selection, and can be discarded through the same per-row rollback action
when TYPO3 allows that workspace operation. Image children render a small
processed thumbnail so editors can identify the changed file without opening
the full record.

### Workspace dependency guard

TYPO3 Workspaces resolves publish dependencies from `sys_refindex` before it
executes a publish command. Inline fields and file fields are structural
dependencies, so Core follows those references and creates `ElementEntity`
objects for the related records.

In real editor workflows, especially with generated Content Blocks collection
tables, `sys_refindex` can temporarily contain a stale inline reference. One
example is a dependency to `tabs_items:50` after that child row was already
removed or published by another operation. TYPO3 then throws
`#1393960943: Element "tabs_items:50" does not exist` during the Core
`/typo3/ajax/workspace/dispatch` request.

Easy Workspace registers a PSR-14 listener for
`IsReferenceConsideredForDependencyEvent`. It runs after TYPO3 Core's own
workspace dependency listener and checks that both sides of a dependency still
exist before Core instantiates the dependent element. Missing records are
ignored as stale references; valid inline dependencies are left untouched and
are still published or discarded through the normal TYPO3 Workspaces and
`DataHandler` flow.

### Only the chosen language

The toolbar JavaScript reads the selected backend language from TYPO3 module
state and, as a fallback, from visible backend/iframe URL parameters such as
`language`, `sys_language_uid` or `L`. That value is sent to the
`webcon_easy_workspace_items` AJAX endpoint as `languageUid`.

On the PHP side, `PendingItemsService` applies the language filter to page-bound
workspace-aware tables that expose a TCA language field (`ctrl.languageField`,
falling back to `sys_language_uid`). This affects page content, inline child
records and news bundles. Translated page and news records are resolved through
their TCA translation parent field (`ctrl.transOrigPointerField`, falling back
to `l10n_parent`) before the item is built, so page/news property changes follow
the same selected-language rule as content elements.

Standalone `sys_file_metadata` rows are intentionally not hidden by the selected
page language, because TYPO3's file metadata changes are root-level workspace
records and the Workspaces module shows default-language file metadata even when
a page language is selected.

If no language can be detected, the request behaves like before and does not add
a language constraint. That keeps non-page modules and unusual backend routes
from accidentally hiding all records.

## Configuration (TSconfig)

Every visible affordance is gated by a TSconfig flag — defaults ON, switch to `0` per **user / group / page** when a role doesn't need that feature. The extension ships [`Configuration/user.tsconfig`](Configuration/user.tsconfig), which TYPO3 v14 auto-loads from every active extension; no manual import.

| Group | Key | Default | Purpose |
|---|---|---|---|
| Master | `enabled` | `1` | Hides the toolbar item entirely when `0` (AJAX endpoints also respond `403`). |
| Header | `enableWorkspaceChip` | `1` | Workspace-name chip next to the title ("Staging"). |
| Header | `enablePreviewLink` | `1` | "Preview link" button (OS clipboard copy via `Workspaces\Preview\PreviewUriBuilder`). |
| Filter | `enableFilter` | `1` | Filter chip row ("To publish" / "All on page"). |
| Filter | `defaultMode` | `changed` | Initial mode: `changed` or `all`. |
| Rendering | `enableThumbnails` | `1` | TYPO3-processed image previews for rows and child rows. |
| Rendering | `enableTypeLabels` | `1` | Second meta line ("Page · Blog Post"). |
| Rendering | `enableHiddenBadge` | `1` | "Hidden" badge + diagonal-stripe thumbnail overlay. |
| Rendering | `showHidden` | `1` | When `0`, hidden records are filtered out **server-side**. |
| Rendering | `maxItems` | `200` | Hard cap on rows per request. |
| Scope | `enableNewsBundles` | `1` | Also list news on the page + their linked content elements. |
| User settings | `userEnabledDefault` | `1` | Default for the personal "Use Easy Workspace" switch. |
| User settings | `showSubelementsInToolbar` | `0` | Default for showing related child rows in the top-right dropdown. **Off** by default so the toolbar stays compact; editors opt in via User Settings. |
| User settings | `showSubelementsInModule` | `1` | Default for showing related child rows in the backend module. |
| Per-row | `enableHoverHighlight` | `1` | Eye icon → scroll + outline the CE in Visual Editor, Viewpage, or another same-origin preview iframe. |
| Per-row | `enableRevert` | `1` | Discard button using TYPO3 v14's DataHandler `discard` cmd. |

**Override precedence** (highest wins): Page TSconfig → User TSconfig on user → User TSconfig on group → defaults.

Personal user settings are applied after TSconfig defaults. The administrator
master switch `enabled = 0` still disables the feature for everyone.

**Server-side enforcement.** Every boolean flag is read by `Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider` (via `BackendUserAuthentication::getTSConfig()` and `BackendUtility::getPagesTSconfig()` — public v14 APIs) and *also* checked in `EasyWorkspaceAjaxController`: e.g. when `showHidden = 0` the hidden rows never reach the response payload, and toggling `enableRevert = 0` returns `403` on the discard endpoint so DevTools can't bypass it.

**[Full configuration reference](Documentation/Configuration.rst)** — every key with its server-side consequences, plus ready-made TSconfig examples.

### Preview link & OS clipboard

The "Preview link" button calls `\TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder::buildUriForPage($pageUid)` (TYPO3 v14's public API) and copies the resulting URL straight to the **operating-system clipboard** via `navigator.clipboard.writeText()` (with a hidden-textarea + `document.execCommand('copy')` fallback for non-secure contexts). It does **not** use TYPO3's record clipboard.

### Eye-icon: locate CE in the rendered preview

Every locatable row in the dropdown has an **eye icon** (TYPO3's
`actions-eye`) next to the title. Hovering or focusing the eye reaches into the
rendered page iframe via `iframe.contentDocument`, locates the rendered content
element by its standard TYPO3 id (`#c{uid}`, with
`[data-uid][data-table=tt_content]` and `[data-typo3-record-uid]` as
fallbacks), and:

- **Scrolls the element into view** in the iframe via `scrollIntoView({ behavior: 'smooth', block: 'center' })` — great for long pages where the CE is well below the visible viewport.
- Applies an inline outline + soft glow so the editor can immediately see *which* CE the dropdown row refers to.

**Iframe lookup order** (first hit wins): `#visual-editor-iframe` (friendsoftypo3/visual-editor) → `#tx_viewpage_iframe` (typo3/cms-viewpage) → any iframe with a name/id matching `*page-preview*` / `*pagepreview*` / `*preview*` → any same-origin iframe with a readable `contentDocument`.

**Adaptive labeling.** The tooltip reads *"Show in Visual Editor"*, *"Show in page preview"*, or *"Show in preview"* depending on which extensions are detected on the server side (`ExtensionManagementUtility::isLoaded('visual_editor')` / `'viewpage'`). The notification shown when no iframe can be located also adapts and points the editor to the right module.

Both effects are reverted on `mouseleave` / `blur` and again on element
disconnect. Clicking the eye triggers the same scroll-and-highlight as hovering,
useful on touch devices.

Inline child rows need one extra step. A Content Blocks collection item such as
`accordion_items` does not render its own frontend wrapper with an id like
`#c123`; the rendered DOM belongs to the parent `tt_content` element. The server
therefore attaches a locate target to inline child rows:

- `locateTable = tt_content`
- `locateLiveUid = <parent live uid>`
- `locateWorkspaceUid = <parent workspace uid>`

The JavaScript uses that locate target when deciding whether to show the eye and
when searching the iframe. This is why rows for accordion items can now still
jump to the visible accordion content element even though the row itself is not
a `tt_content` record. The affordance can be switched off via
`options.webcon_easy_workspace.enableHoverHighlight = 0`.

If the eye appears disabled or cannot find the element, the usual causes are:

- no Visual Editor/Viewpage/same-origin preview iframe is open
- the frontend template does not expose a `#c{uid}` or equivalent data attribute
- the content element is not rendered in the currently selected language
- the row is a non-content record without a `tt_content` locate target

### Discard a single change

Next to the eye or edit action, every **changed** row also has a curved-arrow
**discard** button (TYPO3 core's `actions-undo` SVG). Page-property rows,
content elements, news records, file references and workspace-aware inline child
records use the same affordance. *Discard* is TYPO3's own term for this
operation and maps directly to TYPO3 v14's DataHandler `discard` command, which
removes the workspace version. Clicking opens a `Modal.confirm()` with
`SeverityEnum.warning` and a `btn-warning` confirm action. On confirm the
toolbar POSTs to the `webcon_easy_workspace_discard` backend AJAX route
(`/webcon-easy-workspace/discard`), which runs `DataHandler` with the v14
command:

```php
$cmd[$table][$workspaceUid]['discard'] = true;
```

This deletes the workspace version of that record only — the **live** row stays
untouched. The dropdown auto-refreshes after the discard so the row disappears
(in "Changes only" mode) or its badge flips back to "Live" (in "All on page"
mode). If the discarded item is a nested child, the parent context row updates
with the remaining child changes.

Disable per user/group/page via `options.webcon_easy_workspace.enableRevert = 0`.

### Diff and history

Changed rows can open a server-rendered diff/history modal through the `webcon_easy_workspace_diff` backend AJAX route (`/webcon-easy-workspace/diff`). The modal shows the record's workspace diff and recent `sys_history` entries. Its rollback buttons post to `webcon_easy_workspace_history_rollback` (`/webcon-easy-workspace/history-rollback`); TYPO3's own DataHandler and backend permission checks still decide whether a rollback is allowed.

## Architecture

```
Classes/
├── Backend/ToolbarItem/EasyWorkspaceToolbarItem.php   # Toolbar registration + config injection
├── Configuration/ConfigurationProvider.php            # Reads & normalizes TSconfig
├── Controller/Backend/EasyWorkspaceModuleController.php # Backend module: Fluid render + publish/discard POST
├── Controller/Backend/EasyWorkspaceAjaxController.php # Backend AJAX endpoints (toolbar + module modals)
├── EventListener/IgnoreMissingWorkspaceDependencyReference.php
│                                                          # Drops stale workspace dependency refs
├── Service/
│   ├── PendingItemsService.php                       # Aggregates page, CE, news + related children
│   └── PublishSelectedService.php                    # DataHandler publish cmdmap + discard
└── Dto/PendingItem.php

Configuration/
├── Backend/AjaxRoutes.php                            # items, publish, preview, discard, diff, rollback
├── Backend/Modules.php                               # Parent module + Open items/Records/Checks and diagnostics submodules
├── JavaScriptModules.php                             # `@webconsulting/webcon-easy-workspace/` import map
├── RequestMiddlewares.php                            # TYPO3 backend middleware registration
├── Services.yaml                                     # DI / autowiring
├── TCA/Overrides/be_users.php                        # User Settings toggles
└── user.tsconfig                                     # Auto-loaded TSconfig defaults

Resources/
├── Private/Language/Modules/                         # Backend module labels
├── Private/Templates/Backend/EasyWorkspace/Index.html # Module shell template
├── Private/Partials/Backend/Module/                  # Server-rendered module pieces:
│   ├── Placeholder.html / FlashMessage.html          #   empty / message states
│   ├── RecordRow.html / ChildChange.html             #   table row + child list-group rows
│   ├── PublishBar.html                               #   publish form action bar
│   └── Section/{Pending,All,Diagnostics,Testing}.html #   section partials; Testing is embedded in Diagnostics
├── Private/Templates/ToolbarItems/                   # Trigger + dropdown shell (Lit element)
├── Private/Templates/Diff/Record.html                # Workspace diff/history modal
└── Public/JavaScript/
    ├── easy-workspace-menu-element.js                # Toolbar dropdown (Lit element)
    ├── easy-workspace-module.js                      # Module: small vanilla JS for Modal/AjaxRequest/Notification
    └── visual-editor-decline-button.js               # FE-iframe discard helper

Build/
├── Scripts/runTests.sh                               # Local quality runner
└── phpstan/phpstan.neon                              # TYPO3 PHPStan config at level max
```

The PHP side uses only public TYPO3 v14 APIs (`ConnectionPool`,
`BackendUtility`, `DataHandler`, `ResourceFactory`, `TcaSchemaFactory`,
`ModuleTemplate`, `FlashMessageService`, `BackendUriBuilder`,
`PreviewUriBuilder`). The **toolbar** dropdown uses one `LitElement` rendered
into light DOM so backend Bootstrap / styleguide tokens apply automatically.
The **backend module** is fully server-rendered with Fluid partials, TYPO3
submodule routes, the TYPO3 icon API and Bootstrap 5 markup; its only JS
(~270 lines, no framework) wires the DOM and doc-header preview action to
Core's `Modal`, `AjaxRequest` and `Notification` modules. Extension-specific
CSS remains scoped to the toolbar dropdown, not to the backend module layout.

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
The repository uses `saschaegerer/phpstan-typo3` 3.0.1 with
`phpstan/extension-installer`, matching the current TYPO3 14/PHPStan 2
tooling. GitHub Actions runs the same checks on PHP 8.2, 8.3, 8.4 and
8.5.

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
