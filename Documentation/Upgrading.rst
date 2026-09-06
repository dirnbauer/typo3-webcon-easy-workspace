..  _upgrading:

==============
Upgrading
==============

This extension targets TYPO3 14.3 LTS. The minimum Core version is now
14.3.6, the security and maintenance release of 11 August 2026. Earlier
TYPO3 major versions are not supported by this codebase.

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
