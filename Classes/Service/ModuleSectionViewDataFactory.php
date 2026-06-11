<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Builds per-section view data for the Easy Workspace backend module:
 * diagnostics scan output or pending/all record lists with statistics.
 */
final readonly class ModuleSectionViewDataFactory
{
    public function __construct(
        private PendingItemsService $pendingItemsService,
        private WorkspaceDiagnosticsService $workspaceDiagnosticsService,
        private WorkspaceTestingReportService $workspaceTestingReportService,
    ) {}

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function build(string $section, int $pageUid, int $newsUid, array $config, int $activeWorkspaceId): array
    {
        if ($section === 'diagnostics') {
            $diagnostics = $this->workspaceDiagnosticsService->scan($activeWorkspaceId);
            $diagnostics['testing'] = $this->workspaceTestingReportService->buildFromScan($diagnostics);

            return $diagnostics;
        }

        $payload = $this->emptyListPayload();
        $items = $this->pendingItemsService->listForContext(
            $pageUid,
            $newsUid,
            PendingItemsMode::All,
            $config,
        );
        if ($items === null) {
            return $payload;
        }

        $payload['workspaceId'] = $items['workspaceId'] ?? 0;
        $payload['workspaceTitle'] = $items['workspaceTitle'] ?? '';
        $rawItemList = $items['items'] ?? [];
        if (!is_array($rawItemList)) {
            return $payload;
        }
        /** @var list<array<string, mixed>> $itemList */
        $itemList = array_values(array_filter($rawItemList, is_array(...)));
        $payload['items'] = $itemList;
        $payload['itemGroups'] = $items['itemGroups'] ?? [];
        $payload['changedItemGroups'] = $items['changedItemGroups'] ?? [];
        $payload['totalCount'] = count($itemList);
        $payload['contentElementCount'] = count(array_filter($itemList, static fn(array $i): bool => ($i['table'] ?? '') === 'tt_content'));
        $payload['affectedTableCount'] = count($this->extractAffectedTables($itemList));
        $payload['changedCount'] = count(array_filter($itemList, static fn(array $i): bool => (bool)($i['isChanged'] ?? false)));
        $latestChange = $this->extractLatestChangedSummary($itemList);
        $payload['lastChangedAt'] = $latestChange['tstamp'];
        $payload['lastChangedAtFormatted'] = $payload['lastChangedAt'] > 0 ? BackendUtility::datetime($payload['lastChangedAt']) : '';
        $payload['lastChangedByUid'] = $latestChange['userUid'];
        $payload['lastChangedByName'] = $latestChange['user'];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyListPayload(): array
    {
        return [
            'changedCount' => 0,
            'totalCount' => 0,
            'workspaceTitle' => '',
            'workspaceId' => 0,
            'contentElementCount' => 0,
            'affectedTableCount' => 0,
            'lastChangedAt' => 0,
            'lastChangedAtFormatted' => '',
            'lastChangedByUid' => 0,
            'lastChangedByName' => '',
            'items' => [],
            'itemGroups' => [],
            'changedItemGroups' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, true>
     */
    private function extractAffectedTables(array $items, bool $assumeChanged = false): array
    {
        $tables = [];
        foreach ($items as $item) {
            if ($assumeChanged || (bool)($item['isChanged'] ?? false)) {
                $table = Value::string($item['table'] ?? null);
                if ($table !== '') {
                    $tables[$table] = true;
                }
            }
            $childChanges = $item['childChanges'] ?? [];
            if (is_array($childChanges)) {
                $children = [];
                foreach ($childChanges as $childChange) {
                    if (is_array($childChange)) {
                        $children[] = Value::stringKeyArray($childChange);
                    }
                }
                foreach ($this->extractAffectedTables($children, true) as $table => $selected) {
                    $tables[$table] = $selected;
                }
            }
        }
        ksort($tables);

        return $tables;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{tstamp: int, userUid: int, user: string}
     */
    private function extractLatestChangedSummary(array $items): array
    {
        $latest = ['tstamp' => 0, 'userUid' => 0, 'user' => ''];
        foreach ($items as $item) {
            if ((bool)($item['isChanged'] ?? false)) {
                $latest = $this->newerChangeSummary($latest, [
                    'tstamp' => Value::int($item['latestChangeAt'] ?? null) ?: Value::int($item['tstamp'] ?? null),
                    'userUid' => Value::int($item['latestChangeUserUid'] ?? null),
                    'user' => Value::string($item['latestChangeUser'] ?? null),
                ]);
            }
            $childChanges = $item['childChanges'] ?? [];
            if (is_array($childChanges)) {
                $children = [];
                foreach ($childChanges as $childChange) {
                    if (is_array($childChange)) {
                        $children[] = Value::stringKeyArray($childChange);
                    }
                }
                $latest = $this->newerChangeSummary($latest, $this->extractLatestChangedSummary($children));
            }
        }

        return $latest;
    }

    /**
     * @param array{tstamp: int, userUid: int, user: string} $current
     * @param array{tstamp: int, userUid: int, user: string} $candidate
     * @return array{tstamp: int, userUid: int, user: string}
     */
    private function newerChangeSummary(array $current, array $candidate): array
    {
        if ($candidate['tstamp'] > $current['tstamp']) {
            return $candidate;
        }
        if ($candidate['tstamp'] === $current['tstamp'] && $current['userUid'] <= 0 && $candidate['userUid'] > 0) {
            return $candidate;
        }

        return $current;
    }
}
