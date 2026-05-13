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

/**
 * Renders the "Easy Workspace" trigger in the top-right backend toolbar.
 *
 * The trigger shows a publish-style icon; the dropdown is owned by a Lit
 * custom element (<webcon-easy-workspace-menu>) which fetches the list of
 * pending workspace changes for the current page context and lets the
 * editor publish them to live in one shot.
 */
#[Autoconfigure(public: true)]
final class EasyWorkspaceToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly BackendViewFactory $backendViewFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly Context $context,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    /**
     * Only show the toolbar item to users that have access to at least
     * one (offline) workspace. In live mode the dropdown would be empty,
     * so we hide it entirely to keep the toolbar quiet.
     */
    public function checkAccess(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
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

    /**
     * Sort order on the toolbar. Lower numbers are further to the right.
     * 45 sits to the LEFT of the help dropdown (40) and to the RIGHT of
     * most module-related items.
     */
    public function getIndex(): int
    {
        return 45;
    }
}
