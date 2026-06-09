<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\History\RecordHistory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\DiffGranularity;
use TYPO3\CMS\Core\Utility\DiffUtility;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

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
        private Context $context,
        private LocalizationService $localizationService,
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
     *   actionKey: string,
     *   userUid: int,
     *   user: string,
     *   table: string,
     *   uid: int,
     *   tableLabel: string,
     *   recordTitle: string,
     *   diffs: list<array{field: string, label: string, before: string, after: string, html: string, historyUid: int}>
     * }>
     */
    public function build(string $table, int $uid, bool $includeSubElements = false): array
    {
        // RecordHistory reads $element from constructor as "table:uid".
        // setMaxSteps(0) means "no cap" which is what we want for a
        // single record's lifecycle.
        $history = new RecordHistory(sprintf('%s:%d', $table, $uid));
        $history->setShowSubElements($includeSubElements);
        $history->setMaxSteps(0);
        return $this->buildFromChangeLog(array_values($history->getChangeLog()));
    }

    /**
     * Build a page-wide timeline using TYPO3 core's page-history
     * behaviour: `RecordHistory('pages:uid')` with subelements
     * enabled includes the page record and records stored on it.
     *
     * @return list<array{
     *   historyUid: int,
     *   tstamp: int,
     *   tstampFormatted: string,
     *   action: string,
     *   actionKey: string,
     *   userUid: int,
     *   user: string,
     *   table: string,
     *   uid: int,
     *   tableLabel: string,
     *   recordTitle: string,
     *   diffs: list<array{field: string, label: string, before: string, after: string, html: string, historyUid: int}>
     * }>
     */
    public function buildPage(int $pageUid): array
    {
        return $pageUid > 0 ? $this->build('pages', $pageUid, true) : [];
    }

    /**
     * @param list<mixed> $changeLog
     * @return list<array{
     *   historyUid: int,
     *   tstamp: int,
     *   tstampFormatted: string,
     *   action: string,
     *   actionKey: string,
     *   userUid: int,
     *   user: string,
     *   table: string,
     *   uid: int,
     *   tableLabel: string,
     *   recordTitle: string,
     *   diffs: list<array{field: string, label: string, before: string, after: string, html: string, historyUid: int}>
     * }>
     */
    private function buildFromChangeLog(array $changeLog): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        $entries = [];
        foreach ($changeLog as $rawLog) {
            $log = Value::stringKeyArray($rawLog);
            // Skip entries outside the current workspace context —
            // editors are reviewing "what will publish", live edits
            // aren't part of that decision. Action 1 = INSERT,
            // 2 = UPDATE, 3 = MOVE, 4 = DELETE per DataHandler.
            $entryWs = Value::int($log['workspace'] ?? null);
            if ($workspaceId > 0 && $entryWs !== $workspaceId) {
                continue;
            }
            // The sys_history primary key is the `uid` column on the
            // log row, NOT the array key from getChangeLog() — core's
            // RecordHistory::getHistoryData() runs usort() before
            // returning, which destroys the uid-keyed indexing built
            // up earlier and replaces it with sequential 0/1/2…
            // positions. Reading the position would land us at
            // historyUid=0 (and break the rollback endpoint that
            // strict-validates historyUid > 0).
            $historyUid = Value::int($log['uid'] ?? null);
            if ($historyUid <= 0) {
                continue;
            }
            $entryTable = Value::string($log['tablename'] ?? null);
            $entryUid = Value::int($log['recuid'] ?? null);
            if ($entryTable === '' || $entryUid <= 0) {
                continue;
            }
            $newRecord = Value::stringKeyArray($log['newRecord'] ?? null);
            $oldRecord = Value::stringKeyArray($log['oldRecord'] ?? null);
            $actionType = Value::int($log['actiontype'] ?? null);
            $actionKey = $this->resolveActionKey($actionType);
            $action = $this->describeAction($actionKey);
            $userId = Value::int($log['userid'] ?? null);
            $entries[] = [
                'historyUid' => $historyUid,
                'tstamp' => Value::int($log['tstamp'] ?? null),
                'tstampFormatted' => BackendUtility::datetime(Value::int($log['tstamp'] ?? null)),
                'action' => $action,
                'actionKey' => $actionKey,
                'userUid' => $userId,
                'user' => $this->resolveUser($userId),
                'table' => $entryTable,
                'uid' => $entryUid,
                'tableLabel' => $this->resolveTableLabel($entryTable),
                'recordTitle' => $this->resolveRecordTitle($entryTable, $entryUid, $newRecord, $oldRecord),
                // historyUid duplicated into each diff so the inner
                // <f:for as="d"> doesn't have to reach back into the
                // enclosing entry scope and so the per-field rollback
                // POST always lands at the real sys_history row.
                'diffs' => $this->buildFieldDiffs($entryTable, $entryUid, $oldRecord, $newRecord, $historyUid),
            ];
        }

        return $entries;
    }

    public function countModifiedFields(string $table, int $uid): int
    {
        $fields = [];
        foreach ($this->build($table, $uid) as $entry) {
            if ($entry['actionKey'] !== 'modified') {
                continue;
            }
            foreach ($entry['diffs'] as $diff) {
                $field = $diff['field'];
                if ($field !== '') {
                    $fields[$field] = true;
                }
            }
        }
        return count($fields);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<array{field: string, label: string, before: string, after: string, html: string, historyUid: int}>
     */
    private function buildFieldDiffs(string $table, int $uid, array $old, array $new, int $historyUid): array
    {
        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
        $tableTca = TcaUtility::table($table);
        $columns = Value::stringKeyArray($tableTca['columns'] ?? null);
        $out = [];
        foreach ($fields as $field) {
            if (in_array($field, self::SKIP_FIELDS, true)) {
                continue;
            }
            $fieldTca = Value::stringKeyArray($columns[$field] ?? null);
            if ($fieldTca === []) {
                continue;
            }
            $configuration = Value::stringKeyArray($fieldTca['config'] ?? null);
            $before = $this->formatValue($table, $field, Value::string($old[$field] ?? null), $uid, $configuration, $old);
            $after = $this->formatValue($table, $field, Value::string($new[$field] ?? null), $uid, $configuration, $new);
            if ($before === $after) {
                continue;
            }
            $tcaLabel = Value::string($fieldTca['label'] ?? $field);
            $out[] = [
                'field' => $field,
                'label' => $this->localizationService->resolveLabel($tcaLabel) ?: $field,
                'before' => $before,
                'after' => $after,
                'html' => $this->diffUtility->diff($before, $after, DiffGranularity::WORD),
                'historyUid' => $historyUid,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $row
     */
    private function formatValue(string $table, string $field, string $value, int $uid, array $configuration, array $row): string
    {
        if ($value === '') {
            return '';
        }

        try {
            $processed = BackendUtility::getProcessedValue(
                $table,
                $field,
                $value,
                0,
                true,
                false,
                $uid,
                true,
                Value::int($row['pid'] ?? null),
                $row,
            );
            $formatted = (string)($processed ?? $value);
        } catch (\Throwable) {
            $formatted = $value;
        }

        if (($configuration['type'] ?? '') === 'text' || str_contains($formatted, '<') || str_contains($formatted, '&lt;')) {
            $formatted = html_entity_decode($formatted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $formatted = preg_replace('/<(br|\/p|\/div|\/li|\/ul|\/ol|h[1-6]|\/h[1-6])\b[^>]*>/i', ' ', $formatted) ?? $formatted;
            $formatted = strip_tags($formatted);
        }

        return trim((string)preg_replace('/\s+/u', ' ', $formatted));
    }

    private function resolveActionKey(int $actionType): string
    {
        return match ($actionType) {
            1 => 'created',
            2 => 'modified',
            3 => 'moved',
            4 => 'deleted',
            default => 'changed',
        };
    }

    private function describeAction(string $actionKey): string
    {
        return match ($actionKey) {
            'created' => $this->localizationService->translate('history.action.created'),
            'modified' => $this->localizationService->translate('history.action.modified'),
            'moved' => $this->localizationService->translate('history.action.moved'),
            'deleted' => $this->localizationService->translate('history.action.deleted'),
            default => $this->localizationService->translate('history.action.changed'),
        };
    }

    private function resolveUser(int $userId): string
    {
        if ($userId <= 0) {
            return $this->localizationService->translate('history.user.system');
        }
        $row = BackendUtility::getRecord('be_users', $userId, 'realName, username');
        if (!is_array($row)) {
            return $this->localizationService->translate('history.user.fallback', ['uid' => $userId]);
        }
        $realName = trim(Value::string($row['realName'] ?? null));
        $username = trim(Value::string($row['username'] ?? null));
        if ($realName !== '' && $username !== '') {
            return sprintf('%s (%s)', $realName, $username);
        }
        return $realName !== '' ? $realName : ($username !== '' ? $username : $this->localizationService->translate('history.user.fallback', ['uid' => $userId]));
    }

    private function resolveTableLabel(string $table): string
    {
        $tableTca = TcaUtility::table($table);
        $label = Value::string(Value::stringKeyArray($tableTca['ctrl'] ?? null)['title'] ?? $table);
        $translated = $this->localizationService->resolveLabel($label);
        return $translated !== '' ? $translated : $table;
    }

    /**
     * @param array<string, mixed> $newRecord
     * @param array<string, mixed> $oldRecord
     */
    private function resolveRecordTitle(string $table, int $uid, array $newRecord, array $oldRecord): string
    {
        $row = BackendUtility::getRecord($table, $uid);
        if (!is_array($row)) {
            $row = array_replace($oldRecord, $newRecord, ['uid' => $uid]);
        }
        $title = trim(strip_tags(BackendUtility::getRecordTitle($table, $row, false, true)));
        return $title !== '' ? $title : sprintf('%s #%d', $table, $uid);
    }
}
