<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Schema\Capability\RootLevelCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Workspaces\Dependency\DependencyCollectionAction;
use TYPO3\CMS\Workspaces\Dependency\ElementEntity;
use TYPO3\CMS\Workspaces\Event\IsReferenceConsideredForDependencyEvent;
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
 *
 * On top of the module's rows, each entry is classified (CompanionClassifier):
 * the translation copies TYPO3 makes along with an edit fold into the edited
 * entry, and entries that change nothing at all are told apart as copies.
 * The facts that takes — version state, language, translation pointers, the
 * changed fields — come from the full rows the scan reads anyway, plus the
 * relations a version keeps in MM tables (ManyToManyRelations). The parent
 * pointer is kept as stored as well: core's discard of a version takes every
 * translation pointing at that version's uid along, and the classification
 * names those translations on the entry (its dependents).
 *
 * Core's list is the editor's: a version the editor may not see (a file
 * reference outside their file mounts) is left out, while core's publish and
 * discard still act on it. An entry with such a version below it is marked
 * (hasUnlistedDescendants) and never taken for a copy or a companion; right
 * before a write, descendants() walks what core will act on. Core's publish
 * and stage change also act on the outermost parent of a record: an entry
 * below a version the list left out is marked as well
 * (hasUnlistedAncestors), and parentVersions() walks up before a write.
 */
