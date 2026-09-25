..  _start:

==============
Easy Workspace
==============

Easy Workspace adds a publishing toolbar and a backend module for TYPO3
14.3.6+ on the 14.3 LTS line. It requires PHP 8.4 or 8.5. Composer metadata
is the source of truth for requirements and extension version.

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Toolbar
    Badge
    Configuration
    Upgrading
    Diagnostics
    Testing
    Contributing

..  _installation:

Installation
============

Install from the GitHub VCS repository in a TYPO3 project:

..  code-block:: bash

    composer config repositories.webcon-easy-workspace vcs https://github.com/dirnbauer/typo3-webcon-easy-workspace.git
    composer require webconsulting/webcon-easy-workspace:^1.9
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

Switch to a custom workspace and open a page. The toolbar shows its pending
changes; **Content → Easy Workspace** provides Review queue, All records and
administrator-only Checks and diagnostics. The module supports requesting
review and approving/publishing selected records. See :ref:`configuration-reference`
for stage IDs and personal settings.

..  _architecture:

Architecture
============

``EasyWorkspaceToolbarItem`` renders the trigger and a Lit custom element.
The component receives configuration and translated labels from PHP and
fetches JSON from the items endpoint. It renders the dropdown in light DOM
(see :ref:`toolbar`). The full backend module and diff/history dialogs use
Fluid.

Both entry points share ``PendingItemsService`` and its collection pipeline:

..  code-block:: text

    Toolbar → AJAX controller ─┐
                              ├→ PendingItemsService → PendingItemsCollector
    Module controller ────────┘                          ├→ page/news scope
                                                        ├→ workspace queries
                                                        ├→ item factory/resolvers
                                                        └→ aggregation
    Publish / review / discard → PublishSelectedService → Core DataHandler
    Badge / has-changes ───────→ WorkspaceChangeCounter + ContextChangeSummary
                                 (both from CoreWorkspaceChanges: one core scan
                                 per editor and revision, cached)
    Languages ─────────────────→ LanguageContext (site languages, module data)

The toolbar loads ``components/wew-toolbar-menu.js`` directly. All extension
JavaScript imports use the registered ``@webconsulting/webcon-easy-workspace/``
prefix so TYPO3 versions their URLs. There are no numbered entrypoints,
manual cache timestamps, or alternative Fluid dropdown renderer.

The badge counts the current page or news article (``ContextChangeSummary``)
next to the whole-workspace total (``WorkspaceChangeCounter``) and is owned
on the client by ``BadgeSync`` (see :ref:`badge`). It is event-driven: saves,
navigation to another page and changes reported by other tabs feed one
debounced, single-flight request — no poll, no focus or visibility
trigger; the server ``stamp`` decides whether an open list re-fetches. Core's workspace
dependency event still filters references whose source or target has
actually been removed.

..  _record-scope:

Record scope
============

A page collection contains the page, its content, workspace-aware inline
children and file references. Standalone file metadata belongs to the whole
workspace. Parent rows provide context for child-only changes. Related drafts
remain in the publish selection when subelement details are hidden.

The toolbar shows changed records across languages. The All records module
provides a read-only inventory. PHP collection methods can receive an explicit
``languageUid``; the toolbar has no language or mode controls. Localization
metadata-only changes do not inflate the publish queue or badge.

News scope requires EXT:news and one of:

* ``tx_news_pi1[news]`` in a preview URL;
* an open ``tx_news_domain_model_news`` edit form;
* an explicit ``newsUid`` supplied to the module or AJAX endpoint.

News is never collected by scanning every article in a storage folder.
Slug-only detail URLs need one of the explicit contexts above.

A news collection is the article plus **every** content element linked
through ``tx_news_domain_model_news.content_elements`` (foreign field
``tt_content.tx_news_related_news``) — an article carries up to 99 of them,
and the list, the count, the publish selection and the discard selection
cover all of them, together with their own inline children and file
references. Those elements are addressed through the relation, not through
a backend layout column, so they carry no column label and form a single
group instead of per-column ones. An article without any content element
(the common case, where the body is ``bodytext``) still lists its own
record.

..  _workspace-actions:

Workspace actions
=================

Publish and stage changes resolve selected workspace UIDs before sending a
Core DataHandler command map. Discard accepts a workspace UID or a live UID
from a preview control, but only resolves drafts in the actor's active
workspace. It rejects Live mode and foreign-workspace rows. Missing or already
discarded drafts return success without changing live content.

History rollback uses Core ``RecordHistoryRollback``. Preview links use
``PreviewUriBuilder``. Content highlighting tries Visual Editor, Viewpage,
and other same-origin previews. Physical file overwrites cannot be undone
through TYPO3 workspace versioning.

..  _backend-api:

Backend AJAX endpoints
======================

URLs are generated by TYPO3 and exposed in ``TYPO3.settings.ajaxUrls``.
The route names below use the ``webcon_easy_workspace_`` prefix.

..  list-table::
    :header-rows: 1

    * - Suffix
      - Method
      - Result
    * - ``items``
      - GET
      - JSON pending records, groups, context and workspace identity
    * - ``badge``
      - GET
      - Whole-workspace count: ``changedCount``, ``byTable``, ``byState``,
        ``stamp`` and workspace identity (:ref:`badge-payload`)
    * - ``has_changes``
      - GET
      - The badge payload plus ``hasChanges``
    * - ``diff``
      - GET
      - Fluid HTML containing field differences and history
    * - ``preview_link``
      - GET
      - Workspace preview URL
    * - ``publish``
      - POST
      - Publish selected records; the response embeds the fresh ``badge``
    * - ``discard``
      - POST
      - Discard a draft from the active workspace; embeds the fresh ``badge``
    * - ``history_rollback``
      - POST
      - Roll back a record or field history entry

..  _security:

Access control
==============

Backend authentication and route tokens protect the endpoints. Mutations
are POST-only. Read endpoints check page-show access; diff access also
checks workspace scope. Table policy, workspace membership and Core
DataHandler permissions govern mutations. Feature flags are enforced on
the server. Diagnostics require an administrator.

Report vulnerabilities privately through `GitHub Security Advisories
<https://github.com/dirnbauer/typo3-webcon-easy-workspace/security/advisories/new>`__.

..  _quality:

Quality checks
==============

Run ``composer test``, ``composer audit`` and ``npm test``. CI runs PHP
lint, the coding-guideline dry run, PHPStan level 8, unit tests and real
DataHandler functional tests on MariaDB 10.11 for PHP 8.4 and 8.5, plus the
vitest suite. See :ref:`testing` and :ref:`contributing` for scope.
