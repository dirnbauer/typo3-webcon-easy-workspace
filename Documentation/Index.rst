..  _start:

==============
Easy Workspace
==============

Easy Workspace 14.0.0 is a TYPO3 14.3 LTS backend extension for
publishing workspace changes from the backend toolbar and from a full
backend module.

The extension is v14-only. It requires TYPO3 14.3, PHP 8.2-8.5 and
the TYPO3 system extensions ``backend``, ``core``, ``fluid``,
``frontend`` and ``workspaces``.

..  _installation:

Installation
============

Install the extension with Composer:

..  code-block:: bash
    :caption: Composer installation

    composer require webconsulting/webcon-easy-workspace:^14.0
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

..  _features:

Features
========

* Toolbar dropdown for page, content element and news workspace records.
* Backend module below the TYPO3 Workspaces publish module with visible
  page breadcrumbs, TYPO3 submodules, show-page, preview-link and
  edit-page-property actions.
* One-click publish through TYPO3's ``DataHandler`` publish cmdmap.
* Per-record discard through TYPO3 14's ``discard`` command.
* Preview-link generation through
  ``TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder``.
* Field-level diffs and history rollback using TYPO3 backend APIs.
* Optional Visual Editor and Viewpage iframe highlighting.
* Language-aware page and record scoping for translated workspaces.
* Duplicate suppression for nested inline workspace records.
* Related file references and inline child records below their parent row.
* News bundle support through the same API when EXT:news is installed,
  including related content elements attached to news records.
* Stale workspace dependency guard for missing inline child references.
* TYPO3-processed preview thumbnails for image-bearing rows.
* Personal user settings for the global feature switch and related-record
  visibility in toolbar and module.

..  _record-scope:

Toolbar and module
==================

The toolbar dropdown is a compact Lit web component optimized for quick
publishing from the current backend context. The Easy Workspace backend
module is fully server-rendered with Fluid templates and the TYPO3 backend
styleguide; the underlying service layer is shared with the toolbar so both
entry points see exactly the same workspace records.

