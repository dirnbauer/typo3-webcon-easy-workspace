<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItemsPayload;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Enum\ToolbarContext;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\PendingItemsCollector;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\WorkspaceRecordQuery;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Collects records visible in the toolbar dropdown for a given page or
 * news context. See PendingItemsMode for the changed/all filter
 * semantics.
 */
final readonly class PendingItemsService
{
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
    public function forPage(int $pageUid, PendingItemsMode $mode = PendingItemsMode::Changed, array $config = [], ?int $languageUid = null): array
    {
        return $this->payloadForPage($pageUid, $mode, $config, $languageUid)->toPageArray();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function payloadForPage(int $pageUid, PendingItemsMode $mode = PendingItemsMode::All, array $config = [], ?int $languageUid = null): PendingItemsPayload
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
    public function payloadForNews(int $newsUid, PendingItemsMode $mode = PendingItemsMode::All, array $config = [], ?int $languageUid = null): PendingItemsPayload
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
    public function forNews(int $newsUid, PendingItemsMode $mode = PendingItemsMode::Changed, array $config = [], ?int $languageUid = null): array
    {
        return $this->payloadForNews($newsUid, $mode, $config, $languageUid)->toNewsArray();
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

    /**
     * @param array<string, mixed> $config
     * @return array{context: ToolbarContext, payload: PendingItemsPayload|null}
     */
    public function toolbarCollectionForContext(
        int $pageUid,
        int $newsUid,
        PendingItemsMode $mode,
        array $config,
        ?int $languageUid = null,
    ): array {
        $context = ToolbarContext::resolve($pageUid, $newsUid);

        return [
            'context' => $context,
            'payload' => match ($context) {
                ToolbarContext::News => $this->payloadForNews($newsUid, $mode, $config, $languageUid),
                ToolbarContext::Page => $this->payloadForPage($pageUid, $mode, $config, $languageUid),
                ToolbarContext::None => null,
            },
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{context: string, workspaceId: int, hasChanges: bool, pageUid?: int, newsUid?: int, languageUid?: int|null}
     */
    public function hasChangesForContext(int $pageUid, int $newsUid, array $config, ?int $languageUid = null): array
    {
        $context = ToolbarContext::resolve($pageUid, $newsUid);

        return match ($context) {
            ToolbarContext::News => [
                'context' => $context->value,
                ...$this->hasChangesForNews($newsUid, $config, $languageUid),
            ],
            ToolbarContext::Page => [
                'context' => $context->value,
                ...$this->hasChangesForPage($pageUid, $config, $languageUid),
            ],
            ToolbarContext::None => [
                'context' => $context->value,
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
        PendingItemsMode $mode,
        array $config,
        ?int $languageUid = null,
    ): ?array {
        return match (ToolbarContext::resolve($pageUid, $newsUid)) {
            ToolbarContext::News => $this->forNews($newsUid, $mode, $config, $languageUid),
            ToolbarContext::Page => $this->forPage($pageUid, $mode, $config, $languageUid),
            ToolbarContext::None => null,
        };
    }
}
