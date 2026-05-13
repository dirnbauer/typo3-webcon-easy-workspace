<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;

/**
 * Two endpoints behind the Easy Workspace toolbar dropdown:
 *  - GET  /ajax/webcon-easy-workspace/items   (list pending changes)
 *  - POST /ajax/webcon-easy-workspace/publish (publish selected items)
 */
final readonly class EasyWorkspaceAjaxController
{
    public function __construct(
        private PendingItemsService $pendingItemsService,
        private PublishSelectedService $publishService,
    ) {}

    public function itemsAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $newsUid = (int)($query['newsUid'] ?? 0);
        $pageUid = (int)($query['pageUid'] ?? 0);

        if ($newsUid > 0) {
            return new JsonResponse([
                'context' => 'news',
                ...$this->pendingItemsService->forNews($newsUid),
            ]);
        }
        if ($pageUid > 0) {
            return new JsonResponse([
                'context' => 'page',
                ...$this->pendingItemsService->forPage($pageUid),
            ]);
        }
        return new JsonResponse([
            'context' => 'none',
            'items' => [],
            'workspaceId' => 0,
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
