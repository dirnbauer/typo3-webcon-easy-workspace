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
        $workspaceId = $this->activeMutationWorkspaceId($backendUser);
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
        if ($table === '' || $workspaceUid <= 0) {
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.missingTableWorkspace')]];
        }
        $backendUser ??= $this->accessGuard->user();
        // Resolve the concrete workspace row before handing it to
        // DataHandler. The toolbar usually sends the workspace uid, but
        // frontend preview controls can report the rendered live uid.
        $activeWorkspaceId = $this->activeMutationWorkspaceId($backendUser);
        $target = $this->resolveDiscardTarget($table, $workspaceUid, $activeWorkspaceId);
        if ($target['workspaceUid'] <= 0 || $target['workspaceId'] <= 0) {
            if ($this->discardTargetIsAlreadyLive($table, $workspaceUid, $activeWorkspaceId)) {
                return ['success' => true, 'discarded' => 0, 'errors' => []];
            }
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]];
        }

        $cmd = [
            $table => [
                $target['workspaceUid'] => [
                    'discard' => true,
                ],
            ],
        ];

        $dataHandler = $this->processCmdMapInWorkspace($cmd, $target['workspaceId'], $backendUser);
        $stillExists = $this->workspaceRowStillExists($table, $target['workspaceUid'], $target['workspaceId']);
        if ($stillExists && $dataHandler->errorLog === []) {
            $dataHandler->errorLog[] = $this->localizationService->translate('error.discardNotApplied');
        }

        return [
            'success' => !$stillExists,
            'discarded' => $stillExists ? 0 : 1,
            'errors' => $stillExists ? Value::stringList($dataHandler->errorLog) : [],
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
        $workspaceId = $this->activeMutationWorkspaceId($backendUser);
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
     * @return array{workspaceUid: int, workspaceId: int}
     */
    private function resolveDiscardTarget(string $table, int $uid, int $preferredWorkspaceId): array
    {
        $empty = ['workspaceUid' => 0, 'workspaceId' => 0];
        if (!$this->isWorkspaceAware($table)) {
            return $empty;
        }

        $row = $this->fetchVersionRow($table, $uid);
        if ($row === null || $row['deleted']) {
            return $empty;
        }
        if ($row['workspaceId'] > 0) {
            return ['workspaceUid' => $row['uid'], 'workspaceId' => $row['workspaceId']];
        }

        return $this->findDiscardTargetForLiveRecord($table, $row['uid'], $preferredWorkspaceId);
    }

    private function activeMutationWorkspaceId(?BackendUserAuthentication $backendUser): int
    {
        if ($backendUser !== null) {
            $workspaceId = max(0, $backendUser->workspace);
            if ($workspaceId > 0) {
                return $workspaceId;
            }
            $userWorkspaceId = max(0, Value::int($backendUser->user['workspace_id'] ?? null));
            if ($userWorkspaceId > 0) {
                return $userWorkspaceId;
            }
        }

        return Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmd
     */
    private function processCmdMapInWorkspace(array $cmd, int $workspaceId, ?BackendUserAuthentication $backendUser): DataHandler
    {
        $savedWorkspace = null;
        $savedWorkspaceRec = null;
        if ($backendUser !== null) {
            $savedWorkspace = $backendUser->workspace;
            $savedWorkspaceRec = $backendUser->workspaceRec;
            $workspaceRecord = $backendUser->checkWorkspace($workspaceId);
            if (is_array($workspaceRecord)) {
                $backendUser->workspaceRec = $workspaceRecord;
            } elseif ($backendUser->isAdmin()) {
                $backendUser->workspaceRec = ['uid' => $workspaceId, '_ACCESS' => 'admin'];
            }
            $backendUser->workspace = $workspaceId;
        }

        $savedWorkspaceContext = $this->context->getAspect('workspace');
        $this->context->setAspect('workspace', new WorkspaceAspect($workspaceId));
        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $cmd, $backendUser);
            $dataHandler->process_cmdmap();
        } finally {
            if ($savedWorkspace !== null && $backendUser !== null) {
                $backendUser->workspace = $savedWorkspace;
                if (is_array($savedWorkspaceRec)) {
                    $backendUser->workspaceRec = $savedWorkspaceRec;
                }
            }
            $this->context->setAspect('workspace', $savedWorkspaceContext);
        }

        return $dataHandler;
    }

    private function discardTargetIsAlreadyLive(string $table, int $uid, int $preferredWorkspaceId): bool
    {
        if (!$this->isWorkspaceAware($table)) {
            return false;
        }

        $row = $this->fetchVersionRow($table, $uid);
        if ($row === null || $row['deleted']) {
            return true;
        }
        if ($row['workspaceId'] > 0) {
            return false;
        }

        if ($preferredWorkspaceId > 0 && $this->workspaceVersionsOfLiveRecord($table, $row['uid'], $preferredWorkspaceId) !== []) {
            return false;
        }

        return $this->workspaceVersionsOfLiveRecord($table, $row['uid']) === [];
    }

    /**
     * @return array{workspaceUid: int, workspaceId: int}
     */
    private function findDiscardTargetForLiveRecord(string $table, int $liveUid, int $preferredWorkspaceId): array
    {
        $empty = ['workspaceUid' => 0, 'workspaceId' => 0];
        if ($liveUid <= 0) {
            return $empty;
        }

        if ($preferredWorkspaceId > 0) {
            $versions = $this->workspaceVersionsOfLiveRecord($table, $liveUid, $preferredWorkspaceId);
            if ($versions !== []) {
                return ['workspaceUid' => $versions[0]['uid'], 'workspaceId' => $preferredWorkspaceId];
            }
        }

        // Without a workspace preference the live uid is only
        // unambiguous when exactly one version exists anywhere.
        $versions = $this->workspaceVersionsOfLiveRecord($table, $liveUid);
        if (count($versions) !== 1) {
            return $empty;
        }

        return ['workspaceUid' => $versions[0]['uid'], 'workspaceId' => $versions[0]['workspaceId']];
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
     * All non-deleted workspace versions pointing at a live record,
     * optionally limited to one workspace.
     *
     * @return list<array{uid: int, workspaceId: int}>
     */
    private function workspaceVersionsOfLiveRecord(string $table, int $liveUid, ?int $workspaceId = null): array
    {
        if ($liveUid <= 0) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)),
            $workspaceId === null
                ? $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                : $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        $deletedField = $this->softDeleteField($table);
        if ($deletedField !== null) {
            $constraints[] = $queryBuilder->expr()->eq($deletedField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }

        $rows = $queryBuilder
            ->select('uid', 't3ver_wsid')
            ->from($table)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchAllAssociative();

        $versions = [];
        foreach ($rows as $row) {
            $versionWorkspaceId = Value::int($row['t3ver_wsid'] ?? null);
            if ($versionWorkspaceId > 0) {
                $versions[] = ['uid' => Value::int($row['uid'] ?? null), 'workspaceId' => $versionWorkspaceId];
            }
        }

        return $versions;
    }

    /**
     * Fetch the version metadata of a single row. Expects the table
     * to be workspace-aware (guard with isWorkspaceAware()).
     *
     * @return array{uid: int, liveUid: int, workspaceId: int, deleted: bool}|null
     */
    private function fetchVersionRow(string $table, int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $deletedField = $this->softDeleteField($table);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $select = ['uid', 't3ver_wsid', 't3ver_oid'];
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
            'liveUid' => Value::int($row['t3ver_oid'] ?? null),
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
