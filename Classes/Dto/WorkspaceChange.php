<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

/**
 * One row of the Workspaces module's list: a version, and the versions of
 * the records that depend on it (collection items, file references, inline
 * children), which the module shows nested below it.
 *
 * The page, the language and the move flag are what core's own version
 * selection returns for the row: `wspid` (the page the version lives on,
 * the target page of a move), the record's language field, and whether the
 * row is a move pointer.
 */
final readonly class WorkspaceChange
{
    /**
     * @param list<WorkspaceChange> $children All nested versions, in the module's order.
     * @param int|null $languageUid Null for a table without a language field (or a move pointer, which core lists without one).
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
        return new self(
            $this->table,
            $this->workspaceUid,
            $this->liveUid,
            $children,
            $this->pid,
            $this->languageUid,
            $this->translationParent,
            $this->isMoved,
        );
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
     * Core's language filter: rows of the language, plus move pointers
     * (which core selects without one) and — for the default language —
     * rows of tables without a language field.
     */
    public function matchesLanguage(int $languageUid): bool
    {
        if ($this->isMoved) {
            return true;
        }
        if ($this->languageUid === null) {
            return $languageUid <= 0;
        }

        return $this->languageUid === $languageUid;
    }

    /**
     * @return array{table: string, workspaceUid: int, liveUid: int, pid: int, languageUid: int|null, translationParent: int, isMoved: bool}
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
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $languageUid = $row['languageUid'] ?? null;

        return new self(
            table: (string)($row['table'] ?? ''),
            workspaceUid: (int)($row['workspaceUid'] ?? 0),
            liveUid: (int)($row['liveUid'] ?? 0),
            pid: (int)($row['pid'] ?? 0),
            languageUid: is_int($languageUid) ? $languageUid : null,
            translationParent: (int)($row['translationParent'] ?? 0),
            isMoved: (bool)($row['isMoved'] ?? false),
        );
    }
}
