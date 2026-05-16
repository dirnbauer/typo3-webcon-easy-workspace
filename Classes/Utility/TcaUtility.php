<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Utility;

final class TcaUtility
{
    /**
     * @return array<string, mixed>
     */
    public static function table(string $table): array
    {
        $tca = Value::stringKeyArray($GLOBALS['TCA'] ?? null);
        return Value::stringKeyArray($tca[$table] ?? null);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function tables(): array
    {
        $tables = [];
        foreach (Value::stringKeyArray($GLOBALS['TCA'] ?? []) as $table => $tca) {
            $tables[$table] = Value::stringKeyArray($tca);
        }
        return $tables;
    }

    public static function isWorkspaceAwareHiddenTable(string $table): bool
    {
        $ctrl = Value::stringKeyArray(self::table($table)['ctrl'] ?? null);
        return !empty($ctrl['versioningWS']) && !empty($ctrl['hideTable']);
    }

    /**
     * @param array<string, mixed> $tca
     * @return list<array<string, mixed>>
     */
    public static function extractInlineFieldConfigs(array $tca): array
    {
        $configs = [];
        foreach (Value::stringKeyArray($tca['columns'] ?? null) as $column) {
            $fieldConfig = Value::stringKeyArray(Value::stringKeyArray($column)['config'] ?? null);
            if (($fieldConfig['type'] ?? null) === 'inline') {
                $configs[] = $fieldConfig;
            }
        }
        foreach (Value::stringKeyArray($tca['types'] ?? null) as $typeConfig) {
            foreach (Value::stringKeyArray(Value::stringKeyArray($typeConfig)['columnsOverrides'] ?? null) as $override) {
                $fieldConfig = Value::stringKeyArray(Value::stringKeyArray($override)['config'] ?? null);
                if (($fieldConfig['type'] ?? null) === 'inline') {
                    $configs[] = $fieldConfig;
                }
            }
        }
        return $configs;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return array_key_exists($column, Value::stringKeyArray(self::table($table)['columns'] ?? null));
    }
}
