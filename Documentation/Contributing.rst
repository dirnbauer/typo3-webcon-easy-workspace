..  _contributing:

======================
Contributing & quality
======================

Keep changes in the smallest existing component that owns the behavior.
Prefer removing unused implementations to retaining them for reference;
Git history preserves previous versions.

Responsibilities
================

* Controllers handle requests, access checks and responses.
* ``PendingItemsService`` dispatches page/news contexts.
* The collector, scope objects, query service, factory and resolvers
  collect and present records; the aggregator handles deduplication.
* ``WorkspaceTablePolicy`` determines eligible tables.
* ``PublishSelectedService`` validates and executes Core DataHandler actions.
* Diagnostics remain read-only; seed data belongs in disposable databases.
* The toolbar uses Lit; the backend module and diff dialog use Fluid.

Do not introduce a parallel renderer, numbered asset entrypoints, custom
versioning, or additional service layers that only forward one call.
Use the extension's import-map prefix for JavaScript dependencies so Core
handles cache invalidation. Do not add request polling or session state
when an existing Core event supplies the signal.

Verification
============

..  code-block:: bash

    composer validate --strict
    composer test
    composer audit

PHPStan stays at maximum level. Do not hide new errors behind suppressions
or a baseline. Reproduce behavioral defects with a regression test before
changing the implementation. Functional tests must exercise the real
DataHandler, including foreign workspaces, permissions, Live mode, live-UID
resolution, repeated discards and writes without browser sessions.

For UI changes, open the toolbar and all extension modules in a local TYPO3
14.3 installation. Check selection, diff/history, preview, publish/discard,
asset loading and console errors. Keep optional integrations in the test
scope when their code changes. Do not send real notifications during tests.

Documentation
=============

Update the README, the relevant manual page and the Unreleased changelog
with behavior changes. Requirements must agree with Composer metadata.
Keep release history in the changelog; avoid copying old audit verdicts,
line-count inventories or obsolete feature lists into current instructions.

Security
========

Keep mutations POST-only, preserve route tokens, check workspace and table
permissions, and let Core DataHandler enforce record permissions. Treat
client selections as untrusted input. A missing draft in the active workspace
must never select another workspace's draft. Report private findings through
the advisory link in :ref:`security`.
