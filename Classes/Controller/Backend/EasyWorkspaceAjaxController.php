<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;

final readonly class EasyWorkspaceAjaxController
{
    public function __construct(
        private PendingItemsService $pendingItemsService,
        private PublishSelectedService $publishService,
        private PreviewUriBuilder $previewUriBuilder,
        private ConfigurationProvider $configurationProvider,
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

    public function publishAction(ServerRequestInterface $request): ResponseInterface
    {
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
            if ($table === '' || $workspaceUid <= 0) {
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
        if ($table === '' || $workspaceUid <= 0) {
            return new JsonResponse(['error' => 'Missing table / workspaceUid.'], 400);
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
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
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
