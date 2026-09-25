<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Workspaces\Service\Dependency\CollectionService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * A workspace's changes as the Workspaces module lists them.
 *
 * Core finds the versions — WorkspaceService::selectVersionsInWorkspace(),
 * with the editor's table and page permissions — and nests every record
 * that depends on another one below it: collection items, file references,
 * inline children (CollectionService, the step GridDataService runs before
 * it renders the module's grid). An entry is one row of that grid's top
 * level; the versions nested below it come with it. A changed collection
 * item of an unchanged element is a row of its own, as in the module.
 *
 * Both core classes are marked @internal: they are the module's own code
 * path, and the toolbar is meant to show exactly the module's data.
 */
final readonly class CoreWorkspaceChanges
{
    public function __construct(
        private WorkspaceService $workspaceService,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @param int $pageUid A page for its own changes, -1 for the whole workspace.
     * @return list<WorkspaceChange>
     */
    public function entries(int $workspaceId, int $pageUid = -1, ?int $languageUid = null): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        $rows = [];
        $versions = $this->workspaceService->selectVersionsInWorkspace(
            $workspaceId,
            -99,
            $pageUid > 0 ? $pageUid : -1,
            0,
            'tables_select',
            $languageUid,
        );
        foreach ($versions as $table => $records) {
            foreach (is_array($records) ? $records : [] as $record) {
                $uid = Value::int(is_array($record) ? ($record['uid'] ?? null) : null);
                if ($uid <= 0) {
                    continue;
                }
                $id = $table . ':' . $uid;
                $rows[$id] = [
                    'id' => $id,
                    'table' => (string)$table,
                    'uid' => $uid,
                    'liveUid' => Value::int($record['t3ver_oid'] ?? null) ?: $uid,
                ];
            }
        }
        if ($rows === []) {
            return [];
        }

        // CollectionService is a singleton whose resolver keeps every
        // element it was ever given; a fresh one nests just these.
        $collection = new CollectionService($this->eventDispatcher);
        $resolver = $collection->getDependencyResolver();
        foreach ($rows as $row) {
            $resolver->addElement($row['table'], $row['uid']);
        }

        // process() returns each top-level row followed by all rows nested
        // below it, so a nested row belongs to the last top-level row.
        $entries = [];
        $children = [];
        foreach ($collection->process($rows) as $row) {
            $change = new WorkspaceChange(
                table: Value::string($row['table'] ?? null),
                workspaceUid: Value::int($row['uid'] ?? null),
                liveUid: Value::int($row['liveUid'] ?? null),
            );
            if (Value::int($row['Workspaces_CollectionLevel'] ?? null) === 0 || $entries === []) {
                $entries[] = $change;
                $children[] = [];
                continue;
            }
            $children[count($children) - 1][] = $change;
        }

        foreach ($entries as $index => $entry) {
            $entries[$index] = $entry->withChildren($children[$index]);
        }

        return $entries;
    }
}
