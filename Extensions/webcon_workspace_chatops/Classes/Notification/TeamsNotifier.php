<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Notification;

use TYPO3\CMS\Core\Http\RequestFactory;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceEventPayload;
use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;
use Webconsulting\WebconWorkspaceChatops\Service\LocalizationService;
use Webconsulting\WebconWorkspaceChatops\Utility\Value;

final readonly class TeamsNotifier implements ChannelNotifierInterface
{
    public function __construct(
        private RequestFactory $requestFactory,
        private LocalizationService $localizationService,
    ) {}

    public function provider(): ChatProvider
    {
        return ChatProvider::Teams;
    }

    public function send(WorkspaceEventPayload $payload, array $configuration, array $recipients = []): NotificationResult
    {
        $webhookUrl = trim(Value::string($configuration['webhookUrl'] ?? null));
        if ($webhookUrl === '') {
            return new NotificationResult($this->provider(), false, 'Teams webhook URL is not configured.');
        }

        $body = strtolower(Value::string($configuration['payloadMode'] ?? 'adaptiveCard')) === 'text'
            ? ['text' => $payload->plainTextSummary()]
            : $this->adaptiveCardPayload($payload, $recipients);

        try {
            $response = $this->requestFactory->request($webhookUrl, 'POST', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
            ]);
        } catch (\Throwable $e) {
            return new NotificationResult($this->provider(), false, $e->getMessage());
        }

        $statusCode = $response->getStatusCode();

        return new NotificationResult($this->provider(), $statusCode >= 200 && $statusCode < 300, 'HTTP ' . $statusCode);
    }

    /**
     * @param list<string> $recipients
     * @return array<string, mixed>
     */
    private function adaptiveCardPayload(WorkspaceEventPayload $payload, array $recipients): array
    {
        $facts = [];
        if ($payload->workspaceId > 0) {
            $facts[] = ['title' => $this->localizationService->translate('notification.field.workspace'), 'value' => '#' . $payload->workspaceId];
        }
        if ($payload->pageUid !== null && $payload->pageUid > 0) {
            $facts[] = ['title' => $this->localizationService->translate('notification.field.page'), 'value' => '#' . $payload->pageUid];
        }
        if ($payload->records !== []) {
            $facts[] = ['title' => $this->localizationService->translate('notification.field.records'), 'value' => (string)count($payload->records)];
        }
        if ($recipients !== []) {
            $facts[] = ['title' => $this->localizationService->translate('notification.field.recipients'), 'value' => implode(', ', $recipients)];
        }

        $actions = [];
        if ($payload->previewUrl !== null) {
            $actions[] = ['type' => 'Action.OpenUrl', 'title' => $this->localizationService->translate('notification.action.preview'), 'url' => $payload->previewUrl];
        }
        if ($payload->backendUrl !== null) {
            $actions[] = ['type' => 'Action.OpenUrl', 'title' => $this->localizationService->translate('notification.action.openBackend'), 'url' => $payload->backendUrl];
        }

        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.2',
                        'body' => array_values(array_filter([
                            [
                                'type' => 'TextBlock',
                                'text' => $payload->title,
                                'weight' => 'Bolder',
                                'size' => 'Medium',
                                'wrap' => true,
                            ],
                            $payload->message !== '' ? [
                                'type' => 'TextBlock',
                                'text' => $payload->message,
                                'wrap' => true,
                            ] : null,
                            $facts !== [] ? [
                                'type' => 'FactSet',
                                'facts' => $facts,
                            ] : null,
                        ])),
                        'actions' => $actions,
                    ],
                ],
            ],
        ];
    }
}
