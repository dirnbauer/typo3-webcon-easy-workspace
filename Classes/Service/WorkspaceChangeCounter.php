<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChangeCount;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CoreWorkspaceChanges;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The whole workspace's number of changes, as the Workspaces module counts
 * them: its top-level rows. A changed element counts once however many of
 * its collection items or file references changed with it; a changed item
 * of an unchanged element is a row of its own.
 *
 * Core's list depends on the editor's table and page permissions, so it is
 * remembered per editor, and only until the workspace revision moves — with
 * every DataHandler write (see WorkspaceRevision). A lifetime bounds how
 * long a write that bypassed DataHandler stays unseen.
 */
final readonly class WorkspaceChangeCounter
{
    private const int LIFETIME = 300;

    public function __construct(
        private CoreWorkspaceChanges $coreWorkspaceChanges,
        private WorkspaceRevision $revision,
        private FrontendInterface $cache,
    ) {}

    public function count(int $workspaceId): WorkspaceChangeCount
    {
        if ($workspaceId <= 0) {
            return WorkspaceChangeCount::empty($workspaceId);
        }
        // Read before counting: a write racing this request then moves the
        // stamp on the next request instead of hiding behind this one.
        $revision = $this->revision->current($workspaceId);
        // Keyed by workspace and editor and overwritten in place (the cache
        // is a file backend without garbage collection); valid while the
        // revision it was counted at is current.
        $identifier = sha1(implode('|', ['count', $workspaceId, $this->userUid()]));
        $cached = $this->cache->get($identifier);
        if (is_array($cached)
            && ($cached['revision'] ?? null) === $revision
            && Value::int($cached['expires'] ?? null) > time()
            && is_array($cached['byTable'] ?? null)
            && is_array($cached['byState'] ?? null)
        ) {
            return $this->build($workspaceId, $revision, $cached['byTable'], $cached['byState']);
        }

        $byTable = [];
        $byState = WorkspaceChangeCount::EMPTY_STATES;
        foreach ($this->coreWorkspaceChanges->entries($workspaceId) as $entry) {
            $byTable[$entry->table] = ($byTable[$entry->table] ?? 0) + 1;
            ++$byState[$entry->isNew() ? 'new' : 'changed'];
        }
        $this->cache->set($identifier, [
            'revision' => $revision,
            'expires' => time() + self::LIFETIME,
            'byTable' => $byTable,
            'byState' => $byState,
        ]);

        return $this->build($workspaceId, $revision, $byTable, $byState);
    }

    /**
     * @param array<mixed> $byTable
     * @param array<mixed> $byState
     */
    private function build(int $workspaceId, string $revision, array $byTable, array $byState): WorkspaceChangeCount
    {
        $tables = [];
        foreach ($byTable as $table => $changes) {
            $tables[(string)$table] = Value::int($changes);
        }
        $states = WorkspaceChangeCount::EMPTY_STATES;
        foreach (array_keys($states) as $state) {
            $states[$state] = Value::int($byState[$state] ?? null);
        }
        $total = array_sum($tables);

        return new WorkspaceChangeCount(
            workspaceId: $workspaceId,
            total: $total,
            byTable: $tables,
            byState: $states,
            latestChangeAt: 0,
            stamp: WorkspaceChangeCount::stamp($workspaceId, $total, 0, $revision),
        );
    }

    private function userUid(): int
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? Value::int($user->user['uid'] ?? null) : 0;
    }
}
