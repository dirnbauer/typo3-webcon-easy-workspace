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
    Extension icon, the title, chips for the active workspace
    (``enableWorkspaceChip``), the stage of the listed records ("Mixed
    stages" when they differ) and the count
    ("N on this page · M elsewhere", or "N pending in this workspace"
    outside a page context), and a refresh button.

Groups
    One group for the page or news article (record icon, title, rootline
    path, row count) and, when present, one for workspace-wide file
    metadata records.

Rows
    Record icon or thumbnail, title, change-type pill (new / changed /
    deleted / moved, icons ``wew-change-*``), a meta line with type,
    column, author and relative time, optional related child records
    (``showSubelementsInToolbar``), and hover actions: edit, changes and
    history, discard (``enableRevert``) and show in preview
    (``enableHoverHighlight``).

Footer
    Select-all checkbox with the ``selected/total`` counter, the primary
    ``Publish N`` button, a Preview split button (open in a new tab or copy
    the preview link, ``enablePreviewLink``) and an "Open module" link that
    switches the content frame to the Easy Workspace module for the current
    page.

States
    Loading skeleton (three rows), empty ("Nothing pending"), no context
    and error with a retry button.

..  _toolbar-keyboard:

Keyboard and screen readers
===========================

The container is a ``role="dialog"`` labelled by the title; the rows form a
``role="list"`` with a roving tabindex. :kbd:`ArrowUp` / :kbd:`ArrowDown`
(:kbd:`Home` / :kbd:`End`) move between rows, :kbd:`Space` toggles the row
selection, :kbd:`Enter` opens the editor and :kbd:`Escape` closes the split
menu or the dropdown. Row actions carry ``aria-label``; the count chip is a
polite live region.

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
    The dropdown (min-width 420 px, max-height 70 vh, scroll fade via
    ``mask-image``, badge pulse, row enter/exit transitions using
    ``@starting-style`` and ``transition-behavior: allow-discrete``,
    ``prefers-reduced-motion`` support).
``module.css``
    The backend module.
``diff.css``
    The diff and history modal (loaded by both the toolbar and the module).

Override tokens in your own backend stylesheet, for example
``:root { --wew-accent: var(--typo3-state-primary-bg); }``.

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
    Selection state, grouping, change types, relative time.
``menu-dropdown.js``, ``menu-keyboard.js``
    Popover/Bootstrap plumbing and the roving tabindex.
``menu-decline-sync.js``
    Message protocol with the Visual Editor preview (per-element decline
    button).
``menu-preview-locate.js``, ``menu-modals.js``
    Locating elements in preview iframes, diff/edit modals.

All imports use the ``@webconsulting/webcon-easy-workspace/`` import-map
prefix so TYPO3 versions their URLs.
