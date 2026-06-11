<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Enum;

/**
 * Filter mode for the pending-items list:
 *  - Changed (default): only records with a workspace version
 *  - All              : every record in scope (live + workspace), with
 *                       isChanged flagged per item so the UI can still
 *                       highlight pending work.
 *
 * The backing values are part of the AJAX wire format ("mode" in the
 * toolbar JSON) and the TSconfig vocabulary (defaultMode = all|changed).
 */
enum PendingItemsMode: string
{
    case Changed = 'changed';
    case All = 'all';

    /**
     * Lenient parser for TSconfig / query-string input: unknown or
     * empty values fall back to the conservative Changed mode.
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Changed;
    }

    public function includesUnchanged(): bool
    {
        return $this === self::All;
    }
}
