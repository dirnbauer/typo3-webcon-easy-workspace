..  _badge:

=================
Badge and refresh
=================

The toolbar badge shows the number of pending changes **on the page (or
news article) the editor is currently on** — the page record, its content
elements and their inline children (Content Blocks collections, file
references) — the same rows the dropdown lists, not the whole workspace.
Where no page can be resolved at all (a module outside the Web group, the
dashboard) the badge falls back to the whole-workspace count, which is the
only meaningful number there. The dropdown header always names both:
"N on this page · M elsewhere".

The badge is **event-driven**: it asks the server when something was saved,
when the editor moves to another page or news article, and when another tab
of the same browser reports a change — never on a timer, on focus or on
tab visibility (see :ref:`badge-triggers`).

..  important::

    Changes made by **other editors** (or by scripts, the CLI, an MCP
    client) show up in an open backend at the **next navigation or save**
    in that backend, not by themselves. Up to 1.7.2 a 45-second poll and a
    focus trigger picked them up earlier, at the price of a badge request
    every three to four seconds per open tab while an editor worked — with
    1.7.2's per-request cost that kept one PHP worker permanently busy.

Two numbers are computed per request:

``changedCount``
    The whole workspace — ``WorkspaceChangeCounter``, one aggregate query
    per table (below).
``contextCount``
    The page or news article — ``ContextChangeSummary``, which runs the same
    collection the ``/items`` list is built from in count-only mode and
    caches the result under the workspace stamp. ``null`` when the client
    reported no context.

..  _badge-server:

Server
======

Whole workspace
---------------

``WorkspaceChangeCounter::count(int $workspaceId)`` returns a
``WorkspaceChangeCount`` DTO. For every counted table it runs one aggregate
query::

    SELECT t3ver_state, COUNT(uid) AS changes, MAX(tstamp) AS latest
    FROM <table>
    WHERE ((t3ver_oid = 0 AND t3ver_wsid = :ws) OR (t3ver_oid > 0 AND t3ver_wsid = :ws))
      AND deleted = 0 AND t3ver_state IN (0, 1, 2, 4)
    GROUP BY t3ver_state

The workspace condition is ``t3ver_wsid = :ws`` phrased for Core's
``(t3ver_oid, t3ver_wsid)`` index (``WorkspaceVersionConstraint``): no index
starts with ``t3ver_wsid``, so the plain form scans the table — 80 ms on a
Content Blocks ``tt_content`` with 940 columns, against 0.4 ms.

Counted tables are ``pages`` and ``tt_content``, plus
``tx_news_domain_model_news`` when EXT:news is installed
(``WorkspaceTablePolicy::BADGE_TABLES``). The change type follows
``t3ver_state``: ``1`` new, ``2`` deleted, ``4`` moved, ``0`` changed.

..  _badge-stamp:

The stamp
---------

The DTO carries ``total``, ``byTable``, ``byState`` and a ``stamp``:
``sha1(workspaceId|total|maxTstamp|revision)``. The row fingerprint
(``total``, ``maxTstamp``) alone does not move when a collection item, a
file reference or file metadata is edited, so the stamp also includes the
**workspace revision**: ``WorkspaceRevisionHook`` (a DataHandler
``processDatamapClass``/``processCmdmapClass`` hook) replaces a token in
``sys_registry`` after every DataHandler run that touched a workspace-aware
table — the workspace's own token for a run in a workspace, the Live token
for a run in Live or one that publishes. Every workspace's stamp includes
both. The stamp therefore moves exactly when a count can have changed, and
clients compare it instead of diffing lists.

..  _badge-page:

The page count
--------------

``ContextChangeSummary`` keys the page summary (count and changed records)
by workspace, page or news article, ``showHidden`` and ``maxItems``, and
keeps it while the stamp holds — switching modules on the same page or
returning to a page is a cache read. The cache ``webcon_easy_workspace``
(``SimpleFileBackend``, group ``system``) holds one small entry per page,
overwritten in place; entries also expire after five minutes, which bounds
how long a write that bypasses DataHandler (and misses the row fingerprint)
stays unseen.

On a miss, the page is collected like the dropdown list, in count-only mode
(no thumbnails, URLs or history timelines). The collection is built to stay
cheap on Content Blocks installations, where every collection field is a
base ``tt_content`` column and each element therefore carries a few hundred
inline fields whatever its CType:

- ``WorkspaceVersionPresence`` asks once — one ``UNION ALL`` over all
  workspace-aware tables — which tables hold any row of the workspace. A
  "changed rows" query against a table without one is skipped: it could not
  return anything.
- Inline children of all elements of the page are fetched with one query
  per distinct relation (table, foreign field, match fields), not one per
  element and relation, and handed to their parents in query order.
- ``BackendUtility::workspaceOL()`` runs only for rows that have a version;
  one lookup finds them for the whole page (``WorkspaceRecordQuery::overlayRows()``).
- In "changed" mode an element is built into an item only when it can be
  listed at all: a workspace row, an overlaid row, or one with changed
  children.
- IN lists stay below 200 values, the ``eq_range_index_dive_limit`` above
  which MySQL/MariaDB plan from index statistics — and ``t3ver_oid``, 0 on
  nearly every row, has statistics that make a full scan look cheaper.

