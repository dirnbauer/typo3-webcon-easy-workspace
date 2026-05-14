<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Backend\ToolbarItem;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;

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
        private readonly LanguageServiceFactory $languageServiceFactory,
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
        if ($backendUser->isAdmin()) {
            return true;
        }
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        return $workspaceId > 0;
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
        $beUser = $GLOBALS['BE_USER'] ?? null;
        $languageService = $this->languageServiceFactory->createFromUserPreferences($beUser);
        $payload = $this->configurationProvider->get() + [
            'hasVisualEditor' => ExtensionManagementUtility::isLoaded('visual_editor'),
            'hasViewpage' => ExtensionManagementUtility::isLoaded('viewpage'),
            'labels' => [
                'discardTagTitle' => $this->translate($languageService, 'discardTag.title'),
                'discardTagSubtitle' => $this->translate($languageService, 'discardTag.subtitle'),
            ],
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

    /**
     * Resolve a key from EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf
     * for the BE user's preferred language. Falls back to the English
     * source string if no target exists. Bare `sL()` returns the
     * source for missing keys, so we don't need a separate guard.
     */
    private function translate(LanguageService $languageService, string $key): string
    {
        return (string)$languageService->sL(
            'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:' . $key,
        );
    }
}
