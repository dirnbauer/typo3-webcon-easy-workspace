<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Service\RecordSchemaInspector;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Resolves workspace-aware inline / IRRE / Content Blocks children for
 * pending-item collection.
 *
 * Keep table-specific traversal here — not in PendingItemsCollector or
 * PendingItemAggregator. When a new child table needs special handling,
 * add a focused helper in this class rather than branching shared paths.
 *
 * Cost model. A Content Blocks installation defines every collection field
 * as a base `tt_content` column, so each element carries a few hundred
 * inline fields whatever its CType. Asking each of them separately cost one
 * query per element and field — 15,000 for one page. Instead:
 *
 *  - the field configuration of a table and type is resolved once;
 *  - children of all elements of a page are fetched with one query per
 *    distinct relation (table, foreign field, match fields), then handed to
 *    their parents in the order the per-element query returned them;
 *  - in "changed" mode, relations into a table without any row of the
 *    workspace are not queried at all (see WorkspaceVersionPresence).
 */
final class InlineChildResolver
{
    /**
     * Parents per batched query. Each contributes up to two uids, which
     * keeps the IN list below MySQL/MariaDB's eq_range_index_dive_limit
     * (200), so the optimizer keeps looking the values up in the index
     * instead of estimating from statistics (see WorkspaceRecordQuery).
     */
    private const int PARENT_CHUNK_SIZE = 90;

    /**
     * @var array<string, list<array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}>>
     */
    private array $configsByType = [];

