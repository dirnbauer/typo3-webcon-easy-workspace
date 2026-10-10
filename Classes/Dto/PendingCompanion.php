<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Dto;

use Webconsulting\WebconEasyWorkspace\Enum\CompanionKind;

/**
 * A translation copy TYPO3 made along with a change, as the dropdown shows
 * it on the changed row: its language, how it follows the change, and — for
 * a translation carrying synchronized values — which fields those are. The
 * edit URLs open the translation itself (a companion edited by hand becomes
 * a change of its own).
 */
final readonly class PendingCompanion
{
    /**
     * @param list<string> $fields Labels of the synchronized fields (synced companions only).
     */
    public function __construct(
        public string $table,
        public int $workspaceUid,
        public int $liveUid,
        public int $languageUid,
        public CompanionKind $kind,
        public array $fields = [],
        public ?string $editUrl = null,
        public ?string $contextualEditUrl = null,
    ) {}

    /**
     * @return array{table: string, workspaceUid: int, liveUid: int, languageUid: int, kind: string, fields: list<string>, editUrl: string|null, contextualEditUrl: string|null}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'workspaceUid' => $this->workspaceUid,
            'liveUid' => $this->liveUid,
            'languageUid' => $this->languageUid,
            'kind' => $this->kind->value,
            'fields' => $this->fields,
            'editUrl' => $this->editUrl,
            'contextualEditUrl' => $this->contextualEditUrl,
        ];
    }
}
