<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

use Webconsulting\WebconEasyWorkspace\Enum\ChangeKind;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;

/**
 * One row of the Workspaces module's list: a version, and the versions of
 * the records that depend on it (collection items, file references, inline
 * children), which the module shows nested below it.
 *
 * The page and the move flag are what core's own version selection returns
 * for the row: `wspid` (the page the version lives on, the target page of a
 * move) and whether the row is a move pointer. The other facts come from the
 * version's full row: its version state, its language, its translation
 * pointers (mapped to live uids) and the fields it changes.
 *
 * After classification a top-level entry also knows its role (a change or a
 * copy), the translation copies folded into it (companions), the other
 * translations core's discard of it removes along (dependents) and, for a
 * copy, the group it is cleaned up with.
 *
 * Core lists the versions an editor may see; its publish and discard act on
 * every version nested below a record. An entry with a nested version the
 * list left out (a file reference outside the editor's file mounts, say) has
 * unlisted descendants: whatever they change is invisible here, so such an
 * entry is never taken for a copy or a companion.
 *
 * Core's publish and stage change also go up: they act on a record's
 * outermost parent and everything nested below it. A top-level entry whose
 * record lies below a version the list left out (a translated collection
 * item whose translated element someone else edited, for an editor who may
 * not see elements) has unlisted ancestors: publishing it takes that version
 * and its other children along, so it is never a copy or a companion either.
 */
