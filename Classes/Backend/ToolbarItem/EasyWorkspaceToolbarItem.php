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
        return $this->accessGuard->user($this->request) !== null
            && $this->configurationProvider->get()['enabled']
            && $this->accessGuard->activeWorkspaceId($this->request) > 0;
    }

    public function getItem(): string
    {
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/webcon-easy-workspace/easy-workspace-menu-element.js');
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
        $payload = $this->configurationProvider->get() + [
            'activeWorkspaceId' => $this->accessGuard->activeWorkspaceId($this->request),
            'hasVisualEditor' => ExtensionManagementUtility::isLoaded('visual_editor'),
            'hasViewpage' => ExtensionManagementUtility::isLoaded('viewpage'),
            'labels' => $this->localizationService->labelsForJavaScript(),
        ];
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
