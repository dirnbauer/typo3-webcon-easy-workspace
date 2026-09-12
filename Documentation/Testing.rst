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

Browser checks
==============

Use a disposable TYPO3 14.3.6+ instance with live and workspace versions of
a page and content records. Check the toolbar and every Easy Workspace
submodule, selection updates, diff/history, preview, publishing and discarding.
Verify live content after mutations and inspect console/server logs.
Optional Visual Editor and news integrations need their own representative
records when installed.

Badge checks: the count must match the Workspaces module on the dashboard,
in the list module and on a page; it must update after a FormEngine save, a
Visual Editor save, a publish/discard from the module frame, a change made
in a second tab and a CLI/MCP edit (within the 45 s poll). Keyboard: Arrow
keys, Space, Enter and Escape in the dropdown; both light and dark schemes.

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