final readonly class CoreWorkspaceChanges
{
    private const int LIFETIME = 300;

    /**
     * Part of every cache identifier: bumped whenever the cached rows or
     * entries change shape or meaning, so a deploy never serves arrays of
     * the previous release until the revision moves.
     */
    private const string CACHE_VERSION = '5';

    /** Versions compared per round trip (plus their live rows). */
    private const int ROW_CHUNK = 500;

    /**
     * The facts WorkspaceChange::fromArray() restores when they are missing;
     * a cached change leaves them out.
     *
     * @var array<string, mixed>
     */
    private const array DEFAULTS = [
        'pid' => 0,
        'languageUid' => null,
        'translationParent' => 0,
        'isMoved' => false,
        'contentChanged' => true,
        'versionState' => 0,
        'translationSource' => 0,
        'changedFields' => [],
        'role' => null,
        'dependents' => [],
        'copyGroup' => '',
        'hasUnlistedDescendants' => false,
        'hasUnlistedAncestors' => false,
    ];

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
        private CompanionClassifier $classifier,
        private ManyToManyRelations $manyToManyRelations,
    ) {}

    /**
     * The module's rows, classified: each top-level entry knows whether it
     * is a change or a copy, and carries the translation copies folded into
     * it (see CompanionClassifier).
     *
     * The language filter applies to the classified entries, by the owner's
     * language: filtering first would cut a translation copy off the change
     * it belongs to and leave it as an orphan.
     *
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
        $identifier = sha1(implode('|', ['entries', self::CACHE_VERSION, $workspaceId, $pageUid, $languageUid ?? 'all', $this->userUid()]));
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
        $entries = $this->classifier->classify($this->nest($rows), $this->sortFields($rows));
        if ($languageUid !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn(WorkspaceChange $entry): bool => $entry->matchesLanguage($languageUid),
            ));
        }
        $this->cache->set($identifier, [
            'revision' => $revision,
            'expires' => time() + self::LIFETIME,
            'entries' => array_map(self::changeToArray(...), $entries),
        ]);

        return $entries;
    }

    /**
     * The facts of these nodes read again from their rows, bypassing every
     * cache: what a destructive action re-checks right before it writes.
     * Nodes whose version row is gone, deleted or in another workspace are
     * dropped. A translation pointer at a version is mapped through the
     * nodes themselves (their live uids).
     *
     * Two queries per ROW_CHUNK nodes of a table (the versions, their live
     * rows), plus the MM relations of tables that have them.
     *
     * @param list<WorkspaceChange> $nodes
     * @return array<string, WorkspaceChange> keyed by table:workspaceUid
     */
    public function refreshFacts(int $workspaceId, array $nodes): array
    {
        $byTable = [];
        $liveByTable = [];
        foreach ($nodes as $node) {
            $byTable[$node->table][$node->workspaceUid] = $node;
            $liveByTable[$node->table][$node->workspaceUid] = $node->liveUid;
        }

        $fresh = [];
        foreach ($byTable as $table => $tableNodes) {
            if ($workspaceId <= 0 || !$this->tcaSchemaFactory->has($table)) {
                continue;
            }
            $deletedField = $this->softDeleteField($table);
            $facts = $this->facts(
                $table,
                array_keys($tableNodes),
                $liveByTable[$table],
                static fn(array $row): bool => Value::int($row['t3ver_wsid'] ?? null) === $workspaceId
                    && ($deletedField === null || Value::int($row[$deletedField] ?? null) === 0),
            );
            foreach ($facts as $uid => $fact) {
                $fresh[$table . ':' . $uid] = $tableNodes[$uid]->withFacts(
                    $fact['versionState'],
                    $fact['languageUid'],
                    $fact['translationParent'],
                    $fact['translationSource'],
                    $fact['changedFields'],
                    $fact['rawTranslationParent'],
                );
            }
        }

        return $fresh;
    }

    /**
     * Every version nested below these records in the workspace, as core's
     * publish reaches them (DependencyResolver, ElementEntity::getChildren()):
     * the references in sys_refindex that core's dependency listeners call
     * structural — inline, file, FlexForm — followed down to every version,
     * whoever may see it. What a write acts on, not what the editor's list
     * holds; one query per table and level, not one per record.
     *
     * @param list<WorkspaceChange> $tops
     * @return array<string, array<string, true>> table:workspaceUid of each top => table:workspaceUid of its descendants
     */
    public function descendants(int $workspaceId, array $tops): array
    {
        $descendants = [];
        $frontier = [];
        foreach ($tops as $top) {
            $key = $top->table . ':' . $top->workspaceUid;
            $descendants[$key] = [];
            $frontier[$key][$key] = true;
        }

        while ($frontier !== [] && $workspaceId > 0) {
            $byTable = [];
            foreach (array_keys($frontier) as $key) {
                [$table, $uid] = explode(':', $key, 2);
                $byTable[$table][] = (int)$uid;
            }
            // Referenced record (live or version) => the tops it lies below.
            $references = [];
            foreach ($byTable as $table => $uids) {
                foreach (array_chunk($uids, self::ROW_CHUNK) as $chunk) {
                    foreach ($this->dependencyReferences($workspaceId, $table, $chunk) as [$uid, $referenceKey]) {
                        foreach (array_keys($frontier[$table . ':' . $uid] ?? []) as $topKey) {
                            $references[$referenceKey][$topKey] = true;
                        }
                    }
                }
            }

            $next = [];
            foreach ($this->versionsOf($workspaceId, array_keys($references)) as $referenceKey => $versionKey) {
                foreach (array_keys($references[$referenceKey]) as $topKey) {
                    if ($versionKey === $topKey || isset($descendants[$topKey][$versionKey])) {
                        continue;
                    }
                    $descendants[$topKey][$versionKey] = true;
                    $next[$versionKey][$topKey] = true;
                }
            }
            $frontier = $next;
        }

        return $descendants;
    }

    /**
     * The tops that lie below a version of the workspace, as core's publish
     * and stage change go up (DependencyResolver, ElementEntity::getParents()
     * and getOuterMostParent()): a structural reference in sys_refindex to
     * the top's version or its live record, from a record that is a version
     * of the workspace or has one. Such a parent and everything nested below
     * it are published or staged along with the top. A top is a row of the
     * list's top level, so the list holds no such parent above it: it would
     * have nested the top below it. One query per table, not one per record.
     *
     * @param list<WorkspaceChange> $tops
     * @return array<string, true> table:workspaceUid of each top with a parent version
     */
    public function parentVersions(int $workspaceId, array $tops): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        // Referenced record (version or live) => the tops it stands for.
        $topsOf = [];
        $byTable = [];
        foreach ($tops as $top) {
            $topKey = $top->table . ':' . $top->workspaceUid;
            foreach (array_unique([$top->workspaceUid, $top->liveUid]) as $uid) {
                if ($uid > 0) {
                    $topsOf[$top->table . ':' . $uid][$topKey] = true;
                    $byTable[$top->table][] = $uid;
                }
            }
        }

        // Referencing record => the tops it is a parent of.
        $parents = [];
        foreach ($byTable as $table => $uids) {
            foreach (array_chunk(array_values(array_unique($uids)), self::ROW_CHUNK) as $chunk) {
                foreach ($this->dependencyReferrers($workspaceId, $table, $chunk) as [$parentKey, $referencedKey]) {
                    foreach (array_keys($topsOf[$referencedKey] ?? []) as $topKey) {
                        $parents[$parentKey][$topKey] = true;
                    }
                }
            }
        }

        $withParent = [];
        foreach ($this->versionsOf($workspaceId, array_keys($parents)) as $parentKey => $versionKey) {
            foreach (array_keys($parents[$parentKey]) as $topKey) {
                if ($versionKey !== $topKey) {
                    $withParent[$topKey] = true;
                }
            }
        }

        return $withParent;
    }

    /**
     * The records referencing these ones structurally in the workspace
     * (IsReferenceConsideredForDependencyEvent, as core asks it for a
     * publish).
     *
     * @param list<int> $uids
     * @return list<array{string, string}> [tablename:recuid, ref_table:ref_uid]
     */
    private function dependencyReferrers(int $workspaceId, string $table, array $uids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $result = $queryBuilder->select('tablename', 'recuid', 'field', 'ref_uid')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->in('ref_uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            )
            ->executeQuery();
        $referrers = [];
        while ($row = $result->fetchAssociative()) {
            $parentTable = Value::string($row['tablename'] ?? null);
            $parentUid = Value::int($row['recuid'] ?? null);
            $referenceUid = Value::int($row['ref_uid'] ?? null);
            if ($parentTable === '' || $parentUid <= 0 || !$this->tcaSchemaFactory->has($parentTable)) {
                continue;
            }
            $event = $this->eventDispatcher->dispatch(new IsReferenceConsideredForDependencyEvent(
                $parentTable,
                $parentUid,
                Value::string($row['field'] ?? null),
                $table,
                $referenceUid,
                DependencyCollectionAction::Publish,
                $workspaceId,
            ));
            if ($event instanceof IsReferenceConsideredForDependencyEvent && $event->isDependency()) {
                $referrers[] = [$parentTable . ':' . $parentUid, $table . ':' . $referenceUid];
            }
        }

        return $referrers;
    }

    /**
     * The references of these versions that core's dependency listeners
     * consider structural (IsReferenceConsideredForDependencyEvent).
     *
     * @param list<int> $uids
     * @return list<array{int, string}> [recuid, ref_table:ref_uid]
     */
    private function dependencyReferences(int $workspaceId, string $table, array $uids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $result = $queryBuilder->select('recuid', 'field', 'ref_table', 'ref_uid')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->in('recuid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('ref_table', $queryBuilder->createNamedParameter('_STRING')),
            )
            ->orderBy('recuid')
            ->addOrderBy('sorting')
            ->executeQuery();
        $references = [];
        while ($row = $result->fetchAssociative()) {
            $uid = Value::int($row['recuid'] ?? null);
            $referenceTable = Value::string($row['ref_table'] ?? null);
            $referenceUid = Value::int($row['ref_uid'] ?? null);
            $event = $this->eventDispatcher->dispatch(new IsReferenceConsideredForDependencyEvent(
                $table,
                $uid,
                Value::string($row['field'] ?? null),
                $referenceTable,
                $referenceUid,
                DependencyCollectionAction::Publish,
                $workspaceId,
            ));
            if ($event instanceof IsReferenceConsideredForDependencyEvent && $event->isDependency() && $referenceUid > 0) {
                $references[] = [$uid, $referenceTable . ':' . $referenceUid];
            }
        }

        return $references;
    }

    /**
     * The version in this workspace each referenced record stands for, as
     * ElementEntity resolves it: a version of the workspace is itself, a live
     * record is its version here; anything else (no version, a deleted one,
     * another workspace's, a table without versioning) has none.
     *
     * @param list<string> $keys table:uid
     * @return array<string, string> table:uid => table:versionUid
     */
    private function versionsOf(int $workspaceId, array $keys): array
    {
        $byTable = [];
        foreach ($keys as $key) {
            [$table, $uid] = explode(':', $key, 2);
            if ($this->tcaSchemaFactory->has($table) && $this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
                $byTable[$table][] = (int)$uid;
            }
        }

        $versions = [];
        foreach ($byTable as $table => $uids) {
            $deletedField = $this->softDeleteField($table);
            foreach (array_chunk($uids, self::ROW_CHUNK) as $chunk) {
                $liveUids = [];
                foreach ($this->versionColumns($table, 'uid', $chunk) as $row) {
                    $uid = Value::int($row['uid'] ?? null);
                    $liveUid = Value::int($row['t3ver_oid'] ?? null);
                    $rowWorkspace = Value::int($row['t3ver_wsid'] ?? null);
                    $deleted = $deletedField !== null && Value::int($row[$deletedField] ?? null) > 0;
                    if ($rowWorkspace === $workspaceId && !$deleted
                        && ($liveUid > 0 || Value::int($row['t3ver_state'] ?? null) === 1)
                    ) {
                        // A version of this workspace, or a record new in it.
                        $versions[$table . ':' . $uid] = $table . ':' . $uid;
                    } elseif ($rowWorkspace === 0) {
                        $liveUids[] = $uid;
                    }
                }
                if ($liveUids === []) {
                    continue;
                }
                // A live record stands for its version here, if it has one.
                foreach ($this->versionColumns($table, 't3ver_oid', $liveUids) as $row) {
                    if (Value::int($row['t3ver_wsid'] ?? null) === $workspaceId
                        && ($deletedField === null || Value::int($row[$deletedField] ?? null) === 0)
                    ) {
                        $versions[$table . ':' . Value::int($row['t3ver_oid'] ?? null)] ??= $table . ':' . Value::int($row['uid'] ?? null);
                    }
                }
            }
        }

        return $versions;
    }

    /**
     * The version columns of the rows whose $field is one of $uids.
     *
     * @param list<int> $uids
     * @return list<array<string, mixed>>
     */
    private function versionColumns(string $table, string $field, array $uids): array
    {
        $deletedField = $this->softDeleteField($table);
        $columns = ['uid', 't3ver_oid', 't3ver_wsid', 't3ver_state'];
        if ($deletedField !== null) {
            $columns[] = $deletedField;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return array_map(
            Value::stringKeyArray(...),
            $queryBuilder->select(...$columns)
                ->from($table)
                ->where($queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    /**
     * The field core keeps a record's position in (`sortby`), if any.
     */
    public function sortField(string $table): ?string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return null;
        }
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->hasCapability(TcaSchemaCapability::SortByField)) {
            return null;
        }
        $field = $schema->getCapability(TcaSchemaCapability::SortByField)->getFieldName();

        return $field !== '' ? $field : null;
    }

    /**
     * @param list<WorkspaceChange> $rows
     * @return array<string, string|null> table => sortby field
     */
    private function sortFields(array $rows): array
    {
        $fields = [];
        foreach ($rows as $row) {
            if (!array_key_exists($row->table, $fields)) {
                $fields[$row->table] = $this->sortField($row->table);
            }
        }

        return $fields;
    }

    /**
     * The cached shape of a change: facts that hold their default are left
     * out (WorkspaceChange::fromArray() restores them), and so are empty
     * children and companions — a flat row of the whole workspace's scan
     * carries its identity and the facts it really has. The stored parent
     * pointer is kept only where it differs from the mapped one (a pointer
     * at a version).
     *
     * @return array<string, mixed>
     */
    public static function changeToArray(WorkspaceChange $change): array
    {
        $array = [];
        foreach ($change->toArray() as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS) || $value !== self::DEFAULTS[$key]) {
                $array[$key] = $value;
            }
        }
        if ($change->rawTranslationParent === $change->translationParent) {
            unset($array['rawTranslationParent']);
        }
        if ($change->children !== []) {
            $array['children'] = array_map(self::changeToArray(...), $change->children);
        }
        if ($change->companions !== []) {
            $array['companions'] = array_map(self::changeToArray(...), $change->companions);
        }

        return $array;
    }

    public static function changeFromArray(mixed $row): WorkspaceChange
    {
        $row = Value::stringKeyArray($row);
        $change = WorkspaceChange::fromArray($row);
        $children = is_array($row['children'] ?? null) ? array_values($row['children']) : [];
        if ($children !== []) {
            $change = $change->withChildren(array_map(self::changeFromArray(...), $children));
        }
        $companions = is_array($row['companions'] ?? null) ? array_values($row['companions']) : [];

        return $companions === []
            ? $change
            : $change->withClassification($change->role, array_map(self::changeFromArray(...), $companions), $change->dependents, $change->copyGroup);
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
        $identifier = sha1(implode('|', ['rows', self::CACHE_VERSION, $workspaceId, $this->userUid()]));
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
            'rows' => array_map(self::changeToArray(...), $rows),
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
            // A translation's pointer at a version of this table stands for
            // that version's live record.
            $versionUids = [];
            $liveMap = [];
            foreach ($records as $record) {
                $uid = Value::int(is_array($record) ? ($record['uid'] ?? null) : null);
                $liveUid = Value::int(is_array($record) ? ($record['t3ver_oid'] ?? null) : null);
                if ($uid > 0) {
                    $versionUids[] = $uid;
                    $liveMap[$uid] = $liveUid ?: $uid;
                }
            }
            $facts = $this->facts($table, $versionUids, $liveMap);
            foreach ($records as $record) {
                $record = Value::stringKeyArray($record);
                $uid = Value::int($record['uid'] ?? null);
                $fact = $facts[$uid] ?? null;
                // A version without a row was removed since core selected it.
                if ($uid <= 0 || $fact === null) {
                    continue;
                }
                // A move pointer is the one row core selects without the
                // record's own columns (no pid, no language): only the
                // target page (wspid) and the live page. The full row has
                // the rest.
                $isMoved = !array_key_exists('pid', $record);
                $rows[] = new WorkspaceChange(
                    table: $table,
                    workspaceUid: $uid,
                    liveUid: Value::int($record['t3ver_oid'] ?? null) ?: $uid,
                    pid: Value::int($record['wspid'] ?? $record['pid'] ?? null),
                    isMoved: $isMoved,
                )->withFacts(
                    $isMoved ? 4 : $fact['versionState'],
                    $fact['languageUid'],
                    $fact['translationParent'],
                    $fact['translationSource'],
                    $fact['changedFields'],
                    $fact['rawTranslationParent'],
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
     * Per version uid: its facts from the full row (see WorkspaceChange).
     * Two queries per ROW_CHUNK versions of a table (the versions, their
     * live rows), once per revision as part of the cached scan; a large
     * workspace never holds more than one chunk of full rows.
     *
     * @param list<int> $versionUids
     * @param array<int, int> $liveMap uid => t3ver_oid of core's versions of this table
     * @param (\Closure(array<string, mixed>): bool)|null $keep Which version rows count (all by default).
     * @return array<int, array{versionState: int, languageUid: int|null, translationParent: int, translationSource: int, changedFields: array<string, string>, rawTranslationParent: int}>
     */
    private function facts(string $table, array $versionUids, array $liveMap, ?\Closure $keep = null): array
    {
        $liveOf = static fn(int $uid): int => $liveMap[$uid] ?? $uid;
        $facts = [];
        foreach (array_chunk(array_values(array_unique($versionUids)), self::ROW_CHUNK) as $chunk) {
            $rows = $this->fullRows($table, $chunk);
            if ($keep !== null) {
                $rows = array_filter($rows, $keep);
            }
            $facts += $this->factsOfRows($table, $rows, $liveOf);
        }

        return $facts;
    }

    /**
     * @param array<int, array<string, mixed>> $versionRows keyed by uid
     * @param \Closure(int): int $liveOf
     * @return array<int, array{versionState: int, languageUid: int|null, translationParent: int, translationSource: int, changedFields: array<string, string>, rawTranslationParent: int}>
     */
    private function factsOfRows(string $table, array $versionRows, \Closure $liveOf): array
    {
        $languageField = $this->languageField($table);
        $parentField = $this->translationParentField($table);
        $sourceField = $this->translationSourceField($table);
        $pointerFields = array_values(array_filter([$parentField, $sourceField], is_string(...)));

        $liveUids = [];
        foreach ($versionRows as $row) {
            $liveUid = Value::int($row['t3ver_oid'] ?? null);
            if ($liveUid > 0) {
                $liveUids[] = $liveUid;
            }
        }
        $liveRows = [];
        foreach (array_chunk(array_values(array_unique($liveUids)), self::ROW_CHUNK) as $chunk) {
            $liveRows += $this->fullRows($table, $chunk);
        }

        // Relations kept in MM tables are part of the version as well.
        $relations = $this->manyToManyRelations->changedFields($table, $versionRows, $liveRows);

        $facts = [];
        foreach ($versionRows as $uid => $row) {
            $state = Value::int($row['t3ver_state'] ?? null);
            $liveUid = Value::int($row['t3ver_oid'] ?? null);
            $changedFields = $state === 1 || $liveUid <= 0
                ? []
                : ContentChangeDetector::changedFields($row, $liveRows[$liveUid] ?? null, $pointerFields, $liveOf);
            foreach ($relations[$uid] ?? [] as $field => $hash) {
                $changedFields[$field] = isset($changedFields[$field]) ? sha1($changedFields[$field] . '|' . $hash) : $hash;
            }
            $facts[$uid] = [
                'versionState' => $state,
                'languageUid' => $languageField !== null && array_key_exists($languageField, $row) ? Value::int($row[$languageField]) : null,
                'translationParent' => $parentField !== null ? $liveOf(Value::int($row[$parentField] ?? null)) : 0,
                'translationSource' => $sourceField !== null ? $liveOf(Value::int($row[$sourceField] ?? null)) : 0,
                // A new record changes nothing: it is new.
                'changedFields' => $changedFields,
                // What core's discard looks translations up by.
                'rawTranslationParent' => $parentField !== null ? Value::int($row[$parentField] ?? null) : 0,
            ];
        }

        return $facts;
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
     * Its elements know every version below them (ElementEntity's nested
     * children, read from sys_refindex for the nesting anyway). An entry
     * with a version below it that is not nested in it — core left it out of
     * the editor's list, or it lives elsewhere — has unlisted descendants.
     * They also know their outermost parent (resolved for the nesting as
     * well): a top-level entry whose outermost parent is a version other
     * than itself lies below a version the list does not hold — had the list
     * held it, the entry would be nested below it — and has unlisted
     * ancestors.
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
        $elements = [];
        $collection = new CollectionService($this->eventDispatcher);
        $resolver = $collection->getDependencyResolver();
        foreach ($rows as $row) {
            $id = $row->table . ':' . $row->workspaceUid;
            $byId[$id] = $row;
            $input[$id] = ['id' => $id, 'table' => $row->table, 'uid' => $row->workspaceUid, 'liveUid' => $row->liveUid];
            $elements[$id] = $resolver->addElement($row->table, $row->workspaceUid);
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
            $entry = $entry->withChildren($children[$index]);
            $element = $elements[$entry->table . ':' . $entry->workspaceUid] ?? null;
            if ($element !== null && self::hasUnlistedDescendants($element, $entry)) {
                $entry = $entry->withUnlistedDescendants(true);
            }
            if ($element !== null && self::hasUnlistedAncestors($element)) {
                $entry = $entry->withUnlistedAncestors(true);
            }
            $entries[$index] = $entry;
        }

        return $entries;
    }

    /**
     * Whether core's publish of the element would start at another version:
     * its outermost parent is a version of the workspace (an element
     * ElementEntity did not mark invalid) other than the element itself.
     */
    private static function hasUnlistedAncestors(ElementEntity $element): bool
    {
        $outerMostParent = $element->getOuterMostParent();

        return $outerMostParent instanceof ElementEntity
            && $outerMostParent !== $element
            && (string)$outerMostParent !== (string)$element
            && !$outerMostParent->isInvalid();
    }

    /**
     * Whether a version below the element (one with a version in the
     * workspace: an element ElementEntity did not mark invalid) is not
     * among the entry's nested rows.
     */
    private static function hasUnlistedDescendants(ElementEntity $element, WorkspaceChange $entry): bool
    {
        $listed = [];
        foreach ($entry->children as $child) {
            $listed[$child->table . ':' . $child->workspaceUid] = true;
        }
        foreach ($element->getNestedChildren() as $descendant) {
            if (!$descendant->isInvalid() && !isset($listed[$descendant->getTable() . ':' . $descendant->getId()])) {
                return true;
            }
        }

        return false;
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

    private function translationSourceField(string $table): ?string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return null;
        }
        $schema = $this->tcaSchemaFactory->get($table);

        return $schema->isLanguageAware()
            ? $schema->getCapability(TcaSchemaCapability::Language)->getTranslationSourceField()?->getName()
            : null;
    }

    private function softDeleteField(string $table): ?string
    {
        $schema = $this->tcaSchemaFactory->get($table);

        return $schema->hasCapability(TcaSchemaCapability::SoftDelete)
            ? $schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName()
            : null;
    }

    private function userUid(): int
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? Value::int($user->user['uid'] ?? null) : 0;
    }
}