    /**
     * @var array<string, list<array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}>>
     */
    private array $configsByTable = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly WorkspaceRecordQuery $workspaceRecordQuery,
        private readonly PendingItemFactory $pendingItemFactory,
        private readonly RecordSchemaInspector $schema,
        private readonly WorkspaceVersionPresence $presence,
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
     * @param array<int, list<array<string, mixed>>>|null $prefetchedRows Child rows
     *        per inline config index of this parent, as returned by
     *        prefetchInlineChildRows() (skipped relations are absent); null
     *        queries them here.
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
        ?array $prefetchedRows = null,
    ): array {
        [$parentLiveUid, $parentWorkspaceUid, $parentUids] = self::parentIdentity($parentRow);
        if ($parentUids === []) {
            return [];
        }

        $rowsByConfig = $prefetchedRows ?? $this->prefetchInlineChildRows($parentTable, [$parentRow], $workspaceId, $mode, $languageUid)[0];
        $items = [];
        foreach ($this->resolveInlineChildConfigs($parentTable, $parentRow) as $configIndex => $inlineConfig) {
            foreach ($rowsByConfig[$configIndex] ?? [] as $childRow) {
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
     * Child rows of many parents at once: one query per distinct relation
     * instead of one per parent and relation. The result is indexed like
     * $parentRows, then like resolveInlineChildConfigs() of that parent, and
     * holds for each parent exactly the rows — in the same order — that
     * listInlineChildRows() would return for it alone.
     *
     * @param list<array<string, mixed>> $parentRows
     * @return list<array<int, list<array<string, mixed>>>>
     */
    public function prefetchInlineChildRows(string $parentTable, array $parentRows, int $workspaceId, PendingItemsMode $mode, ?int $languageUid = null): array
    {
        $plan = [];
        $relations = [];
        foreach ($parentRows as $rowIndex => $parentRow) {
            $plan[$rowIndex] = [];
            $parentUids = self::parentIdentity($parentRow)[2];
            if ($parentUids === []) {
                continue;
            }
            foreach ($this->resolveInlineChildConfigs($parentTable, $parentRow) as $configIndex => $inlineConfig) {
                if (!$mode->includesUnchanged() && !$this->presence->hasVersions($inlineConfig['table'], $workspaceId)) {
                    continue;
                }
                $relationKey = self::relationKey($inlineConfig);
                $relations[$relationKey]['config'] ??= $inlineConfig;
                $relations[$relationKey]['parents'][$rowIndex] = $parentUids;
                $plan[$rowIndex][$configIndex] = $relationKey;
            }
        }

        $rowsByRelationAndParent = [];
        foreach ($relations as $relationKey => $relation) {
            foreach (array_chunk($relation['parents'], self::PARENT_CHUNK_SIZE, true) as $parentChunk) {
                $rowsByRelationAndParent[$relationKey] = ($rowsByRelationAndParent[$relationKey] ?? [])
                    + $this->fetchForParents($parentTable, $parentChunk, $workspaceId, $mode, $relation['config'], $languageUid);
            }
        }

        $result = [];
        foreach ($parentRows as $rowIndex => $parentRow) {
            $rowsByConfig = [];
            foreach ($plan[$rowIndex] ?? [] as $configIndex => $relationKey) {
                $rowsByConfig[$configIndex] = $rowsByRelationAndParent[$relationKey][$rowIndex] ?? [];
            }
            $result[] = $rowsByConfig;
        }

        return $result;
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
            if (!$this->presence->hasVersions($table, $workspaceId)) {
                continue;
            }
            $foreignField = $inlineConfig['foreignField'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();

            $constraints = [
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                ...$this->relationConstraints($queryBuilder, $parentTable, $inlineConfig, $languageUid, softDelete: true),
            ];

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
     * Inline relations of one parent row: the base columns of the table
     * with the columnsOverrides of the row's type applied. Depends on the
     * table and the type value only, so it is resolved once per type.
     *
     * @param array<string, mixed> $parentRow
     * @return list<array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}>
     */
    public function resolveInlineChildConfigs(string $parentTable, array $parentRow): array
    {
        $ctrl = Value::stringKeyArray(TcaUtility::table($parentTable)['ctrl'] ?? null);
        $typeField = Value::string($ctrl['type'] ?? null);
        $typeName = $typeField !== '' ? Value::string($parentRow[$typeField] ?? null) : '';

        return $this->configsByType[$parentTable . "\0" . $typeName] ??= $this->buildInlineChildConfigs($parentTable, $typeName);
    }

    /**
     * @return list<array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}>
     */
    private function buildInlineChildConfigs(string $parentTable, string $typeName): array
    {
        $parentTca = TcaUtility::table($parentTable);
        if ($parentTca === []) {
            return [];
        }

        $columns = Value::stringKeyArray($parentTca['columns'] ?? null);
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
            $column = Value::stringKeyArray($column);
            $config = $this->inlineChildConfigFromField($parentTable, $fieldName, $column, Value::stringKeyArray($column['config'] ?? null));
            if ($config !== null) {
                $inlineConfigs[] = $config;
            }
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
        return $this->configsByTable[$parentTable] ??= $this->buildInlineChildConfigsForTable($parentTable);
    }

    /**
     * @return list<array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}>
     */
    private function buildInlineChildConfigsForTable(string $parentTable): array
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
     * Child rows of one parent (live and workspace uid) for one relation.
     *
     * @param list<int> $parentUids
     * @param array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>} $inlineConfig
     * @return list<array<string, mixed>>
     */
    public function listInlineChildRows(string $parentTable, array $parentUids, int $workspaceId, PendingItemsMode $mode, array $inlineConfig, ?int $languageUid = null): array
    {
        if (!$mode->includesUnchanged() && !$this->presence->hasVersions($inlineConfig['table'], $workspaceId)) {
            return [];
        }

        return $this->fetchForParents($parentTable, [0 => $parentUids], $workspaceId, $mode, $inlineConfig, $languageUid)[0] ?? [];
    }

    /**
     * @param list<array<string, mixed>> $parentRows
     */
    public function hasInlineChildChangesForRows(string $parentTable, array $parentRows, int $workspaceId, ?int $languageUid = null): bool
    {
        $relations = [];
        foreach ($parentRows as $parentRow) {
            $parentUids = self::parentIdentity($parentRow)[2];
            if ($parentUids === []) {
                continue;
            }
            foreach ($this->resolveInlineChildConfigs($parentTable, $parentRow) as $inlineConfig) {
                if (!$this->presence->hasVersions($inlineConfig['table'], $workspaceId)) {
                    continue;
                }
                $relationKey = self::relationKey($inlineConfig);
                $relations[$relationKey]['config'] ??= $inlineConfig;
                foreach ($parentUids as $parentUid) {
                    $relations[$relationKey]['uids'][$parentUid] = $parentUid;
                }
            }
        }

        foreach ($relations as $relation) {
            foreach (array_chunk(array_values($relation['uids']), self::PARENT_CHUNK_SIZE * 2) as $uidChunk) {
                if ($this->hasChangedInlineChildRows($parentTable, $uidChunk, $workspaceId, $relation['config'], $languageUid)) {
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
        if ($parentUids === [] || !$this->presence->hasVersions($table, $workspaceId)) {
            return false;
        }
        $foreignField = $inlineConfig['foreignField'];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $constraints = [
            count($parentUids) === 1
                ? $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($parentUids[0], Connection::PARAM_INT))
                : $queryBuilder->expr()->in($foreignField, $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            ...$this->relationConstraints($queryBuilder, $parentTable, $inlineConfig, $languageUid, softDelete: true),
        ];

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
            if (!$this->presence->hasVersions($table, $workspaceId)) {
                continue;
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $constraints = [
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                ...$this->relationConstraints($queryBuilder, $parentTable, $inlineConfig, $languageUid, softDelete: true),
            ];

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

    /**
     * One query for one relation and many parents. Returns the rows per
     * parent key of $parentUidsByKey, each list in query order; the query
     * adds `uid` as last sort key so ties resolve the same way every time.
     *
     * Rows are assigned by the foreign field of the fetched row, before any
     * workspace overlay — exactly the rows a query for that parent alone
     * would have matched.
     *
     * @param array<int, list<int>> $parentUidsByKey
     * @param array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>} $inlineConfig
     * @return array<int, list<array<string, mixed>>>
     */
    private function fetchForParents(string $parentTable, array $parentUidsByKey, int $workspaceId, PendingItemsMode $mode, array $inlineConfig, ?int $languageUid): array
    {
        $table = $inlineConfig['table'];
        $foreignField = $inlineConfig['foreignField'];
        $allUids = [];
        foreach ($parentUidsByKey as $parentUids) {
            foreach ($parentUids as $parentUid) {
                $allUids[$parentUid] = $parentUid;
            }
        }
        if ($allUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        if ($mode->includesUnchanged()) {
            $queryBuilder->getRestrictions()
                ->add(new DeletedRestriction())
                ->add(new WorkspaceRestriction($workspaceId, false));
        }

        $constraints = [
            $queryBuilder->expr()->in($foreignField, $queryBuilder->createNamedParameter(array_values($allUids), Connection::PARAM_INT_ARRAY)),
        ];
        if (!$mode->includesUnchanged()) {
            $constraints[] = $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT));
        }
        array_push($constraints, ...$this->relationConstraints($queryBuilder, $parentTable, $inlineConfig, $languageUid, softDelete: !$mode->includesUnchanged()));

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints);
        $sortsByUid = false;
        foreach ($inlineConfig['orderBy'] as $i => [$column, $direction]) {
            $sortsByUid = $sortsByUid || $column === 'uid';
            if ($i === 0) {
                $queryBuilder->orderBy($column, $direction);
            } else {
                $queryBuilder->addOrderBy($column, $direction);
            }
        }
        if (!$sortsByUid) {
            $queryBuilder->addOrderBy('uid', 'ASC');
        }

        $rawRows = [];
        $result = $queryBuilder->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $rawRows[] = Value::stringKeyArray($row);
        }

        $foreignValues = array_map(static fn(array $row): int => Value::int($row[$foreignField] ?? null), $rawRows);
        $rows = $mode->includesUnchanged()
            ? $this->workspaceRecordQuery->overlayRows($table, $rawRows, $workspaceId)
            : $rawRows;

        $rowsByKey = [];
        $keysByUid = [];
        foreach ($parentUidsByKey as $key => $parentUids) {
            $rowsByKey[$key] = [];
            foreach ($parentUids as $parentUid) {
                $keysByUid[$parentUid][] = $key;
            }
        }
        foreach ($rows as $index => $row) {
            foreach ($keysByUid[$foreignValues[$index]] ?? [] as $key) {
                $rowsByKey[$key][] = $row;
            }
        }

        return $rowsByKey;
    }

    /**
     * The relation part shared by every child query: parent table, match
     * fields, language and — for "changed rows" queries — soft delete.
     *
     * @param array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>} $inlineConfig
     * @return list<string>
     */
    private function relationConstraints(QueryBuilder $queryBuilder, string $parentTable, array $inlineConfig, ?int $languageUid, bool $softDelete): array
    {
        $constraints = [];
        if ($softDelete) {
            $softDeleteField = $this->schema->softDeleteField($inlineConfig['table']);
            if ($softDeleteField !== null) {
                $constraints[] = $queryBuilder->expr()->eq($softDeleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            }
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
        $languageConstraint = $this->workspaceRecordQuery->languageConstraint($queryBuilder, $inlineConfig['table'], $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        return $constraints;
    }

    /**
     * @param array<string, mixed> $parentRow
     * @return array{0: int, 1: int, 2: list<int>} live uid, workspace uid, both (distinct, > 0)
     */
    private static function parentIdentity(array $parentRow): array
    {
        $parentLiveUid = Value::int($parentRow['t3ver_oid'] ?? null) ?: Value::int($parentRow['uid'] ?? null);
        $parentWorkspaceUid = Value::int($parentRow['_ORIG_uid'] ?? $parentRow['uid'] ?? null);
        $parentUids = array_values(array_unique(array_filter([$parentLiveUid, $parentWorkspaceUid], static fn(int $uid): bool => $uid > 0)));

        return [$parentLiveUid, $parentWorkspaceUid, $parentUids];
    }

    /**
     * Everything a child query depends on besides the parent uids.
     *
     * @param array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>} $inlineConfig
     */
    private static function relationKey(array $inlineConfig): string
    {
        return (string)json_encode([
            $inlineConfig['table'],
            $inlineConfig['foreignField'],
            $inlineConfig['foreignTableField'],
            $inlineConfig['foreignMatchFields'],
            $inlineConfig['orderBy'],
        ]);
    }
}
