<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Enum;

/**
 * The dropdown's presentation, chosen per editor in User Settings: the same
 * data, counts and actions in three arrangements. Clarity explains, efficiency
 * keeps the hands on the keyboard, core looks and behaves like the core
 * backend's own lists.
 */
enum ToolbarLayout: string
{
    case Clarity = 'clarity';
    case Efficiency = 'efficiency';
    case Core = 'core';

    /**
     * Lenient parser for a user setting or TSconfig value: case and
     * surrounding blanks do not matter; anything else no layout answers to
     * (a typo, a layout of a later release) is null.
     */
    public static function tryFromSetting(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }
}
