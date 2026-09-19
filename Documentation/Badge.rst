..  _badge:

=================
Badge and refresh
=================

The toolbar badge shows the number of pending changes in the backend
user's active workspace. It is **context-free**: the number is the same on
the dashboard, in the list module or on any page, exactly like the count
in the Workspaces module. Only the dropdown list is scoped to the page or
news article the editor is working on; its header adds
"N on this page · M elsewhere".

..  _badge-server:

Server: one source of truth
===========================

``WorkspaceChangeCounter::count(int $workspaceId)`` returns a
``WorkspaceChangeCount`` DTO. For every counted table it runs a single
aggregate query::

    SELECT t3ver_state, COUNT(*) AS changes, MAX(tstamp) AS latest
    FROM <table>
    WHERE t3ver_wsid = :ws AND deleted = 0 AND t3ver_state IN (0, 1, 2, 4)
    GROUP BY t3ver_state

Counted tables are ``pages`` and ``tt_content``, plus
``tx_news_domain_model_news`` when EXT:news is installed
(``WorkspaceTablePolicy::BADGE_TABLES``, filtered by TCA presence and
workspace awareness). The change type follows ``t3ver_state``:
``1`` new, ``2`` deleted, ``4`` moved, ``0`` changed.

The DTO carries ``total``, ``byTable``, ``byState`` and a ``stamp``:
``sha1(workspaceId|total|maxTstamp)``. The stamp is deterministic and
changes as soon as a version is added, removed or edited, so clients can
compare it instead of diffing lists.

..  _badge-payload:

Payload
=======

``/badge`` and ``/has-changes`` (route names ``webcon_easy_workspace_badge``
and ``webcon_easy_workspace_has_changes``) return:

..  code-block:: json

    {
        "context": "page",
        "workspaceId": 4,
        "workspaceTitle": "Staging",
        "changedCount": 3,
        "byTable": {"pages": 1, "tt_content": 1, "tx_news_domain_model_news": 1},
        "byState": {"new": 1, "changed": 1, "deleted": 1, "moved": 0},
        "latestChangeAt": 1700000600,
        "stamp": "…40 hex characters…"
    }

``context`` echoes the client context (``page``, ``news`` or ``none``);
``pageUid``/``newsUid`` only select page TSconfig. ``/has-changes`` adds
``hasChanges``. The ``publish`` and ``discard`` responses embed the same
object under ``badge`` so the client never has to derive a count from a
list. A user in Live receives ``workspaceId: 0`` and ``changedCount: 0``.

..  _badge-client:

Client: BadgeSync
=================

``Resources/Public/JavaScript/menu-badge.js`` exports ``BadgeSync``, the
only code that writes ``host.badgeCount``, the DOM badge and the toolbar
visibility (hidden only when the server reports workspace ``0``).

..  _badge-seed:

First paint
-----------

``EasyWorkspaceToolbarItem`` renders the count into the toolbar markup::

    <span class="toolbar-item-badge badge badge-pill badge-warning"
          data-wew-workspace-badge data-wew-count="3" data-wew-workspace="4">3</span>

``BadgeSync.seed()`` adopts those attributes before the first request goes
out. The badge is therefore correct in the very first paint, and a failing
badge request can no longer blank it or hide the toolbar item. In Live the
item carries ``webcon-easy-workspace-toolbar--live`` (``display: none``) so
it is not visible before the script runs; ``BadgeSync`` keeps that class and
the ``hidden`` property in sync afterwards.

Triggers
--------

All funnelled through one 120 ms debounce:

* element connect, navigation events, dropdown open;
* Core document events on the top frame, the toolbar's own frame **and the
  module iframe's document**: ``typo3:datahandler:process``,
  ``typo3:pagetree:refresh``, ``typo3:workspace:changed``,
  ``typo3:workspaces:refresh``, ``typo3:module-state-storage:*`` and
  ``typo3-module-loaded``.

  ``typo3-module-loaded`` is what catches a classic FormEngine save: it
  posts the whole form inside the iframe, so it emits neither a DataHandler
  event nor a ``BroadcastChannel`` message — the iframe load is the only
  signal there is. The in-frame listeners are re-attached on every module
  load and catch events a Core module dispatches on its own ``document``;
* same-origin window messages ``typo3:editform:saved`` (FormEngine) and
  ``ve_saveEnded`` (Visual Editor), plus a ``wew-refresh`` message posted by
  the Visual Editor preview script for cross-origin previews;
* ``BroadcastChannel('webcon-easy-workspace')`` messages
  ``{type: 'refresh', reason, workspaceId, stamp, instanceId}`` — posted by
  the toolbar after its own publish/discard/rollback, by the Visual Editor
  preview script after ``ve_saveEnded`` and by the backend module on load.
  Messages from the own ``instanceId`` or with the current ``stamp`` are
  ignored;
* ``visibilitychange`` and ``focus``;
* a poll every 45 s plus up to 5 s jitter while the tab is visible. The
  poll pauses while hidden and backs off to 5 minutes after three
  consecutive errors.

Every request carries an id; a response is applied only when no newer
request was started. When the stamp changed and the dropdown is open, the
list is re-fetched. Publish and discard responses are applied through the
same path (``BadgeSync.apply``) before the list refresh. The badge pulses
once when the count increases (not on the initial load and not with
``prefers-reduced-motion``).

There is no TSconfig for these timings; they are constants in
``menu-constants.js``.
