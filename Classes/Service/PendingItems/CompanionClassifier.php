<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeKind;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;

/**
 * Tells the module's rows apart by what an editor did.
 *
 * Saving a default-language record makes TYPO3 version every translation of
 * it: core's DataMapProcessor writes the synchronized fields (l10n_mode
 * exclude, l10n_state parent/source) into each translation without comparing
 * values, and moves or deletes the translations along. Those versions carry
 * nothing of their own — at most the values their source got. Such a
 * translation is a companion of the entry it mirrors: it is folded into that
 * entry, travels with it on publish and discard, and never counts.
 *
 * A translation E is a companion of entry P when
 *  1. its source (else its parent) is a node Q of P — P itself or a record
 *     nested below it, of E's table;
 *  2. E mirrors Q: identical, or every field E changes Q changes to the same
 *     value, and a moved or deleted E belongs to a Q moved or deleted alike;
 *  3. every nested record of E is identical or mirrors a node of P's subtree
 *     it translates;
 *  4. when P is a companion itself, E belongs to P's owner (a translation of
 *     a translation); a cycle makes none of its members a companion.
 *
 * Every other entry is a change when any of its own nodes changes anything,
 * otherwise a copy — TYPO3's work only, offered for cleanup. Pure: works on
 * the DTOs alone, in O(n) with maps.
 *
 * An entry with unlisted descendants (versions below it the editor's list
 * leaves out, see WorkspaceChange) may change anything there: it is never a
 * companion and never a copy. The same holds for an entry with unlisted
 * ancestors (a version above it the list leaves out): core's publish of it
 * takes that version and its other children along.
 *
 * Each entry finally names the other translations core's discard of it
 * removes along (its dependents, see withDependents()): what the discard
 * confirmation has to say before the click, since core's discard is what
 * runs.
 */
