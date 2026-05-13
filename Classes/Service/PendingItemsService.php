<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
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
        private Context $context,
    ) {}

    /**
     * @return array{workspaceId: int, pageUid: int, items: list<array<string, mixed>>, hasNews: bool, mode: string}
     */
    public function forPage(int $pageUid, string $mode = self::MODE_CHANGED): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        if ($workspaceId <= 0 || $pageUid <= 0) {
            return ['workspaceId' => $workspaceId, 'pageUid' => $pageUid, 'items' => [], 'hasNews' => false, 'mode' => $mode];
        }

        $items = [];

        $pageItem = $this->resolveRecordItem('pages', $pageUid, $workspaceId, isPrimary: true);
        if ($pageItem !== null) {
            $items[] = $pageItem->toArray();
        }

        if ($mode === self::MODE_ALL) {
            foreach ($this->listAllRecordsOnPage('tt_content', $pageUid, $workspaceId, 'sorting') as $row) {
                $item = $this->buildItem('tt_content', $row, isPrimary: false);
                if ($item !== null) {
                    $items[] = $item->toArray();
                }
            }
        } else {
            foreach ($this->resolveChangedRelated('tt_content', 'pid', $pageUid, $workspaceId) as $item) {
                $items[] = $item->toArray();
            }
        }

        $hasNews = $this->tcaSchemaFactory->has('tx_news_domain_model_news');
        if ($hasNews) {
            foreach ($this->resolveNewsItemsOnPage($pageUid, $workspaceId, $mode) as $bundle) {
                $items[] = $bundle['news']->toArray();
                foreach ($bundle['contentElements'] as $ceItem) {
                    $items[] = $ceItem->toArray();
                }
            }
        }

        return [
            'workspaceId' => $workspaceId,
            'pageUid' => $pageUid,
            'items' => $items,
            'hasNews' => $hasNews,
            'mode' => $mode,
        ];
    }

    /**
     * @return array{workspaceId: int, newsUid: int, items: list<array<string, mixed>>, mode: string}
     */
    public function forNews(int $newsUid, string $mode = self::MODE_CHANGED): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        if ($workspaceId <= 0 || $newsUid <= 0 || !$this->tcaSchemaFactory->has('tx_news_domain_model_news')) {
            return ['workspaceId' => $workspaceId, 'newsUid' => $newsUid, 'items' => [], 'mode' => $mode];
        }

        $items = [];
        $newsItem = $this->resolveRecordItem('tx_news_domain_model_news', $newsUid, $workspaceId, isPrimary: true);
        if ($newsItem !== null) {
            $items[] = $newsItem->toArray();
        }

        if ($mode === self::MODE_ALL) {
            foreach ($this->listAllRelatedRecords('tt_content', 'tx_news_related_news', $newsUid, $workspaceId, 'sorting') as $row) {
                $item = $this->buildItem('tt_content', $row, isPrimary: false);
                if ($item !== null) {
                    $items[] = $item->toArray();
                }
            }
        } else {
            foreach ($this->resolveChangedRelated('tt_content', 'tx_news_related_news', $newsUid, $workspaceId) as $item) {
                $items[] = $item->toArray();
            }
        }

        return ['workspaceId' => $workspaceId, 'newsUid' => $newsUid, 'items' => $items, 'mode' => $mode];
    }

    /**
     * Get a sorted list of all records of $table belonging to $parentUid,
     * with workspace overlay applied. Returns the raw row arrays (each
     * row will contain _ORIG_uid when overlaid from a workspace version).
     *
     * @return list<array<string, mixed>>
     */
    private function listAllRecordsOnPage(string $table, int $pageUid, int $workspaceId, string $orderBy): array
    {
        return $this->listAllRelatedRecords($table, 'pid', $pageUid, $workspaceId, $orderBy);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAllRelatedRecords(string $table, string $field, int $parentUid, int $workspaceId, string $orderBy): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // FrontendRestrictionContainer would filter hidden — we want to *include* hidden so the badge can be shown.
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId, true));

        $result = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)))
            ->orderBy($orderBy, 'ASC')
            ->executeQuery();

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
     * @return list<PendingItem>
     */
    private function resolveChangedRelated(string $table, string $field, int $parentUid, int $workspaceId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery();

        $items = [];
        while ($row = $result->fetchAssociative()) {
            $item = $this->buildItem($table, $row, isPrimary: false);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * @return list<array{news: PendingItem, contentElements: list<PendingItem>}>
     */
    private function resolveNewsItemsOnPage(int $pageUid, int $workspaceId, string $mode): array
    {
        if ($mode === self::MODE_ALL) {
            $newsRows = $this->listAllRecordsOnPage('tx_news_domain_model_news', $pageUid, $workspaceId, 'datetime DESC, uid');
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
            $newsItem = $this->buildItem('tx_news_domain_model_news', $newsRow, isPrimary: true);
            if ($newsItem === null) {
                continue;
            }
            $liveUid = $newsItem->liveUid;
            $childItems = [];
            if ($mode === self::MODE_ALL) {
                foreach ($this->listAllRelatedRecords('tt_content', 'tx_news_related_news', $liveUid, $workspaceId, 'sorting') as $ceRow) {
                    $ceItem = $this->buildItem('tt_content', $ceRow, isPrimary: false);
                    if ($ceItem !== null) {
                        $childItems[] = $ceItem;
                    }
                }
            } else {
                foreach ($this->resolveChangedRelated('tt_content', 'tx_news_related_news', $liveUid, $workspaceId) as $ceItem) {
                    $childItems[] = $ceItem;
                }
            }
            $bundles[] = ['news' => $newsItem, 'contentElements' => $childItems];
        }
        return $bundles;
    }

    private function resolveRecordItem(string $table, int $liveUid, int $workspaceId, bool $isPrimary): ?PendingItem
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId, true));

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
        return $this->buildItem($table, $row, $isPrimary);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildItem(string $table, array $row, bool $isPrimary): ?PendingItem
    {
        $rawUid = (int)($row['uid'] ?? 0);
        if ($rawUid <= 0) {
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

        return new PendingItem(
            table: $table,
            liveUid: $liveUid,
            workspaceUid: $workspaceUid,
            title: $title,
            kindLabel: $kindLabel,
            badge: $badge,
            thumbnailUrl: $this->resolveThumbnailUrl($table, $workspaceUid),
            isPrimary: $isPrimary,
            isChanged: $isChanged,
            isHidden: (bool)($row['hidden'] ?? false),
            typeLabel: $this->resolveTypeLabel($table, $row),
        );
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
     * @param array<string, mixed> $row
     */
    private function resolveTypeLabel(string $table, array $row): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return $table;
        }
        $schema = $this->tcaSchemaFactory->get($table);
        $typeField = $schema->getSubSchemaTypeInformation()?->getFieldName();
        if ($typeField === null || !isset($row[$typeField])) {
            $title = $schema->getRawConfiguration()['ctrl']['title'] ?? $table;
            return (string)$this->getLanguageService()->sL($title);
        }
        $value = (string)$row[$typeField];
        $items = $schema->getRawConfiguration()['columns'][$typeField]['config']['items'] ?? [];
        foreach ($items as $item) {
            if ((string)($item['value'] ?? '') === $value) {
                return (string)$this->getLanguageService()->sL((string)($item['label'] ?? $value));
            }
        }
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
