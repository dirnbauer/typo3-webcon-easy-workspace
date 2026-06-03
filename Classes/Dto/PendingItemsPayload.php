<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

/**
 * Typed result of a pending-items collection run. Serialize once at the HTTP boundary.
 */
final readonly class PendingItemsPayload
{
    /**
     * @param list<PendingItem> $items
     * @param list<array{key: string, label: string|null, items: list<PendingItem>}> $itemGroups
     * @param list<array{key: string, label: string|null, items: list<PendingItem>}> $changedItemGroups
     */
    public function __construct(
        public int $workspaceId,
        public string $workspaceTitle,
        public string $mode,
        public array $items,
        public array $itemGroups,
        public array $changedItemGroups,
        public ?int $pageUid = null,
        public ?int $newsUid = null,
        public ?int $languageUid = null,
        public bool $hasNews = false,
    ) {}

    public function hasAnyChanges(): bool
    {
        foreach ($this->items as $item) {
            if ($item->isChanged) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{
     *     workspaceId: int,
     *     workspaceTitle: string,
     *     pageUid: int,
     *     languageUid: int|null,
     *     items: list<array<string, mixed>>,
     *     itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>,
     *     changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>,
     *     hasNews: bool,
     *     mode: string
     * }
     */
    public function toPageArray(): array
    {
        return $this->toPageClientArray(includeDiff: true);
    }

    /**
     * @return array{
     *     workspaceId: int,
     *     workspaceTitle: string,
     *     pageUid: int,
     *     languageUid: int|null,
     *     items: list<array<string, mixed>>,
     *     itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>,
     *     changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>,
     *     hasNews: bool,
     *     mode: string
     * }
     */
    public function toPageClientArray(bool $includeDiff = false): array
    {
        return [
            'workspaceId' => $this->workspaceId,
            'workspaceTitle' => $this->workspaceTitle,
            'pageUid' => $this->pageUid ?? 0,
            'languageUid' => $this->languageUid,
            'items' => self::serializeItems($this->items, $includeDiff),
            'itemGroups' => self::serializeGroups($this->itemGroups, $includeDiff),
            'changedItemGroups' => self::serializeGroups($this->changedItemGroups, $includeDiff),
            'hasNews' => $this->hasNews,
            'mode' => $this->mode,
        ];
    }

    /**
     * @return array{
     *     workspaceId: int,
     *     workspaceTitle: string,
     *     newsUid: int,
     *     languageUid: int|null,
     *     items: list<array<string, mixed>>,
     *     itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>,
     *     changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>,
     *     mode: string
     * }
     */
    public function toNewsArray(): array
    {
        return $this->toNewsClientArray(includeDiff: true);
    }

    /**
     * @return array{
     *     workspaceId: int,
     *     workspaceTitle: string,
     *     newsUid: int,
     *     languageUid: int|null,
     *     items: list<array<string, mixed>>,
     *     itemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>,
     *     changedItemGroups: list<array{key: string, label: string|null, items: list<array<string, mixed>>}>,
     *     mode: string
     * }
     */
    public function toNewsClientArray(bool $includeDiff = false): array
    {
        return [
            'workspaceId' => $this->workspaceId,
            'workspaceTitle' => $this->workspaceTitle,
            'newsUid' => $this->newsUid ?? 0,
            'languageUid' => $this->languageUid,
            'items' => self::serializeItems($this->items, $includeDiff),
            'itemGroups' => self::serializeGroups($this->itemGroups, $includeDiff),
            'changedItemGroups' => self::serializeGroups($this->changedItemGroups, $includeDiff),
            'mode' => $this->mode,
        ];
    }

    /**
     * @param list<PendingItem> $items
     * @return list<array<string, mixed>>
     */
    public static function serializeItems(array $items, bool $includeDiff = false): array
    {
        return array_map(static fn (PendingItem $item): array => $item->toClientArray($includeDiff), $items);
    }

    /**
     * @param list<array{key: string, label: string|null, items: list<PendingItem>}> $groups
     * @return list<array{key: string, label: string|null, items: list<array<string, mixed>>}>
     */
    public static function serializeGroups(array $groups, bool $includeDiff = false): array
    {
        return array_map(
            static fn (array $group): array => [
                'key' => $group['key'],
                'label' => $group['label'],
                'items' => self::serializeItems($group['items'], $includeDiff),
            ],
            $groups,
        );
    }
}
