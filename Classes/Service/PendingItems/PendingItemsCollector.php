<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItemsPayload;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The toolbar's and the module's items for a page or a news article.
 *
 * What is changed comes from core (CoreWorkspaceChanges): the Workspaces
 * module's rows for the page, each with the records nested below it. This
 * class only turns them into items. In "all" mode the page's unchanged
 * records are listed around them, in the page's order.
 */
final readonly class PendingItemsCollector
{
    private const string NEWS_TABLE = 'tx_news_domain_model_news';

    public function __construct(
        private Context $context,
        private TcaSchemaFactory $tcaSchemaFactory,
        private CoreWorkspaceChanges $coreWorkspaceChanges,
        private WorkspaceRecordQuery $workspaceRecordQuery,
        private PendingItemFactory $pendingItemFactory,
        private PendingItemAggregator $pendingItemAggregator,
    ) {}

    /**
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, pageUid: int, languageUid: int|null, hasChanges: bool}
     */
    public function hasChangesForPage(int $pageUid, array $config = [], ?int $languageUid = null): array
    {
        $workspaceId = $this->workspaceId();

        return [
            'workspaceId' => $workspaceId,
            'pageUid' => $pageUid,
            'languageUid' => $languageUid,
            'hasChanges' => $workspaceId > 0 && $pageUid > 0
                && $this->coreWorkspaceChanges->entries($workspaceId, $pageUid, $languageUid) !== [],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, newsUid: int, languageUid: int|null, hasChanges: bool}
     */
    public function hasChangesForNews(int $newsUid, array $config = [], ?int $languageUid = null): array
    {
        $workspaceId = $this->workspaceId();

        return [
            'workspaceId' => $workspaceId,
            'newsUid' => $newsUid,
            'languageUid' => $languageUid,
            'hasChanges' => $workspaceId > 0 && $newsUid > 0 && $this->tcaSchemaFactory->has(self::NEWS_TABLE)
                && $this->newsEntries($newsUid, $workspaceId, $languageUid) !== [],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function forPage(
        int $pageUid,
        int $workspaceId,
        string $workspaceTitle,
        PendingItemsMode $mode,
        array $config,
        ?int $languageUid,
        bool $hasNews,
    ): PendingItemsPayload {
        $maxItems = Value::int($config['maxItems'] ?? 200);
        // Column titles only label and group rows; a count does not need
        // the backend layout.
        $columnLabels = PendingItemFactory::isCountOnly($config) ? [] : $this->pendingItemFactory->resolveColumnLabels($pageUid);
        $items = $this->changedItems(
            $this->coreWorkspaceChanges->entries($workspaceId, $pageUid, $languageUid),
            $config,
            $columnLabels,
            $maxItems,
        );

        if ($mode->includesUnchanged()) {
            $rows = [];
            $pageRecordUid = $this->workspaceRecordQuery->resolvePageRecordUidForLanguage($pageUid, $workspaceId, $languageUid);
            $pageRow = $pageRecordUid > 0 ? $this->workspaceRecordQuery->resolveRecordRow('pages', $pageRecordUid, $workspaceId) : null;
            if ($pageRow !== null) {
                $rows[] = ['table' => 'pages', 'row' => $pageRow];
            }
            foreach ($this->workspaceRecordQuery->listAllRecordsOnPage('tt_content', $pageUid, $workspaceId, [['colPos', 'ASC'], ['sorting', 'ASC']], $languageUid) as $row) {
                $rows[] = ['table' => 'tt_content', 'row' => $row];
            }
            $items = $this->inPageOrder($items, $rows, $config, $columnLabels, $maxItems);
        }

        return $this->payload($items, $workspaceId, $workspaceTitle, $mode, $languageUid, pageUid: $pageUid, hasNews: $hasNews);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function forNews(
        int $newsUid,
        int $workspaceId,
        string $workspaceTitle,
        PendingItemsMode $mode,
        array $config,
        ?int $languageUid,
    ): PendingItemsPayload {
        $maxItems = Value::int($config['maxItems'] ?? 200);
        // A news article's content elements are addressed through
        // tx_news_related_news, not through a backend layout column: their
        // colPos is a leftover, so neither a "Column 0" meta line nor a
        // column group belongs on them.
        $items = $this->changedItems($this->newsEntries($newsUid, $workspaceId, $languageUid), $config, null, $maxItems);

        if ($mode->includesUnchanged()) {
            $rows = [];
            $newsRecordUid = $this->workspaceRecordQuery->resolveRecordUidForLanguage(self::NEWS_TABLE, $newsUid, $workspaceId, $languageUid);
            $newsRow = $newsRecordUid > 0 ? $this->workspaceRecordQuery->resolveRecordRow(self::NEWS_TABLE, $newsRecordUid, $workspaceId) : null;
            if ($newsRow !== null) {
                $rows[] = ['table' => self::NEWS_TABLE, 'row' => $newsRow];
            }
            foreach ($this->workspaceRecordQuery->listAllRelatedRecords('tt_content', 'tx_news_related_news', $newsUid, $workspaceId, [['sorting', 'ASC']], $languageUid) as $row) {
                $rows[] = ['table' => 'tt_content', 'row' => $row];
            }
            $items = $this->inPageOrder($items, $rows, $config, null, $maxItems);
        }

        return $this->payload($items, $workspaceId, $workspaceTitle, $mode, $languageUid, newsUid: $newsUid);
    }

    /**
     * Core's rows of the article's storage folder that belong to the
     * article: its own version and its content elements' (with whatever
     * is nested below them).
     *
     * @return list<WorkspaceChange>
     */
    private function newsEntries(int $newsUid, int $workspaceId, ?int $languageUid): array
    {
        $news = BackendUtility::getRecord(self::NEWS_TABLE, $newsUid, 'pid');
        if ($workspaceId <= 0 || !is_array($news)) {
            return [];
        }

        return array_values(array_filter(
            $this->coreWorkspaceChanges->entries($workspaceId, Value::int($news['pid'] ?? null), $languageUid),
            fn(WorkspaceChange $entry): bool => $this->belongsToNews($entry, $newsUid),
        ));
    }

    private function belongsToNews(WorkspaceChange $entry, int $newsUid): bool
    {
        if ($entry->table === self::NEWS_TABLE) {
            $row = BackendUtility::getRecord(self::NEWS_TABLE, $entry->workspaceUid);
            return $entry->liveUid === $newsUid
                || (is_array($row) && Value::int($row['l10n_parent'] ?? null) === $newsUid);
        }
        if ($entry->table === 'tt_content') {
            $row = BackendUtility::getRecord('tt_content', $entry->workspaceUid, 'tx_news_related_news');
            return is_array($row) && Value::int($row['tx_news_related_news'] ?? null) === $newsUid;
        }

        return false;
    }

    /**
     * One item per core row, its nested versions folded into it; the page
     * (or article) first, then content elements by column and position,
     * then everything else in core's order.
     *
     * @param list<WorkspaceChange> $entries
     * @param array<string, mixed> $config
     * @param array<int, string>|null $columnLabels Null outside a backend layout.
     * @return list<PendingItem>
     */
    private function changedItems(array $entries, array $config, ?array $columnLabels, int $maxItems): array
    {
        $built = [];
        foreach ($entries as $position => $entry) {
            $row = BackendUtility::getRecord($entry->table, $entry->workspaceUid);
            if (!is_array($row)) {
                continue;
            }
            $row = Value::stringKeyArray($row);
            $item = $this->pendingItemFactory->buildItem(
                $entry->table,
                $row,
                isPrimary: $entry->table === 'pages' || $entry->table === self::NEWS_TABLE,
                config: $config,
                columnLabels: $entry->table === 'tt_content' ? $columnLabels : [],
            );
            if ($item === null) {
                continue;
            }
            $item = $this->pendingItemAggregator->withRelatedChanges($item, $this->childItems($entry, $config, $columnLabels));
            $built[] = [
                'item' => $item,
                'order' => [
                    match ($entry->table) {
                        'pages', self::NEWS_TABLE => 0,
                        'tt_content' => 1,
                        default => 2,
                    },
                    $entry->table === 'tt_content' ? Value::int($row['colPos'] ?? null) : 0,
                    $entry->table === 'tt_content' ? Value::int($row['sorting'] ?? null) : 0,
                    $position,
                ],
            ];
        }
        usort($built, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_slice(array_column($built, 'item'), 0, $maxItems);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string>|null $columnLabels
     * @return list<PendingItem>
     */
    private function childItems(WorkspaceChange $entry, array $config, ?array $columnLabels): array
    {
        $items = [];
        foreach ($entry->children as $child) {
            $row = BackendUtility::getRecord($child->table, $child->workspaceUid);
            if (!is_array($row)) {
                continue;
            }
            $item = $this->pendingItemFactory->buildItem(
                $child->table,
                Value::stringKeyArray($row),
                isPrimary: false,
                config: $config,
                columnLabels: $columnLabels ?? [],
                locateTable: $entry->table === 'tt_content' ? 'tt_content' : null,
                locateLiveUid: $entry->liveUid,
                locateWorkspaceUid: $entry->workspaceUid,
            );
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The scope's records in their own order, each replaced by its changed
     * item when core lists one; changed items outside that order (nested
     * tables, file metadata) follow.
     *
     * @param list<PendingItem> $changedItems
     * @param list<array{table: string, row: array<string, mixed>}> $rows
     * @param array<string, mixed> $config
     * @param array<int, string>|null $columnLabels
     * @return list<PendingItem>
     */
    private function inPageOrder(array $changedItems, array $rows, array $config, ?array $columnLabels, int $maxItems): array
    {
        $items = [];
        foreach ($rows as ['table' => $table, 'row' => $row]) {
            $uid = Value::int($row['_ORIG_uid'] ?? $row['uid'] ?? null);
            $liveUid = Value::int($row['t3ver_oid'] ?? null) ?: Value::int($row['uid'] ?? null);
            $index = $this->pendingItemAggregator->findItemIndexByRecordIdentity($changedItems, $table, $liveUid)
                ?? $this->pendingItemAggregator->findItemIndexByRecordIdentity($changedItems, $table, $uid);
            if ($index !== null) {
                $items[] = $changedItems[$index];
                unset($changedItems[$index]);
            } else {
                $item = $this->pendingItemFactory->buildItem(
                    $table,
                    $row,
                    isPrimary: $table === 'pages' || $table === self::NEWS_TABLE,
                    config: $config,
                    columnLabels: $table === 'tt_content' ? $columnLabels : [],
                );
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            if (count($items) >= $maxItems) {
                return $items;
            }
        }

        return array_slice([...$items, ...array_values($changedItems)], 0, $maxItems);
    }

    /**
     * @param list<PendingItem> $items
     */
    private function payload(
        array $items,
        int $workspaceId,
        string $workspaceTitle,
        PendingItemsMode $mode,
        ?int $languageUid,
        ?int $pageUid = null,
        ?int $newsUid = null,
        bool $hasNews = false,
    ): PendingItemsPayload {
        $items = $this->pendingItemAggregator->deduplicateItems($items);
        if (!$mode->includesUnchanged()) {
            $items = $this->pendingItemAggregator->changedItems($items);
        }
        $changedItems = $this->pendingItemAggregator->changedItems($items);

        return new PendingItemsPayload(
            workspaceId: $workspaceId,
            workspaceTitle: $workspaceTitle,
            mode: $mode,
            items: $items,
            itemGroups: $this->pendingItemAggregator->groupItems($items),
            changedItemGroups: $this->pendingItemAggregator->groupItems($changedItems),
            pageUid: $pageUid,
            newsUid: $newsUid,
            languageUid: $languageUid,
            hasNews: $hasNews,
        );
    }

    private function workspaceId(): int
    {
        return Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
    }
}
