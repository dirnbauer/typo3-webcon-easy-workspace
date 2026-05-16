<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Backend\ToolbarItem;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
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
        private readonly Context $context,
        private readonly ConfigurationProvider $configurationProvider,
        private readonly LocalizationService $localizationService,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            return false;
        }
        if (!$this->configurationProvider->get()['enabled']) {
            return false;
        }
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        if ($workspaceId <= 0) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        return true;
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
        // capabilities so the Lit element can adapt its messaging
        // (eye icon tooltip, "no iframe" notification) to what's
        // actually installed instead of always saying "Visual Editor".
        // Translated UI strings the JS reads through `this._config.labels`.
        // Keeping them server-rendered keeps the JS bundle locale-free
        // and lets editors switch backend language without rebuilds.
        $payload = $this->configurationProvider->get() + [
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
        return [
            'class' => 'webcon-easy-workspace-toolbar',
        ];
    }

    public function getIndex(): int
    {
        return 45;
    }

}
