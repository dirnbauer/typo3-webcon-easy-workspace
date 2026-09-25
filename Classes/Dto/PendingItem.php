<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

final readonly class PendingItem
{
    /**
     * @param list<array{field: string, label: string, before: string, after: string, beforeFull: string, afterFull: string, type: string, kind: string}> $diff
     * @param list<array{kindKey: string, kindLabel: string, badge: string}> $changeBadges
     * @param list<PendingChildChange> $childChanges
     * @param list<PendingRecordReference> $publishRecords
     * @param list<PendingChangeRecord> $changeRecords
     * @param int|null $languageUid The record's language; null for a table without one.
     * @param array{table: string, uid: int, title: string}|null $parent The record this one is part of — a collection item's or file reference's element.
     */
    public function __construct(
        public string $table,
        public int $liveUid,
        public int $workspaceUid,
        public string $title,
        public string $kindKey,
        public string $kindLabel,
        public string $badge,
        public string $iconIdentifier,
        public ?string $thumbnailUrl,
        public bool $isPrimary,
        public bool $isChanged,
        public bool $isHidden,
        public string $tableLabel,
        public string $typeLabel,
        public ?string $editUrl,
        public ?string $contextualEditUrl = null,
        public ?string $historyUrl = null,
        public array $diff = [],
        public array $changeBadges = [],
        public array $childChanges = [],
        public array $publishRecords = [],
        public array $changeRecords = [],
        public int $historyDiffCount = 0,
        public ?int $colPos = null,
        public ?string $colPosLabel = null,
        public ?string $locateTable = null,
        public ?int $locateLiveUid = null,
        public ?int $locateWorkspaceUid = null,
        public int $tstamp = 0,
        public int $latestChangeAt = 0,
        public int $latestChangeUserUid = 0,
        public string $latestChangeUser = '',
        public int $stageId = 0,
        public ?int $languageUid = null,
        public ?array $parent = null,
    ) {}

    /**
     * A copy with some constructor arguments replaced.
     *
     * @param array<string, mixed> $overrides Keyed by constructor argument name.
     */
    public function with(array $overrides): self
    {
        /** @var array<string, mixed> $arguments */
        $arguments = array_replace(get_object_vars($this), $overrides);

        return new self(...$arguments);
    }

    public function withPublishMetadata(): self
    {
        if (!$this->isChanged) {
            return $this;
        }

        return $this->with([
            'publishRecords' => [PendingRecordReference::fromPendingItem($this)],
            'changeRecords' => [PendingChangeRecord::fromPendingItem($this)],
        ]);
    }

    public function identityUid(): int
    {
        return $this->kindKey === 'new' || $this->liveUid <= 0 ? $this->workspaceUid : $this->liveUid;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toClientArray(includeDiff: true);
    }

    /**
     * Serialization for the backend module (no field diffs unless asked).
     *
     * @return array<string, mixed>
     */
    public function toClientArray(bool $includeDiff = false): array
    {
        return [
            'table' => $this->table,
            'liveUid' => $this->liveUid,
            'workspaceUid' => $this->workspaceUid,
            'title' => $this->title,
            'kindKey' => $this->kindKey,
            'kindLabel' => $this->kindLabel,
            'badge' => $this->badge,
            'iconIdentifier' => $this->iconIdentifier,
            'thumbnailUrl' => $this->thumbnailUrl,
            'isPrimary' => $this->isPrimary,
            'isChanged' => $this->isChanged,
            'isHidden' => $this->isHidden,
            'tableLabel' => $this->tableLabel,
            'typeLabel' => $this->typeLabel,
            'editUrl' => $this->editUrl,
            'contextualEditUrl' => $this->contextualEditUrl,
            'historyUrl' => $this->historyUrl,
            'historyDiffCount' => $this->historyDiffCount,
            'childChanges' => array_map(static fn(PendingChildChange $child): array => $child->toArray(), $this->childChanges),
            'colPos' => $this->colPos,
            'colPosLabel' => $this->colPosLabel,
            'locateTable' => $this->locateTable,
            'locateLiveUid' => $this->locateLiveUid,
            'locateWorkspaceUid' => $this->locateWorkspaceUid,
            'tstamp' => $this->tstamp,
            'latestChangeAt' => $this->latestChangeAt,
            'latestChangeUserUid' => $this->latestChangeUserUid,
            'latestChangeUser' => $this->latestChangeUser,
            'stageId' => $this->stageId,
            'languageUid' => $this->languageUid,
            'parent' => $this->parent,
            'changeBadges' => $this->isChanged ? ($this->changeBadges ?: [[
                'kindKey' => $this->kindKey,
                'kindLabel' => $this->kindLabel,
                'badge' => $this->badge,
            ]]) : [],
            'publishRecords' => array_map(static fn(PendingRecordReference $record): array => $record->toArray(), $this->publishRecords),
            'changeRecords' => array_map(
                static fn(PendingChangeRecord $record): array => $includeDiff ? $record->toArray() : [
                    'table' => $record->table,
                    'liveUid' => $record->liveUid,
                    'workspaceUid' => $record->workspaceUid,
                    'title' => $record->title,
                    'kindKey' => $record->kindKey,
                    'kindLabel' => $record->kindLabel,
                    'badge' => $record->badge,
                    'historyDiffCount' => $record->historyDiffCount,
                    'editUrl' => $record->editUrl,
                    'contextualEditUrl' => $record->contextualEditUrl,
                    'historyUrl' => $record->historyUrl,
                ],
                $this->changeRecords,
            ),
        ];
    }

    /**
     * What the toolbar dropdown renders and acts on — nothing more. The
     * module's serialization repeats every change record with its URLs;
     * the dropdown only needs the records it publishes.
     *
     * @return array<string, mixed>
     */
    public function toToolbarArray(): array
    {
        return [
            'table' => $this->table,
            'liveUid' => $this->liveUid,
            'workspaceUid' => $this->workspaceUid,
            'title' => $this->title,
            'kindKey' => $this->kindKey,
            'iconIdentifier' => $this->iconIdentifier,
            'thumbnailUrl' => $this->thumbnailUrl,
            'isPrimary' => $this->isPrimary,
            'isChanged' => $this->isChanged,
            'isHidden' => $this->isHidden,
            'tableLabel' => $this->tableLabel,
            'typeLabel' => $this->typeLabel,
            'editUrl' => $this->editUrl,
            'contextualEditUrl' => $this->contextualEditUrl,
            'historyUrl' => $this->historyUrl,
            'historyDiffCount' => $this->historyDiffCount,
            'childChanges' => array_map(static fn(PendingChildChange $child): array => $child->toToolbarArray(), $this->childChanges),
            'colPosLabel' => $this->colPosLabel,
            'locateTable' => $this->locateTable,
            'locateLiveUid' => $this->locateLiveUid,
            'locateWorkspaceUid' => $this->locateWorkspaceUid,
            'tstamp' => $this->tstamp,
            'latestChangeAt' => $this->latestChangeAt,
            'latestChangeUser' => $this->latestChangeUser,
            'languageUid' => $this->languageUid,
            'parent' => $this->parent,
            'publishRecords' => array_map(static fn(PendingRecordReference $record): array => $record->toArray(), $this->publishRecords),
        ];
    }
}
