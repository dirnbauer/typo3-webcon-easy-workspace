..  _toolbar:

================
Toolbar dropdown
================

The dropdown is rendered by the Lit element
``<webcon-easy-workspace-menu-v2>`` (light DOM, no build step). It reads
TSconfig, runtime flags and translated labels from its ``config``
attribute and fetches ``/items`` for the current page or news context.

..  _toolbar-anatomy:

Anatomy
=======

Header
    The workspace name as the title (``enableWorkspaceChip``; "Workspace"
    without it), one sentence with the numbers ("N changes on this page ·
    M more elsewhere in the workspace", or "N pending in this workspace"
    outside a page context), the stage of the listed records as a Core
    badge ("Mixed stages" when they differ) and a refresh button.

Groups
    One group for the page or news article (record icon, title, rootline
    path, row count) and, when present, one for workspace-wide file
    metadata records. Within the page group the rows are ordered by
    language, see :ref:`toolbar-languages`.

Rows
    A selection checkbox (a Core ``.form-check``), record icon or
    thumbnail, title, the change type as a Core badge (new / changed /
    deleted / moved, the module's classes), a meta line with type, column,
    the element a collection item or file reference belongs to ("in …"),
    author and relative time, optional related child records
    (``showSubelementsInToolbar``), and the always-visible actions as Core
    borderless buttons: edit, changes and history, discard (``enableRevert``)
    and show in preview (``enableHoverHighlight``; not offered for a row the
    page does not show in the current language).

    Clicking anywhere on a row that is not a button toggles its checkbox,
    so selecting elements to publish needs no aim.

Footer
    Select-all checkbox with "N of M selected", the primary ``Publish N``
    button, a Preview split button (open in a new tab or copy the preview
    link, ``enablePreviewLink``) and an "Open module" link that switches the
    content frame to the Easy Workspace module for the current page.
    Select-all and ``Publish N`` are only shown while there is something to
    select.

States
    Loading skeleton (three rows), empty ("Nothing pending"), no context
    and error with a retry button, each with a Core icon.

A selected row keeps a light tint; the checkbox carries the state, since
every changed row starts selected.

..  _toolbar-languages:

Languages
=========

A page with translations has changes the editor cannot see in the module's
current language. The list tells them apart:

- Every row carries the record's language (``languageUid``). The response
  names the site's languages (``languages``: id, title, flag icon) and the
  languages the editor's module shows (``viewLanguages``): the page module
  and the Visual Editor keep their selected languages in core's module data
  (``languages``, as ``PageContextFactory`` validates it); the client sends
  the module's identifier (``module``, from Core's module router element)
  and the server reads that module's data. A module without a language —
  a record list, the dashboard — reports ``null``.
- Rows of the view's languages come first, under a "shown in this view"
  line when other languages follow. The rows the page shows only after a
  language switch come next under "Other languages", one sub-header per
  language (flag, title, count) and a hint that says why they are not to be
  seen and that they publish with the rest. They start selected like every
  other row; select-all and ``Publish N`` count them.
- A language chip appears on a row only where its language is not obvious:
  in a view of several languages, or in a module without one, on every row
  that is not in the view's (or the default) language.

The badge is not affected: it counts the page's changes in every language.

..  _toolbar-keyboard:

Keyboard and screen readers
===========================

The container is a ``role="dialog"`` labelled by the title; the rows form a
``role="list"`` with a roving tabindex. :kbd:`ArrowUp` / :kbd:`ArrowDown`
(:kbd:`Home` / :kbd:`End`) move between rows, :kbd:`Space` toggles the row
selection, :kbd:`Enter` opens the editor and :kbd:`Escape` closes the split
menu or the dropdown.

The action buttons of the active row are in the tab order: :kbd:`Tab` walks
from the row into edit, changes, discard and preview, then on to the
footer. :kbd:`ArrowRight` / :kbd:`ArrowLeft` step into and out of the
actions as well. Row actions carry ``aria-label`` and are visible without
hovering; the count chip is a polite live region.

..  _toolbar-styling:

Styling and dark mode
=====================

Styles live in four files under ``Resources/Public/Css/``:

``tokens.css``
    ``--wew-*`` custom properties mapped to TYPO3 backend tokens
    (``--typo3-dropdown-*``, ``--typo3-surface-*``, ``--typo3-badge-*``,
    ``--typo3-state-*``), so light and dark schemes and site themes apply
    automatically. Extension styles only read ``--wew-*``.
``toolbar-menu.css``
    The dropdown's layout (width 460 px, min-width 320 px, max-height
    64 vh, badge pulse, row enter/exit transitions using ``@starting-style``
    and ``transition-behavior: allow-discrete``, ``prefers-reduced-motion``
    support). Badges, buttons and checkboxes are Core's own classes
    (``.badge``, ``.btn-borderless``, ``.form-check``); the file draws no
    gradient and no shadow of its own.
``module.css``
    The backend module.
``diff.css``
    The diff and history modal (loaded by both the toolbar and the module).

Override tokens in your own backend stylesheet, for example
``:root { --wew-accent: var(--typo3-state-primary-bg); }``. No file carries
a hard-coded colour. The outline and the discard tag that the toolbar draws
into preview frames (whose documents do not load the backend stylesheet)
use the backend's primary, success and danger colours, resolved in the
toolbar's own document and handed over as computed values.

..  _toolbar-files:

JavaScript modules
==================

``components/wew-toolbar-menu.js``
    The element: lifecycle, event handlers, discard confirmation.
``templates/{header,group,row,footer,states}.js``
    Lit template functions.
``menu-badge.js``
    ``BadgeSync`` (see :ref:`badge`).
``menu-actions.js``
    List refresh, publish, discard, preview and module navigation.
``menu-selection.js``, ``menu-toolbar-helpers.js``
    Selection state, grouping by language and view, change types, relative
    time.
``menu-dropdown.js``, ``menu-keyboard.js``
    Popover/Bootstrap plumbing and the roving tabindex.
``menu-decline-sync.js``
    Message protocol with the Visual Editor preview (per-element decline
    button).
``menu-preview-locate.js``, ``menu-modals.js``
    Locating elements in preview iframes, diff/edit modals.

All imports use the ``@webconsulting/webcon-easy-workspace/`` import-map
prefix so TYPO3 versions their URLs.
