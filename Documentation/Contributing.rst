..  _contributing:

======================
Contributing & quality
======================

This page records how Easy Workspace is structured, how we keep it
maintainable, and what a **thermo-nuclear** code-quality review expects
from future changes.

The bar is higher than “it works”: behaviour must stay correct **and**
the implementation should leave the codebase simpler, more direct, and
easier to scan than before.

..  _quality-bar:

Quality bar
===========

Before merging substantial PHP or JavaScript changes, confirm:

* **No structural regression** — feature logic stays in the layer that
  already owns the concept (collection, policy, publish, diagnostics).
* **No unjustified file growth past ~700 lines** — decompose before
  crossing **1000 lines** in any single file.
* **No ad-hoc branching** — new ``if`` chains on unrelated paths belong
  behind a dedicated helper, scope object, or service.
* **Reuse canonical helpers** — ``WorkspaceTablePolicy``,
  ``PendingItemsService`` (including ``*ForContext()`` dispatchers),
  ``PublishSelectionNormalizer``, ``PublishSelectedService``, ``Value``,
  ``ConfigurationProvider``; do not duplicate their contracts.
* **PHPStan max** and ``composer test`` stay green.

Run locally:

..  code-block:: bash

    composer test
    Build/Scripts/runTests.sh -s phpstan
    Build/Scripts/runTests.sh -s lint

..  _layer-model:

Layer model
===========

Keep logic in the canonical layer. Controllers orchestrate; services own
behaviour; DTOs carry shaped data to Fluid/JSON.

..  code-block:: text

    Toolbar custom element + glue JS
            │
            ▼
    EasyWorkspaceAjaxController ──► PendingItemsToolbarRenderer (Fluid)
            │
    EasyWorkspaceModuleController (Fluid module)
            │
            ▼
    PendingItemsService (facade + context dispatch)
            │
            ├── PublishSelectionNormalizer ──► PublishSelectedService
            │
            ▼
    PendingItemsCollector
            │
            ├── PageCollectionScope / NewsCollectionScope
            ├── WorkspaceRecordQuery
            ├── PendingItemFactory + resolvers (label, media, URL, timeline)
            ├── InlineChildResolver
            └── PendingItemAggregator (dedupe, groups, parent context)
            │
            ▼
    PublishSelectedService ──► DataHandler (publish / discard)

Module path (server-rendered Fluid):

* ``ModuleSectionViewDataFactory`` — section payload and statistics
* ``EasyWorkspaceModuleDocHeaderBuilder`` — doc-header buttons

Parallel paths (same policy, different presentation):

* ``WorkspaceDiagnosticsService`` + ``WorkspaceTestingReportService``
* ``RecordDiffService`` + ``RecordHistoryTimelineService`` (AJAX diff modal)
* ``ConfigurationProvider`` — single TSconfig normalization

**JavaScript is glue only.** Context detection, AJAX refresh, checkbox
selection, TYPO3 modals, and iframe highlight live under
``Resources/Public/JavaScript/``. Markup and labels are server-rendered
with Fluid and XLF.

..  _file-size-inventory:

Review outcome (2026-06-06)
=========================

Post-refactor thermo-nuclear pass: **approved**. Prior decomposition
targets (module controller, publish selection parsing, AJAX context
branching) are resolved. No new spaghetti in shared paths.

Remaining optional work — not required for merge:

* Extract diff/history modal assembly from ``EasyWorkspaceAjaxController``
  when that file approaches ~500 lines
* Extract module JS label map if ``buildJsLabelMap()`` keeps growing
* Collapse ``PendingItemsService`` empty-payload helpers when a third
  collection context appears

..  _file-size-inventory:

File-size inventory (2026-06-06)
================================

Largest PHP units today (lines, approximate):

..  list-table::
    :header-rows: 1
    :widths: 55 15 30

    * - File
      - Lines
      - Notes
    * - ``EasyWorkspaceModuleController``
      - ~456
      - Request glue; section stats and doc-header extracted
    * - ``EasyWorkspaceAjaxController``
      - ~385
      - Items, publish, discard, diff, rollback; split diff/history if it grows
    * - ``ModuleSectionViewDataFactory``
      - ~170
      - Pending/all statistics and diagnostics payload
    * - ``EasyWorkspaceModuleDocHeaderBuilder``
      - ~176
      - Doc-header view/preview/edit buttons
    * - ``PublishSelectionNormalizer``
      - ~77
      - Shared publish selection parsing
    * - ``PendingItemsService``
      - ~285
      - Facade + context dispatchers
    * - ``InlineChildResolver``
      - ~530
      - Cohesive IRRE / Content Blocks recursion; monitor growth
    * - ``WorkspaceDiagnosticsService``
      - ~513
      - Single-purpose DB scan; acceptable while scan rules stay together
    * - ``PendingItemAggregator``
      - ~428
      - Dedupe, grouping, parent-context — correct home for this logic

