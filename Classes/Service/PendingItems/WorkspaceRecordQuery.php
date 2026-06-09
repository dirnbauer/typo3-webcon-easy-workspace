<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final readonly class WorkspaceRecordQuery
{
    /**
     * Root-level workspace records that have no page/content parent but
     * still represent publishable editor work. The physical sys_file row is
     * not workspace-versioned; sys_file_metadata is TYPO3's publishable FAL
     * record.
     *
     * @var list<string>
     */
    public const STANDALONE_WORKSPACE_TABLES = [
        'sys_file_metadata',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private LocalizationService $localizationService,
    ) {}

    /**
     * Resolve the concrete pages.uid that represents the chosen backend
     * language. Content records keep the default page uid as their pid,
     * but translated page properties are stored as their own pages row.
     */
    public function resolvePageRecordUidForLanguage(int $pageUid, int $workspaceId, ?int $languageUid): int
    {
        return $this->resolveRecordUidForLanguage('pages', $pageUid, $workspaceId, $languageUid);
    }

    public function resolveRecordUidForLanguage(string $table, int $uid, int $workspaceId, ?int $languageUid): int
    {
        if ($languageUid === null || $languageUid <= 0) {
            return $uid;
        }
        $languageField = $this->languageField($table);
        $translationParentField = $this->translationParentField($table);
        if ($languageField === null || $translationParentField === null) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId, false));
        $row = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? Value::int($row['uid'] ?? null) : 0;
    }

    public function languageConstraint(QueryBuilder $queryBuilder, string $table, ?int $languageUid): ?string
    {
        if ($languageUid === null || $languageUid < 0) {
            return null;
        }
        $languageField = $this->languageField($table);
        if ($languageField === null) {
            return null;
        }
        return $queryBuilder->expr()->eq(
            $languageField,
            $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT),
        );
    }

    public function languageField(string $table): ?string
    {
        $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);
        $field = Value::string($ctrl['languageField'] ?? null);
        if ($field !== '' && TcaUtility::hasColumn($table, $field)) {
            return $field;
        }
        return TcaUtility::hasColumn($table, 'sys_language_uid') ? 'sys_language_uid' : null;
    }

    public function translationParentField(string $table): ?string
    {
        $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);
        $field = Value::string($ctrl['transOrigPointerField'] ?? null);
        if ($field !== '' && TcaUtility::hasColumn($table, $field)) {
            return $field;
        }
        return TcaUtility::hasColumn($table, 'l10n_parent') ? 'l10n_parent' : null;
    }

    public function hasWorkspaceVersionForRecord(string $table, int $liveUid, int $workspaceId, ?int $languageUid = null): bool
    {
        if ($liveUid <= 0 || $workspaceId <= 0 || !TcaUtility::hasColumn($table, 't3ver_wsid')) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)),
        ];
        if (TcaUtility::hasColumn($table, 'deleted')) {
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
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

    /**
     * @param int|list<int> $parentUid
     */
    public function hasChangedRowsRelated(string $table, string $field, int|array $parentUid, int $workspaceId, ?int $languageUid = null): bool
    {
        $parentUids = is_array($parentUid) ? array_values(array_filter($parentUid, static fn (int $uid): bool => $uid > 0)) : [$parentUid];
        if ($parentUids === [] || $workspaceId <= 0 || !TcaUtility::hasColumn($table, $field) || !TcaUtility::hasColumn($table, 't3ver_wsid')) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            count($parentUids) === 1
                ? $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($parentUids[0], Connection::PARAM_INT))
                : $queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        if (TcaUtility::hasColumn($table, 'deleted')) {
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        if ((bool)$queryBuilder
            ->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()) {
            return true;
        }

        return $this->hasWorkspaceVersionForLiveRowsRelated($table, $field, $parentUids, $workspaceId, $languageUid);
    }

    /**
     * Detect changed workspace versions through their live row. This covers
     * the common "existing content element was edited" case even if the
     * version row relation is stale or was created before TYPO3 normalized
     * workspace record pids.
     *
     * @param list<int> $parentUids
     */
    private function hasWorkspaceVersionForLiveRowsRelated(string $table, string $field, array $parentUids, int $workspaceId, ?int $languageUid): bool
    {
        if ($parentUids === [] || !TcaUtility::hasColumn($table, 't3ver_oid')) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            count($parentUids) === 1
                ? $queryBuilder->expr()->eq('live.' . $field, $queryBuilder->createNamedParameter($parentUids[0], Connection::PARAM_INT))
                : $queryBuilder->expr()->in('live.' . $field, $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            $queryBuilder->expr()->eq('live.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('workspaceVersion.t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        if (TcaUtility::hasColumn($table, 'deleted')) {
            $constraints[] = $queryBuilder->expr()->eq('live.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            $constraints[] = $queryBuilder->expr()->eq('workspaceVersion.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        $languageField = $this->languageField($table);
        if ($languageUid !== null && $languageUid >= 0 && $languageField !== null) {
            $constraints[] = $queryBuilder->expr()->eq(
                'live.' . $languageField,
                $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT),
            );
        }

        return (bool)$queryBuilder
            ->select('workspaceVersion.uid')
            ->from($table, 'live')
            ->innerJoin('live', $table, 'workspaceVersion', $queryBuilder->expr()->eq('workspaceVersion.t3ver_oid', $queryBuilder->quoteIdentifier('live.uid')))
            ->where(...$constraints)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Get a sorted list of all records of $table belonging to $parentUid,
     * with workspace overlay applied. Returns the raw row arrays (each
     * row will contain _ORIG_uid when overlaid from a workspace version).
     *
     * @param list<array{0: string, 1: string}> $orderBy List of [column, direction] tuples.
     * @return list<array<string, mixed>>
     */
    public function listAllRecordsOnPage(string $table, int $pageUid, int $workspaceId, array $orderBy, ?int $languageUid = null): array
    {
        return $this->listAllRelatedRecords($table, 'pid', $pageUid, $workspaceId, $orderBy, $languageUid);
    }

    /**
     * @param list<array{0: string, 1: string}> $orderBy List of [column, direction] tuples.
     * @return list<array<string, mixed>>
     */
    public function listAllRelatedRecords(string $table, string $field, int $parentUid, int $workspaceId, array $orderBy, ?int $languageUid = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // FrontendRestrictionContainer would filter hidden — we want
        // to *include* hidden so the badge can be shown.
        //
        // WorkspaceRestriction with $includeRowsForWorkspacePreview=false
        // returns one row per conceptual record (live OR new-in-workspace)
        // — never both — so the subsequent workspaceOL call cleanly
        // overlays the workspace version onto live rows without
        // producing duplicates. Setting the flag to true returns
        // workspace versions as separate rows in addition to their
        // live counterparts (see core docstring: "duplicates might be
        // shown and the reduce logic needs to be added after").
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId, false));

        $constraints = [
            $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
        ];
        $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints);

        foreach ($orderBy as $i => [$column, $direction]) {
            if ($i === 0) {
                $queryBuilder->orderBy($column, $direction);
            } else {
                $queryBuilder->addOrderBy($column, $direction);
            }
        }

        $result = $queryBuilder->executeQuery();
        $rows = [];
        while ($row = $result->fetchAssociative()) {
            BackendUtility::workspaceOL($table, $row, $workspaceId);
            if (is_array($row)) {
                $rows[] = Value::stringKeyArray($row);
            }
        }
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listStandaloneWorkspaceRows(string $table, int $workspaceId, int $limit): array
    {
        if ($workspaceId <= 0 || $limit <= 0 || !TcaUtility::hasColumn($table, 't3ver_wsid')) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        if (TcaUtility::hasColumn($table, 'deleted')) {
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }

        $result = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints)
            ->orderBy(TcaUtility::hasColumn($table, 'tstamp') ? 'tstamp' : 'uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery();

        $rows = [];
        while ($row = $result->fetchAssociative()) {
            $rows[] = Value::stringKeyArray($row);
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveRecordRow(string $table, int $liveUid, int $workspaceId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // See listAllRelatedRecords for why $includeRowsForWorkspacePreview=false.
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId, false));

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }
        BackendUtility::workspaceOL($table, $row, $workspaceId);
        if (!is_array($row)) {
            return null;
        }
        return Value::stringKeyArray($row);
    }

    /**
     * Reads the title field from sys_workspace; falls back to a
     * generic label for the live workspace or unknown ids.
     */
    public function resolveWorkspaceTitle(int $workspaceId): string
    {
        if ($workspaceId <= 0) {
            return $this->localizationService->translate('state.live');
        }
        $row = BackendUtility::getRecord('sys_workspace', $workspaceId);
        if (is_array($row) && !empty($row['title'])) {
            return Value::string($row['title']);
        }
        return $this->localizationService->translate('toolbar.title') . ' #' . $workspaceId;
    }

    public function hasStandaloneWorkspaceChanges(int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }
        foreach (self::STANDALONE_WORKSPACE_TABLES as $table) {
            if ($this->listStandaloneWorkspaceRows($table, $workspaceId, 1) !== []) {
                return true;
            }
        }
        return false;
    }
}