final readonly class CompanionClassifier
{
    /**
     * @param list<WorkspaceChange> $entries
     * @param array<string, string|null> $sortFields table => sortby field
     * @return list<WorkspaceChange> The top-level entries with role, companions, dependents and copy group; companions removed from the top level.
     */
    public function classify(array $entries, array $sortFields): array
    {
        if ($entries === []) {
            return [];
        }
        $index = self::nodeIndex($entries);

        // Rule 1-3 against the original set: each candidate's direct owner.
        $ownerOf = [];
        foreach ($entries as $position => $entry) {
            $owner = $this->directOwner($entry, $position, $entries, $index, $sortFields);
            if ($owner !== null) {
                $ownerOf[$position] = $owner;
            }
        }

        $ownerOf = self::withoutCycles($ownerOf);
        $finalOwner = [];
        foreach (array_keys($ownerOf) as $position) {
            $owner = $ownerOf[$position];
            while (isset($ownerOf[$owner])) {
                $owner = $ownerOf[$owner];
            }
            $finalOwner[$position] = $owner;
        }

        $companionsOf = [];
        foreach ($finalOwner as $position => $owner) {
            $companionsOf[$owner][] = $entries[$position];
        }

        $classified = [];
        foreach ($entries as $position => $entry) {
            if (isset($finalOwner[$position])) {
                continue;
            }
            $companions = $companionsOf[$position] ?? [];
            usort(
                $companions,
                static fn(WorkspaceChange $a, WorkspaceChange $b): int => [$a->languageUid ?? 0, $a->workspaceUid] <=> [$b->languageUid ?? 0, $b->workspaceUid],
            );
            $role = self::ownNodesChange($entry) ? ChangeRole::Change : ChangeRole::Copy;
            $classified[] = $entry->withClassification(
                $role,
                $companions,
                [],
                $role === ChangeRole::Copy ? self::copyGroup($entry) : '',
            );
        }

        return self::withDependents($classified);
    }

    /**
     * Rule 2: E carries nothing Q does not carry with the same value.
     */
    public static function mirrors(WorkspaceChange $e, WorkspaceChange $q, ?string $sortField): bool
    {
        $kind = $e->kind();
        if ($kind === ChangeKind::Identical) {
            return true;
        }
        if ($kind === ChangeKind::New) {
            return false;
        }
        $otherKind = $q->kind();
        if (($kind === ChangeKind::Deleted || $kind === ChangeKind::Moved) && $otherKind !== $kind) {
            return false;
        }
        // Moving a record gives each translation its own position.
        $skipSorting = $kind === ChangeKind::Moved && $otherKind === ChangeKind::Moved;
        foreach ($e->changedFields as $field => $hash) {
            if ($skipSorting && $field === $sortField) {
                continue;
            }
            if (($q->changedFields[$field] ?? null) !== $hash) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rules 2 and 3 for one candidate against its matched node and the
     * subtree of the entry that node belongs to.
     *
     * @param array<string, string|null> $sortFields
     */
    public function holds(WorkspaceChange $e, WorkspaceChange $q, WorkspaceChange $owner, array $sortFields): bool
    {
        if ($q->table !== $e->table || !self::mirrors($e, $q, $sortFields[$e->table] ?? null)) {
            return false;
        }
        $subtree = [$owner, ...$owner->children];
        foreach ($e->children as $child) {
            if ($child->kind() === ChangeKind::Identical) {
                continue;
            }
            $origins = array_filter([$child->translationSource, $child->translationParent], static fn(int $uid): bool => $uid > 0);
            $mirrored = false;
            foreach ($subtree as $node) {
                if ($node->table === $child->table
                    && in_array($node->liveUid, $origins, true)
                    && self::mirrors($child, $node, $sortFields[$child->table] ?? null)
                ) {
                    $mirrored = true;
                    break;
                }
            }
            if (!$mirrored) {
                return false;
            }
        }

        return true;
    }

    /**
     * The node a translation was made from: its source first (the more
     * specific origin of a translation of a translation), then its parent.
     * Looked up among every node, nested ones included, so a translated
     * collection item or file reference whose translated element was not
     * versioned still finds the element its origin belongs to.
     *
     * @param array<string, array{int, WorkspaceChange}> $index
     * @return array{int, WorkspaceChange}|null position of the top-level entry, matched node
     */
    public static function origin(WorkspaceChange $e, array $index): ?array
    {
        foreach ([$e->translationSource, $e->translationParent] as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $hit = $index[$e->table . ':' . $uid] ?? null;
            if ($hit === null || ($hit[1]->table === $e->table && $hit[1]->workspaceUid === $e->workspaceUid)) {
                continue;
            }

            return $hit;
        }

        return null;
    }

    /**
     * Every node (top-level and nested) by table:liveUid.
     *
     * @param list<WorkspaceChange> $entries
     * @return array<string, array{int, WorkspaceChange}>
     */
    public static function nodeIndex(array $entries): array
    {
        $index = [];
        foreach ($entries as $position => $entry) {
            foreach ([$entry, ...$entry->children] as $node) {
                $index[$node->table . ':' . $node->liveUid] ??= [$position, $node];
            }
        }

        return $index;
    }

    /**
     * @param list<WorkspaceChange> $entries
     * @param array<string, array{int, WorkspaceChange}> $index
     * @param array<string, string|null> $sortFields
     */
    private function directOwner(WorkspaceChange $entry, int $position, array $entries, array $index, array $sortFields): ?int
    {
        if (($entry->languageUid ?? 0) <= 0 || $entry->reachesUnlisted()) {
            return null;
        }
        $origin = self::origin($entry, $index);
        if ($origin === null || $origin[0] === $position) {
            return null;
        }
        [$ownerPosition, $node] = $origin;

        return $this->holds($entry, $node, $entries[$ownerPosition], $sortFields) ? $ownerPosition : null;
    }

    /**
     * Drops the owner of every entry on a cycle (A owns B owns A): none of
     * them can tell which one carries the edit. Each entry has at most one
     * owner, so each walk ends at an entry without one, at a walked entry,
     * or on a cycle.
     *
     * @param array<int, int> $ownerOf
     * @return array<int, int>
     */
    private static function withoutCycles(array $ownerOf): array
    {
        $done = [];
        foreach (array_keys($ownerOf) as $start) {
            $path = [];
            $current = $start;
            while (isset($ownerOf[$current]) && !isset($done[$current]) && !isset($path[$current])) {
                $path[$current] = count($path);
                $current = $ownerOf[$current];
            }
            if (isset($path[$current])) {
                foreach (array_slice(array_keys($path), $path[$current]) as $member) {
                    unset($ownerOf[$member]);
                }
            }
            $done += $path;
        }

        return $ownerOf;
    }

    private static function ownNodesChange(WorkspaceChange $entry): bool
    {
        if ($entry->reachesUnlisted()) {
            return true;
        }
        foreach ([$entry, ...$entry->children] as $node) {
            if ($node->kind() !== ChangeKind::Identical) {
                return true;
            }
        }

        return false;
    }

    /**
     * Leftover translation copies of one record are cleaned up together:
     * grouped by the record they translate.
     */
    private static function copyGroup(WorkspaceChange $entry): string
    {
        $origin = ($entry->languageUid ?? 0) > 0 && $entry->translationParent > 0 ? $entry->translationParent : $entry->liveUid;

        return $entry->table . ':' . $origin;
    }

    /**
     * Core's discard of a version also discards every translation of the
     * workspace whose parent pointer, as stored, is that version's uid
     * (DataHandler::discardLocalizationOverlayRecords()) — and does so again
     * for every version it discards along: the records nested below it and
     * the translations it discards in turn. Core points a translation at a
     * version when the parent is new, and when it moves or deletes the
     * parent in the workspace; a translator who edits the translation
     * afterwards keeps that pointer, so the discard takes the edit along.
     *
     * Each entry names those translations (its dependents): the top records
     * — entries and their companions — that hold a node such a discard
     * reaches and that change something there, other than the entry and its
     * own companions (which go with it anyway). `edited` marks a translation
     * whose reached nodes carry edits, a move or a deletion of their own; a
     * new translation (of a new record) is not edited. A reached top record
     * takes its nested records along; a reached nested record only itself.
     *
     * @param list<WorkspaceChange> $entries
     * @return list<WorkspaceChange>
     */
    private static function withDependents(array $entries): array
    {
        // Every top record (an entry or one of its companions) with its
        // nodes; every node by its version, and by the version its stored
        // parent pointer names.
        $tops = [];
        $nodes = [];
        $pointingAt = [];
        foreach ($entries as $entry) {
            foreach ([$entry, ...$entry->companions] as $top) {
                $topKey = $top->table . ':' . $top->workspaceUid;
                $tops[$topKey] = $top;
                foreach ([$top, ...$top->children] as $node) {
                    $nodeKey = $node->table . ':' . $node->workspaceUid;
                    $nodes[$nodeKey] = ['node' => $node, 'top' => $topKey];
                    if (($node->languageUid ?? 0) > 0 && $node->rawTranslationParent > 0) {
                        $pointingAt[$node->table . ':' . $node->rawTranslationParent][] = $nodeKey;
                    }
                }
            }
        }
        if ($pointingAt === []) {
            return $entries;
        }

        return array_map(
            static function (WorkspaceChange $entry) use ($tops, $nodes, $pointingAt): WorkspaceChange {
                $dependents = self::dependentsOf($entry, $tops, $nodes, $pointingAt);

                return $dependents === []
                    ? $entry
                    : $entry->withClassification($entry->role, $entry->companions, $dependents, $entry->copyGroup);
            },
            $entries,
        );
    }

    /**
     * @param array<string, WorkspaceChange> $tops table:workspaceUid of each entry and companion
     * @param array<string, array{node: WorkspaceChange, top: string}> $nodes table:workspaceUid of every node, with its top record's key
     * @param array<string, list<string>> $pointingAt table:version uid => keys of the nodes pointing at it
     * @return list<array{table: string, workspaceUid: int, languageUid: int, edited: bool}>
     */
    private static function dependentsOf(WorkspaceChange $entry, array $tops, array $nodes, array $pointingAt): array
    {
        // What the discard of the entry means: the entry and its companions
        // with everything nested below them.
        $own = [];
        $reached = [];
        $queue = [];
        foreach ([$entry, ...$entry->companions] as $top) {
            $own[$top->table . ':' . $top->workspaceUid] = true;
            foreach ([$top, ...$top->children] as $node) {
                $key = $node->table . ':' . $node->workspaceUid;
                $reached[$key] = true;
                $queue[] = $key;
            }
        }

        for ($next = 0; $next < count($queue); ++$next) {
            foreach ($pointingAt[$queue[$next]] ?? [] as $translationKey) {
                // A top record goes with what is nested below it.
                $topKey = $nodes[$translationKey]['top'];
                $goes = $topKey === $translationKey
                    ? array_map(
                        static fn(WorkspaceChange $node): string => $node->table . ':' . $node->workspaceUid,
                        [$tops[$topKey], ...$tops[$topKey]->children],
                    )
                    : [$translationKey];
                foreach ($goes as $goingKey) {
                    if (!isset($reached[$goingKey])) {
                        $reached[$goingKey] = true;
                        $queue[] = $goingKey;
                    }
                }
            }
        }

        // The reached nodes of other top records, by the record they belong to.
        $lost = [];
        foreach (array_keys($reached) as $key) {
            $topKey = $nodes[$key]['top'] ?? null;
            if ($topKey === null || isset($own[$topKey])) {
                continue;
            }
            $kind = $nodes[$key]['node']->kind();
            if ($kind === ChangeKind::Identical) {
                continue;
            }
            $lost[$topKey] = ($lost[$topKey] ?? false) || $kind !== ChangeKind::New;
        }

        $dependents = [];
        foreach ($lost as $topKey => $edited) {
            $top = $tops[$topKey];
            $dependents[] = [
                'table' => $top->table,
                'workspaceUid' => $top->workspaceUid,
                'languageUid' => $top->languageUid ?? 0,
                'edited' => $edited,
            ];
        }
        usort(
            $dependents,
            static fn(array $a, array $b): int => [$a['languageUid'], $a['table'], $a['workspaceUid']] <=> [$b['languageUid'], $b['table'], $b['workspaceUid']],
        );

        return $dependents;
    }
}
