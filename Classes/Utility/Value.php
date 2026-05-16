<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Utility;

final class Value
{
    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || is_bool($value)) {
            return (int)$value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }
        return 0;
    }

    public static function string(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public static function stringKeyArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }
        return $out;
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    public static function scalarStringKeyArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_scalar($item)) {
                $out[$key] = $item;
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $out[] = self::string($item);
        }
        return $out;
    }
}
