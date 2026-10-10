<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeKind;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The translation copies a write takes along, decided on the server.
 *
 * Callers name the records they were given — the dropdown its rows, the
 * module one checkbox, the Visual Editor one decline button — so the
 * companions are added here, from core's list, and never trusted from a
 * client. Before anything is written they are checked again on fresh rows:
 * the list is cached for a revision, and a translation someone edited since
 * then is a change of its own that must neither go live nor be discarded
 * along.
 *
 * The check also walks what core's publish and discard will act on below
 * each record taken along (CoreWorkspaceChanges::descendants()): core's list
 * is the editor's, and a version below a copy that the list left out (a file
 * reference outside the editor's file mounts) may hold an edit nobody here
 * has seen. It walks up as well (CoreWorkspaceChanges::parentVersions()):
 * core's publish and stage change start at a record's outermost parent, and
 * a version above a translated child that the list left out takes its own
 * edits and its other children along. Such a record is never taken along or
 * cleaned up.
 *
 * Owners are looked up among their page's entries — the list the dropdown
 * or the module has just read — and among the whole workspace's only when
 * the page's do not hold them. Core's list reads the backend user from the
 * global; a write may be made for another user (CLI, MCP), so the list is
 * read as that user.
 */
final readonly class CompanionResolver
{
    public function __construct(
        private CoreWorkspaceChanges $core,
        private CompanionClassifier $classifier,
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * The whole workspace's entries, as $user sees them.
     *
     * @return list<WorkspaceChange>
     */
    public function entries(int $workspaceId, BackendUserAuthentication $user): array
    {
        return $this->asUser($user, fn(): array => $this->core->entries($workspaceId));
    }

    /**
     * The top-level entry whose own record this is; null for a nested
     * record, a companion or a record the list does not hold.
     */
    public function ownerEntry(int $workspaceId, string $table, int $workspaceUid, BackendUserAuthentication $user): ?WorkspaceChange
    {
        return $this->ownerEntries($workspaceId, [['table' => $table, 'workspaceUid' => $workspaceUid]], $user)[$table . ':' . $workspaceUid] ?? null;
    }

    /**
     * The top-level entries of the selected records, by table:workspaceUid;
     * a selection the list holds no entry for is left out.
     *
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array<string, WorkspaceChange>
     */
    public function ownerEntries(int $workspaceId, array $selections, BackendUserAuthentication $user): array
    {
        $wanted = [];
        foreach ($selections as $selection) {
            $wanted[$selection['table'] . ':' . $selection['workspaceUid']] = true;
        }
        if ($wanted === [] || $workspaceId <= 0) {
            return [];
        }

        return $this->asUser($user, function () use ($workspaceId, $selections, $wanted): array {
            $found = [];
            $pages = $this->pagesOf($selections);
            foreach (array_unique(array_values($pages)) as $pageUid) {
                foreach ($this->core->entries($workspaceId, $pageUid) as $entry) {
                    $key = $entry->table . ':' . $entry->workspaceUid;
                    if (isset($wanted[$key]) && ($pages[$key] ?? null) === $pageUid) {
                        $found[$key] = $entry;
                    }
                }
            }
            if (count($found) < count($wanted)) {
                // A root-level record, a page the version is not listed on:
                // the whole workspace's list.
                foreach ($this->core->entries($workspaceId) as $entry) {
                    $key = $entry->table . ':' . $entry->workspaceUid;
                    if (isset($wanted[$key])) {
                        $found[$key] ??= $entry;
                    }
                }
            }

            return $found;
        });
    }

    /**
     * The owner's companions that still mirror it, by its fresh rows and
     * theirs. A companion whose row is gone is in neither list.
     *
     * @return array{verified: list<WorkspaceChange>, skipped: list<WorkspaceChange>}
     */
    public function verifiedCompanions(int $workspaceId, WorkspaceChange $owner): array
    {
        return $this->verifiedCompanionsOf($workspaceId, [$owner])[0];
    }

    /**
     * The selection plus the verified companions of every selected owner
     * (their top records: core takes what is nested below them along).
     *
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array{selections: list<array{table: string, workspaceUid: int}>, added: int, skipped: list<array{table: string, workspaceUid: int, languageUid: int}>}
     */
    public function expand(int $workspaceId, array $selections, BackendUserAuthentication $user): array
    {
        $selected = [];
        foreach ($selections as $selection) {
            $selected[$selection['table'] . ':' . $selection['workspaceUid']] = true;
        }
        $owners = array_values(array_filter(
            $this->ownerEntries($workspaceId, $selections, $user),
            static fn(WorkspaceChange $entry): bool => $entry->companions !== [],
        ));

        $added = 0;
        $skipped = [];
        foreach ($this->verifiedCompanionsOf($workspaceId, $owners) as ['verified' => $verified, 'skipped' => $notVerified]) {
            foreach ($verified as $companion) {
                $key = $companion->table . ':' . $companion->workspaceUid;
                if (!isset($selected[$key])) {
                    $selected[$key] = true;
                    $selections[] = ['table' => $companion->table, 'workspaceUid' => $companion->workspaceUid];
                    ++$added;
                }
            }
            foreach ($notVerified as $companion) {
                $skipped[] = ['table' => $companion->table, 'workspaceUid' => $companion->workspaceUid, 'languageUid' => $companion->languageUid ?? 0];
            }
        }

        return ['selections' => $selections, 'added' => $added, 'skipped' => $skipped];
    }

    /**
     * The copy entries still safe to discard: every node of an entry — its
     * own, its companions' and what is nested below them — is still there,
     * in this workspace, and identical to live, and nothing is nested below
     * or above them in the workspace that the entry does not hold. A copy
     * with dependents — translations core's discard of it would remove
     * along, which the cleanup does not ask about — is never discarded here.
     *
     * @param list<WorkspaceChange> $copyEntries
     * @return array{discardable: list<WorkspaceChange>, skipped: list<WorkspaceChange>}
     */
    public function verifiedCopies(int $workspaceId, array $copyEntries): array
    {
        $nodes = [];
        $tops = [];
        foreach ($copyEntries as $entry) {
            foreach ([$entry, ...$entry->companions] as $top) {
                $tops[] = $top;
            }
            foreach (self::nodes([$entry, ...$entry->companions]) as $node) {
                $nodes[] = $node;
            }
        }
        $fresh = $nodes === [] ? [] : $this->core->refreshFacts($workspaceId, $nodes);
        $unlisted = $this->reachingUnlisted($workspaceId, $tops);

        $discardable = [];
        $skipped = [];
        foreach ($copyEntries as $entry) {
            $safe = $entry->dependents === [];
            foreach ($safe ? [$entry, ...$entry->companions] : [] as $top) {
                if (isset($unlisted[$top->table . ':' . $top->workspaceUid])) {
                    $safe = false;
                    break;
                }
            }
            foreach ($safe ? self::nodes([$entry, ...$entry->companions]) : [] as $node) {
                $freshNode = $fresh[$node->table . ':' . $node->workspaceUid] ?? null;
                if ($freshNode === null || $freshNode->kind() !== ChangeKind::Identical) {
                    $safe = false;
                    break;
                }
            }
            if ($safe) {
                $discardable[] = $entry;
            } else {
                $skipped[] = $entry;
            }
        }

        return ['discardable' => $discardable, 'skipped' => $skipped];
    }

    /**
     * Each owner's companions, verified on fresh rows read for all owners at
     * once: a companion holds against the node it was translated from, and
     * only when its chain of origins reaches the owner through companions
     * that hold themselves — a translation of a translation is verified only
     * together with the companion it was translated from. A companion with
     * a version below or above it that the list does not hold is never
     * verified.
     *
     * @param list<WorkspaceChange> $owners
     * @return list<array{verified: list<WorkspaceChange>, skipped: list<WorkspaceChange>}> in the owners' order
     */
    private function verifiedCompanionsOf(int $workspaceId, array $owners): array
    {
        $nodes = [];
        $companions = [];
        foreach ($owners as $owner) {
            if ($owner->companions === []) {
                continue;
            }
            foreach (self::nodes([$owner, ...$owner->companions]) as $node) {
                $nodes[] = $node;
            }
            foreach ($owner->companions as $companion) {
                $companions[] = $companion;
            }
        }
        $fresh = $nodes === [] ? [] : $this->core->refreshFacts($workspaceId, $nodes);
        $unlisted = $this->reachingUnlisted($workspaceId, $companions);
        $sortFields = $this->sortFields($nodes);

        return array_map(
            fn(WorkspaceChange $owner): array => $this->verifyAgainst($owner, $fresh, $unlisted, $sortFields),
            $owners,
        );
    }

    /**
     * @param array<string, WorkspaceChange> $fresh
     * @param array<string, true> $unlisted
     * @param array<string, string|null> $sortFields
     * @return array{verified: list<WorkspaceChange>, skipped: list<WorkspaceChange>}
     */
    private function verifyAgainst(WorkspaceChange $owner, array $fresh, array $unlisted, array $sortFields): array
    {
        if ($owner->companions === []) {
            return ['verified' => [], 'skipped' => []];
        }
        $freshOwner = self::rebuilt($owner, $fresh);
        $freshCompanions = [];
        foreach ($owner->companions as $companion) {
            $freshCompanion = self::rebuilt($companion, $fresh);
            if ($freshCompanion !== null) {
                $freshCompanions[] = $freshCompanion;
            }
        }
        if ($freshOwner === null) {
            return ['verified' => [], 'skipped' => $freshCompanions];
        }

        // The owner and its companions are the node set a companion's
        // origin is looked up in: a translation of a translation mirrors
        // its source, which is a companion itself.
        $tops = [$freshOwner, ...$freshCompanions];
        $index = CompanionClassifier::nodeIndex($tops);

        // Pass 1: the position each companion holds against (0 = the owner).
        $originOf = [];
        foreach ($freshCompanions as $position => $companion) {
            $origin = CompanionClassifier::origin($companion, $index);
            $originOf[$position + 1] = $origin !== null
                && $origin[0] !== $position + 1
                && !isset($unlisted[$companion->table . ':' . $companion->workspaceUid])
                && $this->classifier->holds($companion, $origin[1], $tops[$origin[0]], $sortFields)
                    ? $origin[0]
                    : null;
        }

        // Pass 2: a companion is verified when its chain reaches the owner;
        // a chain that breaks at a companion that does not hold, or loops,
        // does not.
        $verified = [];
        $skipped = [];
        foreach ($freshCompanions as $position => $companion) {
            $at = $position + 1;
            $seen = [];
            while ($at !== null && $at !== 0 && !isset($seen[$at])) {
                $seen[$at] = true;
                $at = $originOf[$at] ?? null;
            }
            if ($at === 0) {
                $verified[] = $companion;
            } else {
                $skipped[] = $companion;
            }
        }

        return ['verified' => $verified, 'skipped' => $skipped];
    }

    /**
     * The tops with a version below them in the workspace that they do not
     * hold as a child, or with a version above them (a top holds none): what
     * core's write would act on unseen.
     *
     * @param list<WorkspaceChange> $tops
     * @return array<string, true> table:workspaceUid
     */
    private function reachingUnlisted(int $workspaceId, array $tops): array
    {
        if ($tops === []) {
            return [];
        }
        $held = [];
        foreach ($tops as $top) {
            foreach ($top->children as $child) {
                $held[$top->table . ':' . $top->workspaceUid][$child->table . ':' . $child->workspaceUid] = true;
            }
        }
        $unlisted = $this->core->parentVersions($workspaceId, $tops);
        foreach ($this->core->descendants($workspaceId, $tops) as $topKey => $descendants) {
            if (array_diff_key($descendants, $held[$topKey] ?? []) !== []) {
                $unlisted[$topKey] = true;
            }
        }

        return $unlisted;
    }

    /**
     * The page whose entries hold each selected record as core lists them
     * (WorkspaceChange::belongsToPage()): the page a version lives on, a
     * page record's own page (its default-language page for a translation).
     * Root-level records have none.
     *
     * @param list<array{table: string, workspaceUid: int}> $selections
     * @return array<string, int> table:workspaceUid => page uid
     */
    private function pagesOf(array $selections): array
    {
        $byTable = [];
        foreach ($selections as $selection) {
            if ($selection['workspaceUid'] > 0 && $this->tcaSchemaFactory->has($selection['table'])) {
                $byTable[$selection['table']][] = $selection['workspaceUid'];
            }
        }

        $pages = [];
        foreach ($byTable as $table => $uids) {
            $schema = $this->tcaSchemaFactory->get($table);
            $parentField = $table === 'pages' && $schema->isLanguageAware()
                ? $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName()
                : null;
            $columns = array_values(array_filter(['uid', 'pid', 't3ver_oid', $parentField], is_string(...)));
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder->select(...$columns)
                ->from($table)
                ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter(array_values(array_unique($uids)), Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $uid = Value::int($row['uid'] ?? null);
                $page = $table === 'pages'
                    ? (($parentField !== null ? Value::int($row[$parentField] ?? null) : 0) ?: (Value::int($row['t3ver_oid'] ?? null) ?: $uid))
                    : Value::int($row['pid'] ?? null);
                if ($page > 0) {
                    $pages[$table . ':' . $uid] = $page;
                }
            }
        }

        return $pages;
    }

    /**
     * Each entry and what is nested below it, flat.
     *
     * @param list<WorkspaceChange> $entries
     * @return list<WorkspaceChange>
     */
    private static function nodes(array $entries): array
    {
        $nodes = [];
        foreach ($entries as $entry) {
            $nodes[] = $entry;
            foreach ($entry->children as $child) {
                $nodes[] = $child;
            }
        }

        return $nodes;
    }

    /**
     * The entry with its fresh facts and those of its children still there;
     * null when its own row is gone.
     *
     * @param array<string, WorkspaceChange> $fresh
     */
    private static function rebuilt(WorkspaceChange $entry, array $fresh): ?WorkspaceChange
    {
        $freshEntry = $fresh[$entry->table . ':' . $entry->workspaceUid] ?? null;
        if ($freshEntry === null) {
            return null;
        }
        $children = [];
        foreach ($entry->children as $child) {
            $freshChild = $fresh[$child->table . ':' . $child->workspaceUid] ?? null;
            if ($freshChild !== null) {
                $children[] = $freshChild;
            }
        }

        return $freshEntry->withChildren($children);
    }

    /**
     * @param list<WorkspaceChange> $nodes
     * @return array<string, string|null>
     */
    private function sortFields(array $nodes): array
    {
        $fields = [];
        foreach ($nodes as $node) {
            if (!array_key_exists($node->table, $fields)) {
                $fields[$node->table] = $this->core->sortField($node->table);
            }
        }

        return $fields;
    }

    /**
     * Reads core's list as $user: core takes the user from the global.
     *
     * @template T
     * @param \Closure(): T $read
     * @return T
     */
    private function asUser(BackendUserAuthentication $user, \Closure $read): mixed
    {
        $current = $GLOBALS['BE_USER'] ?? null;
        if ($current instanceof BackendUserAuthentication && self::uid($current) === self::uid($user)) {
            return $read();
        }
        $GLOBALS['BE_USER'] = $user;
        try {
            return $read();
        } finally {
            if ($current === null) {
                unset($GLOBALS['BE_USER']);
            } else {
                $GLOBALS['BE_USER'] = $current;
            }
        }
    }

    private static function uid(BackendUserAuthentication $user): int
    {
        return Value::int($user->user['uid'] ?? null);
    }
}
