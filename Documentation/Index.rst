..  _start:

==============
Easy Workspace
==============

:Extension:
    webcon_easy_workspace

:Package:
    webconsulting/webcon-easy-workspace

:Version:
    1.0.3

:TYPO3:
    14.3 LTS only

:PHP:
    8.2 – 8.5

Easy Workspace adds a **backend toolbar dropdown** and a **backend module** so editors can publish workspace changes for the **current page** or **one news article** in a single action. All writes go through TYPO3 Core: ``DataHandler`` publish and discard cmdmaps, Workspaces preview URLs, record history, and workspace dependency resolution.

..  _installation:

Installation
============

..  code-block:: bash

    composer require webconsulting/webcon-easy-workspace:^14.0
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

Required system extensions: ``workspaces``, ``backend``, ``fluid``, ``frontend``.

..  _architecture:

Architecture
============

PHP owns data collection, policy, and markup. JavaScript is glue only: context
detection, AJAX refresh, event delegation, TYPO3 modals, and iframe highlight.

Toolbar flow
------------

#. ``EasyWorkspaceToolbarItem`` mounts ``<webcon-easy-workspace-menu>`` with TSconfig JSON.
#. The custom element calls ``items`` AJAX with page/news/language context.
#. ``PendingItemsService`` → ``PendingItemsCollector`` builds ``PendingItemsPayload``.
#. ``PendingItemsToolbarRenderer`` renders Fluid templates (ICU labels from XLF).
#. The response includes ``html`` plus a lightweight JSON item list for publish/discard glue.
#. User actions call ``publish``, ``discard``, ``diff``, or ``preview_link`` AJAX routes.

Module flow
-----------

#. ``EasyWorkspaceModuleController`` uses the same ``PendingItemsService`` server-side.
#. Submodule templates render tables with Fluid (``RecordRow`` partial).
#. Publish/discard use POST forms, not toolbar AJAX.

Shared services
---------------

* ``PendingItemsService`` — facade plus ``resolveContext()`` /
  ``toolbarCollectionForContext()`` / ``hasChangesForContext()`` /
  ``listForContext()`` for page, news, or no context
* ``WorkspaceRecordQuery`` — workspace-aware DB reads
* ``PendingItemFactory`` + resolvers — titles, URLs, thumbnails, badges
* ``InlineChildResolver`` — IRRE / Content Blocks children
* ``PendingItemAggregator`` — grouping, deduplication, parent context
* ``WorkspaceTablePolicy`` — canonical table allow-list
* ``PublishSelectionNormalizer`` — module POST and toolbar AJAX publish
  selections → cmdmap input
* ``PublishSelectedService`` — ``DataHandler`` cmdmaps

Module-only helpers:

* ``ModuleSectionViewDataFactory`` — pending/all statistics and diagnostics
  section payload
* ``EasyWorkspaceModuleDocHeaderBuilder`` — doc-header view, preview, edit
  buttons

..  _feature-inventory:

Complete feature inventory
==========================

The following tables list **every** editor-facing and integrator-facing
capability shipped by this extension.

Toolbar
-------

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Feature
      - Behaviour
    * - Toolbar item registration
      - ``EasyWorkspaceToolbarItem``; hidden when Live workspace or ``enabled = 0``
    * - Orange change indicator
      - ``hasChangesAction`` polling; counts normalized pending rows
    * - Dropdown menu
      - Server-rendered Fluid HTML injected by glue JS; native popover top-layer
    * - No-context state
      - Message when no page/news context is detectable
    * - Loading state
      - Spinner while ``items`` AJAX runs
    * - Header workspace chip
      - Active workspace title (``enableWorkspaceChip``)
    * - Header preview link button
      - Copies ``PreviewUriBuilder`` URL to OS clipboard (``enablePreviewLink``)
    * - Filter tabs
      - **To publish** vs **All on page** (``enableFilter``, ``defaultMode``)
    * - Publish bar
      - Select all / publish selected via ``publish`` AJAX
    * - Per-row checkbox
      - Checked by default for changed rows
    * - Per-row discard
      - Modal + ``discard`` AJAX (``enableRevert``)
    * - Per-row diff pill
      - Opens ``diff`` AJAX in ``Modal.advanced``
    * - Per-row edit link
      - FormEngine when available
    * - Per-row eye icon
      - Preview iframe scroll/highlight (``enableHoverHighlight``)
    * - Thumbnails
      - Processed file images (``enableThumbnails``)
    * - Type + state labels
      - Second line and badges (``enableTypeLabels``, ``enableHiddenBadge``)
    * - Child change rows
      - Optional in toolbar via user setting ``showSubelementsInToolbar``
    * - News context detection
      - Client reads iframe URL / edit form (``enableNewsBundles``)
    * - Language parameter
      - ``languageUid`` sent from module state or URL fallbacks

