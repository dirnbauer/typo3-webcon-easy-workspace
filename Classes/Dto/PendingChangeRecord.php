<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

final readonly class PendingChangeRecord
{
    /**
     * @param list<array{field: string, label: string, before: string, after: string, beforeFull: string, afterFull: string, type: string, kind: string}> $diff
     */
    public function __construct(
        public string $table,
        public int $liveUid,
        public int $workspaceUid,
        public string $title,
        public string $kindKey,
        public string $kindLabel,
        public string $badge,
        public array $diff,
        public int $historyDiffCount,
        public ?string $editUrl,
        public ?string $contextualEditUrl,
        public ?string $historyUrl,
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
            badge: $item->badge,
            diff: $item->diff,
            historyDiffCount: $item->historyDiffCount,
            editUrl: $item->editUrl,
            contextualEditUrl: $item->contextualEditUrl,
            historyUrl: $item->historyUrl,
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
            'diff' => $this->diff,
            'historyDiffCount' => $this->historyDiffCount,
            'editUrl' => $this->editUrl,
            'contextualEditUrl' => $this->contextualEditUrl,
            'historyUrl' => $this->historyUrl,
        ];
    }
}