final readonly class WorkspaceChange
{
    /**
     * @param list<WorkspaceChange> $children All nested versions, in the module's order.
     * @param int|null $languageUid Null for a table without a language field.
     * @param int $translationParent The live uid of the record this one translates (0 for none).
     * @param int $versionState t3ver_state: 0 edit, 1 new, 2 deleted, 4 moved.
     * @param int $translationSource The live uid of the record this translation was made from (0 for none or a table without the field).
     * @param array<string, string> $changedFields field => hash of the new value (see ContentChangeDetector::changedFields()).
     * @param list<WorkspaceChange> $companions Translation copies folded into this entry, each with its own children.
     * @param list<array{table: string, workspaceUid: int, languageUid: int, edited: bool}> $dependents Other translations core's discard of this entry removes along (see CompanionClassifier); `edited` for one with edits of its own, not for a new one.
     * @param bool $hasUnlistedDescendants A version nested below this entry in core's dependency tree is not among the listed rows.
     * @param bool $hasUnlistedAncestors This entry lies below a version in core's dependency tree that is not among the listed rows.
     * @param int $rawTranslationParent The translation parent as the row stores it: the uid core's discard looks translations up by — a version's uid when core pointed the translation at the parent's version (a new parent, or one moved or deleted in the workspace), the live uid otherwise (0 for none).
     */
    public function __construct(
        public string $table,
        public int $workspaceUid,
        public int $liveUid,
        public array $children = [],
        public int $pid = 0,
        public ?int $languageUid = null,
        public int $translationParent = 0,
        public bool $isMoved = false,
        /**
         * False for a version that matches its live record: one TYPO3
         * created along with an edit elsewhere (see ContentChangeDetector).
         */
        public bool $contentChanged = true,
        public int $versionState = 0,
        public int $translationSource = 0,
        public array $changedFields = [],
        public ?ChangeRole $role = null,
        public array $companions = [],
        public array $dependents = [],
        public string $copyGroup = '',
        public bool $hasUnlistedDescendants = false,
        public bool $hasUnlistedAncestors = false,
        public int $rawTranslationParent = 0,
    ) {}

    public function isNew(): bool
    {
        return $this->liveUid === $this->workspaceUid;
    }

    public function kind(): ChangeKind
    {
        return match (true) {
            $this->versionState === 1 => ChangeKind::New,
            $this->versionState === 2 => ChangeKind::Deleted,
            $this->versionState === 4 || $this->isMoved => ChangeKind::Moved,
            $this->changedFields !== [] => ChangeKind::Edited,
            default => ChangeKind::Identical,
        };
    }

    /**
     * @param list<WorkspaceChange> $children
     */
    public function withChildren(array $children): self
    {
        return $this->copy(['children' => $children]);
    }

    /**
     * @param list<WorkspaceChange> $companions
     * @param list<array{table: string, workspaceUid: int, languageUid: int, edited: bool}> $dependents
     */
    public function withClassification(?ChangeRole $role, array $companions, array $dependents, string $copyGroup): self
    {
        return $this->copy([
            'role' => $role,
            'companions' => $companions,
            'dependents' => $dependents,
            'copyGroup' => $copyGroup,
        ]);
    }

    public function withUnlistedDescendants(bool $hasUnlistedDescendants): self
    {
        return $this->copy(['hasUnlistedDescendants' => $hasUnlistedDescendants]);
    }

    public function withUnlistedAncestors(bool $hasUnlistedAncestors): self
    {
        return $this->copy(['hasUnlistedAncestors' => $hasUnlistedAncestors]);
    }

    /**
     * Core's publish, stage change or discard of this entry may act on a
     * version the list does not hold (above it or below it).
     */
    public function reachesUnlisted(): bool
    {
        return $this->hasUnlistedDescendants || $this->hasUnlistedAncestors;
    }

    /**
     * The facts read from the version's full row; whether it changes
     * anything follows from them. The parent pointer as stored defaults to
     * the mapped one (a pointer at a live record, or at a new record).
     *
     * @param array<string, string> $changedFields
     */
    public function withFacts(int $versionState, ?int $languageUid, int $translationParent, int $translationSource, array $changedFields, ?int $rawTranslationParent = null): self
    {
        $change = $this->copy([
            'versionState' => $versionState,
            'languageUid' => $languageUid,
            'translationParent' => $translationParent,
            'translationSource' => $translationSource,
            'changedFields' => $changedFields,
            'rawTranslationParent' => $rawTranslationParent ?? $translationParent,
        ]);

        return $change->copy(['contentChanged' => $change->kind() !== ChangeKind::Identical]);
    }

    /**
     * Core lists a page's changes by the page the version lives on, the
     * page record itself (and its translations) by uid, and the root-level
     * records of tables that ignore the root-level restriction on every
     * page. Same rules as WorkspaceService::selectVersionsInWorkspace() with
     * a page id.
     */
    public function belongsToPage(int $pageUid, bool $ignoresRootLevelRestriction): bool
    {
        if ($this->pid === 0 && $ignoresRootLevelRestriction) {
            return true;
        }
        if ($this->table === 'pages') {
            return $this->liveUid === $pageUid
                || $this->translationParent === $pageUid
                || ($this->isMoved && $this->pid === $pageUid);
        }

        return $this->pid === $pageUid;
    }

    /**
     * Core's language filter: rows of the language and — for the default
     * language — rows of tables without a language field. A move pointer
     * carries the language of its record.
     */
    public function matchesLanguage(int $languageUid): bool
    {
        if ($this->languageUid === null) {
            return $languageUid <= 0;
        }

        return $this->languageUid === $languageUid;
    }

    /**
     * The scalar facts; children and companions are serialized by the
     * caller (CoreWorkspaceChanges), recursively.
     *
     * @return array{table: string, workspaceUid: int, liveUid: int, pid: int, languageUid: int|null, translationParent: int, isMoved: bool, contentChanged: bool, versionState: int, translationSource: int, changedFields: array<string, string>, role: string|null, dependents: list<array{table: string, workspaceUid: int, languageUid: int, edited: bool}>, copyGroup: string, hasUnlistedDescendants: bool, hasUnlistedAncestors: bool, rawTranslationParent: int}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'workspaceUid' => $this->workspaceUid,
            'liveUid' => $this->liveUid,
            'pid' => $this->pid,
            'languageUid' => $this->languageUid,
            'translationParent' => $this->translationParent,
            'isMoved' => $this->isMoved,
            'contentChanged' => $this->contentChanged,
            'versionState' => $this->versionState,
            'translationSource' => $this->translationSource,
            'changedFields' => $this->changedFields,
            'role' => $this->role?->value,
            'dependents' => $this->dependents,
            'copyGroup' => $this->copyGroup,
            'hasUnlistedDescendants' => $this->hasUnlistedDescendants,
            'hasUnlistedAncestors' => $this->hasUnlistedAncestors,
            'rawTranslationParent' => $this->rawTranslationParent,
        ];
    }

    /**
     * The stored parent pointer defaults to the mapped one when the row
     * leaves it out (CoreWorkspaceChanges::changeToArray() does when they
     * are equal).
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $languageUid = $row['languageUid'] ?? null;
        $changedFields = [];
        foreach (is_array($row['changedFields'] ?? null) ? $row['changedFields'] : [] as $field => $hash) {
            if (is_string($field) && is_string($hash)) {
                $changedFields[$field] = $hash;
            }
        }
        $dependents = [];
        foreach (is_array($row['dependents'] ?? null) ? $row['dependents'] : [] as $dependent) {
            if (is_array($dependent)) {
                $dependents[] = [
                    'table' => is_string($dependent['table'] ?? null) ? $dependent['table'] : '',
                    'workspaceUid' => is_int($dependent['workspaceUid'] ?? null) ? $dependent['workspaceUid'] : 0,
                    'languageUid' => is_int($dependent['languageUid'] ?? null) ? $dependent['languageUid'] : 0,
                    'edited' => ($dependent['edited'] ?? false) === true,
                ];
            }
        }
        $role = $row['role'] ?? null;
        $translationParent = (int)($row['translationParent'] ?? 0);

        return new self(
            table: (string)($row['table'] ?? ''),
            workspaceUid: (int)($row['workspaceUid'] ?? 0),
            liveUid: (int)($row['liveUid'] ?? 0),
            pid: (int)($row['pid'] ?? 0),
            languageUid: is_int($languageUid) ? $languageUid : null,
            translationParent: $translationParent,
            isMoved: (bool)($row['isMoved'] ?? false),
            contentChanged: (bool)($row['contentChanged'] ?? true),
            versionState: (int)($row['versionState'] ?? 0),
            translationSource: (int)($row['translationSource'] ?? 0),
            changedFields: $changedFields,
            role: is_string($role) ? ChangeRole::tryFrom($role) : null,
            dependents: $dependents,
            copyGroup: is_string($row['copyGroup'] ?? null) ? $row['copyGroup'] : '',
            hasUnlistedDescendants: (bool)($row['hasUnlistedDescendants'] ?? false),
            hasUnlistedAncestors: (bool)($row['hasUnlistedAncestors'] ?? false),
            rawTranslationParent: array_key_exists('rawTranslationParent', $row) ? (int)$row['rawTranslationParent'] : $translationParent,
        );
    }

    /**
     * @param array<string, mixed> $overrides Keyed by constructor argument name.
     */
    private function copy(array $overrides): self
    {
        /** @var array<string, mixed> $arguments */
        $arguments = array_replace(get_object_vars($this), $overrides);

        return new self(...$arguments);
    }
}
