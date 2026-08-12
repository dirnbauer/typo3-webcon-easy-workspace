<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Configuration;

use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;
use Webconsulting\WebconWorkspaceChatops\Utility\Value;

final readonly class ChatOpsConfiguration
{
    /**
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        $typo3Configuration = Value::stringKeyArray($GLOBALS['TYPO3_CONF_VARS'] ?? null);
        $extensions = Value::stringKeyArray($typo3Configuration['EXTENSIONS'] ?? null);

        return Value::stringKeyArray($extensions['webcon_workspace_chatops'] ?? null);
    }

    public function isEnabled(): bool
    {
        return $this->bool('enabled', true);
    }

    public function apiPath(): string
    {
        $path = trim($this->string('apiPath', '/webcon-chatops/api'));

        return $path !== '' && str_starts_with($path, '/') ? $path : '/webcon-chatops/api';
    }

    public function apiToken(): string
    {
        return $this->string('apiToken', getenv('WEBCON_WORKSPACE_CHATOPS_API_TOKEN') ?: '');
    }

    public function allowUnsignedDevelopmentRequests(): bool
    {
        return $this->bool('allowUnsignedDevelopmentRequests', true);
    }

    public function developmentBackendUserId(): int
    {
        return max(0, $this->int('developmentBackendUserId', 1));
    }

    public function defaultWorkspaceId(): int
    {
        return max(0, $this->int('defaultWorkspaceId', 0));
    }

    public function approvalStageId(): int
    {
        return $this->int('approvalStageId', 1);
    }

    public function publishStageId(): int
    {
        return $this->int('publishStageId', -10);
    }

    public function notifyAfterRecordPublished(): bool
    {
        return $this->bool('notifyAfterRecordPublished', false);
    }

    /**
     * @return list<string>
     */
    public function allowedTables(): array
    {
        $tables = $this->csv('allowedTables', 'pages,tt_content,tx_news_domain_model_news,sys_file_reference,sys_file_metadata');

        return array_values(array_filter($tables, static fn(string $table): bool => $table !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function provider(ChatProvider $provider): array
    {
        return match ($provider) {
            ChatProvider::Slack => [
                'enabled' => $this->bool('slackEnabled', true),
                'webhookUrl' => $this->string('slackWebhookUrl', getenv('WEBCON_WORKSPACE_CHATOPS_SLACK_WEBHOOK') ?: ''),
            ],
            ChatProvider::Teams => [
                'enabled' => $this->bool('teamsEnabled', false),
                'webhookUrl' => $this->string('teamsWebhookUrl', getenv('WEBCON_WORKSPACE_CHATOPS_TEAMS_WEBHOOK') ?: ''),
                'payloadMode' => $this->string('teamsPayloadMode', 'adaptiveCard'),
            ],
            ChatProvider::WhatsApp => [
                'enabled' => $this->bool('whatsappEnabled', false),
                'apiBaseUrl' => rtrim($this->string('whatsappApiBaseUrl', getenv('WEBCON_WORKSPACE_CHATOPS_WHATSAPP_API_BASE_URL') ?: 'https://graph.facebook.com/v20.0'), '/'),
                'phoneNumberId' => $this->string('whatsappPhoneNumberId', getenv('WEBCON_WORKSPACE_CHATOPS_WHATSAPP_PHONE_NUMBER_ID') ?: ''),
                'accessToken' => $this->string('whatsappAccessToken', getenv('WEBCON_WORKSPACE_CHATOPS_WHATSAPP_ACCESS_TOKEN') ?: ''),
                'defaultRecipients' => $this->csv('whatsappDefaultRecipients', getenv('WEBCON_WORKSPACE_CHATOPS_WHATSAPP_RECIPIENTS') ?: ''),
                'templateName' => $this->string('whatsappTemplateName', ''),
                'templateLanguage' => $this->string('whatsappTemplateLanguage', 'en_US'),
            ],
        };
    }

    private function string(string $key, string $default = ''): string
    {
        $raw = $this->raw();
        $value = $raw[$key] ?? $default;

        return is_scalar($value) ? trim(Value::string($value)) : $default;
    }

    private function int(string $key, int $default = 0): int
    {
        $value = $this->string($key, (string)$default);

        return is_numeric($value) ? (int)$value : $default;
    }

    private function bool(string $key, bool $default = false): bool
    {
        $value = $this->raw()[$key] ?? $default;
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

    /**
     * @return list<string>
     */
    private function csv(string $key, string $default = ''): array
    {
        $value = $this->string($key, $default);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value))));
    }
}
