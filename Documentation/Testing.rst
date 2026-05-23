..  _testing:

================
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
    ``article_grid_items`` fixture is shown only when that demo table exists.

Manual-only checks
    Lists real workspace risks that cannot be proven from database metadata,
    for example overwritten FAL files, folder-based file collection drift,
    external cache/index drift and editor intent conflicts.

Local failure fixture
=====================

The workspace checks are most useful together with the seed command in
disposable local data:

..  code-block:: bash

    ddev typo3 webcon-easy-workspace:seed-diagnostics
    ddev typo3 webcon-easy-workspace:seed-diagnostics --execute --page=664 --workspace=1

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
