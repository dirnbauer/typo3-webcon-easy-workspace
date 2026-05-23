..  _diagnostics:

=====================
Workspace diagnostics
=====================

Easy Workspace ships a **Checks and diagnostics** backend submodule. It is an
operational test suite for workspace records that are wrong, stalled or
risky enough that a publish button should not be trusted blindly.

The diagnostics are intentionally split into two groups:

* automatic database checks that can be proven from TCA and version fields,
* manual-only failure classes where TYPO3 cannot know editor intent or
  external system state.

Automatic checks
================

The module scans all workspace-aware TCA tables in the active workspace.
It currently detects issues that need attention:

..  list-table::
    :header-rows: 1

    * - Problem
      - Why it matters
      - Next step
    * - Live row carries workspace fields
      - A live row with ``t3ver_oid`` or ``t3ver_state`` set can be treated
        like a versioned row.
      - Confirm it is the live row, then reset ``t3ver_oid=0`` and
        ``t3ver_state=0``. Do not repair blindly if the row might be a moved
        or deleted placeholder.
    * - Unsupported ``t3ver_state``
      - TYPO3 v14 understands ``0``, ``1``, ``2`` and ``4``. Anything else
        is legacy or corrupt state.
      - Decide the intended state from history and references. Set the
        correct state or discard the workspace row through ``DataHandler``.
    * - Workspace row has no live identity
      - A row with ``t3ver_wsid > 0``, ``t3ver_oid = 0`` and a non-new state
        cannot be safely swapped into live.
      - If it is truly workspace-new, change it to ``t3ver_state=1`` through
        a controlled repair. Otherwise reconnect ``t3ver_oid`` or discard it.
    * - Workspace row points to missing live record
      - Publishing cannot swap into a live row that no longer exists.
      - Restore the live row from backup, reconnect to the correct live UID,
        or discard the orphan workspace version after editorial review.
    * - Duplicate workspace versions for one live record
      - The overlay may pick a different draft than the one the editor sees.
      - Compare history and timestamps, keep the intended draft, then discard
        or merge the other rows through TYPO3 APIs.
    * - Inline child has missing parent
      - Child rows from generated Content Blocks / IRRE tables can remain
        publishable but disappear from page-scoped review.
      - Restore or identify the parent ``tt_content`` row, update the foreign
        parent UID, or discard the child row.

The module prints inspection SQL per issue. Treat that SQL as a read-only
starting point. Repairs should use TYPO3 ``DataHandler`` where possible so
history, reference indexes and workspace dependencies stay coherent.

Manual-only checks
==================

Some workspace bugs cannot be found reliably by scanning records:

* physical FAL files were overwritten in place,
* folder-based file collections changed because live folder contents changed,
* external search indexes or CDN caches serve stale content,
* two valid drafts exist and only the editor knows which one is correct.

The module lists these classes separately. If automatic diagnostics are clean
but preview/live behavior is still wrong, work through the manual list before
changing database rows.

Seed broken records
===================

Use the seed command in a local DDEV/demo instance to create a controlled set
of broken workspace states:

..  code-block:: bash

    ddev typo3 webcon-easy-workspace:seed-diagnostics
    ddev typo3 webcon-easy-workspace:seed-diagnostics --execute --page=664 --workspace=1

The first command is a dry run. The second writes test records with the marker
``[WEW diagnostics seed]``. Re-running with ``--execute`` removes old seed rows
before inserting a fresh set.

After seeding:

1. Switch the backend to the seeded workspace.
2. Open **Content > Easy Workspace > Checks and diagnostics**.
3. Verify each seeded bug appears with a suggested next step.
4. Repair or discard the seed rows before continuing editorial tests.

The seed command writes deliberately inconsistent rows directly to the
database. Use it only on disposable local/demo data, never on production.

The Content Blocks child-table publish bug
==========================================

A real failure that motivated the diagnostics module:

* the toolbar showed two parent rows ready to publish,
* the actual pending rows were child records from ``article_grid_items`` and
  ``accordion_items``,
* the client posted all child rows correctly,
* the backend publish allow-list rejected those child tables,
* the publish endpoint returned success with ``0`` published.

The fix has three parts:

* publish validation accepts workspace-aware hidden child tables that use
  ``foreign_table_parent_uid``,
* empty publish selections return an error instead of a success toast,
* the toolbar refresh request is cache-busted after publish.

The diagnostic scanner now has checks for related classes of hidden broken
child records, especially child rows whose parent record is missing.
