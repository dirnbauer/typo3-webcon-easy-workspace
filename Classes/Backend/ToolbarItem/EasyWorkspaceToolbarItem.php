<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Backend\ToolbarItem;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Enum\ModuleSection;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;
use Webconsulting\WebconEasyWorkspace\Service\ContextChangeSummary;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceChangeCounter;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

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
        private readonly UriBuilder $uriBuilder,
        private readonly ContextChangeSummary $contextChangeSummary,
        private readonly WorkspaceChangeCounter $changeCounter,
    ) {}

    #[\Override]
    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    #[\Override]
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

        return array_any(
            array_keys(GeneralUtility::makeInstance(WorkspaceService::class)->getAvailableWorkspaces()),
            static fn(int|string $workspaceId): bool => (int)$workspaceId > 0,
        );
    }

    #[\Override]
    public function getItem(): string
    {
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/webcon-easy-workspace/components/wew-toolbar-menu.js');
        $this->pageRenderer->addCssFile('EXT:webcon_easy_workspace/Resources/Public/Css/tokens.css');
        $this->pageRenderer->addCssFile('EXT:webcon_easy_workspace/Resources/Public/Css/toolbar-menu.css');
        // The diff/history modal opens in the top frame, so its styles ship with the toolbar.
        $this->pageRenderer->addCssFile('EXT:webcon_easy_workspace/Resources/Public/Css/diff.css');
        $workspaceId = $this->activeWorkspaceId();
        $view = $this->backendViewFactory->create($this->request, ['webconsulting/webcon-easy-workspace']);
        $view->assignMultiple([
            'activeWorkspaceId' => $workspaceId,
            'pendingCount' => $this->firstPaintCount($workspaceId),
        ]);

        return $view->render('ToolbarItems/EasyWorkspaceItem');
    }

    /**
     * Count for the very first paint, before BadgeSync takes over.
     *
     * The badge shows the changes of the page the editor is on, so the
     * server can only pre-fill it when the backend request itself names a
     * page (`?id=`). Without one it renders 0 and stays hidden until the
     * first badge response — BadgeSync detects the page from the module
     * state / iframe URL, which no server request can see.
     */
    private function firstPaintCount(int $workspaceId): int
    {
        if ($workspaceId <= 0) {
            return 0;
        }
        $pageUid = isset($this->request)
            ? Value::int($this->request->getQueryParams()['id'] ?? null)
            : 0;
        if ($pageUid <= 0) {
            return 0;
        }

        return $this->contextChangeSummary->forContext(
            $workspaceId,
            $this->changeCounter->count($workspaceId)->stamp,
            $pageUid,
            0,
            $this->configurationProvider->get($pageUid),
        )['count'] ?? 0;
    }

    /**
     * Core calls getAdditionalAttributes() and checkAccess() before
     * setRequest(); the guard falls back to the BE_USER global then.
     */
    private function activeWorkspaceId(): int
    {
        return $this->accessGuard->activeWorkspaceId($this->request ?? null);
    }

    #[\Override]
    public function hasDropDown(): bool
    {
        return true;
    }

    #[\Override]
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
            'activeWorkspaceId' => $this->activeWorkspaceId(),
            'hasVisualEditor' => ExtensionManagementUtility::isLoaded('visual_editor'),
            'hasViewpage' => ExtensionManagementUtility::isLoaded('viewpage'),
            'moduleIdentifier' => ModuleSection::Pending->moduleIdentifier(),
            'moduleUrl' => $this->moduleUrl(),
            'labels' => $this->localizationService->labelsForJavaScript(),
        ]);
        $view->assign('configJson', json_encode($payload, JSON_THROW_ON_ERROR));
        return $view->render('ToolbarItems/EasyWorkspaceDropDown');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function getAdditionalAttributes(): array
    {
        $classes = ['webcon-easy-workspace-toolbar'];
        if (!$this->configurationProvider->get()['showSubelementsInToolbar']) {
            $classes[] = 'webcon-easy-workspace-toolbar--compact';
        }
        // Rendered but not shown while the user is in Live: the element has
        // to stay in the DOM so entering a workspace reveals it without a
        // page reload. BadgeSync keeps the class and `hidden` in sync.
        if ($this->activeWorkspaceId() <= 0) {
            $classes[] = 'webcon-easy-workspace-toolbar--live';
        }

        return [
            'class' => implode(' ', $classes),
        ];
    }

    #[\Override]
    public function getIndex(): int
    {
        return 45;
    }

    /**
     * Link target of the dropdown's "Open module" action. The page id is
     * appended client-side; an unresolvable route yields an empty string.
     */
    private function moduleUrl(): string
    {
        try {
            return (string)$this->uriBuilder->buildUriFromRoute(ModuleSection::Pending->moduleIdentifier());
        } catch (\Throwable) {
            return '';
        }
    }
}
