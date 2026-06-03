<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

/**
 * Resolved database scope for a news record — shared by change probes and full collection.
 */
final readonly class NewsCollectionScope
{
    /**
     * @param list<array<string, mixed>> $relatedContentRows
     */
    public function __construct(
        public int $newsRecordUid,
        public array $relatedContentRows,
    ) {}
}
