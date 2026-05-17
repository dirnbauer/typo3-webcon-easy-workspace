..  _start:

==============
Easy Workspace
==============

Easy Workspace 14.0.0 is a TYPO3 14.3 LTS backend extension for
publishing workspace changes from the backend toolbar.

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
* One-click publish through TYPO3's ``DataHandler`` publish cmdmap.
* Per-record discard through TYPO3 14's ``discard`` command.
* Preview-link generation through
  ``TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder``.
* Field-level diffs and history rollback using TYPO3 backend APIs.
* Optional Visual Editor and Viewpage iframe highlighting.
* Language-aware page and record scoping for translated workspaces.
* Duplicate suppression for nested inline workspace records.
* Related file references and inline child records below their parent row.
* TYPO3-processed preview thumbnails for image-bearing rows.

..  _record-scope:

Record scope
============

The dropdown is scoped to three things:

* the selected page or the currently edited news record,
* the active backend workspace,
* the selected backend page language.

The toolbar JavaScript detects the page UID from TYPO3's module state
and falls back to the current URL's ``id`` parameter. For news records it
also checks the backend edit URL pattern
``edit[tx_news_domain_model_news][N]=edit``.

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
constraint to every workspace-aware listing query:

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
