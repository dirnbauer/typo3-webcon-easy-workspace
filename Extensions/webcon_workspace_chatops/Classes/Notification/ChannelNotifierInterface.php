<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Notification;

use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceEventPayload;
use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;

interface ChannelNotifierInterface
{
    public function provider(): ChatProvider;

    /**
     * @param array<string, mixed> $configuration
     * @param list<string> $recipients Provider-specific recipient identifiers.
     */
    public function send(WorkspaceEventPayload $payload, array $configuration, array $recipients = []): NotificationResult;
}
