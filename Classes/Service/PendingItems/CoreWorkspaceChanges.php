<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Schema\Capability\RootLevelCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Workspaces\Service\Dependency\CollectionService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceRevision;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * A workspace's changes as the Workspaces module lists them.
 *
 * Core finds the versions — WorkspaceService::selectVersionsInWorkspace(),
 * every level below the editor's mounts, with the editor's table and page
 * permissions — and nests every record that depends on another one below
 * it: collection items, file references, inline children
 * (CollectionService, the step GridDataService runs before it renders the
 * module's grid). An entry is one row of that grid's top level; the
 * versions nested below it come with it. A changed collection item of an
 * unchanged element is a row of its own, as in the module.
 *
 * Core's selection asks every workspace-aware table (hundreds in a Content
 * Blocks installation), so it runs once per workspace revision and editor:
 * the whole workspace's rows are cached, and a page's rows are the subset
 * that core would select for that page — the page the version lives on,
 * the page record and its translations, and the root-level records of
 * tables that ignore the root-level restriction (the rules of
 * selectVersionsInWorkspace() with a page id). The nesting is done per
 * page, as the module does it.
 *
 * Both core classes are marked @internal: they are the module's own code
 * path, and the toolbar is meant to show exactly the module's data.
 */
final readonly class CoreWorkspaceChanges
{
    private const int LIFETIME = 300;

    /** Versions compared per round trip (plus their live rows). */
    private const int ROW_CHUNK = 500;

    /**
     * Core's depth for "every level": the module's "Infinite", and the depth
     * its actions for the entire workspace select with.
     */
    private const int ALL_LEVELS = 999;

    public function __construct(
        private WorkspaceService $workspaceService,
        private EventDispatcherInterface $eventDispatcher,
        private TcaSchemaFactory $tcaSchemaFactory,
        private WorkspaceRevision $revision,
        private FrontendInterface $cache,
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
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

        // Nesting asks core's reference index for every row; a page's tree
        // is remembered for the revision, like its rows.
        $revision = $this->revision->current($workspaceId);
        $identifier = sha1(implode('|', ['entries', $workspaceId, $pageUid, $languageUid ?? 'all', $this->userUid()]));
        $cached = $this->cache->get($identifier);
        if (is_array($cached)
            && ($cached['revision'] ?? null) === $revision
            && Value::int($cached['expires'] ?? null) > time()
            && is_array($cached['entries'] ?? null)
        ) {
            return array_map(self::changeFromArray(...), array_values($cached['entries']));
        }

        $rows = $this->rows($workspaceId);
        if ($pageUid > 0) {
            $rows = array_values(array_filter(
                $rows,
                fn(WorkspaceChange $row): bool => $row->belongsToPage($pageUid, $this->ignoresRootLevelRestriction($row->table)),
            ));
        }
        if ($languageUid !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn(WorkspaceChange $row): bool => $row->matchesLanguage($languageUid),
            ));
        }
        $entries = $this->nest($rows);
        $this->cache->set($identifier, [
            'revision' => $revision,
            'expires' => time() + self::LIFETIME,
            'entries' => array_map(self::changeToArray(...), $entries),
        ]);

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function changeToArray(WorkspaceChange $change): array
    {
        return $change->toArray() + ['children' => array_map(self::changeToArray(...), $change->children)];
    }

    private static function changeFromArray(mixed $row): WorkspaceChange
    {
        $row = Value::stringKeyArray($row);
        $children = is_array($row['children'] ?? null) ? array_values($row['children']) : [];

        return WorkspaceChange::fromArray($row)->withChildren(array_map(self::changeFromArray(...), $children));
    }

    /**
     * Every version of the workspace core lists for this editor, flat.
     *
     * @return list<WorkspaceChange>
     */
    public function rows(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        // Read before selecting: a write racing this request then moves the
        // revision on the next request instead of hiding behind this one.
        $revision = $this->revision->current($workspaceId);
        $identifier = sha1(implode('|', ['rows', $workspaceId, $this->userUid()]));
        $cached = $this->cache->get($identifier);
        if (is_array($cached)
            && ($cached['revision'] ?? null) === $revision
            && Value::int($cached['expires'] ?? null) > time()
            && is_array($cached['rows'] ?? null)
        ) {
            return array_map(self::changeFromArray(...), array_values($cached['rows']));
        }

        $rows = $this->select($workspaceId);
        $this->cache->set($identifier, [
            'revision' => $revision,
            'expires' => time() + self::LIFETIME,
            'rows' => array_map(static fn(WorkspaceChange $row): array => $row->toArray(), $rows),
        ]);

        return $rows;
    }

    /**
     * @return list<WorkspaceChange>
     */
    private function select(int $workspaceId): array
    {
        $rows = [];
        foreach ($this->versions($workspaceId) as $table => $records) {
            $table = (string)$table;
            $records = is_array($records) ? $records : [];
            $languageField = $this->languageField($table);
            $translationParentField = $this->translationParentField($table);
            $contentChanged = $this->contentChanges($table, $records);
            foreach ($records as $record) {
                $record = Value::stringKeyArray($record);
                $uid = Value::int($record['uid'] ?? null);
                if ($uid <= 0) {
                    continue;
                }
                // A move pointer is the one row core selects without the
                // record's own columns (no pid, no language): only the
                // target page (wspid) and the live page.
                $isMoved = !array_key_exists('pid', $record);
                $translationParent = $translationParentField !== null ? Value::int($record[$translationParentField] ?? null) : 0;
                if ($isMoved && $table === 'pages' && $translationParentField !== null) {
                    $page = BackendUtility::getRecord('pages', $uid, $translationParentField);
                    $translationParent = is_array($page) ? Value::int($page[$translationParentField] ?? null) : 0;
                }
                $rows[] = new WorkspaceChange(
                    table: $table,
                    workspaceUid: $uid,
                    liveUid: Value::int($record['t3ver_oid'] ?? null) ?: $uid,
                    pid: Value::int($record['wspid'] ?? $record['pid'] ?? null),
                    languageUid: !$isMoved && $languageField !== null && isset($record[$languageField])
                        ? Value::int($record[$languageField])
                        : null,
                    translationParent: $translationParent,
                    isMoved: $isMoved,
                    contentChanged: $isMoved || ($contentChanged[$uid] ?? true),
                );
            }
        }

        return $rows;
    }

    /**
     * Core's versions of the whole workspace, selected as the module's
     * actions for the entire workspace select them.
     *
     * Without page 0 among the editor's mounts, core looks below the mounts
     * only, as many levels deep as it is told. In a workspace with mount
     * points the editor's mounts are the workspace's (an administrator's as
     * well, whose mount is page 0 otherwise), so depth 0 would find the
     * drafts on the mount pages and none on the pages below them.
     *
     * A mount point without a page — deleted since the workspace was set up —
     * makes core fail on the page tree ("Undefined array key", an exception
     * in the Development context). Such mount points are logged and left
     * out: core is asked below each of the others instead, which costs one
     * scan per mount point until the workspace record is corrected.
     *
     * @return array<mixed> core's version rows by table
     */
    private function versions(int $workspaceId): array
    {
        $mounts = $this->webmounts();
        $missing = in_array(0, $mounts, true) ? [] : $this->missingPages($mounts);
        if ($missing === []) {
            return $this->workspaceService->selectVersionsInWorkspace($workspaceId, -99, -1, self::ALL_LEVELS, 'tables_select');
        }

        $this->logger->warning(
            'Workspace {workspace} has mount points without a page: {pages}. Easy Workspace lists the changes below its other mount points; correct the mount points of the workspace record.',
            ['workspace' => $workspaceId, 'pages' => implode(',', $missing)],
        );
        $versions = [];
        foreach (array_diff($mounts, $missing) as $mount) {
            $mountVersions = $this->workspaceService->selectVersionsInWorkspace($workspaceId, -99, $mount, self::ALL_LEVELS, 'tables_select');
            foreach ($mountVersions as $table => $records) {
                foreach (is_array($records) ? $records : [] as $record) {
                    // Root-level records, and the pages of nested mounts,
                    // come with every mount that covers them.
                    $uid = Value::int(is_array($record) ? ($record['uid'] ?? null) : null);
                    $versions[(string)$table][$uid] ??= $record;
                }
            }
        }

        return $versions;
    }

    /**
     * The mounts core reads for the whole workspace.
     *
     * @return list<int>
     */
    private function webmounts(): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user->getWebmounts() : [];
    }

    /**
     * The mounts without a page record, looked up as core looks up the pages
     * of a tree: deleted pages do not count.
     *
     * @param list<int> $mounts
     * @return list<int>
     */
    private function missingPages(array $mounts): array
    {
        if ($mounts === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $pages = $queryBuilder->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($mounts, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_diff($mounts, array_map(Value::int(...), $pages)));
    }

    /**
     * Per version uid: whether it differs from its live record. Two queries
     * per ROW_CHUNK versions of a table (the versions, their live rows), once
     * per revision as part of the cached scan; a large workspace never holds
     * more than one chunk of full rows.
     *
     * @param array<mixed> $records core's version rows of one table
     * @return array<int, bool>
     */
    private function contentChanges(string $table, array $records): array
    {
        $versionUids = [];
        foreach ($records as $record) {
            $uid = Value::int(is_array($record) ? ($record['uid'] ?? null) : null);
            if ($uid > 0) {
                $versionUids[] = $uid;
            }
        }

        $changes = [];
        foreach (array_chunk(array_values(array_unique($versionUids)), self::ROW_CHUNK) as $chunk) {
            $versionRows = $this->fullRows($table, $chunk);
            $liveUids = [];
            foreach ($versionRows as $row) {
                $liveUid = Value::int($row['t3ver_oid'] ?? null);
                if ($liveUid > 0) {
                    $liveUids[] = $liveUid;
                }
            }
            $liveRows = $this->fullRows($table, $liveUids);
            foreach ($versionRows as $uid => $row) {
                // A new, deleted or moved record is a change by what it is.
                $changes[$uid] = Value::int($row['t3ver_state'] ?? null) !== 0
                    || ContentChangeDetector::differs($row, $liveRows[Value::int($row['t3ver_oid'] ?? null)] ?? null);
            }
        }

        return $changes;
    }

    /**
     * @param list<int> $uids at most ROW_CHUNK
     * @return array<int, array<string, mixed>> keyed by uid; deleted and hidden rows included
     */
    private function fullRows(string $table, array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery();
        $rows = [];
        while ($row = $result->fetchAssociative()) {
            $rows[Value::int($row['uid'] ?? null)] = Value::stringKeyArray($row);
        }

        return $rows;
    }

    /**
     * CollectionService nests the rows that depend on another one below it.
     * It is a singleton whose resolver keeps every element it was ever
     * given; a fresh one nests just these rows.
     *
     * @param list<WorkspaceChange> $rows
     * @return list<WorkspaceChange>
     */
    private function nest(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $byId = [];
        $input = [];
        $collection = new CollectionService($this->eventDispatcher);
        $resolver = $collection->getDependencyResolver();
        foreach ($rows as $row) {
            $id = $row->table . ':' . $row->workspaceUid;
            $byId[$id] = $row;
            $input[$id] = ['id' => $id, 'table' => $row->table, 'uid' => $row->workspaceUid, 'liveUid' => $row->liveUid];
            $resolver->addElement($row->table, $row->workspaceUid);
        }

        // process() returns each top-level row followed by all rows nested
        // below it, so a nested row belongs to the last top-level row.
        $entries = [];
        $children = [];
        foreach ($collection->process($input) as $processed) {
            $id = Value::string($processed['table'] ?? null) . ':' . Value::int($processed['uid'] ?? null);
            $change = $byId[$id] ?? new WorkspaceChange(
                table: Value::string($processed['table'] ?? null),
                workspaceUid: Value::int($processed['uid'] ?? null),
                liveUid: Value::int($processed['liveUid'] ?? null),
            );
            if (Value::int($processed['Workspaces_CollectionLevel'] ?? null) === 0 || $entries === []) {
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

    private function ignoresRootLevelRestriction(string $table): bool
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return false;
        }
        $capability = $this->tcaSchemaFactory->get($table)->getCapability(TcaSchemaCapability::RestrictionRootLevel);

        return $capability instanceof RootLevelCapability && $capability->shallIgnoreRootLevelRestriction();
    }

    private function languageField(string $table): ?string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return null;
        }
        $schema = $this->tcaSchemaFactory->get($table);

        return $schema->isLanguageAware()
            ? $schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName()
            : null;
    }

    private function translationParentField(string $table): ?string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return null;
        }
        $schema = $this->tcaSchemaFactory->get($table);

        return $schema->isLanguageAware()
            ? $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName()
            : null;
    }

    private function userUid(): int
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? Value::int($user->user['uid'] ?? null) : 0;
    }
}
