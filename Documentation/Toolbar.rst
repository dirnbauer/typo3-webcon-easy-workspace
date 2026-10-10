..  _toolbar:

================
Toolbar dropdown
================

The dropdown is rendered by the Lit element
``<webcon-easy-workspace-menu-v2>`` (light DOM, no build step). It reads
TSconfig, runtime flags and translated labels from its ``config``
attribute and fetches ``/items`` for the current page or news context.

Every number in the dropdown counts **edits**: one edited content element is
one change in the badge, the header, the select-all and the publish button,
however many translation copies TYPO3 made along with it (see
:ref:`toolbar-copies`). Each editor picks one of three layouts for the same
list (see :ref:`toolbar-layouts`).

..  _toolbar-anatomy:

Anatomy
=======

Header
    Core's workspace icon, the workspace name as the title
    (``enableWorkspaceChip``; "Workspace" without it), the stage of the
    listed edits as a Core badge ("Mixed stages" when they differ) and two
    icon buttons: open the Easy Workspace module for the current page, and
    refresh. Below, across the full width, one sentence with the numbers:
    "8 changes on this page, 2 of them in other languages · 4 unchanged
    copies · 3 more in this workspace", or "No changes on this page" when
    there are none. A number never wraps away from its noun.

Groups
    One group for the page or news article (record icon, title and
    rootline path) and, when present, one for workspace-wide file metadata.
    The group's edit count shows when there are two groups. Within a group
    the edits of the current view come first, then the other languages and
    the unchanged copies, see :ref:`toolbar-languages`.

Rows
    One row per edit. Column one is the selection checkbox (a Core
    ``.form-check``), column two the record icon or thumbnail. Line one
    holds the title (``lang``-tagged with the record's language, the full
    title in its tooltip); line two starts with the change type as a Core
    badge (new / changed / deleted / moved, so the badges form one column),
    then the hidden badge, the language code where it is not obvious, and
    the meta text: type · column · the element a collection item or file
    reference belongs to · time · author ("by you" for the editor's own
    edits). The meta text shows whole parts only: a part that does not fit
    leaves the line, it is never cut mid-word. Below follow, optionally, the
    related child changes (``showSubelementsInToolbar``) and the
    translations line (see :ref:`toolbar-copies`).

    The actions are Core borderless buttons: edit, changes and history,
    show in preview (``enableHoverHighlight``; only for a row the page shows
    in the current language) and, last and set apart, discard
    (``enableRevert``). Clicking anywhere on a row that is not a button
    toggles its checkbox.

Footer
    Select-all from two edits on ("All (5)" when everything is selected,
    "2 of 5" otherwise; with one edit the publish label carries the count),
    the Preview split button (open in a new tab or copy the preview link,
    ``enablePreviewLink``) and the primary "Publish 1 change" button. With
    nothing selected the button reads "Publish to live", disabled, and
    names why to screen readers. A visually hidden status region announces
    silent outcomes ("List updated").

States
    Loading skeleton in the shape of the rows, empty ("Nothing pending",
    with "View 3 changes elsewhere" when the workspace has more), no context
    (with a button to the module) and error with a retry button.

The checkbox carries the selection; rows are not tinted.

..  _toolbar-copies:
..  _toolbar-unchanged:

Translation copies
==================

Saving a default-language element in a workspace makes TYPO3 version its
translations too: Core's ``DataMapProcessor`` writes the fields that
translations share with their parent (``l10n_mode=exclude``; for
``tt_content`` ``CType``, ``colPos``, ``starttime``, ``endtime``) into every
translation on every save, without comparing values, and DataHandler
versions each translation and its file references. The translations' content
does not change. The Workspaces module lists every copy as a change; up to
1.10 the dropdown did as well, so one edit read as "4".

Since 1.11 the server classifies the workspace once per revision
(``CompanionClassifier``, inside the cached core scan):

