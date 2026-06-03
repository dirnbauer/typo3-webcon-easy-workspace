..  _screenshots-guide:

===================
Backend screenshots
===================

The extension repository does not include a runnable TYPO3 instance. Screenshots
below were captured from a lab project with ``webconsulting/webcon-easy-workspace``
installed, workspace **Staging** active, and page **505** (TYPO3Camp Vienna 2026)
with pending workspace changes.

Recommended capture setup
=========================

1. TYPO3 14.3 backend, dark or light mode (capture both if you publish docs for both).
2. Backend user in workspace **Staging** (or any non-Live workspace).
3. Page with: changed page properties, 2–3 changed content elements, one inline
   child change (e.g. Content Blocks collection), optional FAL change.
4. Browser window width ≥ 1440px; hide unrelated browser extensions.
5. **User settings:** ``/typo3/module/user/setup`` — open the **Easy Workspace** tab
   (form renders inside the module iframe).

Save PNG files into ``Documentation/Images/`` using the filenames below.

Toolbar (Layout / preview)
==========================

..  figure:: /Images/toolbar-trigger.png
    :alt: Easy Workspace toolbar icon with pending count
    :width: 800

    Top bar with the paper-plane trigger and pending-change badge (page 505, Staging).

..  figure:: /Images/toolbar-dropdown-open.png
    :alt: Easy Workspace toolbar dropdown with filter tabs and change list
    :width: 800

    Open dropdown: workspace chip, filter tabs, and pending items with thumbnails.

..  figure:: /Images/toolbar-dropdown-publish.png
    :alt: Easy Workspace toolbar dropdown publish footer
    :width: 800

    Dropdown footer with selection summary and **Publish to live**.

..  figure:: /Images/toolbar-diff-modal.png
    :alt: Easy Workspace per-record diff and history modal
    :width: 800

    Per-row diff modal (field diff and history tabs).

Easy Workspace module
=====================

..  figure:: /Images/module-open-items.png
    :alt: Easy Workspace module open items with publish table
    :width: 800

    **Content → Easy Workspace → Open items** with the publish table (``?id=505``).

..  figure:: /Images/module-doc-header.png
    :alt: Easy Workspace module doc header with page actions
    :width: 800

    Module doc header: breadcrumb, show page, preview, and edit page properties
    (same page context as open items).

..  figure:: /Images/module-all-records.png
    :alt: Easy Workspace all records submodule
    :width: 800

    **All records** submodule (read-only overview).

..  figure:: /Images/module-diagnostics.png
    :alt: Easy Workspace checks and diagnostics
    :width: 800

    **Checks and diagnostics** with scan results (demo data from
    ``webcon-easy-workspace:seed-diagnostics`` on the lab instance).

User settings
=============

..  figure:: /Images/user-settings.png
    :alt: TYPO3 user settings Easy Workspace tab
    :width: 800

    **User settings** (``/typo3/module/user/setup``) → **Easy Workspace** tab with
    personal toggles (enabled, toolbar subelements, module subelements).

Optional shots
==============

* ``toolbar-news-scope.png`` — dropdown scoped to one EXT:news article
* ``toolbar-eye-highlight.png`` — content element outlined in preview iframe
* ``toolbar-child-disclosure.png`` — related child rows expanded (user setting on)

Rendering the manual with images
================================

From a TYPO3 documentation Docker render environment:

..  code-block:: bash

    # paths depend on your docs.typo3.org render setup
    make docs

Ensure ``Documentation/Images/*.png`` is committed or attached to the release
tag when publishing to docs.typo3.org.

Contributing screenshots
========================

Pull requests that add PNGs under ``Documentation/Images/`` are welcome. Use
lossless or high-quality PNG; avoid screenshots containing secrets (tokens,
passwords, private hostnames) — blur or use a demo domain.