The module shows the standard TYPO3 doc header (breadcrumb, page title,
module menu and the "show page", "copy preview link" and "edit page
properties" buttons registered on the module template's button bar). TYPO3
registers Easy Workspace as a parent module with four submodules:

* **Dashboard** — a TYPO3 card with the active workspace, pending count,
  total page count and quick jumps to the other submodules.
* **Pending changes** — the changed-only publish queue. Rows stay dense and
  one-dimensional: select, scan title/type/state, then use the right-aligned
  history, edit and discard actions when needed. Related child records are
  hidden behind a disclosure instead of being spread across the list.
  Publishing submits the selection as a standard ``<form method="post">``;
  the controller publishes through ``DataHandler`` and redirects back with a
  TYPO3 flash message.
* **All records** — every record on the page (read-only inventory view).
* **Recent activity** — cross-page latest workspace changes with field-level
  diffs, rendered server-side without an accordion.

Navigation between submodules uses TYPO3's native module selector and backend
module routes such as ``webcon_easy_workspace_pending``. The current page/news
context is preserved through route parameters. A small companion JavaScript
file (``easy-workspace-module.js``) only wires the rendered DOM and doc-header
buttons into TYPO3 Core's ``Modal``, ``AjaxRequest`` and ``Notification``
modules for the discard, edit, diff and preview-link interactions — no
client-side rendering.

The module does not depend on Apache Solr or any third-party indexing
extension. Optional integrations, such as EXT:news and Visual Editor, are
detected through TYPO3 extension and TCA state.

The pending list is a bulk-action screen first and a review screen second.
The main path is selecting records and publishing them; deeper inspection
stays available through row actions and expandable related-record details.

Record scope
============

The dropdown is scoped to three things:

* the selected page or the currently edited news record,
* the active backend workspace,
* the selected backend page language.

Standalone file metadata is the exception to the page scope: pending
``sys_file_metadata`` rows from the active workspace are appended because they
have no page/content parent but are still publishable workspace records.

The JavaScript detects the page UID from TYPO3's module state and falls
back to the current URL's ``id`` parameter. For news records it also checks
the backend edit URL pattern ``edit[tx_news_domain_model_news][N]=edit``.
The backend module can pass an explicit ``newsUid`` and then requests the
existing ``forNews`` API path so news property changes and related content
elements are shown together.

The selected language is read from TYPO3 module state first. If no
language is available there, the JavaScript checks visible backend and
preview-frame URL parameters such as ``language``, ``sys_language_uid``
and ``L``. The value is sent to the
``webcon_easy_workspace_items`` AJAX route as ``languageUid``.

..  _language-aware-listing:

Language-aware listing
======================

TYPO3 stores translated content records with a language field, usually
``sys_language_uid``. The exact field can be customized through TCA, so
Easy Workspace reads ``ctrl.languageField`` first and only falls back to
``sys_language_uid`` when needed.

When a language UID is known, ``PendingItemsService`` adds that language
constraint to page-bound workspace-aware listing queries:

* page content records,
* inline child records such as Content Blocks collection items,
* news records stored on the page,
* content elements linked to news records.

Translated page and news property records are resolved before the item is
built. The service uses ``ctrl.transOrigPointerField`` and falls back to
``l10n_parent`` to find the translated record for the selected language.

If no language can be detected, no language constraint is added. This is
intentional: non-page backend routes and custom modules should not lose
all records just because they do not expose a language selector.

Standalone ``sys_file_metadata`` rows are not filtered by the selected page
language. TYPO3's Workspaces module also shows default-language file metadata
while a page language is selected, and these root-level records would otherwise
disappear from the publish selection.

..  _duplicate-suppression:

Duplicate suppression
=====================

Workspace versions are stored as additional rows in the same database
table as their live records. A modified workspace row points back to the
live row through ``t3ver_oid``. New workspace-only records have no live
counterpart and use their own workspace UID as their identity.

Inline child records can be reachable through both parent identities. For
example, a changed accordion item can match the live parent content
element UID and the workspace parent content element UID. Without a final
normalization step, that produces multiple dropdown rows for the same
logical publishable record.

The response is therefore de-duplicated server-side:

* existing records are keyed by table name and live UID,
* new workspace-only records are keyed by table name and workspace UID,
* later duplicates are removed before JSON is returned.

The toolbar count, the selected checkbox set and the publish payload all
use the normalized list. One changed nested record is therefore counted,
selected and published once.

..  _related-records:

Related records and thumbnails
==============================

Changed inline records are rendered with the record that owns them. This
includes TYPO3 file references, Content Blocks collection items and other
workspace-aware inline child tables. If only the child changed and the parent
record has no workspace row of its own, Easy Workspace still adds the parent
row as context and nests the child change below it.

The same relation handling is used for page properties and content elements.
Files attached to the page record appear below the page row. Files or other
inline records attached to a content element appear below that content element.

Related child changes are included in the toolbar count, the default
selection, publish operations and per-row discard operations. Image rows use
TYPO3's file processing API to return small preview images for the dropdown
instead of exposing the original file as the list thumbnail.

Standalone file metadata records are also included when they are pending in the
active workspace. TYPO3 does not workspace-version the physical ``sys_file``
row, so Easy Workspace publishes the associated ``sys_file_metadata`` version
and displays it with the actual file name and preview thumbnail.

..  _workspace-dependency-guard:

Workspace dependency guard
==========================

TYPO3 Workspaces resolves publish dependencies from ``sys_refindex`` before a
publish command is executed. Inline fields and file fields are structural
dependencies, so Core follows those references and creates dependency elements
for the related records.

Generated inline tables, for example Content Blocks collection tables, can
leave a stale reference behind for a short time after a child row has already
been removed or published. If Core follows that stale reference, the Workspaces
AJAX endpoint can fail with an exception such as
``Element "tabs_items:50" does not exist``.

Easy Workspace registers a PSR-14 listener for
``IsReferenceConsideredForDependencyEvent``. It runs after TYPO3 Core has
classified a reference as a workspace dependency, verifies that both referenced
records still exist, and clears the dependency flag for missing records.

Valid dependencies are not changed. They continue through TYPO3's normal
Workspaces dependency resolver and ``DataHandler`` publish or discard flow.

..  _locate-icon:

Locate icon
===========

Rows that can be mapped to rendered page content show an eye icon. On
hover, focus or click the JavaScript searches same-origin preview iframes
in this order:

1. ``#visual-editor-iframe`` from EXT:visual_editor.
2. ``#tx_viewpage_iframe`` from EXT:viewpage.
3. Other iframes whose id or name indicates a page preview.
4. Any same-origin iframe with a readable document.

For ordinary content elements the lookup tries the live UID and the
workspace UID against common frontend markers such as ``#c123``,
``[data-content-uid]`` and ``[data-typo3-record-uid]``.

Inline records need a parent locate target. A Content Blocks collection
record, for example ``accordion_items``, does not usually render its own
frontend wrapper. The visible DOM is part of the parent ``tt_content``
element. The server therefore adds ``locateTable``, ``locateLiveUid`` and
``locateWorkspaceUid`` to inline child rows. The JavaScript uses those
values to show the eye icon and to jump to the parent content element.

If the icon cannot jump, the most common causes are:

* no Visual Editor, Viewpage or same-origin preview iframe is open,
* the frontend template does not expose a recognizable content marker,
* the content element is not rendered in the selected language,
* the record is not a content element and has no parent locate target.

..  _configuration:

Configuration
=============

All editor-facing controls are configured through User TSconfig and
Page TSconfig.

TYPO3's User Settings module also contains an Easy Workspace section.
Editors can disable Easy Workspace for their account and independently
hide related child rows in the toolbar dropdown or in the backend module.
Hiding child rows is only a presentation setting: selected parent rows still
publish the related workspace records collected by the server.

..  toctree::
    :maxdepth: 2

    Configuration

..  _quality:

Quality
=======

Run the local checks before shipping changes:

..  code-block:: bash
    :caption: Quality checks

    composer test
    Build/Scripts/runTests.sh -s phpstan
    Build/Scripts/runTests.sh -s lint

PHPStan runs at ``level: max`` with PHP 8.2 as the minimum supported
runtime and ``saschaegerer/phpstan-typo3`` 3.0.1 for TYPO3 14 API
awareness.

The recent-activity feed reads each table's TCA schema before querying.
Tables without ``t3ver_wsid`` are skipped, and the deleted-row constraint is
only added when the schema declares TYPO3's soft-delete capability.
