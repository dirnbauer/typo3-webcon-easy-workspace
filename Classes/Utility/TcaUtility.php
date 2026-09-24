<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Utility;

/**
 * Read access to `$GLOBALS['TCA']`.
 *
 * Every lookup goes straight to the entry it needs. Filtering a whole level
 * through Value::stringKeyArray() first copies it: done for the top level
 * (hundreds of tables) on each table() call and for a table's columns on
 * each hasColumn() call, that turned one Content Blocks page into several
 * million array writes per request.
 */
final class TcaUtility
{
    /**
     * @return array<string, mixed>
     */
    public static function table(string $table): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        $tableTca = is_array($tca) ? ($tca[$table] ?? null) : null;
        if (!is_array($tableTca)) {
            return [];
        }
        /** @var array<string, mixed> $tableTca TCA sections are keyed by name */
        return $tableTca;
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
        $columns = Value::stringKeyArray($tca['columns'] ?? null);
        foreach (Value::stringKeyArray($tca['types'] ?? null) as $typeConfig) {
            foreach (Value::stringKeyArray(Value::stringKeyArray($typeConfig)['columnsOverrides'] ?? null) as $fieldName => $override) {
                $columns[$fieldName] = array_replace_recursive(
                    Value::stringKeyArray($columns[$fieldName] ?? null),
                    Value::stringKeyArray($override),
                );
            }
        }

        $seen = [];
        foreach ($columns as $column) {
            $fieldConfig = Value::stringKeyArray(Value::stringKeyArray($column)['config'] ?? null);
            if (($fieldConfig['type'] ?? null) === 'inline') {
                $key = json_encode([
                    $fieldConfig['foreign_table'] ?? null,
                    $fieldConfig['foreign_field'] ?? null,
                    $fieldConfig['foreign_table_field'] ?? null,
                    $fieldConfig['foreign_match_fields'] ?? null,
                ]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $configs[] = $fieldConfig;
            }
        }
        return $configs;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $columns = self::table($table)['columns'] ?? null;

        return is_array($columns) && array_key_exists($column, $columns);
    }
}
