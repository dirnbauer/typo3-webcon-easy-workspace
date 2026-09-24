<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\WebconEasyWorkspace\Database\WorkspaceVersionConstraint;
use Webconsulting\WebconEasyWorkspace\Service\RecordSchemaInspector;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Which tables hold any (not soft-deleted) row of a workspace at all.
 *
 * Collecting a page used to ask every inline field of every content element
 * "do you have a changed child?" — one query each, 15,000 on a Content
 * Blocks page, although a workspace typically touches three or four tables.
 * One UNION over all workspace-aware tables answers that up front; a table
 * without rows of the workspace cannot contribute a changed row, so every
 * "changed rows of …" query against it is skipped.
 *
 * The answer is a superset of every skipped query (those all add further
 * constraints to `t3ver_wsid = N AND <soft delete> = 0`), so skipping never
 * changes a result. It is remembered per workspace until reset():
 * PendingItemsCollector resets it at the start of each collection, the
 * DataHandler hook after each write.
 */
final class WorkspaceVersionPresence
{
    /**
     * UNION members per statement; SQLite allows 500 compound SELECTs.
     */
    private const int CHUNK_SIZE = 100;

    /**
     * @var array<int, array<string, true>>
     */
    private array $tablesByWorkspace = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RecordSchemaInspector $schema,
    ) {}

    public function hasVersions(string $table, int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }

        return isset(($this->tablesByWorkspace[$workspaceId] ??= $this->scan($workspaceId))[$table]);
    }

    public function reset(): void
    {
        $this->tablesByWorkspace = [];
    }

    /**
     * @return array<string, true>
     */
    private function scan(int $workspaceId): array
    {
        $groups = [];
        foreach ($this->workspaceAwareTables() as $table) {
            $connection = $this->connectionPool->getConnectionForTable($table);
            $groups[spl_object_id($connection)]['connection'] = $connection;
            $groups[spl_object_id($connection)]['tables'][] = $table;
        }

        $present = [];
        foreach ($groups as $group) {
            foreach (array_chunk($group['tables'], self::CHUNK_SIZE) as $chunk) {
                foreach ($this->scanChunk($group['connection'], $chunk, $workspaceId) as $table) {
                    $present[$table] = true;
                }
            }
        }

        return $present;
    }

    /**
     * @param list<string> $tables
     * @return list<string> Tables of the chunk with rows of the workspace.
     */
    private function scanChunk(Connection $connection, array $tables, int $workspaceId): array
    {
        $selects = [];
        foreach ($tables as $table) {
            $selects[] = $this->probeSql($connection, $table, $workspaceId);
        }
        try {
            $rows = $connection->executeQuery(implode(' UNION ALL ', $selects))->fetchAllAssociative();
        } catch (\Throwable) {
            // A table the schema knows but the database does not (pending
            // schema update) breaks the whole UNION. Probe one by one and
            // count a table that cannot be probed as present: the per-row
            // queries then run exactly as they would without this index.
            return array_values(array_filter(
                $tables,
                fn(string $table): bool => $this->probeSingle($connection, $table, $workspaceId),
            ));
        }

        $present = [];
        foreach ($rows as $row) {
            if (Value::int($row['versions'] ?? null) > 0) {
                $present[] = Value::string($row['table_name'] ?? null);
            }
        }

        return $present;
    }

    private function probeSingle(Connection $connection, string $table, int $workspaceId): bool
    {
        try {
            $row = $connection->executeQuery($this->probeSql($connection, $table, $workspaceId))->fetchAssociative();
        } catch (\Throwable) {
            return true;
        }

        return is_array($row) && Value::int($row['versions'] ?? null) > 0;
    }

    private function probeSql(Connection $connection, string $table, int $workspaceId): string
    {
        $where = WorkspaceVersionConstraint::rowsOfSql(
            $connection->quoteIdentifier('t3ver_oid'),
            $connection->quoteIdentifier('t3ver_wsid'),
            $workspaceId,
        );
        $softDeleteField = $this->schema->softDeleteField($table);
        if ($softDeleteField !== null) {
            $where .= ' AND ' . $connection->quoteIdentifier($softDeleteField) . ' = 0';
        }

        return sprintf(
            'SELECT %s AS %s, COUNT(*) AS %s FROM %s WHERE %s',
            $connection->quote($table),
            $connection->quoteIdentifier('table_name'),
            $connection->quoteIdentifier('versions'),
            $connection->quoteIdentifier($table),
            $where,
        );
    }

    /**
     * @return list<string>
     */
    private function workspaceAwareTables(): array
    {
        $tables = [];
        foreach (array_keys(is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : []) as $table) {
            if (is_string($table) && $this->schema->isWorkspaceAware($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }
}
