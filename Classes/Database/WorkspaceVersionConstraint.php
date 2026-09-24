<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Database;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * "Rows of workspace N", phrased so the database can use an index.
 *
 * Core gives every workspace-aware table one index on
 * `(t3ver_oid, t3ver_wsid)` and none that starts with `t3ver_wsid`, so a
 * plain `t3ver_wsid = N` scans the whole table. On a Content Blocks
 * `tt_content` (940 columns, 47k rows) that is 80 ms for a handful of rows.
 *
 * `t3ver_oid` is unsigned, so `t3ver_wsid = N` is the same set as
 * `(t3ver_oid = 0 AND t3ver_wsid = N) OR (t3ver_oid > 0 AND t3ver_wsid = N)`:
 * new records of the workspace, plus versions of existing records. Both
 * halves are ranges of that index — the first exact, the second over the
 * version rows of all workspaces, which are few — so the same query answers
 * in well under a millisecond.
 */
final class WorkspaceVersionConstraint
{
    public static function rowsOf(QueryBuilder $queryBuilder, int $workspaceId, string $tableAlias = ''): CompositeExpression
    {
        $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
        $expr = $queryBuilder->expr();

        return $expr->or(
            $expr->and(
                $expr->eq($prefix . 't3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $expr->eq($prefix . 't3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            ),
            $expr->and(
                $expr->gt($prefix . 't3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $expr->eq($prefix . 't3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            ),
        );
    }

    /**
     * Same constraint as literal SQL, for statements assembled by hand
     * (UNION ALL over many tables). Only integers are interpolated.
     */
    public static function rowsOfSql(string $quotedOidColumn, string $quotedWorkspaceColumn, int $workspaceId): string
    {
        return sprintf(
            '((%1$s = 0 AND %2$s = %3$d) OR (%1$s > 0 AND %2$s = %3$d))',
            $quotedOidColumn,
            $quotedWorkspaceColumn,
            $workspaceId,
        );
    }
}
