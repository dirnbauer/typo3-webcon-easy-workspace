..  _testing:

=========================
Testing and health checks
=========================

Automated checks
================

``composer test`` runs PHP lint, the coding-guideline dry run
(``composer cgl``), PHPStan at level 8 with ``phpVersion: 80400``, unit
tests and functional DataHandler tests. The functional suite defaults to
SQLite; ``typo3DatabaseDriver`` and the other framework database variables
select MariaDB/MySQL instead. CI runs the PHP job on 8.4 and 8.5 with a
MariaDB 10.11 service and a separate JavaScript job.

Run one suite with ``composer test:unit`` or ``composer test:functional``.
The badge counter tests load a tiny fixture extension
(``Tests/Functional/Fixtures/Extensions/news_stub``) that provides a
workspace-aware ``tx_news_domain_model_news`` table, so EXT:news is not a
dev dependency.

JavaScript
----------

..  code-block:: bash

    npm ci
    npm test

``vitest`` (jsdom) aliases the ``@webconsulting/webcon-easy-workspace/``
and ``@typo3/*`` import-map prefixes to the sources and to mocks in
``Tests/JavaScript/mocks``. The suite covers ``BadgeSync`` (server count
wins over the list count, stale responses, debounce, visibility-paused
polling with error backoff, BroadcastChannel filtering, stamp-driven list
refresh, DOM badge rendering) and the selection/grouping helpers. The
runtime still uses TYPO3's import map; ``npm`` is a dev-only dependency.

..  _testing-browser:

Browser scenario
================

``Tests/E2E`` holds a Playwright scenario that drives a **running** TYPO3
installation. It is not part of ``composer test`` and CI does not run it:
it needs an instance, a workspace and a content element to edit.

..  code-block:: bash

    npm ci
    npx playwright install chromium
    WEW_E2E_BASE_URL=https://example.ddev.site \
    WEW_E2E_PASSWORD='…' \
    WEW_E2E_WORKSPACE_ID=4 \
    WEW_E2E_PAGE_UID=103 \
    WEW_E2E_CONTENT_UID=1387 \
    npm run test:e2e

..  list-table::
    :header-rows: 1

    * - Variable
      - Meaning
    * - ``WEW_E2E_BASE_URL``
      - Instance root, e.g. ``https://example.ddev.site`` (default
        ``https://localhost``)
    * - ``WEW_E2E_USERNAME`` / ``WEW_E2E_PASSWORD``
      - Backend account, admin (default user ``admin``). Required.
    * - ``WEW_E2E_WORKSPACE_ID``
      - Workspace the scenario works in. Required.
    * - ``WEW_E2E_PAGE_UID`` / ``WEW_E2E_CONTENT_UID``
      - A page and one of its content elements. Required. Both stay
        untouched: every version the run creates is discarded again.
    * - ``WEW_E2E_RECORDS_MODULE``
      - Module used as the iframe host, default
        ``/typo3/module/content/records?id=<page>``. Point it elsewhere when
        a third-party extension breaks that module.
    * - ``WEW_E2E_ALLOW_PUBLISH``
      - ``1`` also runs the publish case. It writes to **Live**; off by
        default.
    * - ``WEW_E2E_EXTERNAL_CMD``
      - Shell command for the "another actor" case (receives ``WEW_UID`` and
        ``WEW_HEADER``), e.g. a CLI or MCP write. Without it the change is
        made through Core's DataHandler route from a detached fetch.
    * - ``WEW_E2E_BROWSER``
      - ``chromium`` (default), ``webkit`` or ``firefox``.
    * - ``WEW_E2E_STATE``
      - Cached session file, default ``.Build/playwright/state.json``. Core
        rate-limits logins, so the session is reused for 30 minutes.

``badge.spec.js`` is one reproduction per stale-count report: the count
rendered into the markup, a FormEngine save inside the module iframe (and
the same count in a second tab), the dashboard/Records/file list, module
navigation without a page reload, switching to Live and back, a change made
by another actor (within one poll interval), discarding in Core's Workspaces
module and — opt-in — publishing from the dropdown.

``dropdown.spec.js`` covers the dropdown itself: light and dark scheme,
keyboard navigation, accessible names, the dialog/live-region roles and the
loading, empty and error states. It writes the screenshots to
``Build/Reports/screenshots`` (``WEW_E2E_SHOT_DIR``).

The event-driven cases allow 15 seconds — a third of the poll interval —
so that a pass really proves the event path and not the poll. Run them
against an instance that answers in a second or two; a saturated PHP-FPM
pool makes them flaky for reasons that have nothing to do with the badge.

What the scenario cannot check: the Visual Editor and news integrations
(they need their own records) and live content after a real publish. Do
those by hand on a disposable instance, and inspect console and server logs
while you do.

Health checks
=============

Easy Workspace includes **Health checks** at the bottom of the
**Checks and diagnostics** backend submodule. They use the same scanner as the
diagnostics table, but present the result like a TYPO3 Reports screen: grouped
checks, status badges and suggested next steps.

Use it as a fast health check before trusting a workspace publish queue:

1. Switch the backend to the target workspace.
2. Open **Content > Easy Workspace > Checks and diagnostics**.
3. Review groups with warning, error, info or notice state.
4. Use the diagnostics tables above the reports for exact SQL and affected
   records when a database check fails.

Report groups
=============

Database integrity checks
    Runs automatic checks for invalid live version fields, unsupported
    ``t3ver_state`` values, workspace rows without live identity, orphan
    workspace versions and duplicate workspace versions.

Inline child publishing checks
    Highlights the class of failures where a parent content element appears
    publishable but generated hidden child rows, such as Content Blocks
    collection tables, remain pending or lose their parent relation.

Seed fixture coverage
    Lists the deliberately broken states covered by
    ``webcon-easy-workspace:seed-diagnostics``. The optional
    ``article_grid_items`` fixture reports whether that demo table is installed.

Manual-only checks
    Lists real workspace risks that cannot be proven from database metadata,
    for example overwritten FAL files, folder-based file collection drift,
    external cache/index drift and editor intent conflicts.

Local failure fixture
=====================

The workspace checks are most useful together with the seed command in
disposable local data:

..  code-block:: bash

    vendor/bin/typo3 webcon-easy-workspace:seed-diagnostics
    vendor/bin/typo3 webcon-easy-workspace:seed-diagnostics --execute --page=1 --workspace=1

After seeding, the workspace checks should move from green checks to grouped
warnings/errors for the seeded failure classes. The diagnostics tables should
show the same affected rows with inspection SQL. Clean the seed rows after the
test or restore the database snapshot.

Repair rule
===========

The workspace checks are intentionally read-only. They should tell editors and
integrators **what failed and what to do next**, but repairs should still use
TYPO3 APIs such as ``DataHandler`` whenever possible. Direct SQL updates are
only appropriate for controlled repair scripts after the row identity and
editorial intent are known.
