<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\WebconWorkspaceChatops\Configuration\ChatOpsConfiguration;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceEventPayload;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceRecordSelection;
use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;
use Webconsulting\WebconWorkspaceChatops\Enum\WorkspaceEventType;
use Webconsulting\WebconWorkspaceChatops\Notification\NotificationDispatcher;
use Webconsulting\WebconWorkspaceChatops\Security\ApiAccessGuard;
use Webconsulting\WebconWorkspaceChatops\Security\BackendUserImpersonator;
use Webconsulting\WebconWorkspaceChatops\Service\LocalizationService;
use Webconsulting\WebconWorkspaceChatops\Service\UserPreferenceService;
use Webconsulting\WebconWorkspaceChatops\Service\WorkspaceChangeListingService;
use Webconsulting\WebconWorkspaceChatops\Service\WorkspaceRecordSelectionNormalizer;
use Webconsulting\WebconWorkspaceChatops\Service\WorkspaceWorkflowService;
use Webconsulting\WebconWorkspaceChatops\Utility\Value;

final readonly class ChatOpsApiController
{
    public function __construct(
        private ChatOpsConfiguration $configuration,
        private ApiAccessGuard $apiAccessGuard,
        private BackendUserImpersonator $backendUserImpersonator,
        private UserPreferenceService $userPreferenceService,
        private WorkspaceRecordSelectionNormalizer $selectionNormalizer,
        private WorkspaceWorkflowService $workspaceWorkflowService,
        private WorkspaceChangeListingService $workspaceChangeListingService,
        private NotificationDispatcher $notificationDispatcher,
        private LocalizationService $localizationService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->configuration->isEnabled()) {
            return $this->jsonError('api.error.disabled', 403);
        }
        if (!$this->apiAccessGuard->isAllowed($request)) {
            return $this->jsonError('api.error.unauthorized', 401);
        }

        $body = $this->decodeBody($request);
        if ($body === null) {
            return $this->jsonError('api.error.invalidJson', 400);
        }
        $action = strtolower(trim(Value::string($body['action'] ?? ($request->getMethod() === 'GET' ? 'ping' : ''))));

        return match ($action) {
            'ping' => new JsonResponse(['success' => true, 'message' => $this->localizationService->translate('api.status.ok')]),
            'notify' => $this->notify($body),
            'workspace.pending' => $this->pending($body),
            'workspace.review.request' => $this->requestReview($body),
            'workspace.review.approve', 'workspace.publish' => $this->approve($body),
            default => $this->jsonError('api.error.unknownAction', 400),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function notify(array $body): JsonResponse
    {
        $payload = $this->payloadFromBody($body, WorkspaceEventType::fromString(Value::string($body['type'] ?? 'generic')));
        $results = $this->notificationDispatcher->dispatch($payload);

        return new JsonResponse([
            'success' => true,
            'notifications' => array_map(static fn($result): array => $result->toArray(), $results),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function pending(array $body): JsonResponse
    {
        $workspaceId = max(0, Value::int($body['workspaceId'] ?? $this->configuration->defaultWorkspaceId()));
        $pageUid = isset($body['pageUid']) ? Value::int($body['pageUid']) : null;
        $limit = max(1, min(500, Value::int($body['limit'] ?? 100)));

        return new JsonResponse([
            'success' => true,
            'workspaceId' => $workspaceId,
            'pageUid' => $pageUid,
            'records' => $this->workspaceChangeListingService->list($workspaceId, $pageUid, $limit),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestReview(array $body): JsonResponse
    {
        return $this->runWorkflowAction($body, false);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function approve(array $body): JsonResponse
    {
        return $this->runWorkflowAction($body, true);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function runWorkflowAction(array $body, bool $publish): JsonResponse
    {
        $actor = $this->resolveActor($body);
        if ($actor === null) {
            return $this->jsonError('api.error.noActor', 403);
        }
        if (!$this->userPreferenceService->canApproveFromChat($actor) && !$this->apiAccessGuard->allowsUnsignedDevelopmentRequest()) {
            return $this->jsonError('api.error.noApprovalPermission', 403);
        }

        $selections = $this->selectionNormalizer->normalize($body['records'] ?? []);
        if ($selections === []) {
            return $this->jsonError('api.error.emptySelection', 400);
        }

        $comment = trim(Value::string($body['comment'] ?? ($publish ? 'ChatOps approval' : 'ChatOps review requested')));
        $result = $publish
            ? $this->workspaceWorkflowService->approveAndPublish($selections, $actor, $comment)
            : $this->workspaceWorkflowService->requestApproval($selections, $actor, $comment);
        if ($result['success']) {
            $eventType = $publish ? WorkspaceEventType::Published : WorkspaceEventType::ReviewRequested;
            $payload = $this->payloadFromBody($body, $eventType, $selections, $actor);
            $this->notificationDispatcher->dispatch($payload);
        }

        return new JsonResponse(['success' => $result['success'], 'changed' => $result['changed'], 'errors' => $result['errors']]);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<WorkspaceRecordSelection> $selections
     */
    private function payloadFromBody(
        array $body,
        WorkspaceEventType $eventType,
        array $selections = [],
        ?BackendUserAuthentication $actor = null,
    ): WorkspaceEventPayload {
        $title = trim(Value::string($body['title'] ?? null));
        if ($title === '') {
            $title = $this->localizationService->translate($eventType->titleLabelKey());
        }
        $records = $selections !== []
            ? $selections
            : $this->selectionNormalizer->normalize($body['records'] ?? []);

        $workspaceId = max(0, Value::int($body['workspaceId'] ?? null));
        if ($workspaceId <= 0 && $actor !== null) {
            $workspaceId = max(0, $actor->workspace);
        }
        if ($workspaceId <= 0) {
            $workspaceId = $this->configuration->defaultWorkspaceId();
        }

        return new WorkspaceEventPayload(
            type: $eventType,
            title: $title,
            message: trim(Value::string($body['message'] ?? $body['comment'] ?? null)),
            workspaceId: $workspaceId,
            pageUid: isset($body['pageUid']) ? Value::int($body['pageUid']) : null,
            backendUserId: $actor !== null ? Value::int($actor->user['uid'] ?? null) : null,
            records: $records,
            metadata: Value::stringKeyArray($body['metadata'] ?? null),
            previewUrl: trim(Value::string($body['previewUrl'] ?? null)) ?: null,
            backendUrl: trim(Value::string($body['backendUrl'] ?? null)) ?: null,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function resolveActor(array $body): ?BackendUserAuthentication
    {
        $workspaceId = max(0, Value::int($body['workspaceId'] ?? $this->configuration->defaultWorkspaceId()));
        $actor = Value::stringKeyArray($body['actor'] ?? null);
        $backendUserId = Value::int($actor['backendUserId'] ?? $body['backendUserId'] ?? null);
        if ($backendUserId > 0) {
            return $this->backendUserImpersonator->byUid($backendUserId, $workspaceId);
        }

        $provider = ChatProvider::fromString(Value::string($actor['provider'] ?? $body['provider'] ?? null));
        $externalId = trim(Value::string($actor['externalId'] ?? $body['externalId'] ?? null));
        if ($provider !== null && $externalId !== '') {
            return $this->backendUserImpersonator->byExternalIdentity($provider, $externalId, $workspaceId);
        }

        if ($this->apiAccessGuard->allowsUnsignedDevelopmentRequest()) {
            return $this->backendUserImpersonator->byUid($this->configuration->developmentBackendUserId(), $workspaceId);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeBody(ServerRequestInterface $request): ?array
    {
        if ($request->getMethod() === 'GET') {
            return Value::stringKeyArray($request->getQueryParams());
        }

        $body = trim((string)$request->getBody());
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? Value::stringKeyArray($decoded) : null;
    }

    private function jsonError(string $labelKey, int $statusCode): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => $this->localizationService->translate($labelKey),
        ], $statusCode);
    }
}
