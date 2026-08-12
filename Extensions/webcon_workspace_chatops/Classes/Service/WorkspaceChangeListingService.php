<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconWorkspaceChatops\Configuration\ChatOpsConfiguration;
use Webconsulting\WebconWorkspaceChatops\Utility\Value;

final readonly class WorkspaceChangeListingService
{
    public function __construct(
        private ChatOpsConfiguration $configuration,
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $workspaceId, ?int $pageUid = null, int $limit = 100): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        $records = [];
        foreach ($this->configuration->allowedTables() as $table) {
            $records = array_merge($records, $this->listTable($table, $workspaceId, $pageUid, max(1, $limit - count($records))));
            if (count($records) >= $limit) {
                break;
            }
        }

        usort(
            $records,
            static fn(array $left, array $right): int => Value::int($right['tstamp'] ?? null) <=> Value::int($left['tstamp'] ?? null),
        );

        return array_slice($records, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listTable(string $table, int $workspaceId, ?int $pageUid, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        if (!$this->tcaSchemaFactory->has($table) || !$this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
            return [];
        }
        $schema = $this->tcaSchemaFactory->get($table);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $select = ['uid', 't3ver_oid', 't3ver_wsid', 't3ver_stage', 't3ver_state'];
        if ($schema->hasField('pid')) {
            $select[] = 'pid';
        }
        if ($schema->hasField('tstamp')) {
            $select[] = 'tstamp';
        }
        if ($table === 'pages' && $schema->hasField('title')) {
            $select[] = 'title';
        } elseif ($table === 'tt_content' && $schema->hasField('header')) {
            $select[] = 'header';
        }
        if ($table === 'tt_content' && $schema->hasField('CType')) {
            $select[] = 'CType';
        }

        $constraints = [
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER)),
        ];
        if ($pageUid !== null && $pageUid > 0) {
            if ($table === 'pages') {
                $constraints[] = $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                );
            } elseif ($schema->hasField('pid') && $this->tableHasPidScope($table)) {
                $constraints[] = $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER));
            }
        }

        $rows = $queryBuilder
            ->select(...$select)
            ->from($table)
            ->where(...$constraints)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'table' => $table,
                'workspaceUid' => Value::int($row['uid'] ?? null),
                'liveUid' => Value::int($row['t3ver_oid'] ?? null) ?: Value::int($row['uid'] ?? null),
                'pid' => Value::int($row['pid'] ?? null),
                'workspaceId' => Value::int($row['t3ver_wsid'] ?? null),
                'stage' => Value::int($row['t3ver_stage'] ?? null),
                'state' => Value::int($row['t3ver_state'] ?? null),
                'title' => $this->recordTitle($table, $row),
                'tstamp' => Value::int($row['tstamp'] ?? null),
            ];
        }

        return $records;
    }

    private function tableHasPidScope(string $table): bool
    {
        return $table !== 'sys_file_reference' && $table !== 'sys_file_metadata';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recordTitle(string $table, array $row): string
    {
        $candidate = match ($table) {
            'pages' => $row['title'] ?? '',
            'tt_content' => $row['header'] ?? $row['CType'] ?? '',
            default => '',
        };

        $candidate = trim(Value::string($candidate));

        return $candidate !== '' ? $candidate : $table . ':' . Value::int($row['uid'] ?? null);
    }
}
