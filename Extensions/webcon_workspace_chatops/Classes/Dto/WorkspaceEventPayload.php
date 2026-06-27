<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Dto;

use Webconsulting\WebconWorkspaceChatops\Enum\WorkspaceEventType;

final readonly class WorkspaceEventPayload
{
    /**
     * @param list<WorkspaceRecordSelection> $records
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public WorkspaceEventType $type,
        public string $title,
        public string $message,
        public int $workspaceId = 0,
        public ?int $pageUid = null,
        public ?int $backendUserId = null,
        public array $records = [],
        public array $metadata = [],
        public ?string $previewUrl = null,
        public ?string $backendUrl = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'message' => $this->message,
            'workspaceId' => $this->workspaceId,
            'pageUid' => $this->pageUid,
            'backendUserId' => $this->backendUserId,
            'records' => array_map(static fn(WorkspaceRecordSelection $selection): array => $selection->toArray(), $this->records),
            'metadata' => $this->metadata,
            'previewUrl' => $this->previewUrl,
            'backendUrl' => $this->backendUrl,
        ];
    }

    public function plainTextSummary(): string
    {
        $lines = [$this->title];
        if ($this->message !== '') {
            $lines[] = $this->message;
        }
        if ($this->workspaceId > 0) {
            $lines[] = 'Workspace: #' . $this->workspaceId;
        }
        if ($this->pageUid !== null && $this->pageUid > 0) {
            $lines[] = 'Page: #' . $this->pageUid;
        }
        if ($this->records !== []) {
            $lines[] = 'Records: ' . count($this->records);
        }
        if ($this->previewUrl !== null && $this->previewUrl !== '') {
            $lines[] = 'Preview: ' . $this->previewUrl;
        }
        if ($this->backendUrl !== null && $this->backendUrl !== '') {
            $lines[] = 'Backend: ' . $this->backendUrl;
        }

        return implode("\n", $lines);
    }
}
