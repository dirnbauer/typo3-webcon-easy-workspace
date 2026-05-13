<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Versioning\VersionState;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;

/**
 * Collects the list of records that have pending workspace changes
 * tied to a single page context (and any news on that page).
 *
 * Goal: small, focused payload for the toolbar dropdown — page record,
 * its content elements, optionally news records pinned on the page, and
 * for news records also their linked tt_content via tx_news_related_news.
 */
final readonly class PendingItemsService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private ResourceFactory $resourceFactory,
        private Context $context,
    ) {}

    /**
     * @return array{workspaceId: int, pageUid: int, items: list<array<string, mixed>>, hasNews: bool}
     */
    public function forPage(int $pageUid): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        $items = [];

        if ($workspaceId <= 0 || $pageUid <= 0) {
            return ['workspaceId' => $workspaceId, 'pageUid' => $pageUid, 'items' => [], 'hasNews' => false];
        }

        $pageItem = $this->resolvePageItem($pageUid, $workspaceId);
        if ($pageItem !== null) {
            $items[] = $pageItem->toArray();
        }

        foreach ($this->resolveContentItems('tt_content', 'pid', $pageUid, $workspaceId) as $item) {
            $items[] = $item->toArray();
        }

        $hasNews = $this->tcaSchemaFactory->has('tx_news_domain_model_news');
        if ($hasNews) {
            foreach ($this->resolveNewsItemsOnPage($pageUid, $workspaceId) as $newsItem) {
                $items[] = $newsItem['news']->toArray();
                foreach ($newsItem['contentElements'] as $ceItem) {
                    $items[] = $ceItem->toArray();
                }
            }
        }

        return [
            'workspaceId' => $workspaceId,
            'pageUid' => $pageUid,
            'items' => $items,
            'hasNews' => $hasNews,
        ];
    }

    /**
     * @return array{workspaceId: int, newsUid: int, items: list<array<string, mixed>>}
     */
    public function forNews(int $newsUid): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        if ($workspaceId <= 0 || $newsUid <= 0 || !$this->tcaSchemaFactory->has('tx_news_domain_model_news')) {
            return ['workspaceId' => $workspaceId, 'newsUid' => $newsUid, 'items' => []];
        }

        $items = [];
        $newsItem = $this->resolveRecordItem('tx_news_domain_model_news', $newsUid, $workspaceId, isPrimary: true);
        if ($newsItem !== null) {
            $items[] = $newsItem->toArray();
        }
        foreach ($this->resolveContentItems('tt_content', 'tx_news_related_news', $newsUid, $workspaceId) as $ceItem) {
            $items[] = $ceItem->toArray();
        }

        return [
            'workspaceId' => $workspaceId,
            'newsUid' => $newsUid,
            'items' => $items,
        ];
    }

    private function resolvePageItem(int $pageUid, int $workspaceId): ?PendingItem
    {
        return $this->resolveRecordItem('pages', $pageUid, $workspaceId, isPrimary: true);
    }

    /**
     * @return list<PendingItem>
     */
    private function resolveContentItems(string $table, string $relationField, int $parentUid, int $workspaceId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->select('uid', 't3ver_oid', 't3ver_wsid', 't3ver_state', 'sorting')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($relationField, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
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
    private function resolveNewsItemsOnPage(int $pageUid, int $workspaceId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->select('uid', 't3ver_oid', 't3ver_wsid', 't3ver_state')
            ->from('tx_news_domain_model_news')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery();

        $bundles = [];
        while ($row = $result->fetchAssociative()) {
            $newsItem = $this->buildItem('tx_news_domain_model_news', $row, isPrimary: true);
            if ($newsItem === null) {
                continue;
            }
            $liveUid = $newsItem->liveUid;
            $childItems = $this->resolveContentItems('tt_content', 'tx_news_related_news', $liveUid, $workspaceId);
            $bundles[] = ['news' => $newsItem, 'contentElements' => $childItems];
        }
        return $bundles;
    }

    private function resolveRecordItem(string $table, int $liveUid, int $workspaceId, bool $isPrimary): ?PendingItem
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid', 't3ver_oid', 't3ver_wsid', 't3ver_state')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            // Could still be a "new in workspace" record
            $newQuery = $this->connectionPool->getQueryBuilderForTable($table);
            $newQuery->getRestrictions()->removeAll();
            $row = $newQuery
                ->select('uid', 't3ver_oid', 't3ver_wsid', 't3ver_state')
                ->from($table)
                ->where(
                    $newQuery->expr()->eq('uid', $newQuery->createNamedParameter($liveUid, Connection::PARAM_INT)),
                    $newQuery->expr()->eq('t3ver_wsid', $newQuery->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                    $newQuery->expr()->eq('t3ver_state', $newQuery->createNamedParameter(VersionState::NEW_PLACEHOLDER->value, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchAssociative();
            if (!is_array($row)) {
                return null;
            }
        }
        return $this->buildItem($table, $row, $isPrimary);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildItem(string $table, array $row, bool $isPrimary): ?PendingItem
    {
        $workspaceUid = (int)($row['uid'] ?? 0);
        if ($workspaceUid <= 0) {
            return null;
        }
        $liveUid = (int)($row['t3ver_oid'] ?? 0) ?: $workspaceUid;

        // Re-fetch the full workspace row for title resolution + image lookup.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $fullRow = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($fullRow)) {
            return null;
        }

        $title = (string)BackendUtility::getRecordTitle($table, $fullRow);
        if ($title === '') {
            $title = $table . ' #' . $workspaceUid;
        }

        $state = VersionState::tryFrom((int)($row['t3ver_state'] ?? 0)) ?? VersionState::DEFAULT_STATE;
        [$kindLabel, $badge] = match ($state) {
            VersionState::NEW_PLACEHOLDER => ['New', 'success'],
            VersionState::DELETE_PLACEHOLDER => ['Will be deleted', 'danger'],
            VersionState::MOVE_POINTER => ['Moved', 'warning'],
            default => ['Modified', 'info'],
        };

        $thumbnailUrl = $this->resolveThumbnailUrl($table, $workspaceUid);

        return new PendingItem(
            table: $table,
            liveUid: $liveUid,
            workspaceUid: $workspaceUid,
            title: $title,
            kindLabel: $kindLabel,
            badge: $badge,
            thumbnailUrl: $thumbnailUrl,
            isPrimary: $isPrimary,
        );
    }

    /**
     * Returns the public URL of the first image referenced by the record,
     * or null if no image is attached.
     */
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
}
