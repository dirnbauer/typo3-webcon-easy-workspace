<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Enum;

/**
 * How a translation copy follows the change it belongs to, as the dropdown
 * names it: unchanged, carrying the synchronized values of the change, moved
 * along, or deleted along.
 */
enum CompanionKind: string
{
    case Identical = 'identical';
    case Synced = 'synced';
    case Moved = 'moved';
    case Deleted = 'deleted';

    /**
     * A new record is never a companion: it is content of its own.
     */
    public static function fromChangeKind(ChangeKind $kind): self
    {
        return match ($kind) {
            ChangeKind::Identical => self::Identical,
            ChangeKind::Edited => self::Synced,
            ChangeKind::Moved => self::Moved,
            ChangeKind::Deleted => self::Deleted,
            ChangeKind::New => throw new \LogicException('A new record is never a companion.', 1791676800),
        };
    }
}
