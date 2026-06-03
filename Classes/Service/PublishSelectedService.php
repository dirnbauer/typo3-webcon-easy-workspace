<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;
use Webconsulting\WebconEasyWorkspace\Utility\WorkspaceTablePolicy;

/**
 * Publishes a user-selected list of workspace records to live in one
 * DataHandler call.
 *
 * Each selection entry is a tuple [table, workspaceUid]. The service
 * resolves the matching live uid (t3ver_oid or, for new placeholders,
 * the workspace uid itself) and builds the v14-preferred cmdmap:
 *
 *   $cmd[$table][$liveUid]['version'] = [
 *       'action'   => 'publish',
 *       'swapWith' => $workspaceUid,
 *   ];
 */
final readonly class PublishSelectedService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private LocalizationService $localizationService,
        private WorkspaceTablePolicy $workspaceTablePolicy,
    ) {}

    /**
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{success: bool, published: int, errors: list<string>}
     */
    public function publish(array $selections): array
    {
        if ($selections === []) {
            return ['success' => true, 'published' => 0, 'errors' => []];
        }
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        if ($workspaceId <= 0) {
            return ['success' => false, 'published' => 0, 'errors' => [$this->localizationService->translate('error.publishFromLive')]];
        }

        // Group selections by table so we can insert them in priority order.
        $byTable = [];
        $count = 0;
        $rejected = 0;
        foreach ($selections as $entry) {
            $table = $entry['table'];
            $workspaceUid = $entry['workspaceUid'];
            if ($table === '' || $workspaceUid <= 0 || !$this->workspaceTablePolicy->isAllowed($table)) {
                continue;
            }
            // Defence-in-depth: confirm the record really belongs to
            // the active workspace before we hand it to DataHandler.
            // DataHandler does its own workspace check too, but
            // rejecting up front gives a cleaner error path and
            // prevents the cmdmap from being polluted with foreign
            // workspace ids that a crafted payload could include.
            if (!$this->belongsToWorkspace($table, $workspaceUid, $workspaceId)) {
                ++$rejected;
                continue;
            }
            $liveUid = $this->resolveLiveUid($table, $workspaceUid);
            if ($liveUid <= 0) {
                continue;
            }
            $byTable[$table][$liveUid] = [
                'version' => ['action' => 'publish', 'swapWith' => $workspaceUid],
            ];
            ++$count;
        }
        if ($byTable === []) {
            $msg = $rejected > 0
                ? $this->localizationService->translate('error.selectionWrongWorkspace')
                : $this->localizationService->translate('error.noPublishableRecords');
            return ['success' => false, 'published' => 0, 'errors' => [$msg]];
        }

        // Build the cmdmap in priority order: parents first, children
        // second, then anything else in stable order.
        $cmd = [];
        foreach (WorkspaceTablePolicy::PUBLISH_ORDER as $orderedTable) {
            if (isset($byTable[$orderedTable])) {
                $cmd[$orderedTable] = $byTable[$orderedTable];
                unset($byTable[$orderedTable]);
            }
        }
        foreach ($byTable as $table => $rows) {
            $cmd[$table] = $rows;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        return [
            'success' => $dataHandler->errorLog === [],
            'published' => $count,
            'errors' => Value::stringList($dataHandler->errorLog),
        ];
    }

    /**
     * Discard (revert) a single workspace version. Removes the
     * offline record so the live row remains the only version.
     *
     * Uses DataHandler with the TYPO3 v14 cmdmap form:
     *   $cmd[$table][$workspaceUid]['discard'] = true;
     * which is the explicit API for clearing a workspace version.
     *
     * @return array{success: bool, discarded: int, errors: list<string>}
     */
    public function discard(string $table, int $workspaceUid): array
    {
        if ($table === '' || $workspaceUid <= 0) {
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.missingTableWorkspace')]];
        }
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        if ($workspaceId <= 0) {
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.discardFromLive')]];
        }
        // Defence-in-depth: confirm the record belongs to the active
        // workspace before handing it to DataHandler. The toolbar usually
        // sends the concrete workspace uid, but frontend preview controls
        // can report the rendered live uid. TYPO3's discard command accepts
        // both, so resolve live uids to their workspace version first.
        $resolvedWorkspaceUid = $this->resolveWorkspaceUidForDiscard($table, $workspaceUid, $workspaceId);
        if ($resolvedWorkspaceUid <= 0) {
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]];
        }

        $cmd = [
            $table => [
                $resolvedWorkspaceUid => [
                    'discard' => true,
                ],
            ],
        ];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        return [
            'success' => $dataHandler->errorLog === [],
            'discarded' => 1,
            'errors' => Value::stringList($dataHandler->errorLog),
        ];
    }

    private function resolveWorkspaceUidForDiscard(string $table, int $uid, int $workspaceId): int
    {
        if ($workspaceId <= 0 || $uid <= 0 || !TcaUtility::hasColumn($table, 't3ver_wsid')) {
            return 0;
        }

        $deletedField = TcaUtility::hasColumn($table, 'deleted');
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $selectFields = ['uid', 't3ver_wsid', 't3ver_oid'];
        if ($deletedField) {
            $selectFields[] = 'deleted';
        }
        $row = $queryBuilder
            ->select(...$selectFields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return 0;
        }
        if ($deletedField && Value::int($row['deleted'] ?? null) !== 0) {
            return 0;
        }

        $rowWorkspaceId = Value::int($row['t3ver_wsid'] ?? null);
        if ($rowWorkspaceId === $workspaceId) {
            return Value::int($row['uid'] ?? null);
        }
        if ($rowWorkspaceId !== 0) {
            return 0;
        }

        return $this->findWorkspaceVersionUid($table, Value::int($row['uid'] ?? null), $workspaceId);
    }

    private function findWorkspaceVersionUid(string $table, int $liveUid, int $workspaceId): int
    {
        if ($liveUid <= 0 || $workspaceId <= 0) {
            return 0;
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

        $row = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? Value::int($row['uid'] ?? null) : 0;
    }

    /**
     * Verify the row at $table#$workspaceUid actually lives in the
     * given workspace. Returns false for stale uids, deleted rows
     * and — critically — rows belonging to a different workspace.
     */
    private function belongsToWorkspace(string $table, int $workspaceUid, int $workspaceId): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('t3ver_wsid', 'deleted')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return false;
        }
        if (Value::int($row['deleted'] ?? null) !== 0) {
            return false;
        }
        return Value::int($row['t3ver_wsid'] ?? null) === $workspaceId;
    }

    private function resolveLiveUid(string $table, int $workspaceUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid', 't3ver_oid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return 0;
        }
        $liveUid = Value::int($row['t3ver_oid'] ?? null);
        return $liveUid > 0 ? $liveUid : Value::int($row['uid'] ?? null);
    }

}
