<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final readonly class WorkspaceTestingReportService
{
    private const ISSUE_DEFINITIONS = [
        'live-row-version-fields' => [
            'title' => 'Live rows have clean workspace fields',
            'ok' => 'No live rows carry offline version metadata.',
            'fail' => 'Live rows carry workspace version fields.',
            'severity' => 'error',
            'solve' => 'Confirm the row is the real live record, then reset t3ver_oid=0 and t3ver_state=0 with a controlled repair.',
        ],
        'unsupported-version-state' => [
            'title' => 'Workspace rows use TYPO3 v14 version states',
            'ok' => 'All workspace rows use t3ver_state 0, 1, 2 or 4.',
            'fail' => 'Workspace rows use unsupported t3ver_state values.',
            'severity' => 'error',
            'solve' => 'Decide whether the row is modified, new, delete-placeholder or move-placeholder; then fix t3ver_state or discard it through DataHandler.',
        ],
        'workspace-row-without-live-identity' => [
            'title' => 'Workspace rows have a publishable identity',
            'ok' => 'Workspace rows without live identity are marked as new records.',
            'fail' => 'Workspace rows have no live identity but are not marked as new.',
            'severity' => 'error',
            'solve' => 'For a workspace-only new record set t3ver_state=1; otherwise reconnect t3ver_oid to the intended live uid or discard the row.',
        ],
        'orphan-workspace-version' => [
            'title' => 'Workspace versions point to existing live records',
            'ok' => 'No workspace version points to a missing live row.',
            'fail' => 'Workspace versions point to missing live records.',
            'severity' => 'error',
            'solve' => 'Restore the live row, reconnect t3ver_oid to the correct live uid, or discard the orphan workspace row after editorial review.',
        ],
        'duplicate-workspace-version' => [
            'title' => 'Only one workspace version exists per live record',
            'ok' => 'No live record has duplicate workspace versions in the active workspace.',
            'fail' => 'Multiple workspace rows point to the same live record.',
            'severity' => 'warning',
            'solve' => 'Compare history and changed fields, keep the intended version, then discard or merge stale workspace rows through DataHandler.',
        ],
        'inline-child-missing-parent' => [
            'title' => 'Inline child rows still belong to an existing parent',
            'ok' => 'No workspace inline child points to a missing parent content element.',
            'fail' => 'Inline child rows point to missing parent content elements.',
            'severity' => 'warning',
            'solve' => 'Restore or identify the parent tt_content row, update the child relation, or discard the orphan child row.',
        ],
    ];

    public function __construct(
        private WorkspaceDiagnosticsService $diagnosticsService,
        private LocalizationService $localizationService,
    ) {}

    /**
     * @return array{workspaceId: int, groups: list<array<string, mixed>>, summary: array<string, int|string>, scan: array<string, mixed>}
     */
    public function build(?int $workspaceId = null): array
    {
        $scan = $this->diagnosticsService->scan($workspaceId);
        return $this->buildFromScan($scan);
    }

    /**
     * @param array<string, mixed> $scan
     * @return array{workspaceId: int, groups: list<array<string, mixed>>, summary: array<string, int|string>, scan: array<string, mixed>}
     */
    public function buildFromScan(array $scan): array
    {
        $issuesByType = $this->groupIssuesByType($this->listArray($scan['issues'] ?? []));
        $groups = [
            $this->buildDatabaseIntegrityGroup($issuesByType),
            $this->buildInlinePublishGroup($issuesByType),
            $this->buildSeedCoverageGroup(),
            $this->buildManualRiskGroup($this->listArray($scan['manualChecks'] ?? [])),
        ];

        return [
            'workspaceId' => Value::int($scan['workspaceId'] ?? null),
            'groups' => $groups,
            'summary' => $this->summarizeGroups($groups),
            'scan' => $scan,
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $issuesByType
     * @return array<string, mixed>
     */
    private function buildDatabaseIntegrityGroup(array $issuesByType): array
    {
        $items = [];
        foreach ([
            'live-row-version-fields',
            'unsupported-version-state',
            'workspace-row-without-live-identity',
            'orphan-workspace-version',
            'duplicate-workspace-version',
        ] as $type) {
            $items[] = $this->issueDefinitionItem($type, $issuesByType[$type] ?? []);
        }

        return $this->group(
            'Database integrity checks',
            'Automatic tests for version metadata, live identity, duplicate overlays and publish-blocking workspace rows.',
            $items,
        );
    }

    /**
     * @param array<string, list<array<string, mixed>>> $issuesByType
     * @return array<string, mixed>
     */
    private function buildInlinePublishGroup(array $issuesByType): array
    {
        $hiddenInlineTables = $this->hiddenWorkspaceInlineTables();
        $items = [
            $this->issueDefinitionItem('inline-child-missing-parent', $issuesByType['inline-child-missing-parent'] ?? []),
            $this->item(
                'Workspace-aware hidden inline tables are known',
                $hiddenInlineTables === []
                    ? 'No hidden workspace inline tables were found in TCA.'
                    : 'Hidden inline child tables are visible to the publish and diagnostics logic.',
                $hiddenInlineTables === []
                    ? 'No action needed unless this project uses custom inline child tables that are not configured as TCA inline relations.'
                    : 'When publishing a selected parent, keep checking that related hidden child rows are also selected or published by DataHandler.',
                ContextualFeedbackSeverity::OK,
                $hiddenInlineTables === [] ? '0 tables' : implode(', ', $hiddenInlineTables),
            ),
        ];

        return $this->group(
            'Inline child publishing checks',
            'Guards against the class of bugs where a visible parent publishes but its hidden Content Blocks or inline children stay pending.',
            $items,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSeedCoverageGroup(): array
    {
        $items = [];
        foreach (self::ISSUE_DEFINITIONS as $type => $definition) {
            $items[] = $this->item(
                Value::string($definition['title']),
                'Covered by the diagnostics seed command and by the automatic scanner.',
                Value::string($definition['solve']),
                ContextualFeedbackSeverity::OK,
                $type,
            );
        }
        $items[] = $this->item(
            'Optional article_grid_items child fixture',
            TcaUtility::table('article_grid_items') === []
                ? 'The optional Content Blocks demo table is not installed in this TYPO3 instance.'
                : 'The seed command can create a broken inline child row for article_grid_items.',
            TcaUtility::table('article_grid_items') === []
                ? 'Install the demo Content Block table if you want to reproduce that exact child-record failure locally.'
                : 'Run webcon-easy-workspace:seed-diagnostics --execute in a local DDEV database, then open this module and Diagnostics.',
            TcaUtility::table('article_grid_items') === [] ? ContextualFeedbackSeverity::INFO : ContextualFeedbackSeverity::OK,
            TcaUtility::table('article_grid_items') === [] ? 'not installed' : 'available',
        );

        return $this->group(
            'Seed fixture coverage',
            'Confirms which deliberate failure cases can be generated in a local or demo system.',
            $items,
        );
    }

    /**
     * @param list<array<string, mixed>> $manualChecks
     * @return array<string, mixed>
     */
    private function buildManualRiskGroup(array $manualChecks): array
    {
        $items = [];
        foreach ($manualChecks as $check) {
            $items[] = $this->item(
                Value::string($check['title'] ?? null),
                Value::string($check['risk'] ?? null),
                Value::string($check['solve'] ?? null),
                ContextualFeedbackSeverity::NOTICE,
                'manual',
            );
        }

        return $this->group(
            'Manual-only checks',
            'Real workspace failures that cannot be proven from database metadata alone.',
            $items,
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function issueDefinitionItem(string $type, array $issues): array
    {
        $definition = Value::stringKeyArray(self::ISSUE_DEFINITIONS[$type] ?? []);
        $count = count($issues);
        if ($count === 0) {
            return $this->item(
                Value::string($definition['title'] ?? null),
                Value::string($definition['ok'] ?? null),
                Value::string($definition['solve'] ?? null),
                ContextualFeedbackSeverity::OK,
                '0 found',
                [],
            );
        }

        return $this->item(
            Value::string($definition['title'] ?? null),
            Value::string($definition['fail'] ?? null),
            Value::string($definition['solve'] ?? null),
            $this->severityFromName(Value::string($definition['severity'] ?? null)),
            $count . ' found',
            $this->affectedRecords($issues),
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return list<string>
     */
    private function affectedRecords(array $issues): array
    {
        $records = [];
        foreach (array_slice($issues, 0, 5) as $issue) {
            $table = Value::string($issue['table'] ?? null);
            $workspaceUid = Value::int($issue['workspaceUid'] ?? null);
            $liveUid = Value::int($issue['liveUid'] ?? null);
            $detail = Value::string($issue['detail'] ?? null);
            $records[] = trim($table . '#' . ($workspaceUid > 0 ? $workspaceUid : 'live ' . $liveUid) . ($detail !== '' ? ' - ' . $detail : ''));
        }
        if (count($issues) > 5) {
            $records[] = '+' . (count($issues) - 5) . ' more';
        }
        return $records;
    }

    /**
     * @return list<string>
     */
    private function hiddenWorkspaceInlineTables(): array
    {
        $tables = [];
        foreach (TcaUtility::tables() as $parentTca) {
            $parentCtrl = Value::stringKeyArray($parentTca['ctrl'] ?? null);
            if (empty($parentCtrl['versioningWS'])) {
                continue;
            }
            foreach (TcaUtility::extractInlineFieldConfigs($parentTca) as $fieldConfig) {
                $foreignTable = Value::string($fieldConfig['foreign_table'] ?? null);
                if ($foreignTable !== '' && TcaUtility::isWorkspaceAwareHiddenTable($foreignTable)) {
                    $tables[$foreignTable] = $foreignTable;
                }
            }
        }
        sort($tables);
        return $tables;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function group(string $label, string $description, array $items): array
    {
        $severity = $this->highestSeverity($items);
        $issueCount = count(array_filter(
            $items,
            static fn (array $item): bool => Value::int($item['severityValue'] ?? null) > ContextualFeedbackSeverity::OK->value,
        ));

        return [
            'label' => $label,
            'description' => $description,
            'items' => $items,
            'statusCount' => count($items),
            'issueCount' => $issueCount,
            'statusClass' => $severity->getCssClass(),
            'statusLabelKey' => $this->severityLabelKey($severity),
            'statusLabel' => $this->localizationService->translate($this->severityLabelKey($severity)),
            'severityValue' => $severity->value,
            'icon' => $severity->getIconIdentifier(),
        ];
    }

    /**
     * @param list<string> $details
     * @return array<string, mixed>
     */
    private function item(
        string $title,
        string $message,
        string $solve,
        ContextualFeedbackSeverity $severity,
        string $value = '',
        array $details = [],
    ): array {
        return [
            'title' => $title,
            'message' => $message,
            'solve' => $solve,
            'value' => $value,
            'details' => $details,
            'statusClass' => $severity->getCssClass(),
            'statusLabelKey' => $this->severityLabelKey($severity),
            'statusLabel' => $this->localizationService->translate($this->severityLabelKey($severity)),
            'severityValue' => $severity->value,
            'icon' => $severity->getIconIdentifier(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function highestSeverity(array $items): ContextualFeedbackSeverity
    {
        $highest = ContextualFeedbackSeverity::OK;
        foreach ($items as $item) {
            $severityValue = Value::int($item['severityValue'] ?? null);
            if ($severityValue > $highest->value) {
                $highest = ContextualFeedbackSeverity::from($severityValue);
            }
        }
        return $highest;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @return array<string, int|string>
     */
    private function summarizeGroups(array $groups): array
    {
        $statusCount = 0;
        $issueCount = 0;
        $highest = ContextualFeedbackSeverity::OK;
        foreach ($groups as $group) {
            $statusCount += Value::int($group['statusCount'] ?? null);
            $issueCount += Value::int($group['issueCount'] ?? null);
            $severityValue = Value::int($group['severityValue'] ?? null);
            if ($severityValue > $highest->value) {
                $highest = ContextualFeedbackSeverity::from($severityValue);
            }
        }

        return [
            'statusCount' => $statusCount,
            'issueCount' => $issueCount,
            'statusClass' => $highest->getCssClass(),
            'statusLabelKey' => $this->severityLabelKey($highest),
            'statusLabel' => $this->localizationService->translate($this->severityLabelKey($highest)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupIssuesByType(array $issues): array
    {
        $grouped = [];
        foreach ($issues as $issue) {
            $type = Value::string($issue['type'] ?? null);
            if ($type === '') {
                continue;
            }
            $grouped[$type][] = $issue;
        }
        return $grouped;
    }

    private function severityFromName(string $severity): ContextualFeedbackSeverity
    {
        return match ($severity) {
            'error', 'critical' => ContextualFeedbackSeverity::ERROR,
            'warning' => ContextualFeedbackSeverity::WARNING,
            'info' => ContextualFeedbackSeverity::INFO,
            'notice' => ContextualFeedbackSeverity::NOTICE,
            default => ContextualFeedbackSeverity::OK,
        };
    }

    private function severityLabelKey(ContextualFeedbackSeverity $severity): string
    {
        return match ($severity) {
            ContextualFeedbackSeverity::OK => 'module.testing.severity.ok',
            ContextualFeedbackSeverity::WARNING => 'module.testing.severity.warning',
            ContextualFeedbackSeverity::ERROR => 'module.testing.severity.error',
            ContextualFeedbackSeverity::INFO => 'module.testing.severity.info',
            ContextualFeedbackSeverity::NOTICE => 'module.testing.severity.notice',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = Value::stringKeyArray($item);
            }
        }
        return $list;
    }
}
