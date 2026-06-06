<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Utility;

/**
 * Normalizes publish selections from the module POST form and toolbar AJAX
 * into the cmdmap input shape expected by PublishSelectedService.
 */
final readonly class PublishSelectionNormalizer
{
    public function __construct(
        private WorkspaceTablePolicy $workspaceTablePolicy,
    ) {}

    /**
     * Module checkboxes submit colon-separated "table:workspaceUid" strings.
     *
     * @param array<mixed> $entries
     * @return list<array{table: string, workspaceUid: int}>
     */
    public function fromModuleForm(array $entries): array
    {
        $selections = [];
        foreach ($entries as $entry) {
            $entry = is_string($entry) ? $entry : '';
            if ($entry === '') {
                continue;
            }
            [$table, $workspaceUid] = array_pad(explode(':', $entry, 2), 2, '');
            $normalized = $this->normalizePair(Value::string($table), (int)$workspaceUid);
            if ($normalized !== null) {
                $selections[] = $normalized;
            }
        }

        return $selections;
    }

    /**
     * Toolbar AJAX posts structured {table, workspaceUid} objects.
     *
     * @param array<mixed> $entries
     * @return list<array{table: string, workspaceUid: int}>
     */
    public function fromAjaxJson(array $entries): array
    {
        $selections = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = $this->normalizePair(
                Value::string($entry['table'] ?? null),
                Value::int($entry['workspaceUid'] ?? null),
            );
            if ($normalized !== null) {
                $selections[] = $normalized;
            }
        }

        return $selections;
    }

    /**
     * @return array{table: string, workspaceUid: int}|null
     */
    private function normalizePair(string $table, int $workspaceUid): ?array
    {
        if (!$this->workspaceTablePolicy->isAllowed($table) || $workspaceUid <= 0) {
            return null;
        }

        return ['table' => $table, 'workspaceUid' => $workspaceUid];
    }
}
