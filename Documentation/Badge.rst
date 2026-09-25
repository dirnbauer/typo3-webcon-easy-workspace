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

Where the changes come from
---------------------------

Easy Workspace shows the Workspaces module's data and keeps no list of its
own. ``CoreWorkspaceChanges`` asks core the way the module does:

- ``WorkspaceService::selectVersionsInWorkspace()`` finds the versions of the
  workspace (for one page, or for the whole workspace), with the editor's
  table and page permissions;
- ``CollectionService`` nests every record that depends on another one below
  it — collection items, file references, inline children — the step
  ``GridDataService`` runs before it renders the module's grid.

An entry is one row of that grid's top level; the versions nested below it
come with it and are published with it. A changed collection item or file
reference of an unchanged element is a row of its own, as in the module, and
a version whose fields equal the live record's is listed too. Both core
classes are ``@internal``: they are the module's own code path, which is the
point.

Whole workspace
---------------

``WorkspaceChangeCounter::count(int $workspaceId)`` returns a
``WorkspaceChangeCount`` DTO: ``total`` is the number of top-level rows,
``byTable`` counts them per table, ``byState`` tells new records from other
changes. A functional test (``WorkspacesModuleParityTest``) checks the total
against the module's own grid.

..  _badge-stamp:

The stamp
---------

The DTO's ``stamp`` is ``sha1(workspaceId|total|0|revision)``. The
**workspace revision** moves with every change: ``WorkspaceRevisionHook`` (a
DataHandler ``processDatamapClass``/``processCmdmapClass`` hook) replaces a
token in ``sys_registry`` after every DataHandler run that touched a
workspace-aware table — the workspace's own token for a run in a workspace,
the Live token for a run in Live or one that publishes. Every workspace's
stamp includes both. Clients compare the stamp instead of diffing lists.

..  _badge-page:

Caching
-------

Core's list depends on the editor's permissions, so the whole-workspace
count and the page summary (count and changed records) are cached per
editor in ``webcon_easy_workspace`` (``SimpleFileBackend``, group
``system``) — one entry per editor and workspace, or per editor and page,
overwritten in place. An entry is valid while the revision it was built at
is current, and for five minutes at most, which bounds how long a write that
bypasses DataHandler stays unseen.

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
