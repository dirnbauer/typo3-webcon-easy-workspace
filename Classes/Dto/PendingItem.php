<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

final readonly class PendingItem
{
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
        ];
    }
}
