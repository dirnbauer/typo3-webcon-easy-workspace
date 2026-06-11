<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Resolves workspace-aware inline / IRRE / Content Blocks children for
 * pending-item collection.
 *
 * Keep table-specific traversal here — not in PendingItemsCollector or
 * PendingItemAggregator. When a new child table needs special handling,
 * add a focused helper in this class rather than branching shared paths.
 */
final readonly class InlineChildResolver
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private WorkspaceRecordQuery $workspaceRecordQuery,
        private PendingItemFactory $pendingItemFactory,
    ) {}

    /**
     * Content Blocks Collection fields are TYPO3 inline/IRRE child records.
     * They are versioned in their own generated tables, so a parent
     * tt_content record can be unchanged while its repeatable child rows
     * have pending workspace versions.
     *
     * @param array<string, mixed> $parentRow
     * @param array<string, mixed> $config
     * @param array<int, string>   $columnLabels
     * @return list<PendingItem>
     */
    public function resolveInlineChildItems(
        string $parentTable,
        array $parentRow,
        int $workspaceId,
        PendingItemsMode $mode,
        array $config = [],
        array $columnLabels = [],
        ?int $languageUid = null,
    ): array {
        $parentLiveUid = Value::int($parentRow['t3ver_oid'] ?? null) ?: Value::int($parentRow['uid'] ?? null);
        $parentWorkspaceUid = Value::int($parentRow['_ORIG_uid'] ?? $parentRow['uid'] ?? null);
        $parentUids = array_values(array_unique(array_filter([$parentLiveUid, $parentWorkspaceUid], static fn(int $uid): bool => $uid > 0)));
        if ($parentUids === []) {
            return [];
        }

        $items = [];
        foreach ($this->resolveInlineChildConfigs($parentTable, $parentRow) as $inlineConfig) {
            foreach ($this->listInlineChildRows($parentTable, $parentUids, $workspaceId, $mode, $inlineConfig, $languageUid) as $childRow) {
                $item = $this->pendingItemFactory->buildItem(
                    $inlineConfig['table'],
                    $childRow,
                    isPrimary: false,
                    config: $config,
                    columnLabels: $columnLabels,
                    locateTable: $parentTable === 'tt_content' ? 'tt_content' : null,
                    locateLiveUid: $parentTable === 'tt_content' ? $parentLiveUid : null,
                    locateWorkspaceUid: $parentTable === 'tt_content' ? $parentWorkspaceUid : null,
                );
                if ($item !== null && ($mode->includesUnchanged() || $item->isChanged)) {
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $columnLabels
     * @return array<int, list<PendingItem>>
     */
    public function resolveChangedInlineChildItemsOnPage(
        string $parentTable,
        int $pageUid,
        int $workspaceId,
        array $config,
        array $columnLabels,
        ?int $languageUid,
    ): array {
        $itemsByParent = [];
        foreach ($this->resolveInlineChildConfigsForTable($parentTable) as $inlineConfig) {
            $table = $inlineConfig['table'];
            $foreignField = $inlineConfig['foreignField'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();

            $constraints = [
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            ];
            if (TcaUtility::hasColumn($table, 'deleted')) {
                $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            }
            if ($inlineConfig['foreignTableField'] !== null && $inlineConfig['foreignTableField'] !== '') {
                $constraints[] = $queryBuilder->expr()->eq(
                    $inlineConfig['foreignTableField'],
                    $queryBuilder->createNamedParameter($parentTable),
                );
            }
            foreach ($inlineConfig['foreignMatchFields'] as $field => $value) {
                $constraints[] = $queryBuilder->expr()->eq((string)$field, $queryBuilder->createNamedParameter((string)$value));
            }
            $languageConstraint = $this->workspaceRecordQuery->languageConstraint($queryBuilder, $table, $languageUid);
            if ($languageConstraint !== null) {
                $constraints[] = $languageConstraint;
            }

            $result = $queryBuilder
                ->select('*')
                ->from($table)
                ->where(...$constraints)
                ->orderBy($foreignField, 'ASC')
                ->executeQuery();

            while ($row = $result->fetchAssociative()) {
                $row = Value::stringKeyArray($row);
                $parentUid = Value::int($row[$foreignField] ?? null);
                if ($parentUid <= 0) {
                    continue;
                }
                $item = $this->pendingItemFactory->buildItem(
                    $table,
                    $row,
                    isPrimary: false,
                    config: $config,
                    columnLabels: $columnLabels,
                    locateTable: $parentTable === 'tt_content' ? 'tt_content' : null,
                    locateLiveUid: $parentUid,
                    locateWorkspaceUid: $parentUid,
                );
                if ($item instanceof PendingItem) {
                    $itemsByParent[$parentUid][] = $item;
                }
            }
        }

        return $itemsByParent;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $columnLabels
     */
    public function resolveInlineChildParentItem(string $table, int $uid, int $workspaceId, array $config, array $columnLabels): ?PendingItem
    {
        $row = BackendUtility::getRecord($table, $uid);
        if (!is_array($row)) {
            return null;
        }
        $row = Value::stringKeyArray($row);
        if (Value::int($row['t3ver_wsid'] ?? null) <= 0) {
            BackendUtility::workspaceOL($table, $row, $workspaceId);
            if (!is_array($row)) {
                return null;
            }
            $row = Value::stringKeyArray($row);
        }
        return $this->pendingItemFactory->buildItem($table, $row, isPrimary: false, config: array_replace($config, ['showHidden' => true]), columnLabels: $columnLabels);
    }

    /**
     * @param array<string, mixed> $parentRow
     * @return list<array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}>
     */
    public function resolveInlineChildConfigs(string $parentTable, array $parentRow): array
    {
        $parentTca = TcaUtility::table($parentTable);
        if ($parentTca === []) {
            return [];
        }

        $columns = Value::stringKeyArray($parentTca['columns'] ?? null);
        $ctrl = Value::stringKeyArray($parentTca['ctrl'] ?? null);
        $typeField = Value::string($ctrl['type'] ?? null);
        $typeName = $typeField !== '' ? Value::string($parentRow[$typeField] ?? null) : '';
        $types = Value::stringKeyArray($parentTca['types'] ?? null);
        $typeConfig = Value::stringKeyArray($types[$typeName] ?? null);
        foreach (Value::stringKeyArray($typeConfig['columnsOverrides'] ?? null) as $fieldName => $override) {
            $columns[$fieldName] = array_replace_recursive(
                Value::stringKeyArray($columns[$fieldName] ?? null),
                Value::stringKeyArray($override),
            );
        }

        $inlineConfigs = [];
        foreach ($columns as $fieldName => $column) {
            if (!is_array($column)) {
                continue;
            }
            $fieldConfig = Value::stringKeyArray(Value::stringKeyArray($column)['config'] ?? null);
            $fieldType = Value::string($fieldConfig['type'] ?? null);
            if ($fieldType === 'file') {
                if ($this->isWorkspaceAwareInlineChildTable('sys_file_reference')) {
                    $inlineConfigs[] = [
                        'field' => $fieldName,
                        'label' => Value::string(Value::stringKeyArray($column)['label'] ?? $fieldName),
                        'table' => 'sys_file_reference',
                        'foreignField' => 'uid_foreign',
                        'foreignTableField' => 'tablenames',
                        'foreignMatchFields' => ['fieldname' => $fieldName],
                        'orderBy' => $this->resolveChildOrderBy('sys_file_reference', ['foreign_sortby' => 'sorting_foreign']),
                    ];
                }
                continue;
            }
            if ($fieldType !== 'inline') {
                continue;
            }
            $foreignTable = Value::string($fieldConfig['foreign_table'] ?? null);
            $foreignField = Value::string($fieldConfig['foreign_field'] ?? null);
            if ($foreignTable === '' || $foreignField === '' || !$this->isWorkspaceAwareInlineChildTable($foreignTable)) {
                continue;
            }
            $foreignMatchFields = Value::scalarStringKeyArray($fieldConfig['foreign_match_fields'] ?? null);
            $inlineConfigs[] = [
                'field' => $fieldName,
                'label' => Value::string(Value::stringKeyArray($column)['label'] ?? $fieldConfig['label'] ?? $fieldName),
                'table' => $foreignTable,
                'foreignField' => $foreignField,
                'foreignTableField' => isset($fieldConfig['foreign_table_field']) ? Value::string($fieldConfig['foreign_table_field']) : null,
                'foreignMatchFields' => $foreignMatchFields,
                'orderBy' => $this->resolveChildOrderBy($foreignTable, $fieldConfig),
            ];
        }
        return $inlineConfigs;
    }

    /**
     * Fallback config resolver for changed child rows whose parent was
     * not already rendered. It cannot know the parent's concrete type,
     * so it scans base columns and type overrides and de-duplicates by
     * child table / foreign field / match fields.
     *
     * @return list<array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}>
     */
    public function resolveInlineChildConfigsForTable(string $parentTable): array
    {
        $parentTca = TcaUtility::table($parentTable);
        if ($parentTca === []) {
            return [];
        }

        $columns = Value::stringKeyArray($parentTca['columns'] ?? null);
        foreach (Value::stringKeyArray($parentTca['types'] ?? null) as $typeConfig) {
            foreach (Value::stringKeyArray(Value::stringKeyArray($typeConfig)['columnsOverrides'] ?? null) as $fieldName => $override) {
                $columns[$fieldName] = array_replace_recursive(
                    Value::stringKeyArray($columns[$fieldName] ?? null),
                    Value::stringKeyArray($override),
                );
            }
        }

        $configs = [];
        $seen = [];
        foreach ($columns as $fieldName => $column) {
            if (!is_array($column)) {
                continue;
            }
            $fieldConfig = Value::stringKeyArray(Value::stringKeyArray($column)['config'] ?? null);
            $config = $this->inlineChildConfigFromField($parentTable, (string)$fieldName, Value::stringKeyArray($column), $fieldConfig);
            if ($config === null) {
                continue;
            }
            $key = $config['table'] . ':' . $config['foreignField'] . ':' . ($config['foreignTableField'] ?? '') . ':' . json_encode($config['foreignMatchFields']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $configs[] = $config;
        }

        return $configs;
    }

    /**
     * @param array<string, mixed> $column
     * @param array<string, mixed> $fieldConfig
     * @return array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}|null
     */
    public function inlineChildConfigFromField(string $parentTable, string $fieldName, array $column, array $fieldConfig): ?array
    {
        $fieldType = Value::string($fieldConfig['type'] ?? null);
        if ($fieldType === 'file') {
            if (!$this->isWorkspaceAwareInlineChildTable('sys_file_reference')) {
                return null;
            }
            return [
                'field' => $fieldName,
                'label' => Value::string($column['label'] ?? $fieldName),
                'table' => 'sys_file_reference',
                'foreignField' => 'uid_foreign',
                'foreignTableField' => 'tablenames',
                'foreignMatchFields' => ['fieldname' => $fieldName],
                'orderBy' => $this->resolveChildOrderBy('sys_file_reference', ['foreign_sortby' => 'sorting_foreign']),
            ];
        }
        if ($fieldType !== 'inline') {
            return null;
        }

        $foreignTable = Value::string($fieldConfig['foreign_table'] ?? null);
        $foreignField = Value::string($fieldConfig['foreign_field'] ?? null);
        if ($foreignTable === '' || $foreignField === '' || !$this->isWorkspaceAwareInlineChildTable($foreignTable)) {
            return null;
        }
        return [
            'field' => $fieldName,
            'label' => Value::string($column['label'] ?? $fieldConfig['label'] ?? $fieldName),
            'table' => $foreignTable,
            'foreignField' => $foreignField,
            'foreignTableField' => isset($fieldConfig['foreign_table_field']) ? Value::string($fieldConfig['foreign_table_field']) : null,
            'foreignMatchFields' => Value::scalarStringKeyArray($fieldConfig['foreign_match_fields'] ?? null),
            'orderBy' => $this->resolveChildOrderBy($foreignTable, $fieldConfig),
        ];
    }

    public function isWorkspaceAwareInlineChildTable(string $table): bool
    {
        if ($table === 'sys_file_reference') {
            $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);
            return !empty($ctrl['versioningWS']);
        }
        return TcaUtility::isWorkspaceAwareHiddenTable($table);
    }

    /**
     * @param array<string, mixed> $fieldConfig
     * @return list<array{0: string, 1: string}>
     */
    public function resolveChildOrderBy(string $table, array $fieldConfig): array
    {
        $tableTca = TcaUtility::table($table);
        $ctrl = Value::stringKeyArray($tableTca['ctrl'] ?? null);
        $sortField = Value::string($fieldConfig['foreign_sortby'] ?? $ctrl['sortby'] ?? null);
        if ($sortField !== '' && TcaUtility::hasColumn($table, $sortField)) {
            return [[$sortField, 'ASC']];
        }
        if (TcaUtility::hasColumn($table, 'sorting')) {
            return [['sorting', 'ASC']];
        }
        return [['uid', 'ASC']];
    }

    /**
     * @param list<int> $parentUids
     * @param array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>} $inlineConfig
     * @return list<array<string, mixed>>
     */
    public function listInlineChildRows(string $parentTable, array $parentUids, int $workspaceId, PendingItemsMode $mode, array $inlineConfig, ?int $languageUid = null): array
    {
        $table = $inlineConfig['table'];
        $foreignField = $inlineConfig['foreignField'];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        if ($mode->includesUnchanged()) {
            $queryBuilder->getRestrictions()
                ->add(new DeletedRestriction())
                ->add(new WorkspaceRestriction($workspaceId, false));
        }

        $constraints = [
            $queryBuilder->expr()->in($foreignField, $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
        ];
        if (!$mode->includesUnchanged()) {
            $constraints[] = $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT));
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        if ($inlineConfig['foreignTableField'] !== null && $inlineConfig['foreignTableField'] !== '') {
            $constraints[] = $queryBuilder->expr()->eq(
                $inlineConfig['foreignTableField'],
                $queryBuilder->createNamedParameter($parentTable),
            );
        }
        foreach ($inlineConfig['foreignMatchFields'] as $field => $value) {
            $constraints[] = $queryBuilder->expr()->eq((string)$field, $queryBuilder->createNamedParameter((string)$value));
        }
        $languageConstraint = $this->workspaceRecordQuery->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints);
        foreach ($inlineConfig['orderBy'] as $i => [$column, $direction]) {
            if ($i === 0) {
                $queryBuilder->orderBy($column, $direction);
            } else {
                $queryBuilder->addOrderBy($column, $direction);
            }
        }

        $result = $queryBuilder->executeQuery();
        $rows = [];
        while ($row = $result->fetchAssociative()) {
            if ($mode->includesUnchanged()) {
                BackendUtility::workspaceOL($table, $row, $workspaceId);
            }
            if (is_array($row)) {
                $rows[] = Value::stringKeyArray($row);
            }
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $parentRows
     */
    public function hasInlineChildChangesForRows(string $parentTable, array $parentRows, int $workspaceId, ?int $languageUid = null): bool
    {
        foreach ($parentRows as $parentRow) {
            $parentLiveUid = Value::int($parentRow['t3ver_oid'] ?? null) ?: Value::int($parentRow['uid'] ?? null);
            $parentWorkspaceUid = Value::int($parentRow['_ORIG_uid'] ?? $parentRow['uid'] ?? null);
            $parentUids = array_values(array_unique(array_filter([$parentLiveUid, $parentWorkspaceUid], static fn(int $uid): bool => $uid > 0)));
            if ($parentUids === []) {
                continue;
            }
            foreach ($this->resolveInlineChildConfigs($parentTable, $parentRow) as $inlineConfig) {
                if ($this->hasChangedInlineChildRows($parentTable, $parentUids, $workspaceId, $inlineConfig, $languageUid)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param list<int> $parentUids
     * @param array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>} $inlineConfig
     */
    public function hasChangedInlineChildRows(string $parentTable, array $parentUids, int $workspaceId, array $inlineConfig, ?int $languageUid = null): bool
    {
        $table = $inlineConfig['table'];
        $foreignField = $inlineConfig['foreignField'];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $constraints = [
            count($parentUids) === 1
                ? $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($parentUids[0], Connection::PARAM_INT))
                : $queryBuilder->expr()->in($foreignField, $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        if (TcaUtility::hasColumn($table, 'deleted')) {
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        if ($inlineConfig['foreignTableField'] !== null && $inlineConfig['foreignTableField'] !== '') {
            $constraints[] = $queryBuilder->expr()->eq(
                $inlineConfig['foreignTableField'],
                $queryBuilder->createNamedParameter($parentTable),
            );
        }
        foreach ($inlineConfig['foreignMatchFields'] as $field => $value) {
            $constraints[] = $queryBuilder->expr()->eq((string)$field, $queryBuilder->createNamedParameter((string)$value));
        }
        $languageConstraint = $this->workspaceRecordQuery->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        return (bool)$queryBuilder
            ->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    public function hasChangedInlineChildrenOnPage(string $parentTable, int $pageUid, int $workspaceId, ?int $languageUid = null): bool
    {
        if ($pageUid <= 0 || $workspaceId <= 0) {
            return false;
        }

        foreach ($this->resolveInlineChildConfigsForTable($parentTable) as $inlineConfig) {
            $table = $inlineConfig['table'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $constraints = [
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            ];
            if (TcaUtility::hasColumn($table, 'deleted')) {
                $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            }
            if ($inlineConfig['foreignTableField'] !== null && $inlineConfig['foreignTableField'] !== '') {
                $constraints[] = $queryBuilder->expr()->eq(
                    $inlineConfig['foreignTableField'],
                    $queryBuilder->createNamedParameter($parentTable),
                );
            }
            foreach ($inlineConfig['foreignMatchFields'] as $field => $value) {
                $constraints[] = $queryBuilder->expr()->eq((string)$field, $queryBuilder->createNamedParameter((string)$value));
            }
            $languageConstraint = $this->workspaceRecordQuery->languageConstraint($queryBuilder, $table, $languageUid);
            if ($languageConstraint !== null) {
                $constraints[] = $languageConstraint;
            }

            if ((bool)$queryBuilder
                ->select('uid')
                ->from($table)
                ->where(...$constraints)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne()
            ) {
                return true;
            }
        }

        return false;
    }
}
