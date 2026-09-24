<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Versioning\VersionState;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Service\RecordSchemaInspector;
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
    public const array STANDALONE_WORKSPACE_TABLES = [
        'sys_file_metadata',
    ];

    /**
     * Uids per version lookup of overlayRows(). MySQL/MariaDB plan an IN
     * list of more than eq_range_index_dive_limit (200) values from index
     * statistics, and the statistics of t3ver_oid — 0 on nearly every row —
     * make a full scan look cheaper: 80 ms instead of 1 ms on a page of 284
     * elements. Staying below the limit keeps the plan an index range.
     */
    private const int OVERLAY_CHUNK_SIZE = 100;

    public function __construct(
        private ConnectionPool $connectionPool,
        private LocalizationService $localizationService,
        private RecordSchemaInspector $schema,
        private WorkspaceVersionPresence $presence,
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
        if ($liveUid <= 0 || $workspaceId <= 0 || !$this->schema->isWorkspaceAware($table) || !$this->presence->hasVersions($table, $workspaceId)) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)),
        ];
        $softDeleteField = $this->schema->softDeleteField($table);
        if ($softDeleteField !== null) {
            $constraints[] = $queryBuilder->expr()->eq($softDeleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
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
        $parentUids = is_array($parentUid) ? array_values(array_filter($parentUid, static fn(int $uid): bool => $uid > 0)) : [$parentUid];
        if ($parentUids === [] || $workspaceId <= 0 || !TcaUtility::hasColumn($table, $field) || !$this->schema->isWorkspaceAware($table)) {
            return false;
        }
        // Both queries below only ever match rows of the workspace.
        if (!$this->presence->hasVersions($table, $workspaceId)) {
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
        $softDeleteField = $this->schema->softDeleteField($table);
        if ($softDeleteField !== null) {
            $constraints[] = $queryBuilder->expr()->eq($softDeleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
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
        if ($parentUids === [] || !$this->schema->isWorkspaceAware($table)) {
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
        $softDeleteField = $this->schema->softDeleteField($table);
        if ($softDeleteField !== null) {
            $constraints[] = $queryBuilder->expr()->eq('live.' . $softDeleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            $constraints[] = $queryBuilder->expr()->eq('workspaceVersion.' . $softDeleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
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
            $rows[] = Value::stringKeyArray($row);
        }
        return $this->overlayRows($table, $rows, $workspaceId);
    }

    /**
     * BackendUtility::workspaceOL() for a list of rows, with one version
     * lookup for all of them instead of one per row.
     *
     * workspaceOL() changes a row only when getWorkspaceVersionOfRecord()
     * finds a version: a non-deleted row of the workspace whose t3ver_oid
     * is the row's uid, or the row itself when it is a new placeholder.
     * The lookup below uses exactly that condition for all uids at once, and
     * workspaceOL() then runs for the rows it found — so every row comes
     * back as workspaceOL() would have returned it. On a page without
     * versions that is 1 query instead of 1 per content element (each of
     * them selecting 940 named columns on a Content Blocks tt_content).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function overlayRows(string $table, array $rows, int $workspaceId): array
    {
        if ($rows === [] || $workspaceId <= 0 || !$this->schema->isWorkspaceAware($table)) {
            return $rows;
        }
        $versioned = $this->uidsWithWorkspaceVersion(
            $table,
            array_values(array_filter(array_map(static fn(array $row): int => Value::int($row['uid'] ?? null), $rows), static fn(int $uid): bool => $uid > 0)),
            $workspaceId,
        );
        if ($versioned === []) {
            return $rows;
        }

        $overlaid = [];
        foreach ($rows as $row) {
            if (isset($versioned[Value::int($row['uid'] ?? null)])) {
                BackendUtility::workspaceOL($table, $row, $workspaceId);
                if (!is_array($row)) {
                    continue;
                }
                $row = Value::stringKeyArray($row);
            }
            $overlaid[] = $row;
        }
        return $overlaid;
    }

    /**
     * @param list<int> $uids
     * @return array<int, true> The uids of $uids that have a version in the workspace.
     */
    private function uidsWithWorkspaceVersion(string $table, array $uids, int $workspaceId): array
    {
        if ($uids === [] || !$this->presence->hasVersions($table, $workspaceId)) {
            return [];
        }

        $versioned = [];
        $newPlaceholder = VersionState::NEW_PLACEHOLDER->value;
        foreach (array_chunk(array_values(array_unique($uids)), self::OVERLAY_CHUNK_SIZE) as $chunk) {
            // Two lookups instead of one `t3ver_oid IN … OR (uid IN … AND …)`:
            // an OR across two columns makes MySQL/MariaDB scan the table —
            // 80 ms on a 940-column tt_content — while each half is an index
            // lookup on its own.
            // 1. Versions of the rows: core's (t3ver_oid, t3ver_wsid) index.
            $queryBuilder = $this->versionLookupQueryBuilder($table);
            $result = $queryBuilder
                ->select('t3ver_oid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->in('t3ver_oid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                )
                ->executeQuery();
            while (($liveUid = $result->fetchOne()) !== false) {
                $versioned[Value::int($liveUid)] = true;
            }
            // 2. Rows that are new placeholders of the workspace: the primary key.
            $queryBuilder = $this->versionLookupQueryBuilder($table);
            $result = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter($newPlaceholder, Connection::PARAM_INT)),
                )
                ->executeQuery();
            while (($uid = $result->fetchOne()) !== false) {
                $versioned[Value::int($uid)] = true;
            }
        }
        unset($versioned[0]);

        return $versioned;
    }

    private function versionLookupQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // Same restriction as BackendUtility::getWorkspaceVersionOfRecord().
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listStandaloneWorkspaceRows(string $table, int $workspaceId, int $limit): array
    {
        if ($workspaceId <= 0 || $limit <= 0 || !$this->schema->isWorkspaceAware($table) || !$this->presence->hasVersions($table, $workspaceId)) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        $softDeleteField = $this->schema->softDeleteField($table);
        if ($softDeleteField !== null) {
            $constraints[] = $queryBuilder->expr()->eq($softDeleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }

        $result = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints)
            ->orderBy($this->schema->updatedAtField($table) ?? 'uid', 'DESC')
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

        return array_any(
            self::STANDALONE_WORKSPACE_TABLES,
            fn(string $table): bool => $this->listStandaloneWorkspaceRows($table, $workspaceId, 1) !== [],
        );
    }
}
