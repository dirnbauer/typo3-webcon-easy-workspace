<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Notification;

use TYPO3\CMS\Core\Http\RequestFactory;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceEventPayload;
use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;
use Webconsulting\WebconWorkspaceChatops\Service\LocalizationService;
use Webconsulting\WebconWorkspaceChatops\Utility\Value;

final readonly class SlackNotifier implements ChannelNotifierInterface
{
    public function __construct(
        private RequestFactory $requestFactory,
        private LocalizationService $localizationService,
    ) {}

    public function provider(): ChatProvider
    {
        return ChatProvider::Slack;
    }

    public function send(WorkspaceEventPayload $payload, array $configuration, array $recipients = []): NotificationResult
    {
        $webhookUrl = trim(Value::string($configuration['webhookUrl'] ?? null));
        if ($webhookUrl === '') {
            return new NotificationResult($this->provider(), false, 'Slack webhook URL is not configured.');
        }

        $mentions = array_map(static fn(string $id): string => '<@' . trim($id) . '>', array_filter($recipients));
        $message = $payload->message;
        if ($mentions !== []) {
            $message = implode(' ', $mentions) . "\n" . $message;
        }

        $body = [
            'text' => $payload->plainTextSummary(),
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $this->truncate($payload->title, 150),
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $this->truncate($message !== '' ? $message : $payload->plainTextSummary(), 2900),
                    ],
                ],
            ],
        ];
        if ($payload->previewUrl !== null || $payload->backendUrl !== null) {
            $elements = [];
            if ($payload->previewUrl !== null) {
                $elements[] = ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $this->localizationService->translate('notification.action.preview')], 'url' => $payload->previewUrl];
            }
            if ($payload->backendUrl !== null) {
                $elements[] = ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $this->localizationService->translate('notification.action.openBackend')], 'url' => $payload->backendUrl];
            }
            $body['blocks'][] = ['type' => 'actions', 'elements' => $elements];
        }

        return $this->postJson($webhookUrl, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $webhookUrl, array $body): NotificationResult
    {
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

    private function truncate(string $value, int $maxLength): string
    {
        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength - 1) . '...' : $value;
    }
}
