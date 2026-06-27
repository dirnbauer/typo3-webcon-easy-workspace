<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;
use Webconsulting\WebconWorkspaceChatops\Enum\WorkspaceEventType;

final readonly class UserPreferenceService
{
    public function isEnabled(BackendUserAuthentication $backendUser): bool
    {
        return $this->userSettingBool($backendUser, 'webconWorkspaceChatopsEnabled', $this->tsConfigBool($backendUser, 'userEnabledDefault', true));
    }

    public function canApproveFromChat(BackendUserAuthentication $backendUser): bool
    {
        return $this->userSettingBool($backendUser, 'webconWorkspaceChatopsCanApproveFromChat', false);
    }

    public function wantsProvider(BackendUserAuthentication $backendUser, ChatProvider $provider): bool
    {
        return match ($provider) {
            ChatProvider::Slack => $this->userSettingBool($backendUser, 'webconWorkspaceChatopsSlackEnabled', $this->tsConfigBool($backendUser, 'slackEnabledDefault', true)),
            ChatProvider::Teams => $this->userSettingBool($backendUser, 'webconWorkspaceChatopsTeamsEnabled', $this->tsConfigBool($backendUser, 'teamsEnabledDefault', false)),
            ChatProvider::WhatsApp => $this->userSettingBool($backendUser, 'webconWorkspaceChatopsWhatsappEnabled', $this->tsConfigBool($backendUser, 'whatsappEnabledDefault', false)),
        };
    }

    public function wantsEvent(BackendUserAuthentication $backendUser, WorkspaceEventType $eventType): bool
    {
        return $this->userSettingBool($backendUser, $eventType->userSettingKey(), true);
    }

    public function externalIdentity(BackendUserAuthentication $backendUser, ChatProvider $provider): string
    {
        $key = match ($provider) {
            ChatProvider::Slack => 'webconWorkspaceChatopsSlackUserId',
            ChatProvider::Teams => 'webconWorkspaceChatopsTeamsUserId',
            ChatProvider::WhatsApp => 'webconWorkspaceChatopsWhatsappPhone',
        };

        return $this->userSettingString($backendUser, $key);
    }

    private function userSettingString(BackendUserAuthentication $backendUser, string $key): string
    {
        $settings = $backendUser->getUserSettings();
        if (!$settings->has($key)) {
            return '';
        }
        $value = $settings->get($key);

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function userSettingBool(BackendUserAuthentication $backendUser, string $key, bool $default): bool
    {
        $settings = $backendUser->getUserSettings();
        if (!$settings->has($key)) {
            return $default;
        }

        return $this->toBool($settings->get($key), $default);
    }

    private function tsConfigBool(BackendUserAuthentication $backendUser, string $key, bool $default): bool
    {
        $options = $backendUser->getTSConfig()['options.']['webcon_workspace_chatops.'] ?? [];
        if (!is_array($options) || !array_key_exists($key, $options)) {
            return $default;
        }

        return $this->toBool($options[$key], $default);
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }
}
