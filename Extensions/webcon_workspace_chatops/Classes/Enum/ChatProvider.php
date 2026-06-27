<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Enum;

enum ChatProvider: string
{
    case Slack = 'slack';
    case Teams = 'teams';
    case WhatsApp = 'whatsapp';

    public static function fromString(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            self::Slack->value => self::Slack,
            self::Teams->value => self::Teams,
            self::WhatsApp->value => self::WhatsApp,
            default => null,
        };
    }
}
