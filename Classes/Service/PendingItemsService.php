<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItemsPayload;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\PendingItemsCollector;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\WorkspaceRecordQuery;
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

    public const CONTEXT_NONE = 'none';
    public const CONTEXT_PAGE = 'page';
    public const CONTEXT_NEWS = 'news';

    public function __construct(
        private Context $context,
        private TcaSchemaFactory $tcaSchemaFactory,
        private WorkspaceRecordQuery $workspaceRecordQuery,
        private PendingItemsCollector $pendingItemsCollector,
    ) {}

    /**
     * @param array<string, mixed> $config Normalized config from ConfigurationProvider.
     * @return array{workspaceId: int, workspaceTitle: string, pageUid: int, languageUid: int|null, items: list<array<string, mixed>>, itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, hasNews: bool, mode: string}
     */
    public function forPage(int $pageUid, string $mode = self::MODE_CHANGED, array $config = [], ?int $languageUid = null): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        $workspaceTitle = $this->workspaceRecordQuery->resolveWorkspaceTitle($workspaceId);
        if ($workspaceId <= 0 || $pageUid <= 0) {
            return $this->emptyPagePayload($workspaceId, $workspaceTitle, $pageUid, $languageUid, $mode);
        }

        return $this->pendingItemsCollector->forPage(
            $pageUid,
            $workspaceId,
            $workspaceTitle,
            $mode,
            $config,
            $languageUid,
            $this->tcaSchemaFactory->has('tx_news_domain_model_news'),
        )->toPageClientArray(includeDiff: true);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function payloadForPage(int $pageUid, string $mode = self::MODE_ALL, array $config = [], ?int $languageUid = null): PendingItemsPayload
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        $workspaceTitle = $this->workspaceRecordQuery->resolveWorkspaceTitle($workspaceId);
        if ($workspaceId <= 0 || $pageUid <= 0) {
            return new PendingItemsPayload(
                workspaceId: $workspaceId,
                workspaceTitle: $workspaceTitle,
                mode: $mode,
                items: [],
                itemGroups: [],
                changedItemGroups: [],
                pageUid: $pageUid,
                languageUid: $languageUid,
                hasNews: false,
            );
        }

        return $this->pendingItemsCollector->forPage(
            $pageUid,
            $workspaceId,
            $workspaceTitle,
            $mode,
            $config,
            $languageUid,
            $this->tcaSchemaFactory->has('tx_news_domain_model_news'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public function payloadForNews(int $newsUid, string $mode = self::MODE_ALL, array $config = [], ?int $languageUid = null): PendingItemsPayload
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        $workspaceTitle = $this->workspaceRecordQuery->resolveWorkspaceTitle($workspaceId);
        if ($workspaceId <= 0 || $newsUid <= 0 || !$this->tcaSchemaFactory->has('tx_news_domain_model_news')) {
            return new PendingItemsPayload(
                workspaceId: $workspaceId,
                workspaceTitle: $workspaceTitle,
                mode: $mode,
                items: [],
                itemGroups: [],
                changedItemGroups: [],
                newsUid: $newsUid,
                languageUid: $languageUid,
            );
        }

        return $this->pendingItemsCollector->forNews(
            $newsUid,
            $workspaceId,
            $workspaceTitle,
            $mode,
            $config,
            $languageUid,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, workspaceTitle: string, newsUid: int, languageUid: int|null, items: list<array<string, mixed>>, itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, mode: string}
     */
    public function forNews(int $newsUid, string $mode = self::MODE_CHANGED, array $config = [], ?int $languageUid = null): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        $workspaceTitle = $this->workspaceRecordQuery->resolveWorkspaceTitle($workspaceId);
        if ($workspaceId <= 0 || $newsUid <= 0 || !$this->tcaSchemaFactory->has('tx_news_domain_model_news')) {
            return $this->emptyNewsPayload($workspaceId, $workspaceTitle, $newsUid, $languageUid, $mode);
        }

        return $this->pendingItemsCollector->forNews(
            $newsUid,
            $workspaceId,
            $workspaceTitle,
            $mode,
            $config,
            $languageUid,
        )->toNewsClientArray(includeDiff: true);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, pageUid: int, languageUid: int|null, hasChanges: bool}
     */
    public function hasChangesForPage(int $pageUid, array $config = [], ?int $languageUid = null): array
    {
        return $this->pendingItemsCollector->hasChangesForPage($pageUid, $config, $languageUid);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{workspaceId: int, newsUid: int, languageUid: int|null, hasChanges: bool}
     */
    public function hasChangesForNews(int $newsUid, array $config = [], ?int $languageUid = null): array
    {
        return $this->pendingItemsCollector->hasChangesForNews($newsUid, $config, $languageUid);
    }

    public function resolveContext(int $pageUid, int $newsUid): string
    {
        if ($newsUid > 0) {
            return self::CONTEXT_NEWS;
        }
        if ($pageUid > 0) {
            return self::CONTEXT_PAGE;
        }

        return self::CONTEXT_NONE;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{context: string, payload: PendingItemsPayload|null}
     */
    public function toolbarCollectionForContext(
        int $pageUid,
        int $newsUid,
        string $mode,
        array $config,
        ?int $languageUid = null,
    ): array {
        $context = $this->resolveContext($pageUid, $newsUid);

        return match ($context) {
            self::CONTEXT_NEWS => [
                'context' => $context,
                'payload' => $this->payloadForNews($newsUid, $mode, $config, $languageUid),
            ],
            self::CONTEXT_PAGE => [
                'context' => $context,
                'payload' => $this->payloadForPage($pageUid, $mode, $config, $languageUid),
            ],
            default => [
                'context' => self::CONTEXT_NONE,
                'payload' => null,
            ],
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array{context: string, workspaceId: int, hasChanges: bool, pageUid?: int, newsUid?: int, languageUid?: int|null}
     */
    public function hasChangesForContext(int $pageUid, int $newsUid, array $config, ?int $languageUid = null): array
    {
        $context = $this->resolveContext($pageUid, $newsUid);

        return match ($context) {
            self::CONTEXT_NEWS => [
                'context' => $context,
                ...$this->hasChangesForNews($newsUid, $config, $languageUid),
            ],
            self::CONTEXT_PAGE => [
                'context' => $context,
                ...$this->hasChangesForPage($pageUid, $config, $languageUid),
            ],
            default => [
                'context' => self::CONTEXT_NONE,
                'workspaceId' => 0,
                'hasChanges' => false,
            ],
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null Serialized page or news list, or null when no context.
     */
    public function listForContext(
        int $pageUid,
        int $newsUid,
        string $mode,
        array $config,
        ?int $languageUid = null,
    ): ?array {
        $context = $this->resolveContext($pageUid, $newsUid);

        return match ($context) {
            self::CONTEXT_NEWS => $this->forNews($newsUid, $mode, $config, $languageUid),
            self::CONTEXT_PAGE => $this->forPage($pageUid, $mode, $config, $languageUid),
            default => null,
        };
    }

    /**
     * @return array{workspaceId: int, workspaceTitle: string, pageUid: int, languageUid: int|null, items: list<array<string, mixed>>, itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, hasNews: bool, mode: string}
     */
    private function emptyPagePayload(int $workspaceId, string $workspaceTitle, int $pageUid, ?int $languageUid, string $mode): array
    {
        return (new PendingItemsPayload(
            workspaceId: $workspaceId,
            workspaceTitle: $workspaceTitle,
            mode: $mode,
            items: [],
            itemGroups: [],
            changedItemGroups: [],
            pageUid: $pageUid,
            languageUid: $languageUid,
            hasNews: false,
        ))->toPageArray();
    }

    /**
     * @return array{workspaceId: int, workspaceTitle: string, newsUid: int, languageUid: int|null, items: list<array<string, mixed>>, itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>, mode: string}
     */
    private function emptyNewsPayload(int $workspaceId, string $workspaceTitle, int $newsUid, ?int $languageUid, string $mode): array
    {
        return (new PendingItemsPayload(
            workspaceId: $workspaceId,
            workspaceTitle: $workspaceTitle,
            mode: $mode,
            items: [],
            itemGroups: [],
            changedItemGroups: [],
            newsUid: $newsUid,
            languageUid: $languageUid,
        ))->toNewsArray();
    }
}
