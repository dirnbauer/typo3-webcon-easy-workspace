<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;

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

    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private ResourceFactory $resourceFactory,
        private LanguageServiceFactory $languageServiceFactory,
        private BackendUriBuilder $backendUriBuilder,
        private Context $context,
        private RecordDiffService $recordDiffService,
        private BackendLayoutView $backendLayoutView,
    ) {}

    /**
     * @param array<string, mixed> $config Normalized config from ConfigurationProvider.
     * @return array{workspaceId: int, workspaceTitle: string, pageUid: int, items: list<array<string, mixed>>, hasNews: bool, mode: string}
     */
    public function forPage(int $pageUid, string $mode = self::MODE_CHANGED, array $config = []): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        $workspaceTitle = $this->resolveWorkspaceTitle($workspaceId);
        if ($workspaceId <= 0 || $pageUid <= 0) {
            return ['workspaceId' => $workspaceId, 'workspaceTitle' => $workspaceTitle, 'pageUid' => $pageUid, 'items' => [], 'hasNews' => false, 'mode' => $mode];
        }

        $maxItems = (int)($config['maxItems'] ?? 200);
        $items = [];

        $pageItem = $this->resolveRecordItem('pages', $pageUid, $workspaceId, isPrimary: true, config: $config);
        // In "Changes only" mode hide the page record if it has no
        // workspace edits (it would otherwise render as a "Live"
        // row without a checkbox and confuse editors who expect the
        // list to only contain publishable items).
        if ($pageItem !== null && ($mode === self::MODE_ALL || $pageItem->isChanged)) {
            $items[] = $pageItem->toArray();
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

        if ($mode === self::MODE_ALL) {
            foreach ($this->listAllRecordsOnPage('tt_content', $pageUid, $workspaceId, $contentOrder) as $row) {
                $item = $this->buildItem('tt_content', $row, isPrimary: false, config: $config, columnLabels: $columnLabels);
                if ($item !== null) {
                    $items[] = $item->toArray();
                    if (count($items) >= $maxItems) break;
                }
            }
        } else {
            foreach ($this->resolveChangedRelated('tt_content', 'pid', $pageUid, $workspaceId, $config, $contentOrder, $columnLabels) as $item) {
                $items[] = $item->toArray();
                if (count($items) >= $maxItems) break;
            }
        }

        $hasNews = $this->tcaSchemaFactory->has('tx_news_domain_model_news');
        $enableNews = !isset($config['enableNewsBundles']) || (bool)$config['enableNewsBundles'];
        if ($hasNews && $enableNews && count($items) < $maxItems) {
            foreach ($this->resolveNewsItemsOnPage($pageUid, $workspaceId, $mode, $config) as $bundle) {
                if (count($items) >= $maxItems) break;
                $items[] = $bundle['news']->toArray();
                foreach ($bundle['contentElements'] as $ceItem) {
                    $items[] = $ceItem->toArray();
                    if (count($items) >= $maxItems) break 2;
                }
            }
        }

        return [
            'workspaceId' => $workspaceId,
            'workspaceTitle' => $workspaceTitle,
            'pageUid' => $pageUid,
            'items' => $items,
            'hasNews' => $hasNews,
            'mode' => $mode,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, workspaceTitle: string, newsUid: int, items: list<array<string, mixed>>, mode: string}
     */
    public function forNews(int $newsUid, string $mode = self::MODE_CHANGED, array $config = []): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        $workspaceTitle = $this->resolveWorkspaceTitle($workspaceId);
        if ($workspaceId <= 0 || $newsUid <= 0 || !$this->tcaSchemaFactory->has('tx_news_domain_model_news')) {
            return ['workspaceId' => $workspaceId, 'workspaceTitle' => $workspaceTitle, 'newsUid' => $newsUid, 'items' => [], 'mode' => $mode];
        }

        $maxItems = (int)($config['maxItems'] ?? 200);
        $items = [];
        $newsItem = $this->resolveRecordItem('tx_news_domain_model_news', $newsUid, $workspaceId, isPrimary: true, config: $config);
        if ($newsItem !== null && ($mode === self::MODE_ALL || $newsItem->isChanged)) {
            $items[] = $newsItem->toArray();
        }

        if ($mode === self::MODE_ALL) {
            foreach ($this->listAllRelatedRecords('tt_content', 'tx_news_related_news', $newsUid, $workspaceId, [['sorting', 'ASC']]) as $row) {
                $item = $this->buildItem('tt_content', $row, isPrimary: false, config: $config);
                if ($item !== null) {
                    $items[] = $item->toArray();
                    if (count($items) >= $maxItems) break;
                }
            }
        } else {
            foreach ($this->resolveChangedRelated('tt_content', 'tx_news_related_news', $newsUid, $workspaceId, $config) as $item) {
                $items[] = $item->toArray();
                if (count($items) >= $maxItems) break;
            }
        }

        return [
            'workspaceId' => $workspaceId,
            'workspaceTitle' => $workspaceTitle,
            'newsUid' => $newsUid,
            'items' => $items,
            'mode' => $mode,
        ];
    }

    /**
     * Reads the title field from sys_workspace; falls back to a
     * generic label for the live workspace or unknown ids.
     */
    /**
     * Count pending workspace versions across the whole workspace —
     * NOT scoped to a specific page. Used by the toolbar badge so
     * editors see "there's stuff to publish" regardless of which
     * page they have selected.
     *
     * Counts rows with `t3ver_wsid = current workspace AND deleted
     * = 0` across the same set of tables LatestChangesService uses
     * (pages, tt_content, tx_news_domain_model_news). Skips tables
     * absent from the TCA schema (e.g. tx_news on installs without
     * the extension).
     */
    public function countWorkspacePending(): int
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        if ($workspaceId <= 0) {
            return 0;
        }
        $tables = ['pages', 'tt_content', 'tx_news_domain_model_news'];
        $total = 0;
        foreach ($tables as $table) {
            if (!$this->tcaSchemaFactory->has($table)) {
                continue;
            }
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $count = (int)$qb
                ->count('uid')
                ->from($table)
                ->where(
                    $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchOne();
            $total += $count;
        }
        return $total;
    }

    private function resolveWorkspaceTitle(int $workspaceId): string
    {
        if ($workspaceId <= 0) {
            return 'Live';
        }
        $row = BackendUtility::getRecord('sys_workspace', $workspaceId);
        if (is_array($row) && !empty($row['title'])) {
            return (string)$row['title'];
        }
        return 'Workspace #' . $workspaceId;
    }

    /**
     * Get a sorted list of all records of $table belonging to $parentUid,
     * with workspace overlay applied. Returns the raw row arrays (each
     * row will contain _ORIG_uid when overlaid from a workspace version).
     *
     * @param list<array{0: string, 1: string}> $orderBy List of [column, direction] tuples.
     * @return list<array<string, mixed>>
     */
    private function listAllRecordsOnPage(string $table, int $pageUid, int $workspaceId, array $orderBy): array
    {
        return $this->listAllRelatedRecords($table, 'pid', $pageUid, $workspaceId, $orderBy);
    }

    /**
     * @param list<array{0: string, 1: string}> $orderBy List of [column, direction] tuples.
     * @return list<array<string, mixed>>
     */
    private function listAllRelatedRecords(string $table, string $field, int $parentUid, int $workspaceId, array $orderBy): array
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
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId, false));

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)));

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
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<PendingItem>
     */
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
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            );
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
    private function resolveNewsItemsOnPage(int $pageUid, int $workspaceId, string $mode, array $config = []): array
    {
        if ($mode === self::MODE_ALL) {
            $newsRows = $this->listAllRecordsOnPage('tx_news_domain_model_news', $pageUid, $workspaceId, [['datetime', 'DESC'], ['uid', 'ASC']]);
        } else {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder
                ->select('*')
                ->from('tx_news_domain_model_news')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery();
            $newsRows = [];
            while ($row = $result->fetchAssociative()) {
                $newsRows[] = $row;
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
                foreach ($this->listAllRelatedRecords('tt_content', 'tx_news_related_news', $liveUid, $workspaceId, [['sorting', 'ASC']]) as $ceRow) {
                    $ceItem = $this->buildItem('tt_content', $ceRow, isPrimary: false, config: $config);
                    if ($ceItem !== null) {
                        $childItems[] = $ceItem;
                    }
                }
            } else {
                foreach ($this->resolveChangedRelated('tt_content', 'tx_news_related_news', $liveUid, $workspaceId, $config) as $ceItem) {
                    $childItems[] = $ceItem;
                }
            }
            $bundles[] = ['news' => $newsItem, 'contentElements' => $childItems];
        }
        return $bundles;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveRecordItem(string $table, int $liveUid, int $workspaceId, bool $isPrimary, array $config = []): ?PendingItem
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // See listAllRelatedRecords for why $includeRowsForWorkspacePreview=false.
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId, false));

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
        return $this->buildItem($table, $row, $isPrimary, $config);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $config
     */
    /**
     * Public adapter for LatestChangesService — same logic as the
     * internal buildItem(), but always builds with isPrimary=false
     * since the latest-changes feed doesn't have a "primary record"
     * concept (each row stands on its own across pages).
     *
     * Kept as a thin wrapper rather than promoting buildItem itself
     * so the page/news flows can keep the isPrimary flag exclusive
     * to their internal use.
     *
     * @param array<string, mixed> $row    Raw workspace-version row.
     * @param array<string, mixed> $config Normalized ConfigurationProvider output.
     */
    public function buildItemFromRow(string $table, array $row, array $config = []): ?PendingItem
    {
        return $this->buildItem($table, $row, isPrimary: false, config: $config);
    }

    /**
     * @param array<int, string> $columnLabels colPos → name map; only consulted for tt_content rows. Empty falls back to the numeric colPos.
     */
    private function buildItem(string $table, array $row, bool $isPrimary, array $config = [], array $columnLabels = []): ?PendingItem
    {
        $rawUid = (int)($row['uid'] ?? 0);
        if ($rawUid <= 0) {
            return null;
        }

        $isHidden = (bool)($row['hidden'] ?? false);
        if ($isHidden && isset($config['showHidden']) && !$config['showHidden']) {
            return null;
        }

        $isChanged = isset($row['_ORIG_uid']) || (int)($row['t3ver_wsid'] ?? 0) > 0;
        // After workspaceOL the row's uid is the *live* uid; _ORIG_uid is the workspace version uid.
        if ($isChanged) {
            $workspaceUid = (int)($row['_ORIG_uid'] ?? $row['uid']);
            $liveUid = (int)($row['t3ver_oid'] ?? 0) ?: (int)$row['uid'];
        } else {
            $workspaceUid = $rawUid;
            $liveUid = $rawUid;
        }

        $title = $this->resolveTitle($table, $row);

        $state = VersionState::tryFrom((int)($row['t3ver_state'] ?? 0)) ?? VersionState::DEFAULT_STATE;
        if (!$isChanged) {
            $kindLabel = 'Live';
            $badge = 'secondary';
        } else {
            [$kindLabel, $badge] = match ($state) {
                VersionState::NEW_PLACEHOLDER => ['New', 'success'],
                VersionState::DELETE_PLACEHOLDER => ['Will be deleted', 'danger'],
                VersionState::MOVE_POINTER => ['Moved', 'warning'],
                default => ['Modified', 'info'],
            };
        }

        $enableThumbnails = !isset($config['enableThumbnails']) || (bool)$config['enableThumbnails'];
        // record_edit expects the *live* uid for existing records;
        // FormEngine handles the workspace overlay on save automatically.
        $editUrl = $this->buildEditUrl($table, $liveUid);

        // Attach the field-level diff so each row in the dropdown can
        // expand to show *what* changed. Only computed for actual
        // workspace versions; live rows have nothing to diff.
        $diff = $isChanged ? $this->recordDiffService->diff($table, $row) : [];

        // Resolve colPos info for tt_content rows so the frontend
        // can group items by page column with proper labels (e.g.
        // "Hero area" / "Content area"). Null for other tables.
        $colPos = null;
        $colPosLabel = null;
        if ($table === 'tt_content' && array_key_exists('colPos', $row)) {
            $colPos = (int)$row['colPos'];
            $colPosLabel = $columnLabels[$colPos] ?? null;
            if ($colPosLabel === null || $colPosLabel === '') {
                $colPosLabel = sprintf('Column %d', $colPos);
            }
        }

        return new PendingItem(
            table: $table,
            liveUid: $liveUid,
            workspaceUid: $workspaceUid,
            title: $title,
            kindLabel: $kindLabel,
            badge: $badge,
            thumbnailUrl: $enableThumbnails ? $this->resolveThumbnailUrl($table, $workspaceUid) : null,
            isPrimary: $isPrimary,
            isChanged: $isChanged,
            isHidden: $isHidden,
            tableLabel: $this->resolveTableLabel($table),
            typeLabel: $this->resolveTypeLabel($table, $row),
            editUrl: $editUrl,
            diff: $diff,
            colPos: $colPos,
            colPosLabel: $colPosLabel,
        );
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
            $resolved = $languageService->sL((string)$rawLabel);
            $out[(int)$colPos] = $resolved !== '' ? $resolved : (string)$rawLabel;
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
        $title = trim((string)BackendUtility::getRecordTitle($table, $row));
        if ($title !== '' && !str_starts_with($title, '[no title]')) {
            return $title;
        }
        if ($table === 'tt_content' && isset($row['bodytext'])) {
            $fallback = $this->extractTextSnippet((string)$row['bodytext']);
            if ($fallback !== '') {
                return $fallback;
            }
        }
        $typeLabel = $this->resolveTypeLabel($table, $row);
        if ($typeLabel !== '') {
            return $typeLabel . ' · #' . (int)$row['uid'];
        }
        return $table . ' #' . (int)$row['uid'];
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
        $typeField = $schema->getSubSchemaTypeInformation()?->getFieldName();

        // No discriminator field — fall back to the schema's own title.
        if ($typeField === null || !isset($row[$typeField])) {
            $title = $schema->getRawConfiguration()['ctrl']['title'] ?? $table;
            return (string)$this->getLanguageService()->sL($title);
        }

        $value = (string)$row[$typeField];
        $label = BackendUtility::getLabelFromItemlist($table, $typeField, $value);
        if (is_string($label) && $label !== '') {
            return (string)$this->getLanguageService()->sL($label);
        }
        // Last resort — the raw value (still better than nothing).
        return $value;
    }

    private function resolveThumbnailUrl(string $table, int $workspaceUid): ?string
    {
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
        return is_array($row) ? (int)$row['uid_local'] : 0;
    }

    private function referenceToUrl(int $fileUid): ?string
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            if (!str_starts_with($file->getMimeType(), 'image/')) {
                return null;
            }
            $publicUrl = $file->getPublicUrl();
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
        $lang = is_string($GLOBALS['BE_USER']->user['lang'] ?? null) && $GLOBALS['BE_USER']->user['lang'] !== ''
            ? (string)$GLOBALS['BE_USER']->user['lang']
            : 'default';
        return $this->languageServiceFactory->create($lang);
    }
}