Backend module
--------------

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Feature
      - Behaviour
    * - Parent module ``webcon_easy_workspace``
      - Under **Content**, after Workspaces publish; page tree navigation
    * - Submodule **Open items**
      - Publish queue; POST form ``_action=publish``
    * - Submodule **All records**
      - Full list; selection disabled
    * - Submodule **Checks and diagnostics**
      - ``WorkspaceDiagnosticsService`` scan + health reports
    * - Doc header breadcrumb
      - Current page title from permitted page record
    * - Show page button
      - Core view button with rootline
    * - Preview link button
      - Opens preview URL or AJAX fallback
    * - Edit page properties
      - Contextual record edit when user may edit page
    * - Flash messages
      - Publish/discard feedback after redirect
    * - Section statistics
      - Changed count, CE count, affected tables, last editor/time
    * - Related child disclosures
      - Controlled by ``showSubelementsInModule`` user setting
    * - Module JavaScript
      - ``easy-workspace-module.js``: Modal, AjaxRequest, Notification only
    * - Live workspace guard
      - Module body shows disabled message when workspace id is 0

Record types and scope
----------------------

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Scope
      - Included records
    * - Page
      - ``pages``, ``tt_content`` on page, inline children, ``sys_file_reference``
    * - News article (detail / edit)
      - ``tx_news_domain_model_news`` + related ``tt_content`` via ``tx_news_related_news``
    * - Workspace file metadata
      - ``sys_file_metadata`` pending in active workspace (not language-filtered)
    * - Inline / IRRE / Content Blocks
      - Workspace-aware child tables allowed by ``WorkspaceTablePolicy``
    * - Duplicate removal
      - Server normalizes live vs workspace-only keys before UI and publish
    * - Parent-only child change
      - Parent row added as context when child changed alone

Publishing and discard
----------------------

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Bulk publish
      - ``PublishSelectedService::publish()`` → version publish cmdmap
    * - Publish order
      - ``pages``, news, ``tt_content``, ``sys_file_metadata``, then other tables
    * - Workspace verification
      - ``belongsToWorkspace()`` before cmdmap
    * - Table allow-list
      - ``WorkspaceTablePolicy::isAllowed()`` on AJAX and module POST
    * - Discard
      - ``discard`` cmdmap; resolves live uid to workspace uid when needed
    * - DataHandler errors
      - Returned in JSON or flash messages (Core ``errorLog``)

Diff and history
----------------

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Field diff
      - ``RecordDiffService`` + Core ``DiffUtility`` markup
    * - Record history timeline
      - ``RecordHistoryTimelineService`` in diff modal
    * - Page history tab
      - Containing page timeline when applicable
    * - Linear rollback
      - ``historyRollbackAction`` mode ``linear``
    * - Field rollback
      - mode ``field`` with ``table:uid:field`` selector
    * - Rollback errors
      - Localized generic message (no exception leak)

Preview integration
-------------------

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Preview URL
      - ``PreviewUriBuilder::buildUriForPage()``
    * - Iframe detection order
      - Visual Editor → Viewpage → name/id heuristics → same-origin iframe
    * - Locate selectors
      - ``#c{uid}``, ``data-content-uid``, ``data-typo3-record-uid``
    * - Visual Editor decline script
      - ``VisualEditorDeclineButtonMiddleware`` when ``editMode`` and workspace > 0

Diagnostics and CLI
-------------------

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Automatic DB checks
      - Invalid version fields, bad ``t3ver_state``, orphans, duplicates, missing parents
    * - Inspection SQL hints
      - Read-only starting points per issue class
    * - Health check groups
      - Built by ``WorkspaceTestingReportService``
    * - Manual-only checklist
      - FAL, caches, external indexes, editorial conflicts
    * - Seed command
      - ``webcon-easy-workspace:seed-diagnostics`` (dry-run or ``--execute``)

Configuration and security
--------------------------

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - TSconfig namespace
      - ``options.webcon_easy_workspace.*`` (see :ref:`configuration-reference`)
    * - User Settings overrides
      - Per-user enable + subelement visibility
    * - Server-side gates
      - ``enabled``, ``enableRevert``, ``enablePreviewLink``, ``showHidden``, etc.
    * - Stale dependency listener
      - ``IgnoreMissingWorkspaceDependencyReference`` on ``IsReferenceConsideredForDependencyEvent``
    * - AJAX authentication
      - Backend user + route tokens; mutating routes POST-only
    * - Security reports
      - `GitHub Security Advisories <https://github.com/dirnbauer/typo3-webcon-easy-workspace/security/advisories/new>`__

..  _toolbar-and-module:

Toolbar and module (behaviour)
==============================

Both entry points share ``PendingItemsService`` and the same normalized item
list. The toolbar is optimized for speed; the module is server-rendered Fluid
with Bootstrap 5 / TYPO3 styleguide tables and cards.

The module does **not** depend on Apache Solr or search extensions.

