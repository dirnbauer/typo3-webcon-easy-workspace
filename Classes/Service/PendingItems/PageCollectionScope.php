<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

/**
 * Resolved database scope for a page — shared by change probes and full collection.
 */
final readonly class PageCollectionScope
{
    /**
     * @param array<string, mixed>|null $pageRow
     * @param list<array<string, mixed>> $contentRows
     */
    public function __construct(
        public int $pageRecordUid,
        public ?array $pageRow,
        public array $contentRows,
    ) {}
}
