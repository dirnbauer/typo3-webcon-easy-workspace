<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

final readonly class PendingChildChange
{
    public function __construct(
        public string $table,
        public int $liveUid,
        public int $workspaceUid,
        public string $title,
        public string $kindKey,
        public string $kindLabel,
        public string $badge,
        public string $tableLabel,
        public string $typeLabel,
        public ?string $thumbnailUrl,
        public int $tstamp,
        public int $latestChangeAt,
        public int $latestChangeUserUid,
        public string $latestChangeUser,
    ) {}

    public static function fromPendingItem(PendingItem $item): self
    {
        return new self(
            table: $item->table,
            liveUid: $item->liveUid,
            workspaceUid: $item->workspaceUid,
            title: $item->title,
            kindKey: $item->kindKey,
            kindLabel: $item->kindLabel,
            badge: $item->badge !== '' ? $item->badge : 'info',
            tableLabel: $item->tableLabel,
            typeLabel: $item->typeLabel,
            thumbnailUrl: $item->thumbnailUrl,
            tstamp: $item->tstamp,
            latestChangeAt: $item->latestChangeAt,
            latestChangeUserUid: $item->latestChangeUserUid,
            latestChangeUser: $item->latestChangeUser,
        );
    }

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
            'tableLabel' => $this->tableLabel,
            'typeLabel' => $this->typeLabel,
            'thumbnailUrl' => $this->thumbnailUrl,
            'tstamp' => $this->tstamp,
            'latestChangeAt' => $this->latestChangeAt,
            'latestChangeUserUid' => $this->latestChangeUserUid,
            'latestChangeUser' => $this->latestChangeUser,
        ];
    }
}