- A **companion** is a translation version whose differences from live
  mirror its parent's version exactly: identical content, the same synced
  values (a start time set on the parent), or the same move or delete.
  Nested records (file references, collection items) must mirror too.
- An entry with a difference of its own is a **change**: an edit by a
  translator, a new record, a move or delete of its own.
- An entry that changes nothing and has no edited owner is a **copy**, for
  example after saving without changes or after its owner was discarded in
  the Workspaces module.

Companions ride on their owner's row as one line per kind, with a chip per
language:

- "Translations unchanged: DE ZH HU" — identical copies; a chip opens that
  translation for editing.
- "Also applies to the translations (Start time): DE ZH HU" — synced fields,
  named by their TCA labels; chips edit.
- "Translations move along: DE ZH HU" and "Translations are deleted along:
  DE ZH HU" — plain codes.

They do not count, they are not rows of their own, and they cannot be
selected apart from their owner. The server takes them along: publishing,
requesting review and approving the owner include its companions after
re-checking them on fresh rows (a translation edited in the meantime is left
out and reported); discarding the owner discards them too. A translation
that someone edited is never a companion, and the server never adds one to
a write.

Core acts on more than the list shows: its publish and discard take every
version nested below a record along, its publish and stage change start at
the record's outermost parent. A row with a version below or above it that
the editor's list leaves out (a file reference outside their file mounts, a
translated collection item below another editor's translated element) is
never a companion or a copy, and every record taken along is checked for
such versions again right before the write.

Copies collect in the group's cleanup section, "Unchanged copies" ("Drafts
without changes" in the TYPO3 Core layout), one line per element with a
chip per language. "Discard copies" removes them through
``/discard-copies``; the server checks every record again and keeps one that
was edited in the meantime, and one whose discard Core would extend to other
translations (see :ref:`toolbar-dependents`): the cleanup asks no question.
Discarding a copy changes nothing on the website.

..  _toolbar-dependents:

Translations Core discards along
--------------------------------

A discard is Core's discard, unchanged, as in the Workspaces module: Easy
Workspace sends DataHandler commands and writes no record itself. Core's
discard of a version removes every translation of the workspace whose
translation parent points at that version, and does so again for every
version it discards along (the records nested below it, the translations it
removes in turn). Core points translations at a version when the element is
new, and when it moves or deletes the element in the workspace. A translator
who edits such a translation afterwards keeps that pointer: discarding the
move or the deletion discards the translator's edit too. A translation
edited before the move points at the live element and stays.

So the list names these translations on the element before anyone clicks:
an entry's ``dependents`` are the other rows (and companions of other rows)
that Core's discard of it reaches through such a pointer and that change
something there, each with ``table``, ``workspaceUid``, ``languageUid`` and
``edited`` — ``false`` for a new translation, ``true`` for one with edits, a
move or a deletion of its own. The classification computes them from the
translation pointers as stored (``rawTranslationParent``, before they are
mapped to live uids). Every discard confirmation — the dropdown's in all
three layouts, the Visual Editor's decline button and the module's — names
them in two sentences: "New translations of this element are removed too:
German." and "Core discards these translations with it, including their own
edits: German, Hungarian." A record the list does not hold, or a payload
without dependents, gets the plain confirmation. The discard response counts
them (``dependentsDiscarded``), and the success message says how many went
along.

..  _toolbar-languages:

Languages
=========

A page with translations has changes the editor cannot see in the module's
current language. The list tells them apart:

- Every row carries the record's language (``languageUid``). The response
  names the site's languages (``languages``: id, title, flag icon, a short
  ``code`` such as "DE" and the ``hreflang``) and the languages the
  editor's module shows (``viewLanguages``): the page module and the Visual
  Editor keep their selected languages in core's module data; the client
  sends the module's identifier (``module``) and the server reads that
  module's data. A module without a language — a record list, the
  dashboard — reports ``null``.
