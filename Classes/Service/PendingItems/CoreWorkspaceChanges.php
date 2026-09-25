<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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
 * with the editor's table and page permissions — and nests every record
 * that depends on another one below it: collection items, file references,
 * inline children (CollectionService, the step GridDataService runs before
 * it renders the module's grid). An entry is one row of that grid's top
 * level; the versions nested below it come with it. A changed collection
 * item of an unchanged element is a row of its own, as in the module.
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

    public function __construct(
        private WorkspaceService $workspaceService,
        private EventDispatcherInterface $eventDispatcher,
        private TcaSchemaFactory $tcaSchemaFactory,
        private WorkspaceRevision $revision,
        private FrontendInterface $cache,
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
        $versions = $this->workspaceService->selectVersionsInWorkspace($workspaceId, -99, -1, 0, 'tables_select');
        foreach ($versions as $table => $records) {
            $table = (string)$table;
            $languageField = $this->languageField($table);
            $translationParentField = $this->translationParentField($table);
            foreach (is_array($records) ? $records : [] as $record) {
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
                );
            }
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
