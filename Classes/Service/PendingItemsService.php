<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Schema\Exception\InvalidSchemaTypeException;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Versioning\VersionState;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Collects records visible in the toolbar dropdown for a given page or
 * news context.
 *
 * Two modes:
 *  - 'changed' (default): only records with a workspace version
 *  - 'all'              : every record on the page (live + workspace)
 *                         so editors can see context, with isChanged
 *                         flagged on each item.
 */
final readonly class PendingItemsService
{
    public const MODE_CHANGED = 'changed';
    public const MODE_ALL = 'all';

    /**
     * Root-level workspace records that have no page/content parent but
     * still represent publishable editor work. The physical sys_file row is
     * not workspace-versioned; sys_file_metadata is TYPO3's publishable FAL
     * record.
     *
     * @var list<string>
     */
    private const STANDALONE_WORKSPACE_TABLES = [
        'sys_file_metadata',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private ResourceFactory $resourceFactory,
        private LanguageServiceFactory $languageServiceFactory,
        private BackendUriBuilder $backendUriBuilder,
        private Context $context,
        private RecordDiffService $recordDiffService,
        private BackendLayoutView $backendLayoutView,
        private RecordHistoryTimelineService $historyTimelineService,
        private LocalizationService $localizationService,
    ) {}

    /**
     * @param array<string, mixed> $config Normalized config from ConfigurationProvider.
     * @return array{workspaceId: int, workspaceTitle: string, pageUid: int, languageUid: int|null, items: list<array<string, mixed>>, itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, hasNews: bool, mode: string}
     */
    public function forPage(int $pageUid, string $mode = self::MODE_CHANGED, array $config = [], ?int $languageUid = null): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        $workspaceTitle = $this->resolveWorkspaceTitle($workspaceId);
        if ($workspaceId <= 0 || $pageUid <= 0) {
            return ['workspaceId' => $workspaceId, 'workspaceTitle' => $workspaceTitle, 'pageUid' => $pageUid, 'languageUid' => $languageUid, 'items' => [], 'itemGroups' => [], 'changedItemGroups' => [], 'hasNews' => false, 'mode' => $mode];
        }

        $maxItems = Value::int($config['maxItems'] ?? 200);
        $items = [];

        $pageRecordUid = $this->resolvePageRecordUidForLanguage($pageUid, $workspaceId, $languageUid);
        $pageRow = $pageRecordUid > 0 ? $this->resolveRecordRow('pages', $pageRecordUid, $workspaceId) : null;
        $pageItem = $pageRow !== null ? $this->buildItem('pages', $pageRow, isPrimary: true, config: $config) : null;
        // In "Changes only" mode hide the page record if it has no
        // workspace edits (it would otherwise render as a "Live"
        // row without a checkbox and confuse editors who expect the
        // list to only contain publishable items).
        if ($pageItem !== null) {
            $pageItemArray = $this->withRelatedChanges(
                $pageItem->toArray(),
                $this->resolveInlineChildItems('pages', $pageRow, $workspaceId, $mode, $config, languageUid: $languageUid),
            );
            if ($mode === self::MODE_ALL || !empty($pageItemArray['isChanged'])) {
                $items[] = $pageItemArray;
            }
        }

        // Resolve column labels (e.g. "Hero area", "Content area")
        // from the page's BackendLayout so each tt_content row in the
        // dropdown carries the colPos name that the page tree shows
        // in Web → Layout. Empty array if no layout selected — items
        // then just show the numeric colPos ("Column 0", "Column 3"
        // etc.) on the frontend.
        $columnLabels = $this->resolveColumnLabels($pageUid);

        // Order tt_content rows the way editors read them in the
        // Layout module: colPos ASC first (hero / main / sidebar /
        // footer in their backend-layout order), then sorting ASC
        // inside each column.
        $contentOrder = [['colPos', 'ASC'], ['sorting', 'ASC']];

        $contentRows = $this->listAllRecordsOnPage('tt_content', $pageUid, $workspaceId, $contentOrder, $languageUid);
        foreach ($contentRows as $row) {
            $item = $this->buildItem('tt_content', $row, isPrimary: false, config: $config, columnLabels: $columnLabels);
            if ($item !== null) {
                $itemArray = $this->withRelatedChanges(
                    $item->toArray(),
                    $this->resolveInlineChildItems('tt_content', $row, $workspaceId, $mode, $config, $columnLabels, $languageUid),
                );
                if ($mode === self::MODE_ALL || !empty($itemArray['isChanged'])) {
                    $items[] = $itemArray;
                }
                if (count($items) >= $maxItems) break;
            }
        }
        if (count($items) < $maxItems) {
            $items = $this->withInlineChildParents(
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

        $hasNews = $this->tcaSchemaFactory->has('tx_news_domain_model_news');
        $enableNews = !isset($config['enableNewsBundles']) || (bool)$config['enableNewsBundles'];
        if ($hasNews && $enableNews && count($items) < $maxItems) {
            foreach ($this->resolveNewsItemsOnPage($pageUid, $workspaceId, $mode, $config, $languageUid) as $bundle) {
                if (count($items) >= $maxItems) break;
                $items[] = $bundle['news']->toArray();
                foreach ($bundle['contentElements'] as $ceItem) {
                    $items[] = $ceItem->toArray();
                    if (count($items) >= $maxItems) break 2;
                }
            }
        }
        if (count($items) < $maxItems) {
            $items = $this->withStandaloneWorkspaceItems($items, $workspaceId, $config, $maxItems);
        }

        $items = $this->deduplicateItems($items);

        return [
            'workspaceId' => $workspaceId,
            'workspaceTitle' => $workspaceTitle,
            'pageUid' => $pageUid,
            'languageUid' => $languageUid,
            'items' => $items,
            'itemGroups' => $this->groupItems($items),
            'changedItemGroups' => $this->groupItems($this->changedItems($items)),
            'hasNews' => $hasNews,
            'mode' => $mode,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, workspaceTitle: string, newsUid: int, languageUid: int|null, items: list<array<string, mixed>>, itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, mode: string}
     */
    public function forNews(int $newsUid, string $mode = self::MODE_CHANGED, array $config = [], ?int $languageUid = null): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        $workspaceTitle = $this->resolveWorkspaceTitle($workspaceId);
        if ($workspaceId <= 0 || $newsUid <= 0 || !$this->tcaSchemaFactory->has('tx_news_domain_model_news')) {
            return ['workspaceId' => $workspaceId, 'workspaceTitle' => $workspaceTitle, 'newsUid' => $newsUid, 'languageUid' => $languageUid, 'items' => [], 'itemGroups' => [], 'changedItemGroups' => [], 'mode' => $mode];
        }

        $maxItems = Value::int($config['maxItems'] ?? 200);
        $items = [];
        $newsRecordUid = $this->resolveRecordUidForLanguage('tx_news_domain_model_news', $newsUid, $workspaceId, $languageUid);
        $newsItem = $newsRecordUid > 0
            ? $this->resolveRecordItem('tx_news_domain_model_news', $newsRecordUid, $workspaceId, isPrimary: true, config: $config)
            : null;
        if ($newsItem !== null && ($mode === self::MODE_ALL || $newsItem->isChanged)) {
            $items[] = $newsItem->toArray();
        }

        foreach ($this->listAllRelatedRecords('tt_content', 'tx_news_related_news', $newsUid, $workspaceId, [['sorting', 'ASC']], $languageUid) as $row) {
            $item = $this->buildItem('tt_content', $row, isPrimary: false, config: $config);
            if ($item !== null) {
                $itemArray = $this->withRelatedChanges(
                    $item->toArray(),
                    $this->resolveInlineChildItems('tt_content', $row, $workspaceId, $mode, $config, languageUid: $languageUid),
                );
                if ($mode === self::MODE_ALL || !empty($itemArray['isChanged'])) {
                    $items[] = $itemArray;
                }
                if (count($items) >= $maxItems) break;
            }
        }
        if (count($items) < $maxItems) {
            $items = $this->withStandaloneWorkspaceItems($items, $workspaceId, $config, $maxItems);
        }

        $items = $this->deduplicateItems($items);

        return [
            'workspaceId' => $workspaceId,
            'workspaceTitle' => $workspaceTitle,
            'newsUid' => $newsUid,
            'languageUid' => $languageUid,
            'items' => $items,
            'itemGroups' => $this->groupItems($items),
            'changedItemGroups' => $this->groupItems($this->changedItems($items)),
            'mode' => $mode,
        ];
    }

    /**
     * Cheap guard used after Visual Editor saves. It only answers
     * whether a full dropdown refresh is worth doing; it deliberately
     * does not build titles, thumbnails, diffs, history badges, or
     * grouped row payloads.
     *
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, pageUid: int, languageUid: int|null, hasChanges: bool}
     */
    public function hasChangesForPage(int $pageUid, array $config = [], ?int $languageUid = null): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        if ($workspaceId <= 0 || $pageUid <= 0) {
            return ['workspaceId' => $workspaceId, 'pageUid' => $pageUid, 'languageUid' => $languageUid, 'hasChanges' => false];
        }

        $pageRecordUid = $this->resolvePageRecordUidForLanguage($pageUid, $workspaceId, $languageUid);
        $hasChanges = ($pageRecordUid > 0 && $this->hasWorkspaceVersionForRecord('pages', $pageRecordUid, $workspaceId, $languageUid))
            || $this->hasChangedRowsRelated('tt_content', 'pid', $pageUid, $workspaceId, $languageUid);

        $hasNews = $this->tcaSchemaFactory->has('tx_news_domain_model_news');
        $enableNews = !isset($config['enableNewsBundles']) || (bool)$config['enableNewsBundles'];
        if (!$hasChanges && $hasNews && $enableNews) {
            $hasChanges = $this->hasChangedRowsRelated('tx_news_domain_model_news', 'pid', $pageUid, $workspaceId, $languageUid);
        }

        if (!$hasChanges && $pageRecordUid > 0) {
            $pageRow = $this->resolveRecordRow('pages', $pageRecordUid, $workspaceId);
            $hasChanges = $pageRow !== null && $this->hasInlineChildChangesForRows('pages', [$pageRow], $workspaceId, $languageUid);
        }

        if (!$hasChanges) {
            $hasChanges = $this->hasChangedInlineChildrenOnPage('tt_content', $pageUid, $workspaceId, $languageUid);
        }

        if (!$hasChanges) {
            $hasChanges = $this->hasInlineChildChangesForRows(
                'tt_content',
                $this->listAllRecordsOnPage('tt_content', $pageUid, $workspaceId, [['uid', 'ASC']], $languageUid),
                $workspaceId,
                $languageUid,
            );
        }

        if (!$hasChanges) {
            $hasChanges = $this->hasStandaloneWorkspaceChanges($workspaceId);
        }

        return ['workspaceId' => $workspaceId, 'pageUid' => $pageUid, 'languageUid' => $languageUid, 'hasChanges' => $hasChanges];
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

        $newsRecordUid = $this->resolveRecordUidForLanguage('tx_news_domain_model_news', $newsUid, $workspaceId, $languageUid);
        $hasChanges = ($newsRecordUid > 0 && $this->hasWorkspaceVersionForRecord('tx_news_domain_model_news', $newsRecordUid, $workspaceId, $languageUid))
            || $this->hasChangedRowsRelated('tt_content', 'tx_news_related_news', $newsUid, $workspaceId, $languageUid);

        if (!$hasChanges) {
            $hasChanges = $this->hasInlineChildChangesForRows(
                'tt_content',
                $this->listAllRelatedRecords('tt_content', 'tx_news_related_news', $newsUid, $workspaceId, [['uid', 'ASC']], $languageUid),
                $workspaceId,
                $languageUid,
            );
        }

        if (!$hasChanges) {
            $hasChanges = $this->hasStandaloneWorkspaceChanges($workspaceId);
        }

        return ['workspaceId' => $workspaceId, 'newsUid' => $newsUid, 'languageUid' => $languageUid, 'hasChanges' => $hasChanges];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function changedItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $item): bool => !empty($item['isChanged']),
        ));
    }

    /**
     * PHP owns the display grouping contract. The frontend receives a
     * ready-to-render group list and only filters/renders it per tab.
     * Each BackendLayout column appears once, regardless of how many
     * content records or folded inline child changes live inside it.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array{key: string, label: string|null, items: list<array<string, mixed>>}>
     */
    private function groupItems(array $items): array
    {
        $groups = [];
        $primaryItems = [];

        foreach ($items as $item) {
            $table = Value::string($item['table'] ?? null);
            $colPos = array_key_exists('colPos', $item) ? Value::int($item['colPos']) : null;
            if ($table !== 'tt_content' || $colPos === null) {
                $primaryItems[] = $item;
                continue;
            }

            $key = 'column:' . $colPos;
            if (!isset($groups[$key])) {
                $label = Value::string($item['colPosLabel'] ?? null);
                if ($label === '') {
                    $label = $this->localizationService->translate('toolbar.column', ['number' => $colPos]);
                }
                $groups[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = $item;
        }

        $out = [];
        if ($primaryItems !== []) {
            $out[] = [
                'key' => 'records',
                'label' => null,
                'items' => $primaryItems,
            ];
        }
        foreach ($groups as $group) {
            $out[] = $group;
        }
        return $out;
    }

    /**
     * The dropdown should show one row per conceptual workspace record.
     * Workspace overlays and inline lookups can otherwise surface the
     * same record more than once when both live and versioned parent ids
     * are valid lookup anchors. If duplicates still arrive, keep the
     * newest workspace row and merge publish metadata so "All on page"
     * and "To publish" both show the latest visible version only.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function deduplicateItems(array $items): array
    {
        $seen = [];
        $deduplicated = [];
        foreach ($items as $item) {
            $table = Value::string($item['table'] ?? null);
            $kindKey = Value::string($item['kindKey'] ?? null);
            $liveUid = Value::int($item['liveUid'] ?? null);
            $workspaceUid = Value::int($item['workspaceUid'] ?? null);
            $identityUid = $kindKey === 'new' || $liveUid <= 0 ? $workspaceUid : $liveUid;
            if ($table === '' || $identityUid <= 0) {
                $deduplicated[] = $item;
                continue;
            }
            $key = $table . ':' . $identityUid;
            if (isset($seen[$key])) {
                $index = $seen[$key];
                $deduplicated[$index] = $this->mergeItemArrays($deduplicated[$index], $item);
                continue;
            }
            $seen[$key] = count($deduplicated);
            $deduplicated[] = $item;
        }
        return $deduplicated;
    }

    /**
     * Fold workspace changes from inline child records into the visible
     * parent content element. Editors publish "the accordion" or "the
     * article grid", not three implementation rows from a generated
     * Content Blocks child table.
     *
     * @param array<string, mixed> $item
     * @param list<PendingItem> $relatedItems
     * @return array<string, mixed>
     */
    private function withRelatedChanges(array $item, array $relatedItems): array
    {
        foreach ($relatedItems as $relatedItem) {
            $item = $this->mergeItemArrays($item, $relatedItem->toArray());
        }
        return $item;
    }

    /**
     * Attach changed inline child records to their owning record even when
     * the owner did not make it into the first visible row pass. This
     * covers file-only and child-record-only workspace changes on otherwise
     * unchanged or hidden content elements.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $config
     * @param array<int, string> $columnLabels
     * @return list<array<string, mixed>>
     */
    private function withInlineChildParents(
        array $items,
        string $parentTable,
        int $pageUid,
        int $workspaceId,
        string $mode,
        array $config,
        array $columnLabels,
        ?int $languageUid,
        int $maxItems,
    ): array {
        if ($workspaceId <= 0 || $pageUid <= 0) {
            return $items;
        }

        foreach ($this->resolveChangedInlineChildItemsOnPage($parentTable, $pageUid, $workspaceId, $config, $columnLabels, $languageUid) as $parentUid => $childItems) {
            if ($childItems === []) {
                continue;
            }
            $index = $this->findItemIndexByRecordIdentity($items, $parentTable, $parentUid);
            if ($index === null) {
                if (count($items) >= $maxItems) {
                    break;
                }
                $parentItem = $this->resolveInlineChildParentItem($parentTable, $parentUid, $workspaceId, $config, $columnLabels);
                if (!$parentItem instanceof PendingItem) {
                    continue;
                }
                $item = $parentItem->toArray();
            } else {
                $item = $items[$index];
            }

            $item = $this->withRelatedChanges($item, $childItems);
            if ($mode === self::MODE_ALL || !empty($item['isChanged'])) {
                if ($index === null) {
                    $items[] = $item;
                } else {
                    $items[$index] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function withStandaloneWorkspaceItems(array $items, int $workspaceId, array $config, int $maxItems): array
    {
        if ($workspaceId <= 0 || count($items) >= $maxItems) {
            return $items;
        }

        foreach (self::STANDALONE_WORKSPACE_TABLES as $table) {
            foreach ($this->listStandaloneWorkspaceRows($table, $workspaceId, $maxItems - count($items)) as $row) {
                $item = $this->buildItem($table, $row, isPrimary: false, config: $config);
                if ($item instanceof PendingItem) {
                    $items[] = $item->toArray();
                }
                if (count($items) >= $maxItems) {
                    break 2;
                }
            }
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function findItemIndexByRecordIdentity(array $items, string $table, int $uid): ?int
    {
        foreach ($items as $index => $item) {
            if (
                Value::string($item['table'] ?? null) === $table
                && (
                    Value::int($item['liveUid'] ?? null) === $uid
                    || Value::int($item['workspaceUid'] ?? null) === $uid
                )
            ) {
                return $index;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $columnLabels
     * @return array<int, list<PendingItem>>
     */
    private function resolveChangedInlineChildItemsOnPage(
        string $parentTable,
        int $pageUid,
        int $workspaceId,
        array $config,
        array $columnLabels,
        ?int $languageUid,
    ): array {
        $itemsByParent = [];
        foreach ($this->resolveInlineChildConfigsForTable($parentTable) as $inlineConfig) {
            $table = $inlineConfig['table'];
            $foreignField = $inlineConfig['foreignField'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();

            $constraints = [
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            ];
            if (TcaUtility::hasColumn($table, 'deleted')) {
                $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            }
            if ($inlineConfig['foreignTableField'] !== null && $inlineConfig['foreignTableField'] !== '') {
                $constraints[] = $queryBuilder->expr()->eq(
                    $inlineConfig['foreignTableField'],
                    $queryBuilder->createNamedParameter($parentTable),
                );
            }
            foreach ($inlineConfig['foreignMatchFields'] as $field => $value) {
                $constraints[] = $queryBuilder->expr()->eq((string)$field, $queryBuilder->createNamedParameter((string)$value));
            }
            $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
            if ($languageConstraint !== null) {
                $constraints[] = $languageConstraint;
            }

            $result = $queryBuilder
                ->select('*')
                ->from($table)
                ->where(...$constraints)
                ->orderBy($foreignField, 'ASC')
                ->executeQuery();

            while ($row = $result->fetchAssociative()) {
                $row = Value::stringKeyArray($row);
                $parentUid = Value::int($row[$foreignField] ?? null);
                if ($parentUid <= 0) {
                    continue;
                }
                $item = $this->buildItem(
                    $table,
                    $row,
                    isPrimary: false,
                    config: $config,
                    columnLabels: $columnLabels,
                    locateTable: $parentTable === 'tt_content' ? 'tt_content' : null,
                    locateLiveUid: $parentUid,
                    locateWorkspaceUid: $parentUid,
                );
                if ($item instanceof PendingItem) {
                    $itemsByParent[$parentUid][] = $item;
                }
            }
        }

        return $itemsByParent;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $columnLabels
     */
    private function resolveInlineChildParentItem(string $table, int $uid, int $workspaceId, array $config, array $columnLabels): ?PendingItem
    {
        $row = BackendUtility::getRecord($table, $uid);
        if (!is_array($row)) {
            return null;
        }
        $row = Value::stringKeyArray($row);
        if (Value::int($row['t3ver_wsid'] ?? null) <= 0) {
            BackendUtility::workspaceOL($table, $row, $workspaceId);
            if (!is_array($row)) {
                return null;
            }
            $row = Value::stringKeyArray($row);
        }
        return $this->buildItem($table, $row, isPrimary: false, config: array_replace($config, ['showHidden' => true]), columnLabels: $columnLabels);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeItemArrays(array $base, array $incoming): array
    {
        $baseWorkspaceUid = Value::int($base['workspaceUid'] ?? null);
        $incomingWorkspaceUid = Value::int($incoming['workspaceUid'] ?? null);
        $baseChanged = !empty($base['isChanged']);
        $incomingChanged = !empty($incoming['isChanged']);

        if (
            Value::string($base['table'] ?? null) === Value::string($incoming['table'] ?? null)
            && Value::int($base['liveUid'] ?? null) === Value::int($incoming['liveUid'] ?? null)
            && $incomingChanged
            && (!$baseChanged || $incomingWorkspaceUid > $baseWorkspaceUid)
        ) {
            $replacementTable = Value::string($incoming['table'] ?? null);
            $replacementLiveUid = Value::int($incoming['liveUid'] ?? null);
            $preservedBadges = $this->listArray($base['changeBadges'] ?? null);
            $preservedOwnBadges = $this->listArray($base['ownChangeBadges'] ?? null);
            $preservedPublishRecords = $this->withoutConceptualRecord(
                $this->listArray($base['publishRecords'] ?? null),
                $replacementTable,
                $replacementLiveUid,
            );
            $preservedChangeRecords = $this->withoutConceptualRecord(
                $this->listArray($base['changeRecords'] ?? null),
                $replacementTable,
                $replacementLiveUid,
            );
            $base = array_replace($base, $incoming);
            $base['changeBadges'] = $preservedBadges;
            $base['ownChangeBadges'] = $preservedOwnBadges;
            $base['publishRecords'] = $preservedPublishRecords;
            $base['changeRecords'] = $preservedChangeRecords;
        }

        if ($incomingChanged) {
            $base['isChanged'] = true;
        }
        $base['childChanges'] = $this->mergeChildChanges(
            $this->listArray($base['childChanges'] ?? null),
            $incomingChanged && Value::string($incoming['table'] ?? null) !== Value::string($base['table'] ?? null)
                ? [$this->childChangeFromItem($incoming)]
                : $this->listArray($incoming['childChanges'] ?? null),
        );
        $base['changeBadges'] = $this->mergeChangeBadges(
            $this->listArray($base['changeBadges'] ?? null),
            $this->listArray($incoming['changeBadges'] ?? null),
        );
        if (
            Value::string($base['table'] ?? null) === Value::string($incoming['table'] ?? null)
            && Value::int($base['liveUid'] ?? null) === Value::int($incoming['liveUid'] ?? null)
            && $incomingChanged
        ) {
            $base['ownChangeBadges'] = $this->mergeChangeBadges(
                $this->listArray($base['ownChangeBadges'] ?? null),
                $this->listArray($incoming['ownChangeBadges'] ?? $incoming['changeBadges'] ?? null),
            );
        }
        $base['publishRecords'] = $this->mergeRecordReferences(
            $this->listArray($base['publishRecords'] ?? null),
            $this->listArray($incoming['publishRecords'] ?? null),
        );
        $base['changeRecords'] = $this->mergeRecordReferences(
            $this->listArray($base['changeRecords'] ?? null),
            $this->listArray($incoming['changeRecords'] ?? null),
        );

        return $base;
    }

    /**
     * @param list<mixed> $base
     * @param list<mixed> $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeChildChanges(array $base, array $incoming): array
    {
        $merged = [];
        foreach ([...$base, ...$incoming] as $child) {
            if (!is_array($child)) {
                continue;
            }
            $table = Value::string($child['table'] ?? null);
            $workspaceUid = Value::int($child['workspaceUid'] ?? null);
            if ($table === '' || $workspaceUid <= 0) {
                continue;
            }
            $merged[$table . ':' . $workspaceUid] = Value::stringKeyArray($child);
        }
        return array_values($merged);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function childChangeFromItem(array $item): array
    {
        return [
            'table' => Value::string($item['table'] ?? null),
            'liveUid' => Value::int($item['liveUid'] ?? null),
            'workspaceUid' => Value::int($item['workspaceUid'] ?? null),
            'title' => Value::string($item['title'] ?? null),
            'kindKey' => Value::string($item['kindKey'] ?? null),
            'kindLabel' => Value::string($item['kindLabel'] ?? null),
            'badge' => Value::string($item['badge'] ?? null) ?: 'info',
            'tableLabel' => Value::string($item['tableLabel'] ?? null),
            'typeLabel' => Value::string($item['typeLabel'] ?? null),
            'thumbnailUrl' => Value::string($item['thumbnailUrl'] ?? null),
            'tstamp' => Value::int($item['tstamp'] ?? null),
            'latestChangeAt' => Value::int($item['latestChangeAt'] ?? null),
            'latestChangeUserUid' => Value::int($item['latestChangeUserUid'] ?? null),
            'latestChangeUser' => Value::string($item['latestChangeUser'] ?? null),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function listArray(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param list<mixed> $records
     * @return list<mixed>
     */
    private function withoutConceptualRecord(array $records, string $table, int $liveUid): array
    {
        return array_values(array_filter(
            $records,
            static fn (mixed $record): bool => !is_array($record)
                || Value::string($record['table'] ?? null) !== $table
                || Value::int($record['liveUid'] ?? null) !== $liveUid,
        ));
    }

    /**
     * @param list<mixed> $base
     * @param list<mixed> $incoming
     * @return list<array{kindKey: string, kindLabel: string, badge: string}>
     */
    private function mergeChangeBadges(array $base, array $incoming): array
    {
        $merged = [];
        foreach ([...$base, ...$incoming] as $badge) {
            if (!is_array($badge)) {
                continue;
            }
            $kindKey = $this->normalizeChangeBadgeKey(Value::string($badge['kindKey'] ?? null));
            $kindLabel = Value::string($badge['kindLabel'] ?? null);
            $identity = $kindKey !== '' ? $kindKey : mb_strtolower($kindLabel);
            if ($kindKey === '' || $identity === '' || isset($merged[$identity])) {
                continue;
            }
            $merged[$identity] = [
                'kindKey' => $kindKey,
                'kindLabel' => $kindLabel,
                'badge' => Value::string($badge['badge'] ?? null) ?: 'info',
            ];
        }
        return array_values($merged);
    }

    private function normalizeChangeBadgeKey(string $kindKey): string
    {
        return match ($kindKey) {
            'changed' => 'modified',
            'move' => 'moved',
            'new' => 'created',
            'delete' => 'deleted',
            default => $kindKey,
        };
    }

    /**
     * @param list<array{actionKey: string}> $timeline
     * @return list<array{kindKey: string, kindLabel: string, badge: string}>
     */
    private function changeBadgesFromTimeline(array $timeline, string $fallbackKindKey, string $fallbackKindLabel, string $fallbackBadge): array
    {
        $badges = [];
        foreach ($timeline as $entry) {
            $kindKey = $this->normalizeChangeBadgeKey(Value::string($entry['actionKey'] ?? null));
            if ($kindKey === '' || isset($badges[$kindKey])) {
                continue;
            }
            $badges[$kindKey] = $this->changeBadgeForKind($kindKey);
        }

        if ($badges === []) {
            $kindKey = $this->normalizeChangeBadgeKey($fallbackKindKey);
            if ($kindKey !== '') {
                $badges[$kindKey] = in_array($kindKey, ['created', 'modified', 'moved', 'deleted'], true)
                    ? $this->changeBadgeForKind($kindKey)
                    : [
                        'kindKey' => $kindKey,
                        'kindLabel' => $fallbackKindLabel,
                        'badge' => $fallbackBadge ?: 'info',
                    ];
            }
        }

        return array_values($badges);
    }

    /**
     * @return array{kindKey: string, kindLabel: string, badge: string}
     */
    private function changeBadgeForKind(string $kindKey): array
    {
        return match ($kindKey) {
            'created' => [
                'kindKey' => 'created',
                'kindLabel' => $this->localizationService->translate('history.action.created'),
                'badge' => 'success',
            ],
            'moved' => [
                'kindKey' => 'moved',
                'kindLabel' => $this->localizationService->translate('history.action.moved'),
                'badge' => 'warning',
            ],
            'deleted' => [
                'kindKey' => 'deleted',
                'kindLabel' => $this->localizationService->translate('history.action.deleted'),
                'badge' => 'danger',
            ],
            default => [
                'kindKey' => 'modified',
                'kindLabel' => $this->localizationService->translate('history.action.modified'),
                'badge' => 'info',
            ],
        };
    }

    /**
     * @param list<array{actionKey: string, diffs: list<array{field: string}>}> $timeline
     */
    private function countModifiedFieldsInTimeline(array $timeline): int
    {
        $fields = [];
        foreach ($timeline as $entry) {
            if (Value::string($entry['actionKey'] ?? null) !== 'modified') {
                continue;
            }
            foreach ($this->listArray($entry['diffs'] ?? null) as $diff) {
                if (!is_array($diff)) {
                    continue;
                }
                $field = Value::string($diff['field'] ?? null);
                if ($field !== '') {
                    $fields[$field] = true;
                }
            }
        }
        return count($fields);
    }

    /**
     * @param list<mixed> $base
     * @param list<mixed> $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeRecordReferences(array $base, array $incoming): array
    {
        $merged = [];
        foreach ([...$base, ...$incoming] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $table = Value::string($record['table'] ?? null);
            $workspaceUid = Value::int($record['workspaceUid'] ?? null);
            if ($table === '' || $workspaceUid <= 0) {
                continue;
            }
            $merged[$table . ':' . $workspaceUid] = Value::stringKeyArray($record);
        }
        return array_values($merged);
    }

    /**
     * Resolve the concrete pages.uid that represents the chosen backend
     * language. Content records keep the default page uid as their pid,
     * but translated page properties are stored as their own pages row.
     */
    private function resolvePageRecordUidForLanguage(int $pageUid, int $workspaceId, ?int $languageUid): int
    {
        return $this->resolveRecordUidForLanguage('pages', $pageUid, $workspaceId, $languageUid);
    }

    private function resolveRecordUidForLanguage(string $table, int $uid, int $workspaceId, ?int $languageUid): int
    {
        if ($languageUid === null || $languageUid <= 0) {
            return $uid;
        }
        $languageField = $this->languageField($table);
        $translationParentField = $this->translationParentField($table);
        if ($languageField === null || $translationParentField === null) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId, false));
        $row = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? Value::int($row['uid'] ?? null) : 0;
    }

    private function languageConstraint(QueryBuilder $queryBuilder, string $table, ?int $languageUid): ?string
    {
        if ($languageUid === null || $languageUid < 0) {
            return null;
        }
        $languageField = $this->languageField($table);
        if ($languageField === null) {
            return null;
        }
        return $queryBuilder->expr()->eq(
            $languageField,
            $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT),
        );
    }

    private function languageField(string $table): ?string
    {
        $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);
        $field = Value::string($ctrl['languageField'] ?? null);
        if ($field !== '' && TcaUtility::hasColumn($table, $field)) {
            return $field;
        }
        return TcaUtility::hasColumn($table, 'sys_language_uid') ? 'sys_language_uid' : null;
    }

    private function translationParentField(string $table): ?string
    {
        $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);
        $field = Value::string($ctrl['transOrigPointerField'] ?? null);
        if ($field !== '' && TcaUtility::hasColumn($table, $field)) {
            return $field;
        }
        return TcaUtility::hasColumn($table, 'l10n_parent') ? 'l10n_parent' : null;
    }

    private function hasWorkspaceVersionForRecord(string $table, int $liveUid, int $workspaceId, ?int $languageUid = null): bool
    {
        if ($liveUid <= 0 || $workspaceId <= 0 || !TcaUtility::hasColumn($table, 't3ver_wsid')) {
            return false;
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
        $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        return (bool)$queryBuilder
            ->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param int|list<int> $parentUid
     */
    private function hasChangedRowsRelated(string $table, string $field, int|array $parentUid, int $workspaceId, ?int $languageUid = null): bool
    {
        $parentUids = is_array($parentUid) ? array_values(array_filter($parentUid, static fn (int $uid): bool => $uid > 0)) : [$parentUid];
        if ($parentUids === [] || $workspaceId <= 0 || !TcaUtility::hasColumn($table, $field) || !TcaUtility::hasColumn($table, 't3ver_wsid')) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            count($parentUids) === 1
                ? $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($parentUids[0], Connection::PARAM_INT))
                : $queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        if (TcaUtility::hasColumn($table, 'deleted')) {
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        return (bool)$queryBuilder
            ->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param list<array<string, mixed>> $parentRows
     */
    private function hasInlineChildChangesForRows(string $parentTable, array $parentRows, int $workspaceId, ?int $languageUid = null): bool
    {
        foreach ($parentRows as $parentRow) {
            $parentLiveUid = Value::int($parentRow['t3ver_oid'] ?? null) ?: Value::int($parentRow['uid'] ?? null);
            $parentWorkspaceUid = Value::int($parentRow['_ORIG_uid'] ?? $parentRow['uid'] ?? null);
            $parentUids = array_values(array_unique(array_filter([$parentLiveUid, $parentWorkspaceUid], static fn(int $uid): bool => $uid > 0)));
            if ($parentUids === []) {
                continue;
            }
            foreach ($this->resolveInlineChildConfigs($parentTable, $parentRow) as $inlineConfig) {
                if ($this->hasChangedInlineChildRows($parentTable, $parentUids, $workspaceId, $inlineConfig, $languageUid)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param list<int> $parentUids
     * @param array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>} $inlineConfig
     */
    private function hasChangedInlineChildRows(string $parentTable, array $parentUids, int $workspaceId, array $inlineConfig, ?int $languageUid = null): bool
    {
        $table = $inlineConfig['table'];
        $foreignField = $inlineConfig['foreignField'];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $constraints = [
            count($parentUids) === 1
                ? $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($parentUids[0], Connection::PARAM_INT))
                : $queryBuilder->expr()->in($foreignField, $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        if (TcaUtility::hasColumn($table, 'deleted')) {
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        if ($inlineConfig['foreignTableField'] !== null && $inlineConfig['foreignTableField'] !== '') {
            $constraints[] = $queryBuilder->expr()->eq(
                $inlineConfig['foreignTableField'],
                $queryBuilder->createNamedParameter($parentTable),
            );
        }
        foreach ($inlineConfig['foreignMatchFields'] as $field => $value) {
            $constraints[] = $queryBuilder->expr()->eq((string)$field, $queryBuilder->createNamedParameter((string)$value));
        }
        $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        return (bool)$queryBuilder
            ->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    private function hasChangedInlineChildrenOnPage(string $parentTable, int $pageUid, int $workspaceId, ?int $languageUid = null): bool
    {
        if ($pageUid <= 0 || $workspaceId <= 0) {
            return false;
        }

        foreach ($this->resolveInlineChildConfigsForTable($parentTable) as $inlineConfig) {
            $table = $inlineConfig['table'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $constraints = [
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            ];
            if (TcaUtility::hasColumn($table, 'deleted')) {
                $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            }
            if ($inlineConfig['foreignTableField'] !== null && $inlineConfig['foreignTableField'] !== '') {
                $constraints[] = $queryBuilder->expr()->eq(
                    $inlineConfig['foreignTableField'],
                    $queryBuilder->createNamedParameter($parentTable),
                );
            }
            foreach ($inlineConfig['foreignMatchFields'] as $field => $value) {
                $constraints[] = $queryBuilder->expr()->eq((string)$field, $queryBuilder->createNamedParameter((string)$value));
            }
            $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
            if ($languageConstraint !== null) {
                $constraints[] = $languageConstraint;
            }

            if ((bool)$queryBuilder
                ->select('uid')
                ->from($table)
                ->where(...$constraints)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne()
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasStandaloneWorkspaceChanges(int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }
        foreach (self::STANDALONE_WORKSPACE_TABLES as $table) {
            if ($this->listStandaloneWorkspaceRows($table, $workspaceId, 1) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reads the title field from sys_workspace; falls back to a
     * generic label for the live workspace or unknown ids.
     */
    private function resolveWorkspaceTitle(int $workspaceId): string
    {
        if ($workspaceId <= 0) {
            return $this->localizationService->translate('state.live');
        }
        $row = BackendUtility::getRecord('sys_workspace', $workspaceId);
        if (is_array($row) && !empty($row['title'])) {
            return Value::string($row['title']);
        }
        return $this->localizationService->translate('toolbar.title') . ' #' . $workspaceId;
    }

    /**
     * Get a sorted list of all records of $table belonging to $parentUid,
     * with workspace overlay applied. Returns the raw row arrays (each
     * row will contain _ORIG_uid when overlaid from a workspace version).
     *
     * @param list<array{0: string, 1: string}> $orderBy List of [column, direction] tuples.
     * @return list<array<string, mixed>>
     */
    private function listAllRecordsOnPage(string $table, int $pageUid, int $workspaceId, array $orderBy, ?int $languageUid = null): array
    {
        return $this->listAllRelatedRecords($table, 'pid', $pageUid, $workspaceId, $orderBy, $languageUid);
    }

    /**
     * @param list<array{0: string, 1: string}> $orderBy List of [column, direction] tuples.
     * @return list<array<string, mixed>>
     */
    private function listAllRelatedRecords(string $table, string $field, int $parentUid, int $workspaceId, array $orderBy, ?int $languageUid = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // FrontendRestrictionContainer would filter hidden — we want
        // to *include* hidden so the badge can be shown.
        //
        // WorkspaceRestriction with $includeRowsForWorkspacePreview=false
        // returns one row per conceptual record (live OR new-in-workspace)
        // — never both — so the subsequent workspaceOL call cleanly
        // overlays the workspace version onto live rows without
        // producing duplicates. Setting the flag to true returns
        // workspace versions as separate rows in addition to their
        // live counterparts (see core docstring: "duplicates might be
        // shown and the reduce logic needs to be added after").
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId, false));

        $constraints = [
            $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
        ];
        $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints);

        foreach ($orderBy as $i => [$column, $direction]) {
            if ($i === 0) {
                $queryBuilder->orderBy($column, $direction);
            } else {
                $queryBuilder->addOrderBy($column, $direction);
            }
        }

        $result = $queryBuilder->executeQuery();
        $rows = [];
        while ($row = $result->fetchAssociative()) {
            BackendUtility::workspaceOL($table, $row, $workspaceId);
            if (is_array($row)) {
                $rows[] = Value::stringKeyArray($row);
            }
        }
        return $rows;
    }

    /**
     * Content Blocks Collection fields are TYPO3 inline/IRRE child records.
     * They are versioned in their own generated tables, so a parent
     * tt_content record can be unchanged while its repeatable child rows
     * have pending workspace versions.
     *
     * @param array<string, mixed> $parentRow
     * @param array<string, mixed> $config
     * @param array<int, string>   $columnLabels
     * @return list<PendingItem>
     */
    private function resolveInlineChildItems(
        string $parentTable,
        array $parentRow,
        int $workspaceId,
        string $mode,
        array $config = [],
        array $columnLabels = [],
        ?int $languageUid = null,
    ): array {
        $parentLiveUid = Value::int($parentRow['t3ver_oid'] ?? null) ?: Value::int($parentRow['uid'] ?? null);
        $parentWorkspaceUid = Value::int($parentRow['_ORIG_uid'] ?? $parentRow['uid'] ?? null);
        $parentUids = array_values(array_unique(array_filter([$parentLiveUid, $parentWorkspaceUid], static fn(int $uid): bool => $uid > 0)));
        if ($parentUids === []) {
            return [];
        }

        $items = [];
        foreach ($this->resolveInlineChildConfigs($parentTable, $parentRow) as $inlineConfig) {
            foreach ($this->listInlineChildRows($parentTable, $parentUids, $workspaceId, $mode, $inlineConfig, $languageUid) as $childRow) {
                $item = $this->buildItem(
                    $inlineConfig['table'],
                    $childRow,
                    isPrimary: false,
                    config: $config,
                    columnLabels: $columnLabels,
                    locateTable: $parentTable === 'tt_content' ? 'tt_content' : null,
                    locateLiveUid: $parentTable === 'tt_content' ? $parentLiveUid : null,
                    locateWorkspaceUid: $parentTable === 'tt_content' ? $parentWorkspaceUid : null,
                );
                if ($item !== null && ($mode === self::MODE_ALL || $item->isChanged)) {
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $parentRow
     * @return list<array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}>
     */
    private function resolveInlineChildConfigs(string $parentTable, array $parentRow): array
    {
        $parentTca = TcaUtility::table($parentTable);
        if ($parentTca === []) {
            return [];
        }

        $columns = Value::stringKeyArray($parentTca['columns'] ?? null);
        $ctrl = Value::stringKeyArray($parentTca['ctrl'] ?? null);
        $typeField = Value::string($ctrl['type'] ?? null);
        $typeName = $typeField !== '' ? Value::string($parentRow[$typeField] ?? null) : '';
        $types = Value::stringKeyArray($parentTca['types'] ?? null);
        $typeConfig = Value::stringKeyArray($types[$typeName] ?? null);
        foreach (Value::stringKeyArray($typeConfig['columnsOverrides'] ?? null) as $fieldName => $override) {
            $columns[$fieldName] = array_replace_recursive(
                Value::stringKeyArray($columns[$fieldName] ?? null),
                Value::stringKeyArray($override),
            );
        }

        $inlineConfigs = [];
        foreach ($columns as $fieldName => $column) {
            if (!is_array($column)) {
                continue;
            }
            $fieldConfig = Value::stringKeyArray(Value::stringKeyArray($column)['config'] ?? null);
            $fieldType = Value::string($fieldConfig['type'] ?? null);
            if ($fieldType === 'file') {
                if ($this->isWorkspaceAwareInlineChildTable('sys_file_reference')) {
                    $inlineConfigs[] = [
                        'field' => $fieldName,
                        'label' => Value::string(Value::stringKeyArray($column)['label'] ?? $fieldName),
                        'table' => 'sys_file_reference',
                        'foreignField' => 'uid_foreign',
                        'foreignTableField' => 'tablenames',
                        'foreignMatchFields' => ['fieldname' => $fieldName],
                        'orderBy' => $this->resolveChildOrderBy('sys_file_reference', ['foreign_sortby' => 'sorting_foreign']),
                    ];
                }
                continue;
            }
            if ($fieldType !== 'inline') {
                continue;
            }
            $foreignTable = Value::string($fieldConfig['foreign_table'] ?? null);
            $foreignField = Value::string($fieldConfig['foreign_field'] ?? null);
            if ($foreignTable === '' || $foreignField === '' || !$this->isWorkspaceAwareInlineChildTable($foreignTable)) {
                continue;
            }
            $foreignMatchFields = Value::scalarStringKeyArray($fieldConfig['foreign_match_fields'] ?? null);
            $inlineConfigs[] = [
                'field' => $fieldName,
                'label' => Value::string(Value::stringKeyArray($column)['label'] ?? $fieldConfig['label'] ?? $fieldName),
                'table' => $foreignTable,
                'foreignField' => $foreignField,
                'foreignTableField' => isset($fieldConfig['foreign_table_field']) ? Value::string($fieldConfig['foreign_table_field']) : null,
                'foreignMatchFields' => $foreignMatchFields,
                'orderBy' => $this->resolveChildOrderBy($foreignTable, $fieldConfig),
            ];
        }
        return $inlineConfigs;
    }

    /**
     * Fallback config resolver for changed child rows whose parent was
     * not already rendered. It cannot know the parent's concrete type,
     * so it scans base columns and type overrides and de-duplicates by
     * child table / foreign field / match fields.
     *
     * @return list<array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}>
     */
    private function resolveInlineChildConfigsForTable(string $parentTable): array
    {
        $parentTca = TcaUtility::table($parentTable);
        if ($parentTca === []) {
            return [];
        }

        $columns = Value::stringKeyArray($parentTca['columns'] ?? null);
        foreach (Value::stringKeyArray($parentTca['types'] ?? null) as $typeConfig) {
            foreach (Value::stringKeyArray(Value::stringKeyArray($typeConfig)['columnsOverrides'] ?? null) as $fieldName => $override) {
                $columns[$fieldName] = array_replace_recursive(
                    Value::stringKeyArray($columns[$fieldName] ?? null),
                    Value::stringKeyArray($override),
                );
            }
        }

        $configs = [];
        $seen = [];
        foreach ($columns as $fieldName => $column) {
            if (!is_array($column)) {
                continue;
            }
            $fieldConfig = Value::stringKeyArray(Value::stringKeyArray($column)['config'] ?? null);
            $config = $this->inlineChildConfigFromField($parentTable, (string)$fieldName, Value::stringKeyArray($column), $fieldConfig);
            if ($config === null) {
                continue;
            }
            $key = $config['table'] . ':' . $config['foreignField'] . ':' . ($config['foreignTableField'] ?? '') . ':' . json_encode($config['foreignMatchFields']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $configs[] = $config;
        }

        return $configs;
    }

    /**
     * @param array<string, mixed> $column
     * @param array<string, mixed> $fieldConfig
     * @return array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>}|null
     */
    private function inlineChildConfigFromField(string $parentTable, string $fieldName, array $column, array $fieldConfig): ?array
    {
        $fieldType = Value::string($fieldConfig['type'] ?? null);
        if ($fieldType === 'file') {
            if (!$this->isWorkspaceAwareInlineChildTable('sys_file_reference')) {
                return null;
            }
            return [
                'field' => $fieldName,
                'label' => Value::string($column['label'] ?? $fieldName),
                'table' => 'sys_file_reference',
                'foreignField' => 'uid_foreign',
                'foreignTableField' => 'tablenames',
                'foreignMatchFields' => ['fieldname' => $fieldName],
                'orderBy' => $this->resolveChildOrderBy('sys_file_reference', ['foreign_sortby' => 'sorting_foreign']),
            ];
        }
        if ($fieldType !== 'inline') {
            return null;
        }

        $foreignTable = Value::string($fieldConfig['foreign_table'] ?? null);
        $foreignField = Value::string($fieldConfig['foreign_field'] ?? null);
        if ($foreignTable === '' || $foreignField === '' || !$this->isWorkspaceAwareInlineChildTable($foreignTable)) {
            return null;
        }
        return [
            'field' => $fieldName,
            'label' => Value::string($column['label'] ?? $fieldConfig['label'] ?? $fieldName),
            'table' => $foreignTable,
            'foreignField' => $foreignField,
            'foreignTableField' => isset($fieldConfig['foreign_table_field']) ? Value::string($fieldConfig['foreign_table_field']) : null,
            'foreignMatchFields' => Value::scalarStringKeyArray($fieldConfig['foreign_match_fields'] ?? null),
            'orderBy' => $this->resolveChildOrderBy($foreignTable, $fieldConfig),
        ];
    }

    private function isWorkspaceAwareInlineChildTable(string $table): bool
    {
        if ($table === 'sys_file_reference') {
            $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);
            return !empty($ctrl['versioningWS']);
        }
        return TcaUtility::isWorkspaceAwareHiddenTable($table);
    }

    /**
     * @param array<string, mixed> $fieldConfig
     * @return list<array{0: string, 1: string}>
     */
    private function resolveChildOrderBy(string $table, array $fieldConfig): array
    {
        $tableTca = TcaUtility::table($table);
        $ctrl = Value::stringKeyArray($tableTca['ctrl'] ?? null);
        $sortField = Value::string($fieldConfig['foreign_sortby'] ?? $ctrl['sortby'] ?? null);
        if ($sortField !== '' && TcaUtility::hasColumn($table, $sortField)) {
            return [[$sortField, 'ASC']];
        }
        if (TcaUtility::hasColumn($table, 'sorting')) {
            return [['sorting', 'ASC']];
        }
        return [['uid', 'ASC']];
    }

    /**
     * @param list<int> $parentUids
     * @param array{field: string, label: string, table: string, foreignField: string, foreignTableField: string|null, foreignMatchFields: array<string, scalar>, orderBy: list<array{0: string, 1: string}>} $inlineConfig
     * @return list<array<string, mixed>>
     */
    private function listInlineChildRows(string $parentTable, array $parentUids, int $workspaceId, string $mode, array $inlineConfig, ?int $languageUid = null): array
    {
        $table = $inlineConfig['table'];
        $foreignField = $inlineConfig['foreignField'];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        if ($mode === self::MODE_ALL) {
            $queryBuilder->getRestrictions()
                ->add(new DeletedRestriction())
                ->add(new WorkspaceRestriction($workspaceId, false));
        }

        $constraints = [
            $queryBuilder->expr()->in($foreignField, $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
        ];
        if ($mode !== self::MODE_ALL) {
            $constraints[] = $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT));
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }
        if ($inlineConfig['foreignTableField'] !== null && $inlineConfig['foreignTableField'] !== '') {
            $constraints[] = $queryBuilder->expr()->eq(
                $inlineConfig['foreignTableField'],
                $queryBuilder->createNamedParameter($parentTable),
            );
        }
        foreach ($inlineConfig['foreignMatchFields'] as $field => $value) {
            $constraints[] = $queryBuilder->expr()->eq((string)$field, $queryBuilder->createNamedParameter((string)$value));
        }
        $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints);
        foreach ($inlineConfig['orderBy'] as $i => [$column, $direction]) {
            if ($i === 0) {
                $queryBuilder->orderBy($column, $direction);
            } else {
                $queryBuilder->addOrderBy($column, $direction);
            }
        }

        $result = $queryBuilder->executeQuery();
        $rows = [];
        while ($row = $result->fetchAssociative()) {
            if ($mode === self::MODE_ALL) {
                BackendUtility::workspaceOL($table, $row, $workspaceId);
            }
            if (is_array($row)) {
                $rows[] = Value::stringKeyArray($row);
            }
        }
        return $rows;
    }

    /**
     * @param array<string, mixed>             $config
     * @param list<array{0: string, 1: string}> $orderBy       Defaults to [['sorting','ASC']] to match the original behaviour for non-tt_content tables.
     * @param array<int, string>               $columnLabels  colPos → name map. Only relevant for tt_content; ignored for other tables.
     * @return list<PendingItem>
     */
    private function resolveChangedRelated(
        string $table,
        string $field,
        int $parentUid,
        int $workspaceId,
        array $config = [],
        array $orderBy = [['sorting', 'ASC']],
        array $columnLabels = [],
        ?int $languageUid = null,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $constraints = [
            $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
        ];
        $languageConstraint = $this->languageConstraint($queryBuilder, $table, $languageUid);
        if ($languageConstraint !== null) {
            $constraints[] = $languageConstraint;
        }

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints);
        foreach ($orderBy as $i => [$column, $direction]) {
            if ($i === 0) {
                $queryBuilder->orderBy($column, $direction);
            } else {
                $queryBuilder->addOrderBy($column, $direction);
            }
        }

        $result = $queryBuilder->executeQuery();
        $items = [];
        while ($row = $result->fetchAssociative()) {
            $item = $this->buildItem($table, $row, isPrimary: false, config: $config, columnLabels: $columnLabels);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{news: PendingItem, contentElements: list<PendingItem>}>
     */
    private function resolveNewsItemsOnPage(int $pageUid, int $workspaceId, string $mode, array $config = [], ?int $languageUid = null): array
    {
        if ($mode === self::MODE_ALL) {
            $newsRows = $this->listAllRecordsOnPage('tx_news_domain_model_news', $pageUid, $workspaceId, [['datetime', 'DESC'], ['uid', 'ASC']], $languageUid);
        } else {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');
            $queryBuilder->getRestrictions()->removeAll();
            $constraints = [
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            ];
            $languageConstraint = $this->languageConstraint($queryBuilder, 'tx_news_domain_model_news', $languageUid);
            if ($languageConstraint !== null) {
                $constraints[] = $languageConstraint;
            }
            $result = $queryBuilder
                ->select('*')
                ->from('tx_news_domain_model_news')
                ->where(...$constraints)
                ->executeQuery();
            $newsRows = [];
            while ($row = $result->fetchAssociative()) {
                $newsRows[] = Value::stringKeyArray($row);
            }
        }

        $bundles = [];
        foreach ($newsRows as $newsRow) {
            $newsItem = $this->buildItem('tx_news_domain_model_news', $newsRow, isPrimary: true, config: $config);
            if ($newsItem === null) {
                continue;
            }
            $liveUid = $newsItem->liveUid;
            $childItems = [];
            if ($mode === self::MODE_ALL) {
                foreach ($this->listAllRelatedRecords('tt_content', 'tx_news_related_news', $liveUid, $workspaceId, [['sorting', 'ASC']], $languageUid) as $ceRow) {
                    $ceItem = $this->buildItem('tt_content', $ceRow, isPrimary: false, config: $config);
                    if ($ceItem !== null) {
                        $childItems[] = $ceItem;
                    }
                }
            } else {
                foreach ($this->resolveChangedRelated('tt_content', 'tx_news_related_news', $liveUid, $workspaceId, $config, languageUid: $languageUid) as $ceItem) {
                    $childItems[] = $ceItem;
                }
            }
            $bundles[] = ['news' => $newsItem, 'contentElements' => $childItems];
        }
        return $bundles;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listStandaloneWorkspaceRows(string $table, int $workspaceId, int $limit): array
    {
        if ($workspaceId <= 0 || $limit <= 0 || !TcaUtility::hasColumn($table, 't3ver_wsid')) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $constraints = [
            $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
        ];
        if (TcaUtility::hasColumn($table, 'deleted')) {
            $constraints[] = $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }

        $result = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints)
            ->orderBy(TcaUtility::hasColumn($table, 'tstamp') ? 'tstamp' : 'uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery();

        $rows = [];
        while ($row = $result->fetchAssociative()) {
            $rows[] = Value::stringKeyArray($row);
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveRecordItem(string $table, int $liveUid, int $workspaceId, bool $isPrimary, array $config = []): ?PendingItem
    {
        $row = $this->resolveRecordRow($table, $liveUid, $workspaceId);
        return $row !== null ? $this->buildItem($table, $row, $isPrimary, $config) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRecordRow(string $table, int $liveUid, int $workspaceId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // See listAllRelatedRecords for why $includeRowsForWorkspacePreview=false.
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId, false));

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }
        BackendUtility::workspaceOL($table, $row, $workspaceId);
        if (!is_array($row)) {
            return null;
        }
        return Value::stringKeyArray($row);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $config
     * @param array<int, string> $columnLabels colPos -> name map; only consulted for tt_content rows.
     */
    private function buildItem(
        string $table,
        array $row,
        bool $isPrimary,
        array $config = [],
        array $columnLabels = [],
        ?string $locateTable = null,
        ?int $locateLiveUid = null,
        ?int $locateWorkspaceUid = null,
    ): ?PendingItem
    {
        $rawUid = Value::int($row['uid'] ?? null);
        if ($rawUid <= 0) {
            return null;
        }
        if (Value::int($row['deleted'] ?? null) !== 0) {
            return null;
        }

        $isHidden = (bool)($row['hidden'] ?? false);
        if ($isHidden && isset($config['showHidden']) && !$config['showHidden']) {
            return null;
        }

        $isChanged = isset($row['_ORIG_uid']) || Value::int($row['t3ver_wsid'] ?? null) > 0;
        // After workspaceOL the row's uid is the *live* uid; _ORIG_uid is the workspace version uid.
        if ($isChanged) {
            $workspaceUid = Value::int($row['_ORIG_uid'] ?? $row['uid'] ?? null);
            $liveUid = Value::int($row['t3ver_oid'] ?? null) ?: Value::int($row['uid'] ?? null);
        } else {
            $workspaceUid = $rawUid;
            $liveUid = $rawUid;
        }

        $title = $this->resolveTitle($table, $row);

        $state = VersionState::tryFrom(Value::int($row['t3ver_state'] ?? null)) ?? VersionState::DEFAULT_STATE;
        if (!$isChanged) {
            $kindKey = 'live';
            $kindLabel = $this->localizationService->translate('state.live');
            $badge = 'secondary';
        } else {
            [$kindKey, $kindLabel, $badge] = match ($state) {
                VersionState::NEW_PLACEHOLDER => ['new', $this->localizationService->translate('state.new'), 'success'],
                VersionState::DELETE_PLACEHOLDER => ['delete', $this->localizationService->translate('state.delete'), 'danger'],
                VersionState::MOVE_POINTER => ['move', $this->localizationService->translate('state.move'), 'warning'],
                default => ['modified', $this->localizationService->translate('state.modified'), 'info'],
            };
        }

        $enableThumbnails = !isset($config['enableThumbnails']) || (bool)$config['enableThumbnails'];
        // record_edit expects the *live* uid for existing records;
        // FormEngine handles the workspace overlay on save automatically.
        $editUrl = $this->buildEditUrl($table, $liveUid);
        // The v14 contextual variant of the same route — slim Save/Close
        // chrome that fits the sheet-position modal we open from the
        // dropdown's pencil. The JS prefers this URL because this
        // extension is TYPO3 v14-only.
        $contextualEditUrl = $this->buildContextualEditUrl($table, $liveUid);
        $historyUrl = $this->buildRecordHistoryUrl($table, $workspaceUid);

        // Attach the field-level diff so each row in the dropdown can
        // expand to show *what* changed. Only computed for actual
        // workspace versions; live rows have nothing to diff.
        $diff = $isChanged ? $this->recordDiffService->diff($table, $row) : [];
        $timeline = $isChanged ? $this->historyTimelineService->build($table, $workspaceUid) : [];
        $latestChange = $this->latestChangeFromTimeline($timeline, Value::int($row['tstamp'] ?? null));
        $changeBadges = $isChanged
            ? $this->changeBadgesFromTimeline($timeline, $kindKey, $kindLabel, $badge)
            : [];
        $historyDiffCount = $isChanged && $state === VersionState::NEW_PLACEHOLDER
            ? $this->countModifiedFieldsInTimeline($timeline)
            : 0;

        // Resolve colPos info for tt_content rows so the frontend
        // can group items by page column with proper labels (e.g.
        // "Hero area" / "Content area"). Null for other tables.
        $colPos = null;
        $colPosLabel = null;
        if ($table === 'tt_content' && array_key_exists('colPos', $row)) {
            $colPos = Value::int($row['colPos'] ?? null);
            $colPosLabel = $columnLabels[$colPos] ?? null;
            if ($colPosLabel === null || $colPosLabel === '') {
                $colPosLabel = $this->localizationService->translate('toolbar.column', ['number' => $colPos]);
            }
        }

        return new PendingItem(
            table: $table,
            liveUid: $liveUid,
            workspaceUid: $workspaceUid,
            title: $title,
            kindKey: $kindKey,
            kindLabel: $kindLabel,
            badge: $badge,
            iconIdentifier: $this->resolveIconIdentifier($table, $row),
            thumbnailUrl: $enableThumbnails ? $this->resolveThumbnailUrl($table, $workspaceUid) : null,
            isPrimary: $isPrimary,
            isChanged: $isChanged,
            isHidden: $isHidden,
            tableLabel: $this->resolveTableLabel($table),
            typeLabel: $this->resolveTypeLabel($table, $row),
            editUrl: $editUrl,
            contextualEditUrl: $contextualEditUrl,
            historyUrl: $historyUrl,
            diff: $diff,
            changeBadges: $changeBadges,
            childChanges: [],
            historyDiffCount: $historyDiffCount,
            colPos: $colPos,
            colPosLabel: $colPosLabel,
            locateTable: $locateTable,
            locateLiveUid: $locateLiveUid,
            locateWorkspaceUid: $locateWorkspaceUid,
            tstamp: Value::int($row['tstamp'] ?? null),
            latestChangeAt: $latestChange['tstamp'],
            latestChangeUserUid: $latestChange['userUid'],
            latestChangeUser: $latestChange['user'],
        );
    }

    /**
     * @param list<array{tstamp: int, userUid: int, user: string}> $timeline
     * @return array{tstamp: int, userUid: int, user: string}
     */
    private function latestChangeFromTimeline(array $timeline, int $fallbackTimestamp): array
    {
        $latest = ['tstamp' => $fallbackTimestamp, 'userUid' => 0, 'user' => ''];
        foreach ($timeline as $entry) {
            $timestamp = Value::int($entry['tstamp'] ?? null);
            if ($timestamp <= 0 || $timestamp < $latest['tstamp']) {
                continue;
            }
            $latest = [
                'tstamp' => $timestamp,
                'userUid' => Value::int($entry['userUid'] ?? null),
                'user' => Value::string($entry['user'] ?? null),
            ];
        }
        return $latest;
    }

    /**
     * Resolve the BackendLayout column names for $pageUid as a
     * `colPos => name` map. Returns empty array when no layout is
     * configured — callers then fall back to numeric "Column N".
     *
     * @return array<int, string>
     */
    private function resolveColumnLabels(int $pageUid): array
    {
        try {
            $structure = $this->backendLayoutView->getSelectedBackendLayout($pageUid);
        } catch (\Throwable) {
            return [];
        }
        $columns = $structure['usedColumns'] ?? [];
        if (!is_array($columns)) {
            return [];
        }
        $languageService = $this->getLanguageService();
        $out = [];
        foreach ($columns as $colPos => $rawLabel) {
            $resolved = $languageService->sL(Value::string($rawLabel));
            $out[Value::int($colPos)] = $resolved !== '' ? $resolved : Value::string($rawLabel);
        }
        return $out;
    }

    /**
     * Build the standard TYPO3 FormEngine edit URL for a record. Used
     * by the dropdown's eye-icon click to open the record in the
     * familiar backend edit form (same form the page tree's
     * context-menu "Edit" entry opens).
     */
    private function buildEditUrl(string $table, int $uid): ?string
    {
        if ($uid <= 0) {
            return null;
        }
        try {
            return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$table => [$uid => 'edit']],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * TYPO3 v14 provides `record_edit_contextual` — a lightweight
     * variant of EditDocumentController that renders the same
     * FormEngine but with a minimal "Save / Close" chrome, designed
     * to be opened inside a sheet-position modal. We prefer that
     * over the full record_edit when the pencil is clicked from the
     * workspace dropdown: the contextual form fits the slim
     * right-side panel and posts back save/close signals via
     * window.postMessage so the dropdown can refresh after a save.
     *
     * Returns null if a site customizes backend routes in a way that
     * makes the route unavailable — the JS falls back to the regular
     * editUrl in that case.
     */
    private function buildContextualEditUrl(string $table, int $uid): ?string
    {
        if ($uid <= 0) {
            return null;
        }
        try {
            return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit_contextual', [
                'edit' => [$table => [$uid => 'edit']],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build TYPO3 core's native record history route for the concrete
     * workspace version. The browser-side button only navigates to
     * this URL; the actual history UI remains owned by TYPO3.
     */
    private function buildRecordHistoryUrl(string $table, int $uid): ?string
    {
        if ($uid <= 0) {
            return null;
        }
        try {
            return (string)$this->backendUriBuilder->buildUriFromRoute('record_history', [
                'element' => sprintf('%s:%d', $table, $uid),
                'historyEntry' => '',
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Friendly localized name of the *table* (e.g. "Seite" / "Page",
     * "Inhaltselement" / "Page Content", "News" / "Nachricht").
     *
     * TcaSchema::getTitle() takes an optional translator callable so we
     * pass LanguageService::sL — the v14-idiomatic way to resolve the
     * LLL: pointer behind ctrl.title to the editor's backend language.
     */
    private function resolveTableLabel(string $table): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return $table;
        }
        $languageService = $this->getLanguageService();
        $title = $this->tcaSchemaFactory->get($table)->getTitle(
            static fn (string $key): string => (string)$languageService->sL($key),
        );
        return $title !== '' ? $title : $table;
    }

    /**
     * Resolve a display title for the record:
     *   1. TCA label (BackendUtility::getRecordTitle, respects label_alt)
     *   2. For tt_content: first ~80 plain-text chars of bodytext
     *   3. Type label + uid ("Text & media · #42")
     *
     * @param array<string, mixed> $row
     */
    private function resolveTitle(string $table, array $row): string
    {
        if ($table === 'sys_file_metadata') {
            $fileName = $this->resolveFileMetadataTitle($row);
            if ($fileName !== '') {
                return $fileName;
            }
        }
        if ($table === 'sys_file_reference') {
            $fileName = $this->resolveFileReferenceTitle($row);
            if ($fileName !== '') {
                return $fileName;
            }
        }
        $title = trim((string)BackendUtility::getRecordTitle($table, $row));
        if ($title !== '' && !str_starts_with($title, '[no title]')) {
            return $title;
        }
        if ($table === 'tt_content' && isset($row['bodytext'])) {
            $fallback = $this->extractTextSnippet(Value::string($row['bodytext']));
            if ($fallback !== '') {
                return $fallback;
            }
        }
        $typeLabel = $this->resolveTypeLabel($table, $row);
        if ($typeLabel !== '') {
            return $typeLabel . ' · #' . Value::int($row['uid'] ?? null);
        }
        return $table . ' #' . Value::int($row['uid'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveFileMetadataTitle(array $row): string
    {
        $fileUid = Value::int($row['file'] ?? null);
        if ($fileUid <= 0) {
            return '';
        }
        try {
            return $this->resourceFactory->getFileObject($fileUid)->getName();
        } catch (FileDoesNotExistException | ResourceDoesNotExistException) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveFileReferenceTitle(array $row): string
    {
        $uidLocal = Value::int($row['uid_local'] ?? null);
        if ($uidLocal <= 0) {
            return '';
        }
        try {
            return $this->resourceFactory->getFileObject($uidLocal)->getName();
        } catch (FileDoesNotExistException | ResourceDoesNotExistException) {
            return '';
        }
    }

    private function extractTextSnippet(string $raw): string
    {
        $stripped = trim(strip_tags($raw));
        if ($stripped === '') {
            return '';
        }
        // Collapse whitespace and clip to 80 chars.
        $clean = (string)preg_replace('/\s+/u', ' ', $stripped);
        if (mb_strlen($clean) <= 80) {
            return $clean;
        }
        return mb_substr($clean, 0, 80) . '…';
    }

    /**
     * Resolve a friendly type label for the record.
     *   - tt_content   → CType label resolved from TCA items
     *   - pages        → doktype label
     *   - other tables → schema title
     *
     * Uses BackendUtility::getLabelFromItemlist (the official v14 API)
     * which already handles LLL translation, itemsProcFunc results and
     * Page TSconfig overrides.
     *
     * @param array<string, mixed> $row
     */
    private function resolveTypeLabel(string $table, array $row): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return $table;
        }
        $schema = $this->tcaSchemaFactory->get($table);
        try {
            $typeField = $schema->getSubSchemaTypeInformation()->getFieldName();
        } catch (InvalidSchemaTypeException) {
            $typeField = null;
        }

        // No discriminator field — fall back to the schema's own title.
        if ($typeField === null || !isset($row[$typeField])) {
            $rawConfiguration = $schema->getRawConfiguration();
            $ctrl = Value::stringKeyArray($rawConfiguration['ctrl'] ?? null);
            $title = Value::string($ctrl['title'] ?? $table);
            $label = $this->getLanguageService()->sL($title);
            return $label !== '' ? $label : $table;
        }

        $value = Value::string($row[$typeField] ?? null);
        $label = BackendUtility::getLabelFromItemlist($table, $typeField, $value);
        if ($label !== '') {
            return $this->getLanguageService()->sL($label);
        }
        // Last resort — the raw value (still better than nothing).
        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveIconIdentifier(string $table, array $row): string
    {
        $tca = TcaUtility::table($table);
        $ctrl = Value::stringKeyArray($tca['ctrl'] ?? null);
        $typeIconClasses = Value::stringKeyArray($ctrl['typeicon_classes'] ?? null);

        $typeField = Value::string($ctrl['type'] ?? null);
        if ($typeField !== '') {
            $typeValue = Value::string($row[$typeField] ?? null);
            if ($typeValue !== '' && isset($typeIconClasses[$typeValue]) && is_string($typeIconClasses[$typeValue])) {
                return $typeIconClasses[$typeValue];
            }
        }

        if (isset($typeIconClasses['default']) && is_string($typeIconClasses['default']) && $typeIconClasses['default'] !== '') {
            return $typeIconClasses['default'];
        }

        return match ($table) {
            'pages' => 'apps-pagetree-page-default',
            'tt_content' => 'mimetypes-x-content-text',
            'sys_file_metadata', 'sys_file_reference' => 'mimetypes-other-other',
            default => 'mimetypes-other-other',
        };
    }

    private function resolveThumbnailUrl(string $table, int $workspaceUid): ?string
    {
        if ($table === 'sys_file_metadata') {
            $row = BackendUtility::getRecord('sys_file_metadata', $workspaceUid, 'file');
            $fileUid = is_array($row) ? Value::int($row['file'] ?? null) : 0;
            return $fileUid > 0 ? $this->referenceToUrl($fileUid) : null;
        }
        if ($table === 'sys_file_reference') {
            $row = BackendUtility::getRecord('sys_file_reference', $workspaceUid, 'uid_local');
            $fileUid = is_array($row) ? Value::int($row['uid_local'] ?? null) : 0;
            return $fileUid > 0 ? $this->referenceToUrl($fileUid) : null;
        }
        $fieldNamesPerTable = [
            'tt_content' => ['image', 'assets', 'media'],
            'tx_news_domain_model_news' => ['fal_media', 'fal_related_files'],
            'pages' => ['media'],
        ];
        if (!isset($fieldNamesPerTable[$table])) {
            return null;
        }
        foreach ($fieldNamesPerTable[$table] as $fieldname) {
            $referenceUid = $this->findFirstReferenceUid($table, $workspaceUid, $fieldname);
            if ($referenceUid <= 0) {
                continue;
            }
            $url = $this->referenceToUrl($referenceUid);
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    private function findFirstReferenceUid(string $parentTable, int $parentUid, string $fieldname): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid_local')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($parentTable)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldname)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return is_array($row) ? Value::int($row['uid_local'] ?? null) : 0;
    }

    private function referenceToUrl(int $fileUid): ?string
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            if (!str_starts_with($file->getMimeType(), 'image/')) {
                return null;
            }
            $publicUrl = $file
                ->process(ProcessedFile::CONTEXT_IMAGEPREVIEW, ['width' => 96, 'height' => 72])
                ->getPublicUrl();
            return $publicUrl !== null && $publicUrl !== '' ? $publicUrl : null;
        } catch (FileDoesNotExistException | ResourceDoesNotExistException) {
            return null;
        }
    }

    private function getLanguageService(): LanguageService
    {
        if (isset($GLOBALS['LANG']) && $GLOBALS['LANG'] instanceof LanguageService) {
            return $GLOBALS['LANG'];
        }
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
        $user = $backendUser instanceof BackendUserAuthentication ? $backendUser->user : [];
        $lang = Value::string($user['lang'] ?? null);
        if ($lang === '') {
            $lang = 'default';
        }
        return $this->languageServiceFactory->create($lang);
    }
}
