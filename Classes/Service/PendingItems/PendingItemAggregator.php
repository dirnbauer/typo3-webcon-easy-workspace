<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Webconsulting\WebconEasyWorkspace\Dto\PendingChangeRecord;
use Webconsulting\WebconEasyWorkspace\Dto\PendingChildChange;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Dto\PendingRecordReference;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;

final readonly class PendingItemAggregator
{
    public function __construct(
        private PendingItemFactory $pendingItemFactory,
        private PendingItemTimelineResolver $timelineResolver,
        private InlineChildResolver $inlineChildResolver,
        private WorkspaceRecordQuery $workspaceRecordQuery,
        private LocalizationService $localizationService,
    ) {}

    /**
     * @param list<PendingItem> $items
     * @return list<PendingItem>
     */
    public function changedItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (PendingItem $item): bool => $item->isChanged,
        ));
    }

    /**
     * @param list<PendingItem> $items
     * @return list<array{key: string, label: string|null, items: list<PendingItem>}>
     */
    public function groupItems(array $items): array
    {
        $groups = [];
        $primaryItems = [];

        foreach ($items as $item) {
            if ($item->table !== 'tt_content' || $item->colPos === null) {
                $primaryItems[] = $item;
                continue;
            }

            $key = 'column:' . $item->colPos;
            if (!isset($groups[$key])) {
                $label = $item->colPosLabel ?? '';
                if ($label === '') {
                    $label = $this->localizationService->translate('toolbar.column', ['number' => $item->colPos]);
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
     * @param list<PendingItem> $items
     * @return list<PendingItem>
     */
    public function deduplicateItems(array $items): array
    {
        $seen = [];
        $deduplicated = [];
        foreach ($items as $item) {
            $identityUid = $item->identityUid();
            if ($item->table === '' || $identityUid <= 0) {
                $deduplicated[] = $item;
                continue;
            }
            $key = $item->table . ':' . $identityUid;
            if (isset($seen[$key])) {
                $index = $seen[$key];
                $deduplicated[$index] = $this->mergeItems($deduplicated[$index], $item);
                continue;
            }
            $seen[$key] = count($deduplicated);
            $deduplicated[] = $item;
        }
        return array_values($deduplicated);
    }

    /**
     * @param list<PendingItem> $relatedItems
     */
    public function withRelatedChanges(PendingItem $item, array $relatedItems): PendingItem
    {
        foreach ($relatedItems as $relatedItem) {
            $item = $this->mergeItems($item, $relatedItem);
        }
        return $item;
    }

    /**
     * @param list<PendingItem> $items
     * @param array<string, mixed> $config
     * @param array<int, string> $columnLabels
     * @return list<PendingItem>
     */
    public function withInlineChildParents(
        array $items,
        string $parentTable,
        int $pageUid,
        int $workspaceId,
        PendingItemsMode $mode,
        array $config,
        array $columnLabels,
        ?int $languageUid,
        int $maxItems,
    ): array {
        if ($workspaceId <= 0 || $pageUid <= 0) {
            return $items;
        }

        $items = array_values($items);

        foreach ($this->inlineChildResolver->resolveChangedInlineChildItemsOnPage($parentTable, $pageUid, $workspaceId, $config, $columnLabels, $languageUid) as $parentUid => $childItems) {
            if ($childItems === []) {
                continue;
            }
            $index = $this->findItemIndexByRecordIdentity($items, $parentTable, $parentUid);
            if ($index === null) {
                if (count($items) >= $maxItems) {
                    break;
                }
                $parentItem = $this->inlineChildResolver->resolveInlineChildParentItem($parentTable, $parentUid, $workspaceId, $config, $columnLabels);
                if (!$parentItem instanceof PendingItem) {
                    continue;
                }
                $item = $parentItem;
            } else {
                $item = $items[$index];
            }

            $item = $this->withRelatedChanges($item, $childItems);
            if ($mode->includesUnchanged() || $item->isChanged) {
                if ($index === null) {
                    $items[] = $item;
                } else {
                    $items[$index] = $item;
                }
            }
        }

        return array_values($items);
    }

    /**
     * @param list<PendingItem> $items
     * @param array<string, mixed> $config
     * @return list<PendingItem>
     */
    public function withStandaloneWorkspaceItems(array $items, int $workspaceId, array $config, int $maxItems): array
    {
        if ($workspaceId <= 0 || count($items) >= $maxItems) {
            return $items;
        }

        foreach (WorkspaceRecordQuery::STANDALONE_WORKSPACE_TABLES as $table) {
            foreach ($this->workspaceRecordQuery->listStandaloneWorkspaceRows($table, $workspaceId, $maxItems - count($items)) as $row) {
                $item = $this->pendingItemFactory->buildItem($table, $row, isPrimary: false, config: $config);
                if ($item instanceof PendingItem) {
                    $items[] = $item;
                }
                if (count($items) >= $maxItems) {
                    break 2;
                }
            }
        }

        return $items;
    }

    /**
     * @param array<int, PendingItem> $items
     */
    public function findItemIndexByRecordIdentity(array $items, string $table, int $uid): ?int
    {
        foreach ($items as $index => $item) {
            if ($item->table === $table && ($item->liveUid === $uid || $item->workspaceUid === $uid)) {
                return $index;
            }
        }
        return null;
    }

    public function mergeItems(PendingItem $base, PendingItem $incoming): PendingItem
    {
        $incomingChanged = $incoming->isChanged;

        if (
            $base->table === $incoming->table
            && $base->liveUid === $incoming->liveUid
            && $incomingChanged
            && (!$base->isChanged || $incoming->workspaceUid > $base->workspaceUid)
        ) {
            $preservedBadges = $base->changeBadges;
            $preservedPublishRecords = $this->withoutConceptualPublishRecords($base->publishRecords, $incoming->table, $incoming->liveUid);
            $preservedChangeRecords = $this->withoutConceptualChangeRecords($base->changeRecords, $incoming->table, $incoming->liveUid);
            $base = $this->replaceCoreFields($base, $incoming);
            $base = $this->withAggregatedFields(
                $base,
                isChanged: true,
                childChanges: $base->childChanges,
                changeBadges: $preservedBadges,
                publishRecords: $preservedPublishRecords,
                changeRecords: $preservedChangeRecords,
            );
        }

        $childChanges = $this->mergeChildChanges(
            $base->childChanges,
            $incomingChanged && $incoming->table !== $base->table
                ? [PendingChildChange::fromPendingItem($incoming)]
                : $incoming->childChanges,
        );

        return $this->withAggregatedFields(
            $base,
            isChanged: $base->isChanged || $incomingChanged,
            childChanges: $childChanges,
            changeBadges: $this->mergeChangeBadges($base->changeBadges, $incoming->changeBadges),
            publishRecords: $this->mergeRecordReferences($base->publishRecords, $incoming->publishRecords),
            changeRecords: $this->mergeChangeRecords($base->changeRecords, $incoming->changeRecords),
        );
    }

    /**
     * @param list<PendingChildChange> $base
     * @param list<PendingChildChange> $incoming
     * @return list<PendingChildChange>
     */
    private function mergeChildChanges(array $base, array $incoming): array
    {
        $merged = [];
        foreach ([...$base, ...$incoming] as $child) {
            $merged[$child->table . ':' . $child->workspaceUid] = $child;
        }
        return array_values($merged);
    }

    /**
     * @param list<array{kindKey: string, kindLabel: string, badge: string}> $base
     * @param list<array{kindKey: string, kindLabel: string, badge: string}> $incoming
     * @return list<array{kindKey: string, kindLabel: string, badge: string}>
     */
    private function mergeChangeBadges(array $base, array $incoming): array
    {
        $merged = [];
        foreach ([...$base, ...$incoming] as $badge) {
            $kindKey = $this->timelineResolver->normalizeChangeBadgeKey((string)($badge['kindKey'] ?? ''));
            $kindLabel = (string)($badge['kindLabel'] ?? '');
            $identity = $kindKey !== '' ? $kindKey : mb_strtolower($kindLabel);
            if ($kindKey === '' || $identity === '' || isset($merged[$identity])) {
                continue;
            }
            $merged[$identity] = [
                'kindKey' => $kindKey,
                'kindLabel' => $kindLabel,
                'badge' => (string)($badge['badge'] ?? '') ?: 'info',
            ];
        }
        return array_values($merged);
    }

    /**
     * @param list<PendingRecordReference> $base
     * @param list<PendingRecordReference> $incoming
     * @return list<PendingRecordReference>
     */
    private function mergeRecordReferences(array $base, array $incoming): array
    {
        $merged = [];
        foreach ([...$base, ...$incoming] as $record) {
            $merged[$record->table . ':' . $record->workspaceUid] = $record;
        }
        return array_values($merged);
    }

    /**
     * @param list<PendingChangeRecord> $base
     * @param list<PendingChangeRecord> $incoming
     * @return list<PendingChangeRecord>
     */
    private function mergeChangeRecords(array $base, array $incoming): array
    {
        $merged = [];
        foreach ([...$base, ...$incoming] as $record) {
            $merged[$record->table . ':' . $record->workspaceUid] = $record;
        }
        return array_values($merged);
    }

    /**
     * @param list<PendingRecordReference> $records
     * @return list<PendingRecordReference>
     */
    private function withoutConceptualPublishRecords(array $records, string $table, int $liveUid): array
    {
        return array_values(array_filter(
            $records,
            static fn (PendingRecordReference $record): bool => $record->table !== $table || $record->liveUid !== $liveUid,
        ));
    }

    /**
     * @param list<PendingChangeRecord> $records
     * @return list<PendingChangeRecord>
     */
    private function withoutConceptualChangeRecords(array $records, string $table, int $liveUid): array
    {
        return array_values(array_filter(
            $records,
            static fn (PendingChangeRecord $record): bool => $record->table !== $table || $record->liveUid !== $liveUid,
        ));
    }

    private function replaceCoreFields(PendingItem $base, PendingItem $incoming): PendingItem
    {
        return new PendingItem(
            table: $incoming->table,
            liveUid: $incoming->liveUid,
            workspaceUid: $incoming->workspaceUid,
            title: $incoming->title,
            kindKey: $incoming->kindKey,
            kindLabel: $incoming->kindLabel,
            badge: $incoming->badge,
            iconIdentifier: $incoming->iconIdentifier,
            thumbnailUrl: $incoming->thumbnailUrl,
            isPrimary: $incoming->isPrimary,
            isChanged: $incoming->isChanged,
            isHidden: $incoming->isHidden,
            tableLabel: $incoming->tableLabel,
            typeLabel: $incoming->typeLabel,
            editUrl: $incoming->editUrl,
            contextualEditUrl: $incoming->contextualEditUrl,
            historyUrl: $incoming->historyUrl,
            diff: $incoming->diff,
            changeBadges: $incoming->changeBadges,
            childChanges: $incoming->childChanges,
            publishRecords: $incoming->publishRecords,
            changeRecords: $incoming->changeRecords,
            historyDiffCount: $incoming->historyDiffCount,
            colPos: $incoming->colPos,
            colPosLabel: $incoming->colPosLabel,
            locateTable: $incoming->locateTable,
            locateLiveUid: $incoming->locateLiveUid,
            locateWorkspaceUid: $incoming->locateWorkspaceUid,
            tstamp: $incoming->tstamp,
            latestChangeAt: $incoming->latestChangeAt,
            latestChangeUserUid: $incoming->latestChangeUserUid,
            latestChangeUser: $incoming->latestChangeUser,
        );
    }

    /**
     * @param list<PendingChildChange> $childChanges
     * @param list<array{kindKey: string, kindLabel: string, badge: string}> $changeBadges
     * @param list<PendingRecordReference> $publishRecords
     * @param list<PendingChangeRecord> $changeRecords
     */
    private function withAggregatedFields(
        PendingItem $item,
        bool $isChanged,
        array $childChanges,
        array $changeBadges,
        array $publishRecords,
        array $changeRecords,
    ): PendingItem {
        return new PendingItem(
            table: $item->table,
            liveUid: $item->liveUid,
            workspaceUid: $item->workspaceUid,
            title: $item->title,
            kindKey: $item->kindKey,
            kindLabel: $item->kindLabel,
            badge: $item->badge,
            iconIdentifier: $item->iconIdentifier,
            thumbnailUrl: $item->thumbnailUrl,
            isPrimary: $item->isPrimary,
            isChanged: $isChanged,
            isHidden: $item->isHidden,
            tableLabel: $item->tableLabel,
            typeLabel: $item->typeLabel,
            editUrl: $item->editUrl,
            contextualEditUrl: $item->contextualEditUrl,
            historyUrl: $item->historyUrl,
            diff: $item->diff,
            changeBadges: $changeBadges,
            childChanges: $childChanges,
            publishRecords: $publishRecords,
            changeRecords: $changeRecords,
            historyDiffCount: $item->historyDiffCount,
            colPos: $item->colPos,
            colPosLabel: $item->colPosLabel,
            locateTable: $item->locateTable,
            locateLiveUid: $item->locateLiveUid,
            locateWorkspaceUid: $item->locateWorkspaceUid,
            tstamp: $item->tstamp,
            latestChangeAt: $item->latestChangeAt,
            latestChangeUserUid: $item->latestChangeUserUid,
            latestChangeUser: $item->latestChangeUser,
        );
    }
}
