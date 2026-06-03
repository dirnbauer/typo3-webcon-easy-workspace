..  _configuration-reference:

=======================
Configuration reference
=======================

Easy Workspace 1.0.3 ships ``Configuration/user.tsconfig``. TYPO3 14 loads
this file automatically for active extensions, so no manual import is needed.

Override precedence is:

1. Page TSconfig on the current page.
2. User TSconfig on the backend user.
3. User TSconfig on backend user groups.
4. The shipped defaults.

Backend users also get personal Easy Workspace switches in TYPO3's
``User Settings`` module. The shipped TSconfig values define the defaults
for those personal switches until a user saves their profile. The
administrator master switch ``enabled = 0`` still disables the feature
for everyone.

The personal switches apply to both entry points: the top-right toolbar
dropdown and the Easy Workspace backend module below the TYPO3 Workspaces
publish module.

The module and toolbar share the same service layer and configuration flags.
The module is server-rendered with Fluid and TYPO3 submodule routes for the
publish overview, page-record inventory and diagnostics.
Submodule behaviour is still controlled by the same TSconfig keys. The
pending submodule is intentionally optimized as a dense Bootstrap 5 /
TYPO3 styleguide publish queue: related child records are optional detail
disclosures, while publish eligibility is still decided by the server-side
record collection. Submodule navigation uses TYPO3's native module selector
instead of custom in-page navigation. The doc-header preview-link button uses
the same ``enablePreviewLink`` flag as the toolbar dropdown.

..  _configuration-options:

Options
=======

All keys live below ``options.webcon_easy_workspace``.

..  confval:: enabled

    :type: bool
    :Default: 1

    Hides the toolbar item and makes AJAX endpoints return ``403``
    when disabled.

..  confval:: userEnabledDefault

    :type: bool
    :Default: 1

    Default for the personal ``Use Easy Workspace`` user setting.

..  confval:: showSubelementsInToolbar

    :type: bool
    :Default: 0

    Default for showing related child records and their visual status
    badges in the top-right toolbar.
    **Off** by default so the compact toolbar dropdown stays readable;
    editors who want subelement details visible there opt in through their
    User Settings. Disabling this only hides the details and badges;
    publishing still includes the related records collected by the server.

..  confval:: showSubelementsInModule

    :type: bool
    :Default: 1

    Default for showing related child records and their visual status
    badges in the Easy Workspace backend module. Disabling this removes the
    related-record details from the dense publish queue; publishing still
    includes the related records collected by the server.

..  confval:: enableWorkspaceChip

    :type: bool
    :Default: 1

    Shows the active workspace name next to the dropdown title.

..  confval:: enablePreviewLink

    :type: bool
    :Default: 1

    Shows the preview-link button. The server endpoint also checks
    this flag. In the backend module the button is registered in the
    TYPO3 doc-header button bar and handled by the module JavaScript.

..  confval:: enableFilter

    :type: bool
    :Default: 1

    Shows the "To publish" and "All on page" filter controls.

..  confval:: defaultMode

    :type: string
    :Default: changed

    Initial list mode. Allowed values are ``changed`` and ``all``.

..  confval:: enableThumbnails

    :type: bool
    :Default: 1

    Enables TYPO3-processed preview thumbnails for image-bearing
    ``pages``, ``tt_content`` and ``tx_news_domain_model_news`` rows, as
    well as changed image file references listed below parent rows.

..  confval:: enableTypeLabels

    :type: bool
    :Default: 1

    Shows the second metadata line with table and type labels.

..  confval:: enableHiddenBadge

    :type: bool
    :Default: 1

    Shows the hidden-state badge and thumbnail stripe.

..  confval:: showHidden

    :type: bool
    :Default: 1

    Includes hidden records in the server response. When set to ``0``,
    hidden records are filtered before the JSON payload is returned.

..  confval:: maxItems

    :type: int
    :Default: 200

    Maximum number of rows returned for one dropdown request.

..  confval:: enableNewsBundles

    :type: bool
    :Default: 1

    When EXT:news is installed, enables client-side detection of a single
    news article on its detail view (Visual Editor / preview page or edit
    form). The toolbar then calls ``forNews`` instead of ``forPage``.
    Server-side collection never scans news off pages or folders. Set to
    ``0`` to disable news handling entirely.

..  confval:: enableHoverHighlight

    :type: bool
    :Default: 1

    Shows the locate icon for content elements and enables iframe
    highlighting.

..  confval:: enableRevert

    :type: bool
    :Default: 1

    Shows the per-row discard button. The discard endpoint returns
    ``403`` when this flag is disabled.

..  _configuration-example:

Example
=======

Disable preview links and discard for a junior editor group:

..  code-block:: typoscript
    :caption: Backend user group TSconfig

    options.webcon_easy_workspace {
        enablePreviewLink = 0
        enableRevert = 0
    }

..  _configuration-server-enforcement:

Server-side enforcement
=======================

The controller re-reads configuration for every request. Feature flags
are not only client-side UI toggles: ``enabled``, ``enablePreviewLink``
and ``enableRevert`` are enforced by the matching backend endpoints.

The personal ``Use Easy Workspace`` switch is folded into the effective
``enabled`` state. The related-record visibility switches are presentation
settings only: disabling them hides nested child rows in the chosen UI, but
does not remove those related records from the server-side publish payload.

Backend AJAX URLs are generated by TYPO3's route ``UriBuilder`` and
exposed through ``TYPO3.settings.ajaxUrls``. Non-public routes therefore
carry TYPO3 route tokens, and state-changing routes are restricted to
``POST`` in ``Configuration/Backend/AjaxRoutes.php``.

The stale workspace dependency guard is always active when the extension is
loaded. It is not a user-facing TSconfig option because it only filters
``sys_refindex`` references whose source or target record no longer exists.
Valid workspace dependencies are still handled by TYPO3 Core.
