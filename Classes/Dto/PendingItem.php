<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

final readonly class PendingItem
{
    /**
     * @param list<array{field: string, label: string, before: string, after: string, beforeFull: string, afterFull: string, type: string, kind: string}> $diff
     *   Field-level diff between this record's live and workspace
     *   versions. Empty for unchanged rows (isChanged=false) or when
     *   the diff service couldn't compute one.
     * @param list<array{kindKey: string, kindLabel: string, badge: string}> $changeBadges
     *   Ordered, de-duplicated history action badges for the dropdown row.
     * @param list<array<string, mixed>> $childChanges
     *   Changed inline child records, such as versioned file references,
     *   rendered indented inside the owning visible record.
     * @param int|null    $colPos      Column position from the page's BackendLayout. tt_content rows only; null for pages/news/etc. Used by the frontend to group rows by page column ("Hero area", "Content area", …).
     * @param string|null $colPosLabel Human-readable column name resolved via BackendLayout/usedColumns. Falls back to "Column N" when no layout is configured. Null when colPos is null.
     */
    public function __construct(
        public string $table,
        public int $liveUid,
        public int $workspaceUid,
        public string $title,
        public string $kindKey,
        public string $kindLabel,
        public string $badge,
        public ?string $thumbnailUrl,
        public bool $isPrimary,
        public bool $isChanged,
        public bool $isHidden,
        public string $tableLabel,
        public string $typeLabel,
        public ?string $editUrl,
        public ?string $contextualEditUrl = null,
        public array $diff = [],
        public array $changeBadges = [],
        public array $childChanges = [],
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'liveUid' => $this->liveUid,
            'workspaceUid' => $this->workspaceUid,
            'title' => $this->title,
            'kindKey' => $this->kindKey,
            'kindLabel' => $this->kindLabel,
            'badge' => $this->badge,
            'thumbnailUrl' => $this->thumbnailUrl,
            'isPrimary' => $this->isPrimary,
            'isChanged' => $this->isChanged,
            'isHidden' => $this->isHidden,
            'tableLabel' => $this->tableLabel,
            'typeLabel' => $this->typeLabel,
            'editUrl' => $this->editUrl,
            'contextualEditUrl' => $this->contextualEditUrl,
            'diff' => $this->diff,
            'historyDiffCount' => $this->historyDiffCount,
            'childChanges' => $this->childChanges,
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
            'publishRecords' => $this->isChanged ? [[
                'table' => $this->table,
                'liveUid' => $this->liveUid,
                'workspaceUid' => $this->workspaceUid,
            ]] : [],
            'changeRecords' => $this->isChanged ? [[
                'table' => $this->table,
                'liveUid' => $this->liveUid,
                'workspaceUid' => $this->workspaceUid,
                'title' => $this->title,
                'kindKey' => $this->kindKey,
                'kindLabel' => $this->kindLabel,
                'badge' => $this->badge,
                'diff' => $this->diff,
                'historyDiffCount' => $this->historyDiffCount,
                'editUrl' => $this->editUrl,
                'contextualEditUrl' => $this->contextualEditUrl,
            ]] : [],
        ];
    }
}