..  _record-scope:

Record scope
============

See the feature tables above. Standalone ``sys_file_metadata`` is the deliberate
exception to page language filtering.

..  _news-handling:

News handling
=============

News is **not** scanned from storage folders. Per-article scope activates on:

* news detail preview URL with ``tx_news_pi1[news]=N``, or
* open news edit form ``edit[tx_news_domain_model_news][N]``.

Slug-only detail URLs need the edit form or explicit ``newsUid`` on module/AJAX
routes. Gated by :confval:`enableNewsBundles`.

..  _language-aware-listing:

Language-aware listing
======================

When ``languageUid`` is known, page-bound tables use TCA ``languageField`` and
translation parent resolution. File metadata stays visible across page language
selection (same as Core Workspaces behaviour).

..  _duplicate-suppression:

Duplicate suppression
=====================

Items are keyed by ``table + liveUid`` or ``table + workspaceUid`` for
workspace-only records before JSON/HTML output and publish payloads.

..  _related-records:

Related records and thumbnails
==============================

Inline children and file references nest under parents. Thumbnails use Core file
processing — never client-supplied paths.

..  _workspace-dependency-guard:

Workspace dependency guard
==========================

Stale ``sys_refindex`` targets for deleted inline rows are ignored by the
PSR-14 listener so publish does not fail with missing-element exceptions.

..  _locate-icon:

Locate icon
===========

Inline rows carry ``locateTable`` / ``locateLiveUid`` / ``locateWorkspaceUid``
pointing at parent ``tt_content``.

..  _security:

Security posture
================

A focused audit on **2026-06-03** confirmed prior fixes remain in place:

* **Table allow-list** on publish, discard, diff, and history rollback table names.
* **Active workspace** check on publish/discard cmdmap construction.
* **TSconfig master switch** on all AJAX actions including publish.
* **Parameterized SQL** in custom queries; literal ``orderBy`` columns only.
* **Generic AJAX errors** for preview build and rollback failures.
* **Server-rendered Fluid** for toolbar markup; ICU labels from XLF; trusted HTML injection.
* **POST-only** publish, discard, history rollback routes.

**Residual accepted risk (TYPO3 backend):** ``items``, ``hasChanges``, and ``diff``
do not call ``BackendUtility::readPageAccess()`` before loading data for a
supplied ``pageUid``. Mitigation today is backend login, workspace membership,
and Core record permissions on publish/discard. Sites with strict IDOR
requirements may add explicit page/news access checks in a future release.

Report vulnerabilities privately via GitHub Security Advisories, not public
issues.

..  _configuration:

Configuration
=============

..  toctree::
    :maxdepth: 2

    Configuration
    Diagnostics
    Testing
    Contributing

..  _maintainability:

Maintainability
===============

**Thermo-nuclear review verdict (2026-06-06): pass.**

``composer test`` and PHPStan level ``max`` are green. No file exceeds
**1000 lines**. The pending-items pipeline, table policy, publish selection
normalizer, and page/news context dispatchers sit in the canonical layers.

Completed refactor (same day):

* ``EasyWorkspaceModuleController`` (456 lines) — section stats and
  doc-header extracted
* ``PublishSelectionNormalizer`` — shared module POST / toolbar AJAX parsing
* ``PendingItemsService`` context helpers — page/news branching removed from
  toolbar AJAX

Watch list — decompose before **~700 lines**:

* ``InlineChildResolver`` (530) — IRRE / Content Blocks traversal
* ``WorkspaceDiagnosticsService`` (513) — workspace DB integrity scan
* ``easy-workspace-module.js`` (543) — module Modal / AjaxRequest glue

Optional backlog (not blockers):

* ``EasyWorkspaceAjaxController::diffAction`` — extract
  ``RecordDiffModalViewDataFactory`` when timeline/label assembly grows
* ``EasyWorkspaceModuleController::buildJsLabelMap()`` — extract when module
  client strings multiply
* ``PendingItemsService`` — ``forPage`` / ``forNews`` / ``payloadFor*`` still
  repeat empty-payload setup; acceptable until a third context appears

**Do not add** ad-hoc conditionals to shared collectors or query helpers.
Push new scope rules into ``PageCollectionScope`` / ``NewsCollectionScope``
or a dedicated resolver.

**Do not merge** PRs that push any file past **1000 lines**, scatter
page/news checks into collectors, or duplicate ``WorkspaceTablePolicy`` gates.

See :ref:`review-outcome` and :ref:`file-size-inventory` in
:ref:`contributing` for the full layer model, measured line counts,
anti-patterns, and contributor checklist.

..  _quality:

Quality
=======

..  code-block:: bash

    composer test
    Build/Scripts/runTests.sh -s phpstan
    Build/Scripts/runTests.sh -s lint

PHPStan level ``max`` with ``saschaegerer/phpstan-typo3`` 3.0.1.
