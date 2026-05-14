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
     */
    /**
     * @param int|null    $colPos      Column position from the page's BackendLayout. tt_content rows only; null for pages/news/etc. Used by the frontend to group rows by page column ("Hero area", "Content area", …).
     * @param string|null $colPosLabel Human-readable column name resolved via BackendLayout/usedColumns. Falls back to "Column N" when no layout is configured. Null when colPos is null.
     */
    public function __construct(
        public string $table,
        public int $liveUid,
        public int $workspaceUid,
        public string $title,
        public string $kindLabel,
        public string $badge,
        public ?string $thumbnailUrl,
        public bool $isPrimary,
        public bool $isChanged,
        public bool $isHidden,
        public string $tableLabel,
        public string $typeLabel,
        public ?string $editUrl,
        public array $diff = [],
        public ?int $colPos = null,
        public ?string $colPosLabel = null,
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
            'kindLabel' => $this->kindLabel,
            'badge' => $this->badge,
            'thumbnailUrl' => $this->thumbnailUrl,
            'isPrimary' => $this->isPrimary,
            'isChanged' => $this->isChanged,
            'isHidden' => $this->isHidden,
            'tableLabel' => $this->tableLabel,
            'typeLabel' => $this->typeLabel,
            'editUrl' => $this->editUrl,
            'diff' => $this->diff,
            'colPos' => $this->colPos,
            'colPosLabel' => $this->colPosLabel,
        ];
    }
}
