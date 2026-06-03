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
    ) {}

    public function withPublishMetadata(): self
    {
        if (!$this->isChanged) {
            return $this;
        }

        return new self(
            table: $this->table,
            liveUid: $this->liveUid,
            workspaceUid: $this->workspaceUid,
            title: $this->title,
            kindKey: $this->kindKey,
            kindLabel: $this->kindLabel,
            badge: $this->badge,
            iconIdentifier: $this->iconIdentifier,
            thumbnailUrl: $this->thumbnailUrl,
            isPrimary: $this->isPrimary,
            isChanged: $this->isChanged,
            isHidden: $this->isHidden,
            tableLabel: $this->tableLabel,
            typeLabel: $this->typeLabel,
            editUrl: $this->editUrl,
            contextualEditUrl: $this->contextualEditUrl,
            historyUrl: $this->historyUrl,
            diff: $this->diff,
            changeBadges: $this->changeBadges,
            childChanges: $this->childChanges,
            publishRecords: [PendingRecordReference::fromPendingItem($this)],
            changeRecords: [PendingChangeRecord::fromPendingItem($this)],
            historyDiffCount: $this->historyDiffCount,
            colPos: $this->colPos,
            colPosLabel: $this->colPosLabel,
            locateTable: $this->locateTable,
            locateLiveUid: $this->locateLiveUid,
            locateWorkspaceUid: $this->locateWorkspaceUid,
            tstamp: $this->tstamp,
            latestChangeAt: $this->latestChangeAt,
            latestChangeUserUid: $this->latestChangeUserUid,
            latestChangeUser: $this->latestChangeUser,
        );
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
     * Lightweight serialization for toolbar JS glue (no field diffs).
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
            'childChanges' => array_map(static fn (PendingChildChange $child): array => $child->toArray(), $this->childChanges),
            'colPos' => $this->colPos,
            'colPosLabel' => $this->colPosLabel,
            'locateTable' => $this->locateTable,
            'locateLiveUid' => $this->locateLiveUid,
            'locateWorkspaceUid' => $this->locateWorkspaceUid,
            'tstamp' => $this->tstamp,
            'latestChangeAt' => $this->latestChangeAt,
            'latestChangeUserUid' => $this->latestChangeUserUid,
            'latestChangeUser' => $this->latestChangeUser,
            'changeBadges' => $this->isChanged ? ($this->changeBadges ?: [[
                'kindKey' => $this->kindKey,
                'kindLabel' => $this->kindLabel,
                'badge' => $this->badge,
            ]]) : [],
            'publishRecords' => array_map(static fn (PendingRecordReference $record): array => $record->toArray(), $this->publishRecords),
            'changeRecords' => array_map(
                static fn (PendingChangeRecord $record): array => $includeDiff ? $record->toArray() : [
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
}
