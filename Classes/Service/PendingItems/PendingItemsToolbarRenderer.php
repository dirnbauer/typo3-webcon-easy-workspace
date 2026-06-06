<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItemsPayload;

/**
 * Renders the toolbar dropdown menu markup via Fluid (ICU labels server-side).
 *
 * @deprecated since Easy Workspace 1.2 — toolbar menu is Lit-rendered client-side.
 *             Kept for reference; not used by the items AJAX endpoint anymore.
 */
final readonly class PendingItemsToolbarRenderer
{
    public function __construct(
        private ViewFactoryInterface $viewFactory,
    ) {}

    /**
     * @param array<string, mixed> $config Normalized TSconfig from ConfigurationProvider.
     */
    public function renderMenu(
        ServerRequestInterface $request,
        PendingItemsPayload $payload,
        array $config,
        string $context,
        string $viewMode,
        int $pageUid = 0,
        int $newsUid = 0,
    ): string {
        $changedCount = count(array_filter($payload->items, static fn (PendingItem $item): bool => $item->isChanged));
        $totalCount = count($payload->items);

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:webcon_easy_workspace/Resources/Private/Templates'],
            partialRootPaths: ['EXT:webcon_easy_workspace/Resources/Private/Partials'],
            templatePathAndFilename: 'EXT:webcon_easy_workspace/Resources/Private/Templates/Toolbar/Menu.html',
            request: $request,
        ));

        $view->assignMultiple([
            'config' => $config,
            'payload' => $payload,
            'context' => $context,
            'viewMode' => $viewMode,
            'pageUid' => $pageUid,
            'newsUid' => $newsUid,
            'changedCount' => $changedCount,
            'totalCount' => $totalCount,
            'itemGroups' => $payload->itemGroups,
            'changedItemGroups' => $payload->changedItemGroups,
            'showSubelements' => (bool)($config['showSubelementsInToolbar'] ?? false),
            'compactToolbar' => !($config['showSubelementsInToolbar'] ?? false),
        ]);

        return $view->render();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function renderLoading(ServerRequestInterface $request, array $config): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:webcon_easy_workspace/Resources/Private/Templates'],
            partialRootPaths: ['EXT:webcon_easy_workspace/Resources/Private/Partials'],
            templatePathAndFilename: 'EXT:webcon_easy_workspace/Resources/Private/Templates/Toolbar/Loading.html',
            request: $request,
        ));
        $view->assign('config', $config);
        return $view->render();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function renderNoContext(ServerRequestInterface $request, array $config): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:webcon_easy_workspace/Resources/Private/Templates'],
            partialRootPaths: ['EXT:webcon_easy_workspace/Resources/Private/Partials'],
            templatePathAndFilename: 'EXT:webcon_easy_workspace/Resources/Private/Templates/Toolbar/NoContext.html',
            request: $request,
        ));
        $view->assign('config', $config);
        return $view->render();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function renderError(ServerRequestInterface $request, array $config): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:webcon_easy_workspace/Resources/Private/Templates'],
            partialRootPaths: ['EXT:webcon_easy_workspace/Resources/Private/Partials'],
            templatePathAndFilename: 'EXT:webcon_easy_workspace/Resources/Private/Templates/Toolbar/Error.html',
            request: $request,
        ));
        $view->assign('config', $config);
        return $view->render();
    }
}
