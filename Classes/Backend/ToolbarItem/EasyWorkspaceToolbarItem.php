<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Backend\ToolbarItem;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;

/**
 * Renders the "Easy Workspace" trigger in the top-right backend toolbar.
 *
 * Visibility and the available dropdown features are driven by User
 * TSconfig — see Configuration/user.tsconfig for the auto-loaded
 * defaults.
 */
#[Autoconfigure(public: true)]
final class EasyWorkspaceToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly BackendViewFactory $backendViewFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendAccessGuard $accessGuard,
        private readonly ConfigurationProvider $configurationProvider,
        private readonly LocalizationService $localizationService,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        // Core calls checkAccess() (via array_filter) *before* setRequest()
        // (via array_map) in BackendController::getToolbarItems(), so
        // $this->request is not yet initialized here. The access guard is
        // built for exactly this case: with no request it falls back to the
        // BE_USER global — same backend user, no PSR-7 attribute needed.
        if ($this->accessGuard->user() === null
            || !$this->configurationProvider->get()['enabled']
        ) {
            return false;
        }

        // Render a hidden marker for anyone who can work in a workspace —
        // even while they are currently in Live. The element stays in the
        // DOM so the toolbar can be revealed and the badge filled without a
        // full page reload once the user enters or populates a workspace.
        // Actual visibility is decided client-side (syncToolbarVisibility).
        return $this->accessGuard->activeWorkspaceId() > 0
            || $this->userCanUseWorkspaces();
    }

    /**
     * True when the backend user has access to at least one editable
     * (non-live) workspace. Mirrors how the core workspace selector
     * decides visibility; the result is request-cached by WorkspaceService.
     */
    private function userCanUseWorkspaces(): bool
    {
        // Reached from checkAccess() before setRequest(); use the guard's
        // BE_USER fallback rather than the uninitialized $this->request.
        $user = $this->accessGuard->user();
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        foreach (array_keys(GeneralUtility::makeInstance(WorkspaceService::class)->getAvailableWorkspaces()) as $workspaceId) {
            if ((int)$workspaceId > 0) {
                return true;
            }
        }

        return false;
    }

    public function getItem(): string
    {
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/webcon-easy-workspace/easy-workspace-menu-element-v3.js');
        $this->pageRenderer->addCssFile('EXT:webcon_easy_workspace/Resources/Public/Css/easy-workspace.css');
        $view = $this->backendViewFactory->create($this->request, ['webconsulting/webcon-easy-workspace']);
        return $view->render('ToolbarItems/EasyWorkspaceItem');
    }

    public function hasDropDown(): bool
    {
        return true;
    }

    public function getDropDown(): string
    {
        $view = $this->backendViewFactory->create($this->request, ['webconsulting/webcon-easy-workspace']);
        // Merge user-configurable TSconfig with detected runtime
        // capabilities so the toolbar glue script can adapt its messaging
        // (eye icon tooltip, "no iframe" notification) to what's
        // actually installed instead of always saying "Visual Editor".
        // Translated UI strings the JS reads through `this._config.labels`.
        // Keeping them server-rendered keeps the JS bundle locale-free
        // and lets editors switch backend language without rebuilds.
        $payload = array_replace($this->configurationProvider->get(), [
            'activeWorkspaceId' => $this->accessGuard->activeWorkspaceId($this->request),
            'hasVisualEditor' => ExtensionManagementUtility::isLoaded('visual_editor'),
            'hasViewpage' => ExtensionManagementUtility::isLoaded('viewpage'),
            'labels' => $this->localizationService->labelsForJavaScript(),
        ]);
        $view->assign('configJson', json_encode($payload, JSON_THROW_ON_ERROR));
        return $view->render('ToolbarItems/EasyWorkspaceDropDown');
    }

    /**
     * @return array<string, string>
     */
    public function getAdditionalAttributes(): array
    {
        $classes = ['webcon-easy-workspace-toolbar'];
        if (!$this->configurationProvider->get()['showSubelementsInToolbar']) {
            $classes[] = 'webcon-easy-workspace-toolbar--compact';
        }

        return [
            'class' => implode(' ', $classes),
        ];
    }

    public function getIndex(): int
    {
        return 45;
    }

}
