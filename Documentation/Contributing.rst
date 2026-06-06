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

    Toolbar Lit custom element + glue modules
            │
            ▼
    EasyWorkspaceAjaxController (JSON items payload)
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

..  _review-outcome:

Thermo-nuclear review (2026-06-06, release 1.1.0)
=================================================

**Verdict: pass.** PHPStan level ``max`` and ``composer test`` are green.
No file exceeds **1000 lines**. Canonical layers are respected; no new
ad-hoc branching in shared collectors or query helpers.

Completed the same day (post-refactor):

* ``EasyWorkspaceModuleController`` (456 lines) — section stats and
  doc-header extracted to dedicated services
* ``PublishSelectionNormalizer`` — shared module POST / toolbar AJAX
  publish selection parsing
* ``PendingItemsService`` context dispatchers — page/news branching
  removed from ``EasyWorkspaceAjaxController``

Prioritized findings (none are merge blockers):

#. **No structural regression** — pending-items pipeline, table policy,
   publish path, and diagnostics stay in the canonical layers.
#. **Optional code-judo** — ``EasyWorkspaceAjaxController::diffAction``
   still assembles timeline data and ~15 localized labels inline (~70
   lines). Extract a ``RecordDiffModalViewDataFactory`` (or similar)
   when that action grows or when diff labels are shared with the module.
#. **Watch list (~500–700 lines)** — ``InlineChildResolver`` (530),
   ``WorkspaceDiagnosticsService`` (513), and ``easy-workspace-module.js``
   (543) are cohesive but large. Decompose before crossing **700**;
   never cross **1000** without a compelling reason.
#. **Facade repetition** — ``PendingItemsService`` repeats workspace-id and
   empty-payload setup across ``forPage`` / ``forNews`` / ``payloadFor*``.
   Acceptable until a third collection context appears.
#. **Module label map** — ``buildJsLabelMap()`` (~35 lines) in the module
   controller is a static key list. Extract when adding substantial new
   module client strings.

Do **not** approve future PRs that:

* Push any single file past **1000 lines** without decomposition
* Add page/news ``if`` chains to ``PendingItemsCollector``,
  ``WorkspaceRecordQuery``, or ``PublishSelectedService`` instead of
  scope objects or ``PendingItemsService`` dispatchers
* Introduce thin wrappers or duplicate ``WorkspaceTablePolicy`` gates

..  _file-size-inventory:

File-size inventory (2026-06-06)
================================

Measured with ``wc -l``. Budget: decompose before **~700**; hard stop at
**1000**.

Largest PHP units:

..  list-table::
    :header-rows: 1
    :widths: 55 10 35

    * - File
      - Lines
      - Notes
    * - ``InlineChildResolver``
      - 530
      - IRRE / Content Blocks traversal; add table helpers here, not in collector
    * - ``WorkspaceDiagnosticsService``
      - 513
      - Single-purpose DB scan; keep scan rules together
    * - ``EasyWorkspaceModuleController``
      - 456
      - Request glue; stats and doc-header delegated
    * - ``PendingItemAggregator``
      - 428
      - Dedupe, grouping, parent context
    * - ``WorkspaceTestingReportService``
      - 426
      - Health-check grouping from diagnostics scan
    * - ``EasyWorkspaceAjaxController``
      - 385
      - AJAX orchestration; extract diff modal if ``diffAction`` grows
    * - ``RecordHistoryTimelineService``
      - 355
      - History timeline for diff modal
    * - ``WorkspaceRecordQuery``
      - 333
      - Workspace-aware DB reads
    * - ``PendingItemsCollector``
      - 332
      - Page/news scope orchestration
    * - ``RecordDiffService``
      - 304
      - Field-level diff payload
    * - ``PendingItemsService``
      - 285
      - Facade + ``*ForContext()`` dispatchers
    * - ``PublishSelectedService``
      - 264
      - DataHandler publish/discard cmdmaps
    * - ``ModuleSectionViewDataFactory``
      - 170
      - Section statistics and diagnostics payload
    * - ``EasyWorkspaceModuleDocHeaderBuilder``
      - 176
      - Doc-header view/preview/edit buttons
    * - ``PublishSelectionNormalizer``
      - 77
      - Shared publish selection parsing

Largest JavaScript units:

..  list-table::
    :header-rows: 1
    :widths: 55 10 35

    * - File
      - Lines
      - Notes
    * - ``easy-workspace-module.js``
      - 543
      - Module glue (Modal, AjaxRequest, publish bar)
    * - ``menu-preview-locate.js``
      - 337
      - Iframe detection + scroll/highlight
    * - ``components/wew-toolbar-menu.js``
      - ~780
      - Lit toolbar dropdown (light DOM, Visual Editor style)
    * - ``menu-actions.js``
      - ~290
      - Toolbar publish/discard refresh orchestration
    * - ``menu-toolbar-helpers.js``
      - ~80
      - Row/footer helpers shared by the Lit template

Toolbar JS is split into focused modules (``menu-context.js``,
``menu-selection.js``, ``menu-modals.js``, etc.). Keep new toolbar
behaviour in the smallest existing module rather than growing one file.

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
