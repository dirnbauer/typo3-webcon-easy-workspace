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
            'key' => 'module.testing.issue.liveRowVersionFields',
            'severity' => 'error',
        ],
        'unsupported-version-state' => [
            'key' => 'module.testing.issue.unsupportedVersionState',
            'severity' => 'error',
        ],
        'workspace-row-without-live-identity' => [
            'key' => 'module.testing.issue.workspaceRowWithoutLiveIdentity',
            'severity' => 'error',
        ],
        'orphan-workspace-version' => [
            'key' => 'module.testing.issue.orphanWorkspaceVersion',
            'severity' => 'error',
        ],
        'duplicate-workspace-version' => [
            'key' => 'module.testing.issue.duplicateWorkspaceVersion',
            'severity' => 'warning',
        ],
        'inline-child-missing-parent' => [
            'key' => 'module.testing.issue.inlineChildMissingParent',
            'severity' => 'warning',
        ],
        'file-reference-missing-owner' => [
            'key' => 'module.testing.issue.fileReferenceMissingOwner',
            'severity' => 'error',
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
            'module.testing.group.databaseIntegrity.title',
            'module.testing.group.databaseIntegrity.description',
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
            $this->issueDefinitionItem('file-reference-missing-owner', $issuesByType['file-reference-missing-owner'] ?? []),
            $this->item(
                $this->localizationService->translate('module.testing.hiddenInlineTables.title'),
                $hiddenInlineTables === []
                    ? $this->localizationService->translate('module.testing.hiddenInlineTables.ok')
                    : $this->localizationService->translate('module.testing.hiddenInlineTables.found'),
                $hiddenInlineTables === []
                    ? $this->localizationService->translate('module.testing.hiddenInlineTables.solve.ok')
                    : $this->localizationService->translate('module.testing.hiddenInlineTables.solve.found'),
                ContextualFeedbackSeverity::OK,
                $hiddenInlineTables === [] ? $this->localizationService->translate('module.testing.value.zeroTables') : implode(', ', $hiddenInlineTables),
            ),
        ];

        return $this->group(
            'module.testing.group.inlinePublishing.title',
            'module.testing.group.inlinePublishing.description',
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
            $labelKeyPrefix = Value::string($definition['key']);
            $items[] = $this->item(
                $this->localizationService->translate($labelKeyPrefix . '.title'),
                $this->localizationService->translate('module.testing.seed.covered'),
                $this->localizationService->translate($labelKeyPrefix . '.solve'),
                ContextualFeedbackSeverity::OK,
                $type,
            );
        }
        $items[] = $this->item(
            $this->localizationService->translate('module.testing.seed.articleGridItems.title'),
            TcaUtility::table('article_grid_items') === []
                ? $this->localizationService->translate('module.testing.seed.articleGridItems.notInstalled')
                : $this->localizationService->translate('module.testing.seed.articleGridItems.available'),
            TcaUtility::table('article_grid_items') === []
                ? $this->localizationService->translate('module.testing.seed.articleGridItems.solve.notInstalled')
                : $this->localizationService->translate('module.testing.seed.articleGridItems.solve.available'),
            TcaUtility::table('article_grid_items') === [] ? ContextualFeedbackSeverity::INFO : ContextualFeedbackSeverity::OK,
            TcaUtility::table('article_grid_items') === []
                ? $this->localizationService->translate('module.testing.value.notInstalled')
                : $this->localizationService->translate('module.testing.value.available'),
        );

        return $this->group(
            'module.testing.group.seedCoverage.title',
            'module.testing.group.seedCoverage.description',
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
            'module.testing.group.manualOnly.title',
            'module.testing.group.manualOnly.description',
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
        $labelKeyPrefix = Value::string($definition['key'] ?? null);
        $count = count($issues);
        if ($count === 0) {
            return $this->item(
                $this->localizationService->translate($labelKeyPrefix . '.title'),
                $this->localizationService->translate($labelKeyPrefix . '.ok'),
                $this->localizationService->translate($labelKeyPrefix . '.solve'),
                ContextualFeedbackSeverity::OK,
                $this->localizationService->translate('module.testing.value.found', ['count' => $count]),
                [],
            );
        }

        return $this->item(
            $this->localizationService->translate($labelKeyPrefix . '.title'),
            $this->localizationService->translate($labelKeyPrefix . '.fail'),
            $this->localizationService->translate($labelKeyPrefix . '.solve'),
            $this->severityFromName(Value::string($definition['severity'] ?? null)),
            $this->localizationService->translate('module.testing.value.found', ['count' => $count]),
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
            $record = $table . '#' . (
                $workspaceUid > 0
                    ? (string)$workspaceUid
                    : $this->localizationService->translate('module.testing.detail.liveUid', ['uid' => $liveUid])
            );
            $records[] = $detail === ''
                ? $record
                : $this->localizationService->translate('module.testing.detail.withDetail', [
                    'record' => $record,
                    'detail' => $detail,
                ]);
        }
        if (count($issues) > 5) {
            $records[] = $this->localizationService->translate('module.testing.value.more', ['count' => count($issues) - 5]);
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
    private function group(string $labelKey, string $descriptionKey, array $items): array
    {
        $severity = $this->highestSeverity($items);
        $issueCount = count(array_filter(
            $items,
            static fn (array $item): bool => Value::int($item['severityValue'] ?? null) > ContextualFeedbackSeverity::OK->value,
        ));

        return [
            'label' => $this->localizationService->translate($labelKey),
            'description' => $this->localizationService->translate($descriptionKey),
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
