<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The record a collection item or file reference is part of, from core's
 * reference index: the element whose inline field points at it. A row of
 * its own in the list (its element has no version) still tells the editor
 * which element it belongs to.
 */
final readonly class RecordParentResolver
{
    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * Only records of tables the backend keeps out of the record list —
     * inline children, file references — have an element to name.
     */
    public function hasParent(string $table): bool
    {
        $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);

        return !empty($ctrl['hideTable']);
    }

    /**
     * @return array{table: string, uid: int, title: string}|null
     */
    public function parentOf(string $table, int $liveUid, int $workspaceUid, int $workspaceId): ?array
    {
        $uids = array_values(array_unique(array_filter([$liveUid, $workspaceUid], static fn(int $uid): bool => $uid > 0)));
        if ($uids === [] || !$this->hasParent($table)) {
            return null;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $row = $queryBuilder
            ->select('tablename', 'recuid')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->in('ref_uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->in('workspace', $queryBuilder->createNamedParameter([0, $workspaceId], Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('workspace', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }
        $parentTable = Value::string($row['tablename'] ?? null);
        $parentUid = Value::int($row['recuid'] ?? null);
        if ($parentTable === '' || $parentUid <= 0) {
            return null;
        }
        $parent = BackendUtility::getRecordWSOL($parentTable, $parentUid);
        if (!is_array($parent)) {
            return null;
        }
        $parent = Value::stringKeyArray($parent);
        $liveParentUid = Value::int($parent['t3ver_oid'] ?? null) ?: $parentUid;

        return [
            'table' => $parentTable,
            'uid' => $liveParentUid,
            'title' => trim(strip_tags(BackendUtility::getRecordTitle($parentTable, $parent))),
        ];
    }
}
