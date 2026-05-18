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
    ) {}

    /**
     * Table priority for the cmdmap order. Pages must be published
     * before any tt_content sitting on them, otherwise NEW workspace
     * content elements can lose their parent. News records similarly
     * need to be published before the tt_content rows that reference
     * them via tx_news_related_news.
     *
     * @var list<string>
     */
    private const TABLE_ORDER = [
        'pages',
        'tx_news_domain_model_news',
        'tt_content',
        'sys_file_metadata',
    ];

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
            if ($table === '' || $workspaceUid <= 0 || !$this->isAllowedWorkspaceTable($table)) {
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
        foreach (self::TABLE_ORDER as $orderedTable) {
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
        // Defence-in-depth: confirm the workspace version belongs to
        // the active workspace before handing it to DataHandler.
        if (!$this->belongsToWorkspace($table, $workspaceUid, $workspaceId)) {
            return ['success' => false, 'discarded' => 0, 'errors' => [$this->localizationService->translate('error.recordWrongWorkspace')]];
        }

        $cmd = [
            $table => [
                $workspaceUid => [
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

    private function isAllowedWorkspaceTable(string $table): bool
    {
        if (in_array($table, self::TABLE_ORDER, true)) {
            return true;
        }
        if ($table === 'sys_file_metadata' || $table === 'sys_file_reference') {
            $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);
            return !empty($ctrl['versioningWS']);
        }
        if (!TcaUtility::isWorkspaceAwareHiddenTable($table)) {
            return false;
        }
        foreach (TcaUtility::tables() as $parentTca) {
            $ctrl = Value::stringKeyArray($parentTca['ctrl'] ?? null);
            if (empty($ctrl['versioningWS'])) {
                continue;
            }
            foreach (TcaUtility::extractInlineFieldConfigs($parentTca) as $fieldConfig) {
                if (($fieldConfig['foreign_table'] ?? '') === $table && !empty($fieldConfig['foreign_field'])) {
                    return true;
                }
            }
        }
        return false;
    }
}
