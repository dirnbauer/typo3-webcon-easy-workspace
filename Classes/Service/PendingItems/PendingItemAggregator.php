<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Webconsulting\WebconEasyWorkspace\Dto\PendingChangeRecord;
use Webconsulting\WebconEasyWorkspace\Dto\PendingChildChange;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Dto\PendingRecordReference;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;

final readonly class PendingItemAggregator
{
    public function __construct(
        private PendingItemTimelineResolver $timelineResolver,
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
            static fn(PendingItem $item): bool => $item->isChanged,
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
            // The newer version's fields, with what was aggregated so far.
            $base = $incoming->with([
                'isChanged' => true,
                'childChanges' => $base->childChanges,
                'changeBadges' => $preservedBadges,
                'publishRecords' => $preservedPublishRecords,
                'changeRecords' => $preservedChangeRecords,
            ]);
        }

        $childChanges = $this->mergeChildChanges(
            $base->childChanges,
            $incomingChanged && $incoming->table !== $base->table
                ? [PendingChildChange::fromPendingItem($incoming)]
                : $incoming->childChanges,
        );

        return $base->with([
            'isChanged' => $base->isChanged || $incomingChanged,
            'childChanges' => $childChanges,
            'changeBadges' => $this->mergeChangeBadges($base->changeBadges, $incoming->changeBadges),
            'publishRecords' => $this->mergeRecordReferences($base->publishRecords, $incoming->publishRecords),
            'changeRecords' => $this->mergeChangeRecords($base->changeRecords, $incoming->changeRecords),
        ]);
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
            static fn(PendingRecordReference $record): bool => $record->table !== $table || $record->liveUid !== $liveUid,
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
            static fn(PendingChangeRecord $record): bool => $record->table !== $table || $record->liveUid !== $liveUid,
        ));
    }
}
