<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;

final readonly class PendingItemUrlBuilder
{
    public function __construct(
        private BackendUriBuilder $backendUriBuilder,
    ) {}

    public function buildEditUrl(string $table, int $uid): ?string
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

    public function buildContextualEditUrl(string $table, int $uid): ?string
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

    public function buildRecordHistoryUrl(string $table, int $uid): ?string
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
}
