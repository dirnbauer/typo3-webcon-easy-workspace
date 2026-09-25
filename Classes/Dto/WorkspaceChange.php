<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

/**
 * One row of the Workspaces module's list: a version, and the versions of
 * the records that depend on it (collection items, file references, inline
 * children), which the module shows nested below it.
 */
final readonly class WorkspaceChange
{
    /**
     * @param list<WorkspaceChange> $children All nested versions, in the module's order.
     */
    public function __construct(
        public string $table,
        public int $workspaceUid,
        public int $liveUid,
        public array $children = [],
    ) {}

    public function isNew(): bool
    {
        return $this->liveUid === $this->workspaceUid;
    }

    /**
     * @param list<WorkspaceChange> $children
     */
    public function withChildren(array $children): self
    {
        return new self($this->table, $this->workspaceUid, $this->liveUid, $children);
    }
}
