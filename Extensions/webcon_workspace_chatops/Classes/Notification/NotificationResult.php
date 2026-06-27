<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Notification;

use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;

final readonly class NotificationResult
{
    public function __construct(
        public ChatProvider $provider,
        public bool $success,
        public string $message = '',
    ) {}

    /**
     * @return array{provider: string, success: bool, message: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'success' => $this->success,
            'message' => $this->message,
        ];
    }
}
