<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        if ($workspaceId <= 0) {
            return ['success' => false, 'published' => 0, 'errors' => ['Cannot publish from the live workspace.']];
        }

        // Group selections by table so we can insert them in priority order.
        $byTable = [];
        $count = 0;
        foreach ($selections as $entry) {
            $table = $entry['table'] ?? '';
            $workspaceUid = (int)($entry['workspaceUid'] ?? 0);
            if ($table === '' || $workspaceUid <= 0) {
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
            return ['success' => false, 'published' => 0, 'errors' => ['No publishable records in selection.']];
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
            'errors' => $dataHandler->errorLog,
        ];
    }

    /**
     * Discard (revert) a single workspace version. Removes the
     * offline record so the live row remains the only version.
     *
     * Uses DataHandler with the standard v14 cmdmap form
     *   $cmd[$table][$workspaceUid]['version'] = ['action' => 'flush'];
     * which is the supported way to clear a workspace version.
     *
     * @return array{success: bool, discarded: int, errors: list<string>}
     */
    public function discard(string $table, int $workspaceUid): array
    {
        if ($table === '' || $workspaceUid <= 0) {
            return ['success' => false, 'discarded' => 0, 'errors' => ['Missing table / workspaceUid.']];
        }
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        if ($workspaceId <= 0) {
            return ['success' => false, 'discarded' => 0, 'errors' => ['Cannot discard from the live workspace.']];
        }

        $cmd = [
            $table => [
                $workspaceUid => [
                    'version' => ['action' => 'flush'],
                ],
            ],
        ];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        return [
            'success' => $dataHandler->errorLog === [],
            'discarded' => 1,
            'errors' => $dataHandler->errorLog,
        ];
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
        $liveUid = (int)$row['t3ver_oid'];
        return $liveUid > 0 ? $liveUid : (int)$row['uid'];
    }
}
