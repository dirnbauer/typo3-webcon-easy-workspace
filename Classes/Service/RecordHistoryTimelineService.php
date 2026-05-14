<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\History\RecordHistory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\DiffGranularity;
use TYPO3\CMS\Core\Utility\DiffUtility;

/**
 * Builds a workspace-scoped edit timeline for a single record by
 * delegating to TYPO3 core's RecordHistory service (which reads
 * `sys_history`) and post-processing the change log into a flat
 * structure the Fluid template can iterate.
 *
 * Why not call RecordHistory's grid template directly? The native
 * one is wired into the standalone History module and pulls in a
 * lot of chrome we don't want inside a Modal. We just need the
 * raw change log + a per-field DiffUtility::diff for each entry.
 *
 * Scope is workspace-only: editors using this dialog are looking
 * at "what edits will publish", so live-baseline history rows are
 * irrelevant noise. The live baseline appears as a single anchor
 * row at the bottom of the timeline.
 */
final readonly class RecordHistoryTimelineService
{
    /**
     * Fields we never include in the timeline. Same allow-list as
     * RecordDiffService::SKIP_FIELDS — kept independent so the two
     * services can evolve apart if needed.
     *
     * @var list<string>
     */
    private const SKIP_FIELDS = [
        'uid', 'pid', 'tstamp', 'crdate', 'sorting',
        't3ver_wsid', 't3ver_oid', 't3ver_state', 't3ver_stage', 't3ver_count',
        'l18n_diffsource', 'l10n_diffsource',
        'l10n_state', 'l10n_source', 'l10n_parent',
        'tx_impexp_origuid',
    ];

    public function __construct(
        private DiffUtility $diffUtility,
        private LanguageServiceFactory $languageServiceFactory,
        private Context $context,
    ) {}

    /**
     * Build a timeline for $table/$uid scoped to the current
     * workspace. Returns entries newest-first so the frontend can
     * render top-down without re-sorting.
     *
     * @param string $table     TCA table name
     * @param int    $uid       The workspace-version uid
     * @return list<array{
     *   historyUid: int,
     *   tstamp: int,
     *   tstampFormatted: string,
     *   action: string,
     *   user: string,
     *   diffs: list<array{field: string, label: string, before: string, after: string, html: string}>
     * }>
     */
    public function build(string $table, int $uid): array
    {
        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);

        // RecordHistory reads $element from constructor as "table:uid".
        // setShowSubElements(false) keeps it focused on this record;
        // setMaxSteps(0) means "no cap" which is what we want for a
        // single record's lifecycle.
        $history = new RecordHistory(sprintf('%s:%d', $table, $uid));
        $history->setShowSubElements(false);
        $history->setMaxSteps(0);
        $changeLog = $history->getChangeLog();
        $diffData = $history->getDiff($changeLog);
        // RecordHistory::getDiff returns an array keyed by sys_history
        // row uid. Re-fold into our timeline shape.
        $diffEntries = $diffData['differences'] ?? [];

        $languageService = $this->getLanguageService();
        $entries = [];
        foreach ($changeLog as $historyUid => $log) {
            // Skip entries outside the current workspace context —
            // editors are reviewing "what will publish", live edits
            // aren't part of that decision. Action 1 = INSERT,
            // 2 = UPDATE, 3 = MOVE, 4 = DELETE per DataHandler.
            $entryWs = (int)($log['workspace'] ?? 0);
            if ($workspaceId > 0 && $entryWs !== $workspaceId) {
                continue;
            }
            $newRecord = is_array($log['newRecord'] ?? null) ? $log['newRecord'] : [];
            $oldRecord = is_array($log['oldRecord'] ?? null) ? $log['oldRecord'] : [];
            $entries[] = [
                'historyUid' => (int)$historyUid,
                'tstamp' => (int)($log['tstamp'] ?? 0),
                'tstampFormatted' => BackendUtility::datetime((int)($log['tstamp'] ?? 0)),
                'action' => $this->describeAction((int)($log['actiontype'] ?? 0), $languageService),
                'user' => $this->resolveUser((int)($log['userid'] ?? 0)),
                'diffs' => $this->buildFieldDiffs($table, $oldRecord, $newRecord),
            ];
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<array{field: string, label: string, before: string, after: string, html: string}>
     */
    private function buildFieldDiffs(string $table, array $old, array $new): array
    {
        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
        $languageService = $this->getLanguageService();
        $out = [];
        foreach ($fields as $field) {
            if (in_array($field, self::SKIP_FIELDS, true)) {
                continue;
            }
            $before = (string)($old[$field] ?? '');
            $after = (string)($new[$field] ?? '');
            if ($before === $after) {
                continue;
            }
            $tcaLabel = $GLOBALS['TCA'][$table]['columns'][$field]['label'] ?? $field;
            $out[] = [
                'field' => $field,
                'label' => $languageService->sL((string)$tcaLabel) ?: $field,
                'before' => $before,
                'after' => $after,
                'html' => $this->diffUtility->diff($before, $after, DiffGranularity::WORD),
            ];
        }
        return $out;
    }

    private function describeAction(int $actionType, LanguageService $languageService): string
    {
        return match ($actionType) {
            1 => 'Created',
            2 => 'Modified',
            3 => 'Moved',
            4 => 'Deleted',
            default => 'Changed',
        };
    }

    private function resolveUser(int $userId): string
    {
        if ($userId <= 0) {
            return 'System';
        }
        $row = BackendUtility::getRecord('be_users', $userId, 'realName, username');
        if (!is_array($row)) {
            return sprintf('User #%d', $userId);
        }
        $realName = trim((string)($row['realName'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        if ($realName !== '' && $username !== '') {
            return sprintf('%s (%s)', $realName, $username);
        }
        return $realName !== '' ? $realName : ($username !== '' ? $username : sprintf('User #%d', $userId));
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'] ?? $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
    }
}
