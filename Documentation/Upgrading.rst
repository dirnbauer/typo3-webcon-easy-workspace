..  _upgrading:

==============
Upgrading
==============

This extension targets TYPO3 14.3 LTS. The minimum Core version is now
14.3.6, the security and maintenance release of 11 August 2026. Earlier
TYPO3 major versions are not supported by this codebase.

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

1.4.0 requires **PHP 8.4 or 8.5**. Update the constraint to ``^1.4``: the
older tags ``v14.0.0``–``v14.0.2`` predate ``v1.3.9`` and never match a
``^1.x`` constraint.

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

The optional ChatOps package also requires Core 14.3.6+. Install or update it
separately; Easy Workspace does not enable it automatically.

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
