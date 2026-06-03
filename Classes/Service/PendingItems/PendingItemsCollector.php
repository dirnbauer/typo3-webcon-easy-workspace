<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItemsPayload;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Collects pending workspace items for page or news scope and probes
 * whether a scope has publishable changes without building full rows.
 */
final readonly class PendingItemsCollector
{
    public function __construct(
        private Context $context,
        private TcaSchemaFactory $tcaSchemaFactory,
        private WorkspaceRecordQuery $workspaceRecordQuery,
        private PendingItemFactory $pendingItemFactory,
        private InlineChildResolver $inlineChildResolver,
        private PendingItemAggregator $pendingItemAggregator,
    ) {}

    /**
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, pageUid: int, languageUid: int|null, hasChanges: bool}
     */
    public function hasChangesForPage(int $pageUid, array $config = [], ?int $languageUid = null): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        if ($workspaceId <= 0 || $pageUid <= 0) {
            return ['workspaceId' => $workspaceId, 'pageUid' => $pageUid, 'languageUid' => $languageUid, 'hasChanges' => false];
        }

        return [
            'workspaceId' => $workspaceId,
            'pageUid' => $pageUid,
            'languageUid' => $languageUid,
            'hasChanges' => $this->probePageChanges($this->resolvePageScope($pageUid, $workspaceId, $languageUid), $pageUid, $workspaceId, $languageUid),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, newsUid: int, languageUid: int|null, hasChanges: bool}
     */
    public function hasChangesForNews(int $newsUid, array $config = [], ?int $languageUid = null): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        if ($workspaceId <= 0 || $newsUid <= 0 || !$this->tcaSchemaFactory->has('tx_news_domain_model_news')) {
            return ['workspaceId' => $workspaceId, 'newsUid' => $newsUid, 'languageUid' => $languageUid, 'hasChanges' => false];
        }

        return [
            'workspaceId' => $workspaceId,
            'newsUid' => $newsUid,
            'languageUid' => $languageUid,
            'hasChanges' => $this->probeNewsChanges($this->resolveNewsScope($newsUid, $workspaceId, $languageUid), $newsUid, $workspaceId, $languageUid),
        ];
    }

    private function resolvePageScope(int $pageUid, int $workspaceId, ?int $languageUid): PageCollectionScope
    {
        $pageRecordUid = $this->workspaceRecordQuery->resolvePageRecordUidForLanguage($pageUid, $workspaceId, $languageUid);
        $pageRow = $pageRecordUid > 0
            ? $this->workspaceRecordQuery->resolveRecordRow('pages', $pageRecordUid, $workspaceId)
            : null;

        return new PageCollectionScope(
            pageRecordUid: $pageRecordUid,
            pageRow: $pageRow,
            contentRows: $this->workspaceRecordQuery->listAllRecordsOnPage(
                'tt_content',
                $pageUid,
                $workspaceId,
                [['colPos', 'ASC'], ['sorting', 'ASC']],
                $languageUid,
            ),
        );
    }

    private function resolveNewsScope(int $newsUid, int $workspaceId, ?int $languageUid): NewsCollectionScope
    {
        return new NewsCollectionScope(
            newsRecordUid: $this->workspaceRecordQuery->resolveRecordUidForLanguage('tx_news_domain_model_news', $newsUid, $workspaceId, $languageUid),
            relatedContentRows: $this->workspaceRecordQuery->listAllRelatedRecords(
                'tt_content',
                'tx_news_related_news',
                $newsUid,
                $workspaceId,
                [['sorting', 'ASC']],
                $languageUid,
            ),
        );
    }

    private function probePageChanges(PageCollectionScope $scope, int $pageUid, int $workspaceId, ?int $languageUid): bool
    {
        if (
            ($scope->pageRecordUid > 0 && $this->workspaceRecordQuery->hasWorkspaceVersionForRecord('pages', $scope->pageRecordUid, $workspaceId, $languageUid))
            || $this->workspaceRecordQuery->hasChangedRowsRelated('tt_content', 'pid', $pageUid, $workspaceId, $languageUid)
        ) {
            return true;
        }

        if ($scope->pageRow !== null && $this->inlineChildResolver->hasInlineChildChangesForRows('pages', [$scope->pageRow], $workspaceId, $languageUid)) {
            return true;
        }

        if ($this->inlineChildResolver->hasChangedInlineChildrenOnPage('tt_content', $pageUid, $workspaceId, $languageUid)) {
            return true;
        }

        if ($this->inlineChildResolver->hasInlineChildChangesForRows('tt_content', $scope->contentRows, $workspaceId, $languageUid)) {
            return true;
        }

        return $this->workspaceRecordQuery->hasStandaloneWorkspaceChanges($workspaceId);
    }

    private function probeNewsChanges(NewsCollectionScope $scope, int $newsUid, int $workspaceId, ?int $languageUid): bool
    {
        if (
            ($scope->newsRecordUid > 0 && $this->workspaceRecordQuery->hasWorkspaceVersionForRecord('tx_news_domain_model_news', $scope->newsRecordUid, $workspaceId, $languageUid))
            || $this->workspaceRecordQuery->hasChangedRowsRelated('tt_content', 'tx_news_related_news', $newsUid, $workspaceId, $languageUid)
        ) {
            return true;
        }

        if ($this->inlineChildResolver->hasInlineChildChangesForRows('tt_content', $scope->relatedContentRows, $workspaceId, $languageUid)) {
            return true;
        }

        return $this->workspaceRecordQuery->hasStandaloneWorkspaceChanges($workspaceId);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function forPage(
        int $pageUid,
        int $workspaceId,
        string $workspaceTitle,
        string $mode,
        array $config,
        ?int $languageUid,
        bool $hasNews,
    ): PendingItemsPayload {
        $scope = $this->resolvePageScope($pageUid, $workspaceId, $languageUid);
        $maxItems = Value::int($config['maxItems'] ?? 200);
        $items = [];
        $columnLabels = $this->pendingItemFactory->resolveColumnLabels($pageUid);

        if ($scope->pageRow !== null) {
            $pageItem = $this->pendingItemFactory->buildItem('pages', $scope->pageRow, isPrimary: true, config: $config);
            if ($pageItem !== null) {
                $pageItem = $this->pendingItemAggregator->withRelatedChanges(
                    $pageItem,
                    $this->inlineChildResolver->resolveInlineChildItems('pages', $scope->pageRow, $workspaceId, $mode, $config, languageUid: $languageUid),
                );
                if ($this->includeItem($pageItem, $mode)) {
                    $items[] = $pageItem;
                }
            }
        }

        $items = $this->collectContentRows(
            $items,
            $scope->contentRows,
            $workspaceId,
            $mode,
            $config,
            $columnLabels,
            $languageUid,
            $maxItems,
        );

        if (count($items) < $maxItems) {
            $items = $this->pendingItemAggregator->withInlineChildParents(
                $items,
                'tt_content',
                $pageUid,
                $workspaceId,
                $mode,
                $config,
                $columnLabels,
                $languageUid,
                $maxItems,
            );
        }

        return $this->finalizePayload(
            $items,
            $workspaceId,
            $workspaceTitle,
            $mode,
            $config,
            $languageUid,
            $maxItems,
            pageUid: $pageUid,
            hasNews: $hasNews,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public function forNews(
        int $newsUid,
        int $workspaceId,
        string $workspaceTitle,
        string $mode,
        array $config,
        ?int $languageUid,
    ): PendingItemsPayload {
        $scope = $this->resolveNewsScope($newsUid, $workspaceId, $languageUid);
        $maxItems = Value::int($config['maxItems'] ?? 200);
        $items = [];

        if ($scope->newsRecordUid > 0) {
            $newsItem = $this->pendingItemFactory->resolveRecordItem('tx_news_domain_model_news', $scope->newsRecordUid, $workspaceId, isPrimary: true, config: $config);
            if ($newsItem !== null && $this->includeItem($newsItem, $mode)) {
                $items[] = $newsItem;
            }
        }

        $items = $this->collectContentRows(
            $items,
            $scope->relatedContentRows,
            $workspaceId,
            $mode,
            $config,
            [],
            $languageUid,
            $maxItems,
        );

        return $this->finalizePayload(
            $items,
            $workspaceId,
            $workspaceTitle,
            $mode,
            $config,
            $languageUid,
            $maxItems,
            newsUid: $newsUid,
        );
    }

    /**
     * @param list<PendingItem> $items
     * @param list<array<string, mixed>> $contentRows
     * @param array<string, mixed> $config
     * @param array<int, string> $columnLabels
     * @return list<PendingItem>
     */
    private function collectContentRows(
        array $items,
        array $contentRows,
        int $workspaceId,
        string $mode,
        array $config,
        array $columnLabels,
        ?int $languageUid,
        int $maxItems,
    ): array {
        foreach ($contentRows as $row) {
            $item = $this->pendingItemFactory->buildItem('tt_content', $row, isPrimary: false, config: $config, columnLabels: $columnLabels);
            if ($item === null) {
                continue;
            }
            $item = $this->pendingItemAggregator->withRelatedChanges(
                $item,
                $this->inlineChildResolver->resolveInlineChildItems('tt_content', $row, $workspaceId, $mode, $config, $columnLabels, $languageUid),
            );
            if ($this->includeItem($item, $mode)) {
                $items[] = $item;
            }
            if (count($items) >= $maxItems) {
                break;
            }
        }
        return $items;
    }

    /**
     * @param list<PendingItem> $items
     * @param array<string, mixed> $config
     */
    private function finalizePayload(
        array $items,
        int $workspaceId,
        string $workspaceTitle,
        string $mode,
        array $config,
        ?int $languageUid,
        int $maxItems,
        ?int $pageUid = null,
        ?int $newsUid = null,
        bool $hasNews = false,
    ): PendingItemsPayload {
        if (count($items) < $maxItems) {
            $items = $this->pendingItemAggregator->withStandaloneWorkspaceItems($items, $workspaceId, $config, $maxItems);
        }

        $items = $this->pendingItemAggregator->deduplicateItems($items);
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

    private function includeItem(PendingItem $item, string $mode): bool
    {
        return $mode === PendingItemsService::MODE_ALL || $item->isChanged;
    }
}