- The edits of the view's languages come first, under an "English · in this
  view" line when more follows. The edits the page shows only after a
  language switch follow in a section: "Translations" in a view of the
  default language, "Other languages" otherwise, with a count and a short
  status ("not shown in English"). Its heading has a checkbox that selects
  or clears all of its edits (partly checked when some are selected). The
  section opens by itself when nothing of the group is in view; the
  "Efficient" layout also opens it while it holds five edits or fewer.
  Opened, one sub-heading per language (flag, title, count).
- Companions never create a language section: they stay on their owner's
  row whatever language the view shows.
- A language code appears on a row only where its language is not obvious:
  in a view of several languages, or in a module without one, on every row
  that is not in the view's (or the default) language.

The dropdown remembers which sections the editor opened until they move to
another page, news article or workspace.

..  _toolbar-layouts:

Layouts
=======

Every editor chooses how the dropdown presents the same list under
**User Settings → Easy Workspace → Dropdown layout**
(``webconEasyWorkspaceLayout``). Its first choice, "Site default", follows
User TSconfig ``options.webcon_easy_workspace.layout`` (``clarity`` when
unset; a value no layout answers to falls back to it). Data, counts, selection, publish,
discard and the cleanup behave the same in all three; colours come from
Core's tokens, light and dark, and every motion stops under
``prefers-reduced-motion``.

``clarity`` — "Clear" (default)
    Explains what goes live. The header leads with the edit count in full
    ink. Every edit is a small card with a slim bar in its change-type
    colour; the translations line is the card's foot, behind Core's
    translate icon. Selected cards stand raised, an unselected card lies
    flat and its bar turns grey. Once, a callout explains in plain words why
    TYPO3 made the copies ("Your translations stay as they are");
    "Got it" hides it for good — stored in the editor's ``uc`` under
    ``webconEasyWorkspace.copiesNoteSeen`` through Core's persistent storage
    (remove that key to show it again). The cleanup discards without a
    confirmation, since nothing on the website changes.

``efficiency`` — "Efficient"
    Dense, a mail-client list. The title has line one to itself; with a
    mouse the actions appear at its end while the row is hovered or has
    keyboard focus (always on touch). Locate sits on the type icon: hovering
    turns it into an eye and highlights the element in the preview,
    clicking scrolls to it. Unselected rows mute their title and icon. The
    translation chips carry a pencil. A folded section says how many of its
    edits are selected; the cleanup starts folded. Keyboard shortcuts, named
    in the footer: :kbd:`Delete` / :kbd:`Backspace` on a row opens the
    discard confirmation, :kbd:`Ctrl` / :kbd:`Cmd` + :kbd:`Enter` publishes
    the selection, :kbd:`A` selects or clears all. They are ignored with
    other modifiers, in text fields and in the preview menu.

``core`` — "TYPO3 Core"
    The backend's own idiom, accessibility first. Rows read like a Core
    list: edge to edge, separated by hairlines, the title on up to two
    lines with the change badge at the end of line one, so all badges form
    one column; on hover a rail in the change colour slides in at the row's
    edge. The checkbox's description reads the badge, the meta line and the
    translations in order. The cleanup is called "Drafts without changes";
    the section and each element have their own discard, and both ask first
    in Core's confirmation dialog.

The toolbar item loads the three stylesheets after ``toolbar-menu.css``;
each is scoped to ``.wew-menu[data-wew-layout="<name>"]``, so a layout can be
switched without new assets.

..  _toolbar-keyboard:

Keyboard and screen readers
===========================

The container is a ``role="dialog"`` labelled by the title and described by
the summary. Groups and sections are headings with nested lists.

One stop of the list is in the tab order at a time, together with its
actions. The stops are the rows' checkboxes, the section toggles and the
first chip of each cleanup line. :kbd:`ArrowUp` / :kbd:`ArrowDown` (:kbd:`Home` / :kbd:`End`)
move between stops; :kbd:`Space` toggles the checkbox; :kbd:`Enter` on a
checkbox opens the editor. :kbd:`ArrowRight` steps from a row into its
actions and its translation chips, and opens a folded section;
:kbd:`ArrowLeft` steps back and folds the section. :kbd:`Escape` closes the
split menu, then the dropdown.

