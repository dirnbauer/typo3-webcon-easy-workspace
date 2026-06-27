<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Dto;

final readonly class WorkspaceRecordSelection
{
    public function __construct(
        public string $table,
        public int $workspaceUid,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $table = trim((string)($data['table'] ?? ''));
        $workspaceUid = (int)($data['workspaceUid'] ?? $data['workspace_uid'] ?? $data['uid'] ?? 0);
        if ($table === '' || $workspaceUid <= 0) {
            return null;
        }

        return new self($table, $workspaceUid);
    }

    /**
     * @return array{table: string, workspaceUid: int}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'workspaceUid' => $this->workspaceUid,
        ];
    }
}
