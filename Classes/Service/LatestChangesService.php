<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Lists the most recently changed records *across the workspace* —
 * not scoped to the page the editor is currently on — so the toolbar
 * dropdown can show a cross-page "latest activity" feed.
 *
 * The query selects every workspace-version row of the supported
 * tables (pages, tt_content, optionally tx_news_domain_model_news, plus
 * workspace-aware inline child tables such as Content Blocks Collection rows)
 * with `t3ver_wsid = current workspace`, sorted by `tstamp DESC`,
 * then merges across tables and trims to $limit.
 *
 * Rows returned by this query are the raw workspace versions (no
 * workspaceOL applied) — PendingItemsService::buildItem() already
 * supports that shape: it detects t3ver_wsid > 0 to flag isChanged
 * and reads t3ver_oid as the live uid.
 *
 * Why not use Workspaces' own toolbar service? The core
 * Workspaces\Service\WorkspaceService produces a paged grid scoped
 * to a single table at a time and ships with a lot of grid metadata
 * we don't render. Going direct via Doctrine keeps the response
 * small and lets us reuse PendingItem unchanged.
 */
final readonly class LatestChangesService
{
    /**
     * Hard cap to keep the toolbar dropdown light. The frontend
     * lazy-loads this list only when the accordion is expanded, so
     * over-fetching here means doing useless work for editors who
     * never open it.
     */
    public const DEFAULT_LIMIT = 20;

    /**
     * Tables we consider for the latest-changes feed. tx_news is
     * skipped automatically when the extension isn't installed
     * (no TCA schema → no query).
     *
     * @var list<string>
     */
    private const TABLES = [
        'pages',
        'tt_content',
        'tx_news_domain_model_news',
        'sys_file_metadata',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private PendingItemsService $pendingItemsService,
        private RecordDiffService $recordDiffService,
        private Context $context,
    ) {}

    /**
     * @param array<string, mixed> $config Normalized ConfigurationProvider output.
     * @return array{workspaceId: int, items: list<array<string, mixed>>}
     */
    public function list(int $limit = self::DEFAULT_LIMIT, array $config = []): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        if ($workspaceId <= 0) {
            return ['workspaceId' => $workspaceId, 'items' => []];
        }

        // Collect candidate rows from every supported table. We
        // intentionally fetch a few extras per table so the
        // cross-table sort can drop older entries before we cut to
        // the final $limit.
        $perTableCap = $limit;
        $rows = [];
        foreach ($this->resolveWorkspaceTables() as $table) {
            if (!$this->tcaSchemaFactory->has($table)) {
                continue;
            }
            foreach ($this->queryWorkspaceVersions($table, $workspaceId, $perTableCap) as $row) {
                $row = Value::stringKeyArray($row);
                $rows[] = ['table' => $table, 'row' => $row, 'tstamp' => Value::int($row['tstamp'] ?? null)];
            }
        }

        // Cross-table sort by tstamp DESC. Records edited within the
        // same second are kept in insertion order (PHP's sort is
        // stable), which is fine — the resolution is at the second
        // boundary anyway.
        usort($rows, static fn(array $a, array $b): int => $b['tstamp'] <=> $a['tstamp']);
        $rows = array_slice($rows, 0, $limit);

        $items = [];
        foreach ($rows as $entry) {
            $item = $this->pendingItemsService->buildItemFromRow($entry['table'], $entry['row'], $config);
            if (!$item instanceof PendingItem) {
                continue;
            }
            $payload = $item->toArray();
            // Attach the per-record field diff so the dropdown can
            // render "what changed" inline without a follow-up
            // round-trip. The cost is one extra BackendUtility::getRecord
            // per item (live counterpart) — bounded by $limit, max 50.
            $payload['diff'] = $this->recordDiffService->diff($entry['table'], $entry['row']);
            $payload['tstamp'] = $entry['tstamp'];
            $items[] = $payload;
        }

        return ['workspaceId' => $workspaceId, 'items' => $items];
    }

    /**
     * @return list<string>
     */
    private function resolveWorkspaceTables(): array
    {
        $tables = self::TABLES;
        foreach (TcaUtility::tables() as $parentTca) {
            $ctrl = Value::stringKeyArray($parentTca['ctrl'] ?? null);
            if (empty($ctrl['versioningWS'])) {
                continue;
            }
            foreach (TcaUtility::extractInlineFieldConfigs($parentTca) as $fieldConfig) {
                $foreignTable = Value::string($fieldConfig['foreign_table'] ?? null);
                if ($foreignTable !== '' && TcaUtility::isWorkspaceAwareHiddenTable($foreignTable)) {
                    $tables[] = $foreignTable;
                }
            }
        }
        return array_values(array_unique($tables));
    }

    /**
     * Fetch workspace-version rows for one table, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function queryWorkspaceVersions(string $table, int $workspaceId, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // No restrictions — we *want* hidden/disabled workspace
        // versions in the feed (they're still pending changes). We
        // do exclude soft-deleted rows.
        $queryBuilder->getRestrictions()->removeAll();
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->hasField('t3ver_wsid')) {
            return [];
        }

        $constraints = [
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        if ($schema->hasCapability(TcaSchemaCapability::SoftDelete)) {
            $deletedField = $schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName();
            if ($deletedField !== '' && $schema->hasField($deletedField)) {
                $constraints[] = $queryBuilder->expr()->eq($deletedField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            }
        }

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints)
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit);

        $result = $queryBuilder->executeQuery();
        $rows = [];
        while ($row = $result->fetchAssociative()) {
            $rows[] = Value::stringKeyArray($row);
        }
        return $rows;
    }
}
