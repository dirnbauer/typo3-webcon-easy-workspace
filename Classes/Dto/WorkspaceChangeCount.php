<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

/**
 * Whole-workspace change count as reported by the toolbar badge.
 *
 * `total` counts changes — what someone edited. Translation copies TYPO3
 * made along with a change and unchanged copies do not count; `versionCount`
 * is every row the Workspaces module lists (changes, their companions and
 * the copies), `copyCount` the copies waiting for cleanup.
 *
 * The stamp is a cheap fingerprint of the workspace state: the client
 * compares it to decide whether an open dropdown list has to re-fetch and
 * whether another tab has to be told, and the server keys the cached page
 * count by it.
 */
final readonly class WorkspaceChangeCount
{
    public const array EMPTY_STATES = ['new' => 0, 'changed' => 0, 'deleted' => 0, 'moved' => 0];

    /**
     * @param array<string, int> $byTable
     * @param array{new: int, changed: int, deleted: int, moved: int} $byState
     */
    public function __construct(
        public int $workspaceId,
        public int $total,
        public array $byTable,
        public array $byState,
        public int $latestChangeAt,
        public string $stamp,
        public int $versionCount = 0,
        public int $copyCount = 0,
    ) {}

    public static function empty(int $workspaceId): self
    {
        return new self($workspaceId, 0, [], self::EMPTY_STATES, 0, self::stamp($workspaceId, 0, 0), 0, 0);
    }

    /**
     * Deterministic fingerprint of the workspace, its number of changes and
     * its DataHandler revision (see WorkspaceRevision), which moves with
     * every write to a workspace-aware table.
     */
    public static function stamp(int $workspaceId, int $total, int $latestChangeAt, string $revision = ''): string
    {
        return sha1($workspaceId . '|' . $total . '|' . $latestChangeAt . ($revision !== '' ? '|' . $revision : ''));
    }

    /**
     * @return array{
     *     workspaceId: int,
     *     changedCount: int,
     *     versionCount: int,
     *     copyCount: int,
     *     byTable: array<string, int>,
     *     byState: array{new: int, changed: int, deleted: int, moved: int},
     *     latestChangeAt: int,
     *     stamp: string
     * }
     */
    public function toArray(): array
    {
        return [
            'workspaceId' => $this->workspaceId,
            'changedCount' => $this->total,
            'versionCount' => $this->versionCount,
            'copyCount' => $this->copyCount,
            'byTable' => $this->byTable,
            'byState' => $this->byState,
            'latestChangeAt' => $this->latestChangeAt,
            'stamp' => $this->stamp,
        ];
    }
}