Largest JavaScript units:

..  list-table::
    :header-rows: 1
    :widths: 55 15 30

    * - File
      - Lines
      - Notes
    * - ``easy-workspace-module.js``
      - ~543
      - Module glue (Modal, AjaxRequest, publish bar)
    * - ``menu-preview-locate.js``
      - ~337
      - Iframe detection + scroll/highlight

No file currently exceeds **1000 lines**. Treat that as a hard budget.

..  _known-hotspots:

Known hotspots & preferred remedies
===================================

These are **maintainability targets**, not open bugs. Address them when
touching the surrounding code — do not drive a large refactor for style
alone.

Publish selection parsing
    ``PublishSelectionNormalizer`` (``Classes/Utility/``) normalizes
    module POST ``table:workspaceUid`` strings and toolbar AJAX JSON
    objects into the cmdmap input for ``PublishSelectedService``.

Module controller
    ``EasyWorkspaceModuleController`` (~456 lines) delegates section
    statistics to ``ModuleSectionViewDataFactory`` and doc-header buttons
    to ``EasyWorkspaceModuleDocHeaderBuilder``.

Page/news context
    ``PendingItemsService::resolveContext()``,
    ``toolbarCollectionForContext()``, ``hasChangesForContext()``, and
    ``listForContext()`` centralize page-vs-news-vs-none dispatch for
    toolbar AJAX and the backend module.

``PendingItemsService`` facade repetition
    ``forPage`` / ``forNews`` / ``payloadFor*`` repeat workspace-id and
    empty-payload setup. The facade is still the **canonical API** for
    toolbar and module; minor duplication is acceptable until a third
    context appears.

``InlineChildResolver`` size
    Workspace-aware child traversal is inherently branchy. Prefer **new
    table-specific helpers** inside ``PendingItems/`` over more conditionals
    in ``PendingItemsCollector``.

AJAX diff and history
    ``diffAction`` and ``historyRollbackAction`` still live in
    ``EasyWorkspaceAjaxController``. Extract a dedicated renderer/service
    only if combined growth makes the controller hard to scan.

Module JS labels
    ``buildJsLabelMap()`` in the module controller is a static key list.
    Extract when adding substantial new module client strings.

..  _anti-patterns:

Anti-patterns to reject in review
==================================

* Pushing ``EasyWorkspaceModuleController`` or ``EasyWorkspaceAjaxController``
  past **1000 lines** without extracting view-data, modal, or doc-header
  builders.
* Adding feature checks to ``WorkspaceRecordQuery`` or
  ``PublishSelectedService`` that belong in ``ConfigurationProvider`` or
  ``WorkspaceTablePolicy``.
* Client-side publish eligibility — the server collection is authoritative.
* New thin wrappers that only rename an existing service method.
* Copy-pasted ``WorkspaceTablePolicy::isAllowed()`` gates instead of
  routing through one mutation helper.
* String-built HTML in PHP where Fluid templates already exist for the
  same UI.

..  _security-contributing:

Security expectations for contributors
======================================

See :ref:`security` in the main manual. When extending endpoints:

* Enforce ``enabled`` and feature flags server-side.
* Keep mutating routes **POST-only** with route tokens.
* Run new table names through ``WorkspaceTablePolicy``.
* Verify ``t3ver_wsid`` before cmdmaps.
* Return generic AJAX errors; do not leak exception messages.

Optional hardening backlog: explicit ``readPageAccess()`` before
``items`` / ``hasChanges`` / ``diff`` when a site needs stricter IDOR
prevention than Core backend defaults.

..  _documentation-contributing:

Documentation changes
=====================

When behaviour changes, update in the same PR:

* ``README.md`` — integrator-facing summary and feature list
* ``Documentation/Index.rst`` — architecture and feature inventory
* ``Documentation/Configuration.rst`` — new or changed TSconfig keys
* ``CHANGELOG.md`` — user-visible changes under **Unreleased**

Screenshots are not shipped in the manual; describe behaviour in prose
and rely on local backend access for visual verification.
