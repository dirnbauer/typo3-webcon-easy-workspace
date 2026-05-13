<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Service\LatestChangesService;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;

final readonly class EasyWorkspaceAjaxController
{
    /**
     * Tables the dropdown is allowed to operate on. Anything else is
     * silently rejected — protects against a crafted request passing
     * an arbitrary $TCA table (e.g. be_users, sys_log) through to
     * DataHandler. The UI itself only ever produces these three.
     */
    private const ALLOWED_TABLES = [
        'pages',
        'tt_content',
        'tx_news_domain_model_news',
    ];

    public function __construct(
        private PendingItemsService $pendingItemsService,
        private PublishSelectedService $publishService,
        private PreviewUriBuilder $previewUriBuilder,
        private ConfigurationProvider $configurationProvider,
        private LatestChangesService $latestChangesService,
    ) {}

    public function itemsAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $newsUid = (int)($query['newsUid'] ?? 0);
        $pageUid = (int)($query['pageUid'] ?? 0);
        $config = $this->configurationProvider->get($pageUid > 0 ? $pageUid : null);

        if (!$config['enabled']) {
            return new JsonResponse(['error' => 'Easy Workspace is disabled by TSconfig.'], 403);
        }

        $defaultMode = $config['defaultMode'];
        $requestedMode = (string)($query['mode'] ?? $defaultMode);
        $mode = $config['enableFilter']
            ? ($requestedMode === PendingItemsService::MODE_ALL ? PendingItemsService::MODE_ALL : PendingItemsService::MODE_CHANGED)
            : PendingItemsService::MODE_CHANGED;

        if ($newsUid > 0) {
            return new JsonResponse([
                'context' => 'news',
                ...$this->pendingItemsService->forNews($newsUid, $mode, $config),
            ]);
        }
        if ($pageUid > 0) {
            return new JsonResponse([
                'context' => 'page',
                ...$this->pendingItemsService->forPage($pageUid, $mode, $config),
            ]);
        }
        return new JsonResponse([
            'context' => 'none',
            'items' => [],
            'workspaceId' => 0,
            'mode' => $mode,
        ]);
    }

    /**
     * Cross-page "latest workspace changes" feed.
     *
     * Powers the lazy-loaded accordion at the bottom of the toolbar
     * dropdown — only invoked when the editor expands it, so the
     * common case (dropdown opened, accordion stays closed) costs
     * zero database round-trips.
     *
     * No page/news context is needed — the result is always scoped
     * to the editor's current workspace.
     */
    public function latestAction(ServerRequestInterface $request): ResponseInterface
    {
        $config = $this->configurationProvider->get(null);
        if (!$config['enabled']) {
            return new JsonResponse(['error' => 'Easy Workspace is disabled by TSconfig.'], 403);
        }

        $query = $request->getQueryParams();
        $requestedLimit = (int)($query['limit'] ?? LatestChangesService::DEFAULT_LIMIT);
        // Clamp to a sane range. 1 keeps degenerate ?limit=0 calls
        // from returning the entire workspace, 50 caps the response
        // size for the dropdown UI.
        $limit = max(1, min(50, $requestedLimit));

        return new JsonResponse($this->latestChangesService->list($limit, $config));
    }

    public function publishAction(ServerRequestInterface $request): ResponseInterface
    {
        // Mirror the gating the other endpoints do — without this the
        // toolbar item could be hidden by TSconfig (enabled = 0) but
        // the publish endpoint would still happily accept POSTs.
        $config = $this->configurationProvider->get();
        if (!$config['enabled']) {
            return new JsonResponse(['error' => 'Easy Workspace is disabled by TSconfig.'], 403);
        }

        $payload = $this->decodeBody($request);
        $rawSelections = $payload['selections'] ?? [];
        if (!is_array($rawSelections)) {
            return new JsonResponse(['error' => 'Invalid selections payload.'], 400);
        }

        $selections = [];
        foreach ($rawSelections as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $table = (string)($entry['table'] ?? '');
            $workspaceUid = (int)($entry['workspaceUid'] ?? 0);
            // Allow-list — keeps arbitrary TCA tables (be_users,
            // sys_log, …) out of the DataHandler cmdmap.
            if (!in_array($table, self::ALLOWED_TABLES, true) || $workspaceUid <= 0) {
                continue;
            }
            $selections[] = ['table' => $table, 'workspaceUid' => $workspaceUid];
        }

        return new JsonResponse($this->publishService->publish($selections));
    }

    public function discardAction(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->decodeBody($request);
        $table = (string)($payload['table'] ?? '');
        $workspaceUid = (int)($payload['workspaceUid'] ?? 0);
        if (!in_array($table, self::ALLOWED_TABLES, true) || $workspaceUid <= 0) {
            return new JsonResponse(['error' => 'Missing or unsupported table / workspaceUid.'], 400);
        }
        $config = $this->configurationProvider->get();
        if (!$config['enabled']) {
            return new JsonResponse(['error' => 'Easy Workspace is disabled by TSconfig.'], 403);
        }
        if (!($config['enableRevert'] ?? true)) {
            return new JsonResponse(['error' => 'Revert is disabled by TSconfig.'], 403);
        }
        return new JsonResponse($this->publishService->discard($table, $workspaceUid));
    }

    public function previewLinkAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['pageUid'] ?? 0);
        if ($pageUid <= 0) {
            return new JsonResponse(['error' => 'Missing pageUid.'], 400);
        }
        $config = $this->configurationProvider->get($pageUid);
        if (!$config['enablePreviewLink']) {
            return new JsonResponse(['error' => 'Preview link is disabled by TSconfig.'], 403);
        }
        try {
            $url = $this->previewUriBuilder->buildUriForPage($pageUid);
        } catch (\Throwable) {
            // Generic message to the client; the underlying exception
            // is already logged by Core's error handler.
            return new JsonResponse(['error' => 'Could not build a preview link for this page.'], 500);
        }
        return new JsonResponse(['url' => $url]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(ServerRequestInterface $request): array
    {
        if (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $body = (string)$request->getBody();
            if ($body === '') {
                return [];
            }
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }
        $parsed = $request->getParsedBody();
        return is_array($parsed) ? $parsed : [];
    }
}
