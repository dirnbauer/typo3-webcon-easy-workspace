<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Notification;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\WebconWorkspaceChatops\Configuration\ChatOpsConfiguration;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceEventPayload;
use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;
use Webconsulting\WebconWorkspaceChatops\Service\UserPreferenceService;

final readonly class NotificationDispatcher
{
    public function __construct(
        private ChatOpsConfiguration $configuration,
        private UserPreferenceService $userPreferenceService,
        private SlackNotifier $slackNotifier,
        private TeamsNotifier $teamsNotifier,
        private WhatsAppNotifier $whatsAppNotifier,
    ) {}

    /**
     * @param list<BackendUserAuthentication> $recipientUsers
     * @return list<NotificationResult>
     */
    public function dispatch(WorkspaceEventPayload $payload, array $recipientUsers = []): array
    {
        if (!$this->configuration->isEnabled()) {
            return [];
        }

        $results = [];
        foreach ($this->notifiers() as $notifier) {
            $provider = $notifier->provider();
            $providerConfig = $this->configuration->provider($provider);
            if (!($providerConfig['enabled'] ?? false)) {
                continue;
            }
            $recipientIds = $this->providerRecipientIds($provider, $payload, $recipientUsers);
            if ($provider === ChatProvider::WhatsApp && $recipientIds === [] && empty($providerConfig['defaultRecipients'])) {
                continue;
            }
            $results[] = $notifier->send($payload, $providerConfig, $recipientIds);
        }

        return $results;
    }

    /**
     * @return list<ChannelNotifierInterface>
     */
    private function notifiers(): array
    {
        return [
            $this->slackNotifier,
            $this->teamsNotifier,
            $this->whatsAppNotifier,
        ];
    }

    /**
     * @param list<BackendUserAuthentication> $recipientUsers
     * @return list<string>
     */
    private function providerRecipientIds(ChatProvider $provider, WorkspaceEventPayload $payload, array $recipientUsers): array
    {
        $ids = [];
        foreach ($recipientUsers as $backendUser) {
            if (!$this->userPreferenceService->isEnabled($backendUser)
                || !$this->userPreferenceService->wantsEvent($backendUser, $payload->type)
                || !$this->userPreferenceService->wantsProvider($backendUser, $provider)
            ) {
                continue;
            }
            $externalIdentity = $this->userPreferenceService->externalIdentity($backendUser, $provider);
            if ($externalIdentity !== '') {
                $ids[] = $externalIdentity;
            }
        }

        return array_values(array_unique($ids));
    }
}