On production data (page 1149, 40 elements, workspace "Staging") a badge
cost 15,393 queries and 2.3 s up to 1.7.2; it now costs 30 queries and
about 30 ms uncached, 11 queries and 6 ms from the cache. The list
(``/items``) went from 2.2 s to about 35 ms. A snapshot test
(``PendingItemsServicePageSnapshotTest``) pins every list, group and count
of a scenario with edits, new/delete/move placeholders, translations,
hidden elements, discarded drafts, collection children and file references
to what 1.7.2 returned.

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
        "contextCount": 2,
        "records": [
            {"table": "tt_content", "liveUid": 812, "workspaceUid": 1044},
            {"table": "pages", "liveUid": 1149, "workspaceUid": 1149}
        ],
        "changedCount": 3,
        "byTable": {"pages": 1, "tt_content": 1, "tx_news_domain_model_news": 1},
        "byState": {"new": 1, "changed": 1, "deleted": 1, "moved": 0},
        "latestChangeAt": 1700000600,
        "stamp": "…40 hex characters…"
    }

``context`` echoes the client context (``page``, ``news`` or ``none``);
``pageUid``/``newsUid`` select the page TSconfig **and** scope
``contextCount`` and ``records`` — the changed rows of that page, from
which the Visual Editor preview draws its decline buttons. ``/has-changes``
adds ``hasChanges``. The ``items``, ``publish`` and ``discard`` responses
embed the same object under ``badge`` — their requests carry
``pageUid``/``newsUid`` too, so the echoed badge is already scoped — and the
client never has to derive a count from a list or ask twice. A user in
Live receives ``workspaceId: 0`` and ``changedCount: 0``.

..  _badge-client:

Client: BadgeSync
=================

``Resources/Public/JavaScript/menu-badge.js`` exports ``BadgeSync``, the
only code that writes ``host.badgeCount`` (the workspace total, read by the
header chip), ``host.contextCount``, ``host.changedRecords``, the DOM badge
and the toolbar visibility (hidden only when the server reports workspace
``0``) — and the only code that decides when the toolbar talks to the
server. ``BadgeSync.badgeNumber()`` is the single place that decides what
the badge shows: ``contextCount``, or ``changedCount`` when it is ``null``.

..  _badge-seed:

First paint
-----------

``EasyWorkspaceToolbarItem`` renders the count into the toolbar markup::

    <span class="toolbar-item-badge badge badge-pill badge-warning"
          data-wew-workspace-badge data-wew-count="3" data-wew-workspace="4">3</span>

``BadgeSync.seed()`` adopts those attributes before the first request goes
out, so a failing badge request can no longer blank the badge or hide the
toolbar item. The server can only pre-fill the count when the backend
request itself names a page (``?id=``); otherwise it renders ``0`` and the
first ``/badge`` response fills in — the page is detected client-side from
``ModuleStateStorage`` and the module iframe URL, which no server request
can see. In Live the item carries ``webcon-easy-workspace-toolbar--live``
(``display: none``) so it is not visible before the script runs;
``BadgeSync`` keeps that class and the ``hidden`` property in sync
afterwards.

..  _badge-triggers:

Triggers
--------

A request goes out **only** for these, all funnelled through one 120 ms
debounce:

..  list-table::
    :header-rows: 1

    * - Signal
      - Request
    * - The toolbar element connects (backend page load)
      - ``/badge``
    * - Core events on the top frame, the toolbar's own frame and the module
        iframe's document: ``typo3:datahandler:process``,
        ``typo3:pagetree:refresh``, ``typo3:workspace:changed``,
        ``typo3:workspaces:refresh``, ``typo3-module-loaded``
      - ``/badge``
    * - Window messages: ``wew-refresh`` (any origin — the Visual Editor
        bridge), and same-origin ``typo3:editform:saved`` (FormEngine) and
        ``ve_saveEnded``
      - ``/badge``
    * - ``typo3:module-state-storage:update*:web`` (page-tree selection) or
        a preview frame reloading — **only when the page or news article
        changed**
      - ``/badge``, once the module has loaded (at most 1.5 s later)
    * - A ``BroadcastChannel`` message from another tab
      - none when that tab is on the same page (its payload is adopted),
        otherwise ``/badge``
    * - Dropdown opened, refresh button, the toolbar's own edit, rollback,
        publish or discard
      - the list (``/items``), which carries the badge block
    * - Focus, blur, clicks, tab visibility, time passing
      - **none**

``typo3-module-loaded`` is what catches a classic FormEngine save: it posts
the whole form inside the iframe, so it emits neither a DataHandler event
nor a ``BroadcastChannel`` message — the iframe load is the only signal
there is. The in-frame listeners are re-attached on every module load and
catch events a Core module dispatches on its own ``document``.

The Visual Editor (1.10) posts ``ve_saveEnded`` only down into its preview
frames, never to the backend. ``visual-editor-decline-button.js``, which
runs inside the preview, bridges it as one ``wew-refresh`` message to the
top window — the toolbar of that tab.

**One request at a time.** At most one request is in flight per tab;
signals that arrive meanwhile collapse into a single follow-up (dropped when
the answer already carries the stamp a channel message announced). A burst
— one Visual Editor save emits several signals — therefore costs exactly one
request. While the dropdown is open, a run fetches the list instead of
``/badge``: its response carries the badge block, so one request serves
both.

**Other tabs.** When a response moves the stamp, the tab posts
``{type: 'refresh', reason, workspaceId, stamp, instanceId, contextKey, payload}``
on ``BroadcastChannel('webcon-easy-workspace')``. A tab on the same page
applies ``payload`` without asking the server; a tab on another page asks
for its own count; a tab that already holds the stamp ignores the message,
so it cannot bounce back and forth.

When the stamp moved and the dropdown is open, the list is re-fetched.
Publish and discard responses are applied through the same path
(``BadgeSync.apply``). The badge pulses once when the count increases (not
on the initial load and not with ``prefers-reduced-motion``).

There is no TSconfig for these timings; they are constants in
``menu-constants.js``. ``Tests/JavaScript/badge-sync.test.js`` pins the
trigger matrix above, and ``editing-session.test.js`` a scripted five-minute
Visual Editor session: 13 requests, where 1.7.2 made 98.
