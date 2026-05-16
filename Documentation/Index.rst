..  _start:

==============
Easy Workspace
==============

Easy Workspace 14.0.0 is a TYPO3 14.3 LTS backend extension for
publishing workspace changes from the backend toolbar.

The extension is v14-only. It requires TYPO3 14.3, PHP 8.2-8.5 and
the TYPO3 system extensions ``backend``, ``core``, ``fluid``,
``frontend`` and ``workspaces``.

..  _installation:

Installation
============

Install the extension with Composer:

..  code-block:: bash
    :caption: Composer installation

    composer require webconsulting/webcon-easy-workspace:^14.0
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

..  _features:

Features
========

* Toolbar dropdown for page, content element and news workspace records.
* One-click publish through TYPO3's ``DataHandler`` publish cmdmap.
* Per-record discard through TYPO3 14's ``discard`` command.
* Preview-link generation through
  ``TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder``.
* Field-level diffs and history rollback using TYPO3 backend APIs.
* Optional Visual Editor and Viewpage iframe highlighting.

..  _configuration:

Configuration
=============

All editor-facing controls are configured through User TSconfig and
Page TSconfig.

..  toctree::
    :maxdepth: 2

    Configuration

..  _quality:

Quality
=======

Run the local checks before shipping changes:

..  code-block:: bash
    :caption: Quality checks

    composer test
    Build/Scripts/runTests.sh -s phpstan
    Build/Scripts/runTests.sh -s lint

PHPStan runs at ``level: max`` with PHP 8.2 as the minimum supported
runtime and ``saschaegerer/phpstan-typo3`` 3.0.1 for TYPO3 14 API
awareness.
