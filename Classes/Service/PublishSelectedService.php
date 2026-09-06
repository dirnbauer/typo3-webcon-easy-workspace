<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;
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
 *
 * Permission model: DataHandler and the workspaces DataHandlerHook
 * remain the enforcement layer (publish gate, record-level checks).
 * The pre-flight checks here reject foreign-workspace rows and
 * tables the user may not modify before anything reaches the cmdmap,
 * giving clean error responses instead of opaque DataHandler logs.
 */
final readonly class PublishSelectedService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private LocalizationService $localizationService,
        private WorkspaceTablePolicy $workspaceTablePolicy,
        private TcaSchemaFactory $tcaSchemaFactory,
        private BackendAccessGuard $accessGuard,
        private ConfigurationProvider $configurationProvider,
    ) {}

    /**
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{success: bool, changed: int, errors: list<string>}
     */
    public function requestReview(array $selections, ?BackendUserAuthentication $backendUser = null): array
    {
        $config = $this->configurationProvider->get();

        return $this->sendToStage(
            $selections,
            Value::int($config['approvalStageId'] ?? 1),
            $backendUser,
            $this->localizationService->translate('review.stage.comment'),
        );
    }

    /**
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{success: bool, published: int, errors: list<string>}
     */
    public function approveAndPublish(array $selections, ?BackendUserAuthentication $backendUser = null): array
    {
        $config = $this->configurationProvider->get();
        $stageResult = $this->sendToStage(
            $selections,
            Value::int($config['publishStageId'] ?? -10),
            $backendUser,
            $this->localizationService->translate('approval.stage.comment'),
        );
        if (!$stageResult['success']) {
            return ['success' => false, 'published' => 0, 'errors' => $stageResult['errors']];
        }

        return $this->publish($selections, $backendUser);
    }

    /**
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{success: bool, published: int, errors: list<string>}
     */
    public function publish(array $selections, ?BackendUserAuthentication $backendUser = null): array
    {
        if ($selections === []) {
            return ['success' => true, 'published' => 0, 'errors' => []];
        }
        $backendUser ??= $this->accessGuard->user();
        $workspaceId = $backendUser->workspace ?? 0;
        if ($backendUser === null || $workspaceId <= 0) {
            return ['success' => false, 'published' => 0, 'errors' => [$this->localizationService->translate('error.publishFromLive')]];
        }
        if (!$backendUser->isAdmin() && $backendUser->checkWorkspace($workspaceId) === false) {
            return ['success' => false, 'published' => 0, 'errors' => [$this->localizationService->translate('error.noPublishPermission')]];
        }

        // Deduplicate and group by table; drop tables outside the
        // policy allow-list up front.
        $uidsByTable = [];
        foreach ($selections as $entry) {
            if ($entry['table'] !== '' && $entry['workspaceUid'] > 0 && $this->workspaceTablePolicy->isAllowed($entry['table'])) {
                $uidsByTable[$entry['table']][$entry['workspaceUid']] = true;
            }
        }

        $byTable = [];
        $count = 0;
        $rejected = 0;
        $denied = false;
        foreach ($uidsByTable as $table => $uidSet) {
            if (!$backendUser->check('tables_modify', $table)) {
                $denied = true;
                continue;
            }
            // One query per table: confirm workspace membership and
            // resolve live uids in the same round trip.
            $liveByWorkspaceUid = $this->mapWorkspaceUidsToLive($table, array_keys($uidSet), $workspaceId);
            $rejected += count($uidSet) - count($liveByWorkspaceUid);
            foreach ($liveByWorkspaceUid as $workspaceUid => $liveUid) {
                $byTable[$table][$liveUid] = [
                    'version' => ['action' => 'publish', 'swapWith' => $workspaceUid],
                ];
                ++$count;
            }
        }
        if ($byTable === []) {
            $msg = match (true) {
                $denied => $this->localizationService->translate('error.noTablePermission'),
                $rejected > 0 => $this->localizationService->translate('error.selectionWrongWorkspace'),
                default => $this->localizationService->translate('error.noPublishableRecords'),
            };
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
        $cmd += $byTable;

        // DataHandler is a prototype — makeInstance is the intended
        // way to obtain a fresh instance per operation.
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd, $backendUser);
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
    public function discard(string $table, int $workspaceUid, ?BackendUserAuthentication $backendUser = null): array
    {
        if (!$this->workspaceTablePolicy->isAllowed($table) || !$this->isWorkspaceAware($table) || $workspaceUid <= 0) {
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.missingTableWorkspace')]];
        }
        $backendUser ??= $this->accessGuard->user();
        $workspaceId = $backendUser->workspace ?? 0;
        if ($backendUser === null || $workspaceId <= 0 || $backendUser->checkWorkspace($workspaceId) === false) {
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]];
        }
        if (!$backendUser->check('tables_modify', $table)) {
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.noTablePermission')]];
        }

        $row = $this->fetchVersionRow($table, $workspaceUid);
        if ($row === null || $row['deleted']) {
            return ['success' => true, 'discarded' => 0, 'errors' => []];
        }
        if ($row['workspaceId'] === 0) {
            // Preview controls may send a live UID. Resolve it only in the
            // current workspace; never guess from another workspace's draft.
            $versions = $this->workspaceVersionsOfLiveRecord($table, $row['uid'], $workspaceId);
            if ($versions === []) {
                return ['success' => true, 'discarded' => 0, 'errors' => []];
            }
            if (count($versions) !== 1) {
                return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]];
            }
            $workspaceUid = $versions[0];
        } elseif ($row['workspaceId'] !== $workspaceId) {
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]];
        }

        $dataHandler = $this->processCmdMapInWorkspace(
            [$table => [$workspaceUid => ['discard' => true]]],
            $workspaceId,
            $backendUser,
        );
        $stillExists = $this->workspaceRowStillExists($table, $workspaceUid, $workspaceId);
        $errors = Value::stringList($dataHandler->errorLog);
        if ($stillExists && $errors === []) {
            $errors[] = $this->localizationService->translate('error.discardNotApplied');
        }

        return [
            'success' => !$stillExists,
            'discarded' => $stillExists ? 0 : 1,
            'errors' => $stillExists ? $errors : [],
        ];
    }

    /**
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{success: bool, changed: int, errors: list<string>}
     */
    private function sendToStage(array $selections, int $stageId, ?BackendUserAuthentication $backendUser, string $comment): array
    {
        if ($selections === []) {
            return ['success' => true, 'changed' => 0, 'errors' => []];
        }
        $backendUser ??= $this->accessGuard->user();
        $workspaceId = $backendUser->workspace ?? 0;
        if ($backendUser === null || $workspaceId <= 0) {
            return ['success' => false, 'changed' => 0, 'errors' => [$this->localizationService->translate('error.publishFromLive')]];
        }
        if (!$backendUser->isAdmin() && $backendUser->checkWorkspace($workspaceId) === false) {
            return ['success' => false, 'changed' => 0, 'errors' => [$this->localizationService->translate('error.noPublishPermission')]];
        }

        $uidsByTable = [];
        foreach ($selections as $entry) {
            if ($entry['table'] !== '' && $entry['workspaceUid'] > 0 && $this->workspaceTablePolicy->isAllowed($entry['table'])) {
                $uidsByTable[$entry['table']][$entry['workspaceUid']] = true;
            }
        }

        $cmd = [];
        $count = 0;
        $rejected = 0;
        $denied = false;
        foreach ($uidsByTable as $table => $uidSet) {
            if (!$backendUser->check('tables_modify', $table)) {
                $denied = true;
                continue;
            }
            $liveByWorkspaceUid = $this->mapWorkspaceUidsToLive($table, array_keys($uidSet), $workspaceId);
            $rejected += count($uidSet) - count($liveByWorkspaceUid);
            foreach (array_keys($liveByWorkspaceUid) as $workspaceUid) {
                $cmd[$table][$workspaceUid]['version'] = [
                    'action' => 'setStage',
                    'stageId' => $stageId,
                    'comment' => $comment,
                ];
                ++$count;
            }
        }
        if ($cmd === []) {
            $msg = match (true) {
                $denied => $this->localizationService->translate('error.noTablePermission'),
                $rejected > 0 => $this->localizationService->translate('error.selectionWrongWorkspace'),
                default => $this->localizationService->translate('error.noPublishableRecords'),
            };
            return ['success' => false, 'changed' => 0, 'errors' => [$msg]];
        }

        $dataHandler = $this->processCmdMapInWorkspace($cmd, $workspaceId, $backendUser);

        return [
            'success' => $dataHandler->errorLog === [],
            'changed' => $count,
            'errors' => Value::stringList($dataHandler->errorLog),
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmd
     */
    private function processCmdMapInWorkspace(array $cmd, int $workspaceId, BackendUserAuthentication $backendUser): DataHandler
    {
        $savedWorkspaceContext = $this->context->getAspect('workspace');
        $this->context->setAspect('workspace', new WorkspaceAspect($workspaceId));
        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $cmd, $backendUser);
            $dataHandler->process_cmdmap();
        } finally {
            $this->context->setAspect('workspace', $savedWorkspaceContext);
        }

        return $dataHandler;
    }

    private function workspaceRowStillExists(string $table, int $workspaceUid, int $workspaceId): bool
    {
        if ($workspaceId <= 0 || !$this->isWorkspaceAware($table)) {
            return false;
        }

        $row = $this->fetchVersionRow($table, $workspaceUid);

        return $row !== null && !$row['deleted'] && $row['workspaceId'] === $workspaceId;
    }

    /**
     * One query per table: confirm the rows really live in the given
     * workspace and resolve their live uids in the same round trip.
     *
     * @param list<int> $uids
     * @return array<int, int> workspaceUid => liveUid
     */
    private function mapWorkspaceUidsToLive(string $table, array $uids, int $workspaceId): array
    {
        if ($uids === [] || !$this->isWorkspaceAware($table)) {
            return [];
        }

        $deletedField = $this->softDeleteField($table);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $select = ['uid', 't3ver_oid', 't3ver_wsid'];
        if ($deletedField !== null) {
            $select[] = $deletedField;
        }
        $rows = $queryBuilder
            ->select(...$select)
            ->from($table)
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            if ($deletedField !== null && Value::int($row[$deletedField] ?? null) !== 0) {
                continue;
            }
            if (Value::int($row['t3ver_wsid'] ?? null) !== $workspaceId) {
                continue;
            }
            $workspaceUid = Value::int($row['uid'] ?? null);
            $map[$workspaceUid] = Value::int($row['t3ver_oid'] ?? null) ?: $workspaceUid;
        }

        return $map;
    }

    /**
     * Non-deleted versions of a live record in the active workspace.
     *
     * @return list<int>
     */
    private function workspaceVersionsOfLiveRecord(string $table, int $liveUid, int $workspaceId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        $deletedField = $this->softDeleteField($table);
        if ($deletedField !== null) {
            $constraints[] = $queryBuilder->expr()->eq($deletedField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }

        $uids = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(Value::int(...), $uids);
    }

    /**
     * Fetch the version metadata of a single row. Expects the table
     * to be workspace-aware (guard with isWorkspaceAware()).
     *
     * @return array{uid: int, workspaceId: int, deleted: bool}|null
     */
    private function fetchVersionRow(string $table, int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $deletedField = $this->softDeleteField($table);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $select = ['uid', 't3ver_wsid'];
        if ($deletedField !== null) {
            $select[] = $deletedField;
        }
        $row = $queryBuilder
            ->select(...$select)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        return [
            'uid' => Value::int($row['uid'] ?? null),
            'workspaceId' => Value::int($row['t3ver_wsid'] ?? null),
            'deleted' => $deletedField !== null && Value::int($row[$deletedField] ?? null) !== 0,
        ];
    }

    /**
     * TCA is the source of truth for workspace support and the
     * soft-delete column — no live DB schema introspection needed.
     */
    private function isWorkspaceAware(string $table): bool
    {
        return $this->tcaSchemaFactory->has($table) && $this->tcaSchemaFactory->get($table)->isWorkspaceAware();
    }

    private function softDeleteField(string $table): ?string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return null;
        }
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->hasCapability(TcaSchemaCapability::SoftDelete)) {
            return null;
        }

        return $schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName();
    }
}
