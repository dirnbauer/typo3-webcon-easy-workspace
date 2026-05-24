<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final readonly class WorkspaceDiagnosticsService
{
    private const FILE = 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:';

    private const VALID_VERSION_STATES = [0, 1, 2, 4];

    private const SEVERITY_LABEL_KEYS = [
        'critical' => 'module.diagnostics.severity.critical',
        'warning' => 'module.diagnostics.severity.warning',
        'info' => 'module.diagnostics.severity.info',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @return array{workspaceId: int, issues: list<array<string, mixed>>, manualChecks: list<array<string, string>>, summary: array<string, int>, tablesScanned: int, tablesWithWorkspaceRows: int, workspaceRowsScanned: int}
     */
    public function scan(?int $workspaceId = null): array
    {
        $workspaceId ??= Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        $issues = [];
        $tablesScanned = 0;
        $tablesWithWorkspaceRows = 0;
        $workspaceRowsScanned = 0;

        foreach ($this->workspaceTables() as $table) {
            ++$tablesScanned;
            $workspaceRows = $this->countWorkspaceRows($table, $workspaceId);
            if ($workspaceRows > 0) {
                ++$tablesWithWorkspaceRows;
                $workspaceRowsScanned += $workspaceRows;
            }
            $issues = array_merge(
                $issues,
                $this->findInvalidLiveRows($table),
                $this->findUnsupportedVersionStates($table, $workspaceId),
                $this->findModifiedWorkspaceRowsWithoutLiveCounterpart($table, $workspaceId),
                $this->findOrphanWorkspaceVersions($table, $workspaceId),
                $this->findDuplicateWorkspaceVersions($table, $workspaceId),
                $this->findBrokenInlineChildParents($table, $workspaceId),
                $this->findBrokenFileReferenceOwners($table, $workspaceId),
            );
        }

        usort($issues, static fn (array $a, array $b): int => ($a['sort'] ?? 99) <=> ($b['sort'] ?? 99) ?: strcmp(Value::string($a['table'] ?? null), Value::string($b['table'] ?? null)));

        return [
            'workspaceId' => $workspaceId,
            'issues' => $issues,
            'manualChecks' => $this->manualChecks(),
            'summary' => $this->summarize($issues),
            'tablesScanned' => $tablesScanned,
            'tablesWithWorkspaceRows' => $tablesWithWorkspaceRows,
            'workspaceRowsScanned' => $workspaceRowsScanned,
        ];
    }

    /**
     * @return list<string>
     */
    private function workspaceTables(): array
    {
        $tables = [];
        foreach (TcaUtility::tables() as $table => $tca) {
            $ctrl = Value::stringKeyArray($tca['ctrl'] ?? null);
            if (!empty($ctrl['versioningWS'])) {
                $tables[] = $table;
            }
        }
        sort($tables);
        return $tables;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findInvalidLiveRows(string $table): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid', 't3ver_state')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->neq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->neq('t3ver_state', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                ),
            )
            ->setMaxResults(200)
            ->executeQuery()
            ->fetchAllAssociative();

        $issues = [];
        foreach ($rows as $row) {
            $issues[] = $this->issue(
                'live-row-version-fields',
                'critical',
                $table,
                Value::int($row['uid'] ?? null),
                0,
                Value::int($row['uid'] ?? null),
                Value::int($row['pid'] ?? null),
                'module.diagnostics.issue.liveRowVersionFields',
                'SELECT uid,pid,t3ver_oid,t3ver_state FROM ' . $table . ' WHERE uid=' . Value::int($row['uid'] ?? null) . ';',
            );
        }
        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findUnsupportedVersionStates(string $table, int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid', 't3ver_state')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->notIn('t3ver_state', $queryBuilder->createNamedParameter(self::VALID_VERSION_STATES, Connection::PARAM_INT_ARRAY)),
            )
            ->setMaxResults(200)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): array => $this->issue(
            'unsupported-version-state',
            'critical',
            $table,
            Value::int($row['uid'] ?? null),
            $workspaceId,
            Value::int($row['t3ver_oid'] ?? null),
            Value::int($row['pid'] ?? null),
            'module.diagnostics.issue.unsupportedVersionState',
            'SELECT uid,pid,t3ver_oid,t3ver_state FROM ' . $table . ' WHERE uid=' . Value::int($row['uid'] ?? null) . ';',
        ), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findModifiedWorkspaceRowsWithoutLiveCounterpart(string $table, int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'pid', 't3ver_state')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('t3ver_state', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
            )
            ->setMaxResults(200)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): array => $this->issue(
            'workspace-row-without-live-identity',
            'critical',
            $table,
            Value::int($row['uid'] ?? null),
            $workspaceId,
            0,
            Value::int($row['pid'] ?? null),
            'module.diagnostics.issue.workspaceRowWithoutLiveIdentity',
            'SELECT uid,pid,t3ver_oid,t3ver_wsid,t3ver_state FROM ' . $table . ' WHERE uid=' . Value::int($row['uid'] ?? null) . ';',
        ), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findOrphanWorkspaceVersions(string $table, int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $workspaceRows = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(500)
            ->executeQuery()
            ->fetchAllAssociative();

        $issues = [];
        foreach ($workspaceRows as $row) {
            $liveUid = Value::int($row['t3ver_oid'] ?? null);
            if ($liveUid > 0 && !$this->recordExists($table, $liveUid)) {
                $issues[] = $this->issue(
                    'orphan-workspace-version',
                    'critical',
                    $table,
                    Value::int($row['uid'] ?? null),
                    $workspaceId,
                    $liveUid,
                    Value::int($row['pid'] ?? null),
                    'module.diagnostics.issue.orphanWorkspaceVersion',
                    'SELECT uid,pid,t3ver_oid,t3ver_wsid FROM ' . $table . ' WHERE uid=' . Value::int($row['uid'] ?? null) . ';',
                );
            }
        }
        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findDuplicateWorkspaceVersions(string $table, int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('t3ver_oid')
            ->addSelectLiteral('COUNT(*) AS duplicate_count', 'GROUP_CONCAT(uid ORDER BY uid ASC) AS workspace_uids')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->groupBy('t3ver_oid')
            ->having('COUNT(*) > 1')
            ->setMaxResults(200)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): array => $this->issue(
            'duplicate-workspace-version',
            'warning',
            $table,
            0,
            $workspaceId,
            Value::int($row['t3ver_oid'] ?? null),
            0,
            'module.diagnostics.issue.duplicateWorkspaceVersion',
            'SELECT uid,pid,t3ver_oid,t3ver_wsid,tstamp,crdate FROM ' . $table . ' WHERE t3ver_wsid=' . $workspaceId . ' AND t3ver_oid=' . Value::int($row['t3ver_oid'] ?? null) . ' ORDER BY uid;',
            Value::string($row['workspace_uids'] ?? null),
        ), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findBrokenInlineChildParents(string $table, int $workspaceId): array
    {
        if ($workspaceId <= 0 || !TcaUtility::hasColumn($table, 'foreign_table_parent_uid')) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'pid', 'foreign_table_parent_uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('foreign_table_parent_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(500)
            ->executeQuery()
            ->fetchAllAssociative();

        $issues = [];
        foreach ($rows as $row) {
            $parentUid = Value::int($row['foreign_table_parent_uid'] ?? null);
            if ($parentUid > 0 && !$this->recordExists('tt_content', $parentUid)) {
                $issues[] = $this->issue(
                    'inline-child-missing-parent',
                    'warning',
                    $table,
                    Value::int($row['uid'] ?? null),
                    $workspaceId,
                    0,
                    Value::int($row['pid'] ?? null),
                    'module.diagnostics.issue.inlineChildMissingParent',
                    'SELECT uid,pid,foreign_table_parent_uid,t3ver_oid,t3ver_wsid FROM ' . $table . ' WHERE uid=' . Value::int($row['uid'] ?? null) . ';',
                    $this->translate('module.diagnostics.detail.parentContentElementUid', ['parentUid' => $parentUid]),
                );
            }
        }
        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findBrokenFileReferenceOwners(string $table, int $workspaceId): array
    {
        if ($workspaceId <= 0 || $table !== 'sys_file_reference') {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'pid', 'uid_foreign', 'tablenames', 'fieldname')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('uid_foreign', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('tablenames', $queryBuilder->createNamedParameter('', Connection::PARAM_STR)),
            )
            ->setMaxResults(500)
            ->executeQuery()
            ->fetchAllAssociative();

        $issues = [];
        foreach ($rows as $row) {
            $ownerTable = Value::string($row['tablenames'] ?? null);
            $ownerUid = Value::int($row['uid_foreign'] ?? null);
            if ($ownerTable === '' || $ownerUid <= 0 || TcaUtility::table($ownerTable) === []) {
                continue;
            }
            if (!$this->recordExists($ownerTable, $ownerUid)) {
                $issues[] = $this->issue(
                    'file-reference-missing-owner',
                    'critical',
                    $table,
                    Value::int($row['uid'] ?? null),
                    $workspaceId,
                    0,
                    Value::int($row['pid'] ?? null),
                    'module.diagnostics.issue.fileReferenceMissingOwner',
                    'SELECT uid,pid,uid_local,uid_foreign,tablenames,fieldname,t3ver_oid,t3ver_wsid FROM sys_file_reference WHERE uid=' . Value::int($row['uid'] ?? null) . ';',
                    $this->translate('module.diagnostics.detail.ownerField', [
                        'ownerTable' => $ownerTable,
                        'ownerUid' => $ownerUid,
                        'fieldName' => Value::string($row['fieldname'] ?? null),
                    ]),
                );
            }
        }
        return $issues;
    }

    private function recordExists(string $table, int $uid): bool
    {
        if ($uid <= 0 || TcaUtility::table($table) === []) {
            return false;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
        ];
        if (TcaUtility::hasColumn($table, 'deleted')) {
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        return (bool)$queryBuilder
            ->count('uid')
            ->from($table)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchOne();
    }

    private function countWorkspaceRows(string $table, int $workspaceId): int
    {
        if ($workspaceId <= 0 || !TcaUtility::hasColumn($table, 't3ver_wsid')) {
            return 0;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return Value::int($queryBuilder
            ->count('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne());
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, int>
     */
    private function summarize(array $issues): array
    {
        $summary = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => count($issues)];
        foreach ($issues as $issue) {
            $severity = Value::string($issue['severity'] ?? null);
            if (isset($summary[$severity])) {
                ++$summary[$severity];
            }
        }
        return $summary;
    }

    /**
     * @return list<array<string, string>>
     */
    private function manualChecks(): array
    {
        return array_map(fn (string $key): array => [
            'title' => $this->translate('module.diagnostics.manual.' . $key . '.title'),
            'risk' => $this->translate('module.diagnostics.manual.' . $key . '.risk'),
            'solve' => $this->translate('module.diagnostics.manual.' . $key . '.solve'),
        ], [
            'overwrittenFalFiles',
            'folderFileCollectionDrift',
            'externalCacheIndexDrift',
            'editorIntentConflicts',
        ]);
    }

    private function tableLabel(string $table): string
    {
        $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);
        $title = Value::string($ctrl['title'] ?? null);
        if ($title === '') {
            return $table;
        }
        $label = $this->languageService()->sL($title);
        return $label !== '' ? $label : $table;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $label = $this->languageService()->label(self::FILE . $key, $arguments);
        if (is_string($label) || $label instanceof \Stringable) {
            $label = (string)$label;
            if ($label !== '') {
                return $label;
            }
        }
        return $key;
    }

    private function languageService(): LanguageService
    {
        if (($GLOBALS['LANG'] ?? null) instanceof LanguageService) {
            return $GLOBALS['LANG'];
        }
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof AbstractUserAuthentication ? $GLOBALS['BE_USER'] : null;
        return $this->languageServiceFactory->createFromUserPreferences($backendUser);
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(
        string $type,
        string $severity,
        string $table,
        int $workspaceUid,
        int $workspaceId,
        int $liveUid,
        int $pid,
        string $labelKeyPrefix,
        string $sql,
        string $detail = '',
    ): array {
        $sort = ['critical' => 0, 'warning' => 10, 'info' => 20][$severity] ?? 99;
        return [
            'type' => $type,
            'severity' => $severity,
            'sort' => $sort,
            'table' => $table,
            'tableLabel' => $this->tableLabel($table),
            'workspaceUid' => $workspaceUid,
            'workspaceId' => $workspaceId,
            'liveUid' => $liveUid,
            'pid' => $pid,
            'severityLabel' => $this->translate(self::SEVERITY_LABEL_KEYS[$severity] ?? self::SEVERITY_LABEL_KEYS['info']),
            'title' => $this->translate($labelKeyPrefix . '.title'),
            'impact' => $this->translate($labelKeyPrefix . '.impact'),
            'solve' => $this->translate($labelKeyPrefix . '.solve'),
            'sql' => $sql,
            'detail' => $detail,
        ];
    }
}
