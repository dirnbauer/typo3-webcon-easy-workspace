<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Notification;

use TYPO3\CMS\Core\Http\RequestFactory;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceEventPayload;
use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;

final readonly class WhatsAppNotifier implements ChannelNotifierInterface
{
    public function __construct(private RequestFactory $requestFactory) {}

    public function provider(): ChatProvider
    {
        return ChatProvider::WhatsApp;
    }

    public function send(WorkspaceEventPayload $payload, array $configuration, array $recipients = []): NotificationResult
    {
        $apiBaseUrl = rtrim((string)($configuration['apiBaseUrl'] ?? ''), '/');
        $phoneNumberId = trim((string)($configuration['phoneNumberId'] ?? ''));
        $accessToken = trim((string)($configuration['accessToken'] ?? ''));
        $defaultRecipients = $this->stringList($configuration['defaultRecipients'] ?? []);
        $recipients = array_values(array_unique(array_merge($defaultRecipients, $recipients)));
        if ($apiBaseUrl === '' || $phoneNumberId === '' || $accessToken === '') {
            return new NotificationResult($this->provider(), false, 'WhatsApp Cloud API is not configured.');
        }
        if ($recipients === []) {
            return new NotificationResult($this->provider(), false, 'No WhatsApp recipients configured.');
        }

        $success = true;
        $messages = [];
        foreach ($recipients as $recipient) {
            $result = $this->sendToRecipient($apiBaseUrl, $phoneNumberId, $accessToken, $recipient, $payload, $configuration);
            $success = $success && $result->success;
            $messages[] = $recipient . ': ' . $result->message;
        }

        return new NotificationResult($this->provider(), $success, implode('; ', $messages));
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function sendToRecipient(
        string $apiBaseUrl,
        string $phoneNumberId,
        string $accessToken,
        string $recipient,
        WorkspaceEventPayload $payload,
        array $configuration,
    ): NotificationResult {
        $templateName = trim((string)($configuration['templateName'] ?? ''));
        $body = $templateName !== ''
            ? [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => (string)($configuration['templateLanguage'] ?? 'en_US')],
                ],
            ]
            : [
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $payload->plainTextSummary(),
                ],
            ];

        try {
            $response = $this->requestFactory->request($apiBaseUrl . '/' . rawurlencode($phoneNumberId) . '/messages', 'POST', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
            ]);
        } catch (\Throwable $e) {
            return new NotificationResult($this->provider(), false, $e->getMessage());
        }

        $statusCode = $response->getStatusCode();

        return new NotificationResult($this->provider(), $statusCode >= 200 && $statusCode < 300, 'HTTP ' . $statusCode);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn(mixed $item): string => trim((string)$item), $value)));
    }
}
