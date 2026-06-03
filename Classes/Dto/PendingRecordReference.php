<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

final readonly class PendingRecordReference
{
    public function __construct(
        public string $table,
        public int $liveUid,
        public int $workspaceUid,
    ) {}

    public static function fromPendingItem(PendingItem $item): self
    {
        return new self($item->table, $item->liveUid, $item->workspaceUid);
    }

    /**
     * @return array{table: string, liveUid: int, workspaceUid: int}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'liveUid' => $this->liveUid,
            'workspaceUid' => $this->workspaceUid,
        ];
    }
}
