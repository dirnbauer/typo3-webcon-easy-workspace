<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\History\RecordHistory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
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
        private LanguageServiceFactory $languageServiceFactory,
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
     *   diffs: list<array{field: string, label: string, before: string, after: string, html: string, historyUid: int}>
     * }>
     */
    public function build(string $table, int $uid): array
    {
        $workspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));

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
                // historyUid duplicated into each diff so the inner
                // <f:for as="d"> doesn't have to reach back into the
                // enclosing entry scope and so the per-field rollback
                // POST always lands at the real sys_history row.
                'diffs' => $this->buildFieldDiffs($table, $oldRecord, $newRecord, $historyUid),
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
    private function buildFieldDiffs(string $table, array $old, array $new, int $historyUid): array
    {
        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
        $languageService = $this->getLanguageService();
        $out = [];
        foreach ($fields as $field) {
            if (in_array($field, self::SKIP_FIELDS, true)) {
                continue;
            }
            $before = Value::string($old[$field] ?? null);
            $after = Value::string($new[$field] ?? null);
            if ($before === $after) {
                continue;
            }
            $tableTca = TcaUtility::table($table);
            $columns = Value::stringKeyArray($tableTca['columns'] ?? null);
            $fieldTca = Value::stringKeyArray($columns[$field] ?? null);
            $tcaLabel = Value::string($fieldTca['label'] ?? $field);
            $out[] = [
                'field' => $field,
                'label' => $languageService->sL($tcaLabel) ?: $field,
                'before' => $before,
                'after' => $after,
                'html' => $this->diffUtility->diff($before, $after, DiffGranularity::WORD),
                'historyUid' => $historyUid,
            ];
        }
        return $out;
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

    private function getLanguageService(): LanguageService
    {
        if (($GLOBALS['LANG'] ?? null) instanceof LanguageService) {
            return $GLOBALS['LANG'];
        }
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof AbstractUserAuthentication ? $GLOBALS['BE_USER'] : null;
        return $this->languageServiceFactory->createFromUserPreferences($backendUser);
    }
}
