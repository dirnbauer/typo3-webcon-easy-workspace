<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Versioning\VersionState;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChangeCount;
use Webconsulting\WebconEasyWorkspace\Utility\Value;
use Webconsulting\WebconEasyWorkspace\Utility\WorkspaceTablePolicy;

/**
 * Single source of truth for the toolbar badge.
 *
 * Counts every pending version of one workspace with a single aggregate
 * query per counted table (COUNT + MAX(tstamp), grouped by t3ver_state).
 * No records are materialised, so the badge stays cheap enough to poll.
 */
final readonly class WorkspaceChangeCounter
{
    /**
     * Version states that represent an editor-visible pending change.
     *
     * @var list<int>
     */
    private const COUNTED_STATES = [0, 1, 2, 4];

    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    public function count(int $workspaceId): WorkspaceChangeCount
    {
        if ($workspaceId <= 0) {
            return WorkspaceChangeCount::empty($workspaceId);
        }

        $byTable = [];
        $byState = WorkspaceChangeCount::EMPTY_STATES;
        $total = 0;
        $latestChangeAt = 0;
        foreach ($this->countedTables() as $table) {
            foreach ($this->aggregate($table, $workspaceId) as $row) {
                $state = VersionState::tryFrom(Value::int($row['t3ver_state'] ?? null));
                $changes = Value::int($row['changes'] ?? null);
                if ($state === null || $changes <= 0) {
                    continue;
                }
                $byTable[$table] = ($byTable[$table] ?? 0) + $changes;
                $byState[self::stateKey($state)] += $changes;
                $total += $changes;
                $latestChangeAt = max($latestChangeAt, Value::int($row['latest'] ?? null));
            }
        }

        return new WorkspaceChangeCount(
            workspaceId: $workspaceId,
            total: $total,
            byTable: $byTable,
            byState: $byState,
            latestChangeAt: $latestChangeAt,
            stamp: WorkspaceChangeCount::stamp($workspaceId, $total, $latestChangeAt),
        );
    }

    /**
     * Tables that contribute to the badge: the policy's badge tables that
     * are installed and workspace-aware (news only when EXT:news exists).
     *
     * @return list<string>
     */
    public function countedTables(): array
    {
        $tables = [];
        foreach (WorkspaceTablePolicy::BADGE_TABLES as $table) {
            if ($this->tcaSchemaFactory->has($table) && $this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregate(string $table, int $workspaceId): array
    {
        $schema = $this->tcaSchemaFactory->get($table);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $constraints = [
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            $queryBuilder->expr()->in('t3ver_state', $queryBuilder->createNamedParameter(self::COUNTED_STATES, Connection::PARAM_INT_ARRAY)),
        ];
        if ($schema->hasCapability(TcaSchemaCapability::SoftDelete)) {
            $constraints[] = $queryBuilder->expr()->eq(
                $schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName(),
                $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
            );
        }

        $latest = $schema->hasCapability(TcaSchemaCapability::UpdatedAt)
            ? $queryBuilder->expr()->max($schema->getCapability(TcaSchemaCapability::UpdatedAt)->getFieldName(), 'latest')
            : '0 AS ' . $queryBuilder->quoteIdentifier('latest');

        $rows = $queryBuilder
            ->select('t3ver_state')
            ->addSelectLiteral($queryBuilder->expr()->count('uid', 'changes'), $latest)
            ->from($table)
            ->where(...$constraints)
            ->groupBy('t3ver_state')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_map(Value::stringKeyArray(...), $rows));
    }

    /**
     * @return 'new'|'changed'|'deleted'|'moved'
     */
    private static function stateKey(VersionState $state): string
    {
        return match ($state) {
            VersionState::NEW_PLACEHOLDER => 'new',
            VersionState::DELETE_PLACEHOLDER => 'deleted',
            VersionState::MOVE_POINTER => 'moved',
            VersionState::DEFAULT_STATE => 'changed',
        };
    }
}
