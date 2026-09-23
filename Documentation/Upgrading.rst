..  _upgrading:

==============
Upgrading
==============

This extension targets TYPO3 14.3 LTS. The minimum Core version is now
14.3.6, the security and maintenance release of 11 August 2026. Earlier
TYPO3 major versions are not supported by this codebase.

Upgrading to 1.7.2
==================

No migration. The decline button in the Visual Editor now appears for
editors in a workspace; it had never loaded (see the changelog).

Upgrading to 1.7.1
==================

No migration. The copy of the Workspace ChatOps extension that sat in
``Extensions/webcon_workspace_chatops`` was removed. It was never installed
by Composer (the package only loaded its classes for its own tests) and has
been superseded by the separate ``webconsulting/webcon-mcp-chat-bridge``
extension. Installations that registered that folder by hand as a local path
repository must switch to the chat bridge.

Upgrading to 1.7
================

No migration. Things to know if you override the toolbar's markup or styles:

- The row and select-all checkboxes are wrapped in Core's ``.form-check``
  (``span.form-check.wew-row__check-wrap`` and
  ``div.form-check.wew-menu__selectall``). Core defines the checkbox tokens
  on that wrapper; without it the boxes rendered invisible.
  ``.wew-menu__selectall-check``, ``.wew-menu__selectall-label`` and
  ``.wew-sr-only`` are gone (the hint uses Core's ``.visually-hidden``).
- The action buttons of the active row are tabbable
  (``tabindex="0"``), the others stay ``-1``.
- The module no longer flushes the flash message queue itself; Core's
  ``Module`` layout renders publish, review and discard messages. The
  ``FlashMessage`` partial was removed.
- ``IFRAME_HIGHLIGHT_STYLE`` left ``menu-constants.js``; the preview
  outline is built by ``highlightStyle()`` in ``menu-preview-locate.js``
  from the backend's tokens.

The Git tags ``v14.0.0``–``v14.0.2`` were deleted from both remotes, and
the GitHub release of 14.0.0 with them: 14.0.0 was the first release
before the version line was renumbered to 1.x, and 14.0.1/14.0.2 pointed
at the commits of 1.3.5 and 1.3.6. Composer no longer offers 14.0.2 as the
"latest" version; a constraint on ``^14.0`` no longer resolves, use
``^1.7``.

Upgrading to 1.6
================

No migration. The toolbar badge now counts the **current page or news
article** instead of the whole workspace (:ref:`badge`); 1.4.0 had moved it
the other way, and editors read it as "what is pending here". The
whole-workspace number is still in the dropdown header
("N on this page · M elsewhere") and still in the payload as
``changedCount`` — the badge reads the new ``contextCount`` and falls back
to ``changedCount`` where no page can be resolved. Integrations that read
``changedCount`` keep working unchanged.

The dropdown is narrower (440 px, min-width 320 px) and denser, its header
no longer renders the extension tile, and the row actions are permanent
instead of hover-revealed (no ``opacity`` transition on
``.wew-row__actions``). Site stylesheets that positioned the dropdown by
its old 540 px width or restyled ``.wew-menu__brand`` need adapting.

Two behaviours change because a v14 trap was fixed (see the changelog):
``/has-changes`` answered ``false`` for every context and now answers
truthfully, and discarded (soft-deleted) drafts no longer count as pending.
Integrations that worked around the first by ignoring ``hasChanges`` can
stop doing so.

Upgrading to 1.5
================

No migration. 1.5.0 fixes the remaining cases in which the badge went
stale (:ref:`badge`) and adds a browser scenario (:ref:`testing-browser`).

One thing to know if you style the toolbar item: it now carries
``webcon-easy-workspace-toolbar--live`` (``display: none``) while the user
is in Live, and the badge ships ``data-wew-count`` and
``data-wew-workspace``. Overrides that assumed an always-visible item or an
always-empty badge in the server markup need adapting.

Upgrading to 1.4
================

1.4.0 requires **PHP 8.4 or 8.5**. Update the constraint to ``^1.4``.

**Breaking: CSS files renamed.** ``Resources/Public/Css/easy-workspace.css``
was replaced by ``tokens.css``, ``toolbar-menu.css``, ``module.css`` and
``diff.css``; the dropdown markup and its ``.wew-*`` class names changed
with the redesign. Site stylesheets that referenced the old file or its
selectors must be adapted; theming now works through the ``--wew-*`` tokens
(see :ref:`toolbar-styling`).

The toolbar badge counts the whole active workspace instead of the current
page (:ref:`badge`). ``/badge`` and ``/has-changes`` return the new payload;
integrations that read ``changedCount`` keep working, ``pageUid``/``newsUid``
are no longer echoed. Publish and discard responses gained a ``badge`` key.

JavaScript: ``menu-backend-save-sync.js`` became ``menu-decline-sync.js``,
``menu-badge.js`` owns the badge, templates live in
``Resources/Public/JavaScript/templates/``. Flush caches and reload open
backend tabs after the update so the import map picks up the new modules.

Updating an existing TYPO3 14.3 project
======================================

Back up the installation, then update the extension and Core together:

..  code-block:: bash

    composer update webconsulting/webcon-easy-workspace 'typo3/cms-*' --with-all-dependencies
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

Reload open backend tabs after flushing caches. The toolbar now loads
``components/wew-toolbar-menu.js`` directly through TYPO3's import map.
Custom overrides must stop referring to the removed
``easy-workspace-menu-element*.js`` wrappers or the old Fluid toolbar
renderer/templates. The active backend module templates remain available.

No extension database schema or data migration is required for this cleanup.
The unused session change-stamp hook was removed; toolbar refresh continues
through Core events and does not need session data to be migrated. The badge
JSON no longer contains the unused ``revision``, ``changedAt`` or
``changedWorkspaceId`` bookkeeping fields.

Behavior corrections
====================

Discard now rejects Live mode and foreign-workspace rows, even for
administrators. Live UIDs from preview controls resolve only inside the
active workspace. Repeated discards remain harmless. Integrations must
select the intended workspace before calling mutation services; stale user
record fields or a different Context aspect no longer select a workspace.

From TYPO3 12 or 13
==================

Upgrade the host installation through TYPO3's supported major-version steps
and execute its schema updates and upgrade wizards before installing this
v14-only extension. There are no v12/v13 compatibility branches here.

Verification
============

Run ``composer test`` and ``composer audit`` in the extension checkout.
In the host project, check the toolbar and all Easy Workspace sections,
publish/discard sample drafts, and confirm the resulting live content.
Test news and Visual Editor flows when those optional extensions are installed.