A row's checkbox is described by its meta line and its translations line,
which carries the full explanation of the copies as hidden text; the stage
badge is preceded by a hidden "Stage:". After a discard, a publish or a
cleanup, focus moves to the next row, the first row or the empty state's
heading; while the request runs it waits on the control that started it,
and stays there when the request fails. After a save from a translation
chip, focus moves to the translation's own row when the save made it an
edit, else back to the chip. When a new list removes the focused control
otherwise (a row whose edit made it equal to live), focus moves to the stop
now at its place. A modal opened from the dropdown reopens it when it
closes, except Core's "open in full form", which leaves the full form in
front; Core's plain record form reports no save, so the list is fetched
again whenever it closes.

..  _toolbar-styling:

Styling and dark mode
=====================

Styles live under ``Resources/Public/Css/``:

``tokens.css``
    ``--wew-*`` custom properties mapped to TYPO3 backend tokens
    (``--typo3-dropdown-*``, ``--typo3-surface-*``, ``--typo3-badge-*``,
    ``--typo3-state-*``), so light and dark schemes and site themes apply
    automatically. The toolbar's styles only read ``--wew-*``; the module's
    selected-row colours come from Core's table tokens.
``toolbar-menu.css``
    The shared dropdown: width (460 px, at least 320 px), the viewport's
    height below the top bar, the narrow layout below 25 rem (a container
    query), row enter and exit transitions with ``@starting-style``,
    forced-colours and reduced-motion rules. Badges, buttons and checkboxes
    are Core's own classes (``.badge``, ``.btn``, ``.form-check``).
``layouts/{clarity,efficiency,core}.css``
    One file per layout (see :ref:`toolbar-layouts`).
``module.css``
    The backend module.
``diff.css``
    The diff and history modal (loaded by both the toolbar and the module).

Override tokens in your own backend stylesheet, for example
``:root { --wew-accent: var(--typo3-state-primary-bg); }``. Colour values
appear only in ``tokens.css``: Core's tokens, plus the shadow inks for the
elevation Core has no token for (the scroll edge under the header and
footer, the raised cards of the "clarity" layout, ``--wew-shadow-*``). No
file draws a gradient. The outline and the discard tag that the
toolbar draws into preview frames (whose documents do not load the backend
stylesheet) use the backend's primary, success and danger colours, resolved
in the toolbar's own document and handed over as computed values.

..  _toolbar-files:

JavaScript modules
==================

``components/wew-toolbar-menu.js``
    The element: lifecycle, event handlers, discard confirmation, focus
    restoration.
``templates/{header,group,row,footer,states}.js``
    The shared Lit templates; their part renderers are the building blocks
    of the layouts.
``layouts/index.js``, ``layouts/{clarity,efficiency,core}.js``, ``menu-layout.js``
    The layout registry and the three profiles. A profile sets flags and
    renderer or behaviour hooks; ``layouts/index.js`` documents the
    contract.
``menu-badge.js``
    ``BadgeSync`` (see :ref:`badge`).
``menu-actions.js``
    List refresh, publish, discard, cleanup, preview and module navigation.
``menu-selection.js``, ``menu-toolbar-helpers.js``, ``menu-roles.js``
    Selection state (edits only), grouping by language and view, cleanup
    groups, change types, relative time.
``menu-dropdown.js``, ``menu-keyboard.js``
    Popover/Bootstrap plumbing and the roving focus stops.
``menu-decline-sync.js``
    Message protocol with the Visual Editor preview (per-element decline
    button, on edits only).
``menu-preview-locate.js``, ``menu-modals.js``
    Locating elements in preview iframes, diff/edit modals.

All imports use the ``@webconsulting/webcon-easy-workspace/`` import-map
prefix so TYPO3 versions their URLs.
