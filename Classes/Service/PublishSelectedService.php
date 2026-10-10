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
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CompanionResolver;
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
 *
 * Translation copies TYPO3 made along with a change (its companions) go
 * with it: publishing, staging and discarding a change takes them along,
 * added here from core's list and checked again on fresh rows (see
 * CompanionResolver) — whoever calls, whatever they send. A companion that
 * cannot follow is reported as a warning, never as a failure of the change.
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
        private CompanionResolver $companionResolver,
    ) {}

    /**
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{success: bool, changed: int, warnings: list<string>, errors: list<string>}
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
     * Moves the selection to the publish stage, then publishes it. The
     * companions are worked out once, before anything is written: the stage
     * change moves the workspace's revision, and the same companions follow
     * the change through both steps.
     *
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{success: bool, published: int, companions: int, skippedCompanions: list<array{table: string, workspaceUid: int, languageUid: int}>, warnings: list<string>, errors: list<string>}
     */
    public function approveAndPublish(array $selections, ?BackendUserAuthentication $backendUser = null): array
    {
        $result = ['success' => false, 'published' => 0, 'companions' => 0, 'skippedCompanions' => [], 'warnings' => [], 'errors' => []];
        if ($selections === []) {
            return ['success' => true] + $result;
        }
        $acting = $this->publishingUser($backendUser);
        if (is_string($acting)) {
            return ['errors' => [$acting]] + $result;
        }
        [$backendUser, $workspaceId] = $acting;

        $config = $this->configurationProvider->get();
        $ownerKeys = self::keys($selections);
        $expansion = $this->companionResolver->expand($workspaceId, $selections, $backendUser);
        $stageResult = $this->stageExpanded(
            $ownerKeys,
            $expansion,
            Value::int($config['publishStageId'] ?? -10),
            $workspaceId,
            $backendUser,
            $this->localizationService->translate('approval.stage.comment'),
        );
        if (!$stageResult['success']) {
            return ['warnings' => $stageResult['warnings'], 'errors' => $stageResult['errors']] + $result;
        }

        $published = $this->publishExpanded($ownerKeys, $expansion, $workspaceId, $backendUser);
        $published['warnings'] = array_values(array_unique([...$stageResult['warnings'], ...$published['warnings']]));

        return $published;
    }

    /**
     * Publishes the selected records and the verified companions of the
     * selected changes. `published` counts the selected records that went
     * live — what the editor picked, not what was taken along (that is
     * `companions`); the change succeeded when none of them is left in the
     * workspace, and whatever DataHandler logged about the rest is a
     * warning then.
     *
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{success: bool, published: int, companions: int, skippedCompanions: list<array{table: string, workspaceUid: int, languageUid: int}>, warnings: list<string>, errors: list<string>}
     */
    public function publish(array $selections, ?BackendUserAuthentication $backendUser = null): array
    {
        $result = ['success' => false, 'published' => 0, 'companions' => 0, 'skippedCompanions' => [], 'warnings' => [], 'errors' => []];
        if ($selections === []) {
            return ['success' => true] + $result;
        }
        $acting = $this->publishingUser($backendUser);
        if (is_string($acting)) {
            return ['errors' => [$acting]] + $result;
        }
        [$backendUser, $workspaceId] = $acting;

        return $this->publishExpanded(
            self::keys($selections),
            $this->companionResolver->expand($workspaceId, $selections, $backendUser),
            $workspaceId,
            $backendUser,
        );
    }

    /**
     * @param array<string, true> $ownerKeys The records the caller selected.
     * @param array{selections: list<array{table: string, workspaceUid: int}>, added: int, skipped: list<array{table: string, workspaceUid: int, languageUid: int}>} $expansion
     * @return array{success: bool, published: int, companions: int, skippedCompanions: list<array{table: string, workspaceUid: int, languageUid: int}>, warnings: list<string>, errors: list<string>}
     */
    private function publishExpanded(array $ownerKeys, array $expansion, int $workspaceId, BackendUserAuthentication $backendUser): array
    {
        $result = ['success' => false, 'published' => 0, 'companions' => 0, 'skippedCompanions' => [], 'warnings' => [], 'errors' => []];
        $mapped = $this->mapSelections($expansion['selections'], $workspaceId, $backendUser);
        if ($mapped['byTable'] === []) {
            return ['errors' => [$mapped['error']]] + $result;
        }

        // Build the cmdmap in priority order: parents first, children
        // second, then anything else in stable order.
        $byTable = [];
        foreach ($mapped['byTable'] as $table => $liveByWorkspaceUid) {
            foreach ($liveByWorkspaceUid as $workspaceUid => $liveUid) {
                $byTable[$table][$liveUid] = [
                    'version' => ['action' => 'publish', 'swapWith' => $workspaceUid],
                ];
            }
        }
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

        $owners = self::ownersIn($mapped['byTable'], $ownerKeys);
        $remaining = $this->stillInWorkspace($owners, $workspaceId);
        $log = Value::stringList($dataHandler->errorLog);
        $success = $remaining === [];

        return [
            'success' => $success,
            'published' => count($owners),
            'companions' => $expansion['added'],
            'skippedCompanions' => $expansion['skipped'],
            'warnings' => $success ? $log : [],
            'errors' => $success ? [] : ($log ?: [$this->localizationService->translate('error.unknown')]),
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
     * The change's companions are discarded in a second run once the change
     * itself is gone: core removes the translations of a moved, deleted or
     * new record together with it, but leaves the copies of an edited one
     * behind. They are looked up before anything is written — afterwards
     * the change is gone from core's list and its copies look like orphans
     * — and checked again on fresh rows, so the second run never discards a
     * translation someone edited.
     *
     * Core's own discard is left exactly as it is: it removes every
     * translation that points at the discarded version, a translator's edit
     * of a moved or deleted element's translation included, as the
     * Workspaces module does. The list names those translations on the
     * change before the click (its dependents, see CompanionClassifier), and
     * the confirmation says what goes.
     *
     * `companionsDiscarded` counts the companions gone afterwards, whichever
     * run removed them; `dependentsDiscarded` the change's dependents gone
     * afterwards.
     *
     * @return array{success: bool, discarded: int, companionsDiscarded: int, dependentsDiscarded: int, warnings: list<string>, errors: list<string>}
     */
    public function discard(string $table, int $workspaceUid, ?BackendUserAuthentication $backendUser = null): array
    {
        $result = ['success' => false, 'discarded' => 0, 'companionsDiscarded' => 0, 'dependentsDiscarded' => 0, 'warnings' => [], 'errors' => []];
        if (!$this->workspaceTablePolicy->isAllowed($table) || !$this->isWorkspaceAware($table) || $workspaceUid <= 0) {
            return ['errors' => [$this->localizationService->translate('error.missingTableWorkspace')]] + $result;
        }
        $backendUser ??= $this->accessGuard->user();
        $workspaceId = $backendUser->workspace ?? 0;
        if ($backendUser === null || $workspaceId <= 0 || $backendUser->checkWorkspace($workspaceId) === false) {
            return ['errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]] + $result;
        }
        if (!$backendUser->check('tables_modify', $table)) {
            return ['errors' => [$this->localizationService->translate('error.noTablePermission')]] + $result;
        }

        $row = $this->fetchVersionRow($table, $workspaceUid);
        if ($row === null || $row['deleted']) {
            return ['success' => true] + $result;
        }
        if ($row['workspaceId'] === 0) {
            // Preview controls may send a live UID. Resolve it only in the
            // current workspace; never guess from another workspace's draft.
            $versions = $this->workspaceVersionsOfLiveRecord($table, $row['uid'], $workspaceId);
            if ($versions === []) {
                return ['success' => true] + $result;
            }
            if (count($versions) !== 1) {
                return ['errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]] + $result;
            }
            $workspaceUid = $versions[0];
        } elseif ($row['workspaceId'] !== $workspaceId) {
            return ['errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]] + $result;
        }

        $owner = $this->companionResolver->ownerEntry($workspaceId, $table, $workspaceUid, $backendUser);
        $companions = $owner !== null ? $this->companionResolver->verifiedCompanions($workspaceId, $owner)['verified'] : [];
        $dependents = array_map(
            static fn(array $dependent): array => ['table' => $dependent['table'], 'workspaceUid' => $dependent['workspaceUid']],
            $owner->dependents ?? [],
        );

        $dataHandler = $this->processCmdMapInWorkspace(
            [$table => [$workspaceUid => ['discard' => true]]],
            $workspaceId,
            $backendUser,
        );
        $stillExists = $this->stillInWorkspace([['table' => $table, 'workspaceUid' => $workspaceUid]], $workspaceId) !== [];
        $errors = Value::stringList($dataHandler->errorLog);
        if ($stillExists) {
            return [
                'errors' => $errors !== [] ? $errors : [$this->localizationService->translate('error.discardNotApplied')],
            ] + $result;
        }

        $warnings = [];
        // Core may have removed some with the change already.
        $companionRecords = self::records($companions);
        $targets = $this->stillInWorkspace($companionRecords, $workspaceId);
        if ($targets !== []) {
            $cmd = [];
            foreach ($targets as $target) {
                $cmd[$target['table']][$target['workspaceUid']] = ['discard' => true];
            }
            $warnings = Value::stringList($this->processCmdMapInWorkspace($cmd, $workspaceId, $backendUser)->errorLog);
        }

        return [
            'success' => true,
            'discarded' => 1,
            'companionsDiscarded' => count($companionRecords) - count($this->stillInWorkspace($companionRecords, $workspaceId)),
            'dependentsDiscarded' => count($dependents) - count($this->stillInWorkspace($dependents, $workspaceId)),
            'warnings' => $warnings,
            'errors' => [],
        ];
    }

    /**
     * Discards copies — entries that change nothing, with their companions
     * — after checking on fresh rows that every node of each is still
     * identical to live. Discarding them changes nothing on the website;
     * nothing is ever published here.
     *
     * `discarded` and `skipped` count top records (an entry and each of its
     * companions): the entries edited meanwhile, with dependents (core's
     * discard would take other translations along), or of a table the
     * editor may not modify, are skipped.
     *
     * @param list<WorkspaceChange> $copyEntries
     * @return array{success: bool, discarded: int, skipped: int, errors: list<string>}
     */
    public function discardCopies(array $copyEntries, ?BackendUserAuthentication $backendUser = null): array
    {
        $result = ['success' => false, 'discarded' => 0, 'skipped' => 0, 'errors' => []];
        $backendUser ??= $this->accessGuard->user();
        $workspaceId = $backendUser->workspace ?? 0;
        if ($backendUser === null || $workspaceId <= 0 || $backendUser->checkWorkspace($workspaceId) === false) {
            return ['errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]] + $result;
        }
        if ($copyEntries === []) {
            return ['success' => true] + $result;
        }

        ['discardable' => $discardable, 'skipped' => $notIdentical] = $this->companionResolver->verifiedCopies($workspaceId, $copyEntries);
        $skipped = array_sum(array_map(static fn(WorkspaceChange $entry): int => 1 + count($entry->companions), $notIdentical));
        $cmd = [];
        $targets = [];
        foreach ($discardable as $entry) {
            $tops = [$entry, ...$entry->companions];
            $allowed = array_all(
                $tops,
                fn(WorkspaceChange $top): bool => $this->workspaceTablePolicy->isAllowed($top->table)
                    && $this->isWorkspaceAware($top->table)
                    && $backendUser->check('tables_modify', $top->table),
            );
            if (!$allowed) {
                $skipped += count($tops);
                continue;
            }
            foreach ($tops as $top) {
                $cmd[$top->table][$top->workspaceUid] = ['discard' => true];
                $targets[] = $top;
            }
        }
        if ($cmd === []) {
            return ['success' => true, 'skipped' => $skipped] + $result;
        }

        $dataHandler = $this->processCmdMapInWorkspace($cmd, $workspaceId, $backendUser);
        $remaining = $this->stillInWorkspace(self::records($targets), $workspaceId);
        $errors = Value::stringList($dataHandler->errorLog);

        return [
            'success' => $remaining === [],
            'discarded' => count($targets) - count($remaining),
            'skipped' => $skipped,
            'errors' => $remaining === [] ? [] : ($errors ?: [$this->localizationService->translate('error.discardNotApplied')]),
        ];
    }

    /**
     * Moves the selected records — and the verified companions of the
     * selected changes, so they keep their change's stage — to a stage.
     *
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{success: bool, changed: int, warnings: list<string>, errors: list<string>}
     */
    private function sendToStage(array $selections, int $stageId, ?BackendUserAuthentication $backendUser, string $comment): array
    {
        $result = ['success' => false, 'changed' => 0, 'warnings' => [], 'errors' => []];
        if ($selections === []) {
            return ['success' => true] + $result;
        }
        $acting = $this->publishingUser($backendUser);
        if (is_string($acting)) {
            return ['errors' => [$acting]] + $result;
        }
        [$backendUser, $workspaceId] = $acting;

        return $this->stageExpanded(
            self::keys($selections),
            $this->companionResolver->expand($workspaceId, $selections, $backendUser),
            $stageId,
            $workspaceId,
            $backendUser,
            $comment,
        );
    }

    /**
     * It succeeded when every selected record is in that stage; whatever
     * DataHandler logged about the rest is a warning then.
     *
     * @param array<string, true> $ownerKeys The records the caller selected.
     * @param array{selections: list<array{table: string, workspaceUid: int}>, added: int, skipped: list<array{table: string, workspaceUid: int, languageUid: int}>} $expansion
     * @return array{success: bool, changed: int, warnings: list<string>, errors: list<string>}
     */
    private function stageExpanded(array $ownerKeys, array $expansion, int $stageId, int $workspaceId, BackendUserAuthentication $backendUser, string $comment): array
    {
        $result = ['success' => false, 'changed' => 0, 'warnings' => [], 'errors' => []];
        $mapped = $this->mapSelections($expansion['selections'], $workspaceId, $backendUser);
        if ($mapped['byTable'] === []) {
            return ['errors' => [$mapped['error']]] + $result;
        }

        $cmd = [];
        foreach ($mapped['byTable'] as $table => $liveByWorkspaceUid) {
            foreach (array_keys($liveByWorkspaceUid) as $workspaceUid) {
                $cmd[$table][$workspaceUid]['version'] = [
                    'action' => 'setStage',
                    'stageId' => $stageId,
                    'comment' => $comment,
                ];
            }
        }

        $dataHandler = $this->processCmdMapInWorkspace($cmd, $workspaceId, $backendUser);
        $owners = self::ownersIn($mapped['byTable'], $ownerKeys);
        $stages = [];
        foreach (self::byTable($owners) as $table => $uids) {
            foreach ($this->stagesOf($table, $uids) as $uid => $stage) {
                $stages[$table . ':' . $uid] = $stage;
            }
        }
        $success = array_all(
            $owners,
            static fn(array $owner): bool => ($stages[$owner['table'] . ':' . $owner['workspaceUid']] ?? null) === $stageId,
        );
        $log = Value::stringList($dataHandler->errorLog);

        return [
            'success' => $success,
            'changed' => count($owners),
            'warnings' => $success ? $log : [],
            'errors' => $success ? [] : ($log ?: [$this->localizationService->translate('error.unknown')]),
        ];
    }

    /**
     * The user a publish or a stage change acts as, with their workspace;
     * the message to show when they cannot.
     *
     * @return array{BackendUserAuthentication, int}|string
     */
    private function publishingUser(?BackendUserAuthentication $backendUser): array|string
    {
        $backendUser ??= $this->accessGuard->user();
        $workspaceId = $backendUser->workspace ?? 0;
        if ($backendUser === null || $workspaceId <= 0) {
            return $this->localizationService->translate('error.publishFromLive');
        }
        if (!$backendUser->isAdmin() && $backendUser->checkWorkspace($workspaceId) === false) {
            return $this->localizationService->translate('error.noPublishPermission');
        }

        return [$backendUser, $workspaceId];
    }

    /**
     * The selections grouped by table and resolved to live uids — tables
     * outside the policy, tables the editor may not modify and rows of
     * another workspace left out — with the message to show when nothing
     * is left.
     *
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{byTable: array<string, array<int, int>>, error: string}
     */
    private function mapSelections(array $selections, int $workspaceId, BackendUserAuthentication $backendUser): array
    {
        // Deduplicate and group by table; drop tables outside the
        // policy allow-list up front.
        $uidsByTable = [];
        foreach ($selections as $entry) {
            if ($entry['table'] !== '' && $entry['workspaceUid'] > 0 && $this->workspaceTablePolicy->isAllowed($entry['table'])) {
                $uidsByTable[$entry['table']][$entry['workspaceUid']] = true;
            }
        }

        $byTable = [];
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
            if ($liveByWorkspaceUid !== []) {
                $byTable[$table] = $liveByWorkspaceUid;
            }
        }

        return [
            'byTable' => $byTable,
            'error' => match (true) {
                $denied => $this->localizationService->translate('error.noTablePermission'),
                $rejected > 0 => $this->localizationService->translate('error.selectionWrongWorkspace'),
                default => $this->localizationService->translate('error.noPublishableRecords'),
            },
        ];
    }

    /**
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array<string, true> table:workspaceUid
     */
    private static function keys(array $selections): array
    {
        $keys = [];
        foreach ($selections as $selection) {
            $keys[$selection['table'] . ':' . $selection['workspaceUid']] = true;
        }

        return $keys;
    }

    /**
     * The selected records among the ones that reached the cmdmap.
     *
     * @param array<string, array<int, int>> $byTable
     * @param array<string, true> $ownerKeys
     * @return list<array{table: string, workspaceUid: int}>
     */
    private static function ownersIn(array $byTable, array $ownerKeys): array
    {
        $owners = [];
        foreach ($byTable as $table => $liveByWorkspaceUid) {
            foreach (array_keys($liveByWorkspaceUid) as $workspaceUid) {
                if (isset($ownerKeys[$table . ':' . $workspaceUid])) {
                    $owners[] = ['table' => $table, 'workspaceUid' => $workspaceUid];
                }
            }
        }

        return $owners;
    }

    /**
     * @param list<int> $uids
     * @return array<int, int> uid => t3ver_stage
     */
    private function stagesOf(string $table, array $uids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 't3ver_stage')
            ->from($table)
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();
        $stages = [];
        foreach ($rows as $row) {
            $stages[Value::int($row['uid'] ?? null)] = Value::int($row['t3ver_stage'] ?? null);
        }

        return $stages;
    }

    /**
     * The records whose version is still in the workspace (not deleted, not
     * published): one query per table.
     *
     * @param list<array{table: string, workspaceUid: int}> $records
     * @return list<array{table: string, workspaceUid: int}>
     */
    private function stillInWorkspace(array $records, int $workspaceId): array
    {
        $remaining = [];
        foreach (self::byTable($records) as $table => $uids) {
            $present = $workspaceId > 0 ? $this->mapWorkspaceUidsToLive($table, $uids, $workspaceId) : [];
            foreach ($records as $record) {
                if ($record['table'] === $table && isset($present[$record['workspaceUid']])) {
                    $remaining[] = $record;
                }
            }
        }

        return $remaining;
    }

    /**
     * @param list<array{table: string, workspaceUid: int}> $records
     * @return array<string, list<int>>
     */
    private static function byTable(array $records): array
    {
        $byTable = [];
        foreach ($records as $record) {
            $byTable[$record['table']][$record['workspaceUid']] = $record['workspaceUid'];
        }

        return array_map(array_values(...), $byTable);
    }

    /**
     * @param list<WorkspaceChange> $changes
     * @return list<array{table: string, workspaceUid: int}>
     */
    private static function records(array $changes): array
    {
        return array_map(
            static fn(WorkspaceChange $change): array => ['table' => $change->table, 'workspaceUid' => $change->workspaceUid],
            $changes,
        );
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
