<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Field-level diff between a workspace-version row and its live
 * counterpart. Produces a small list of changes
 *   [{field, label, before, after, type, kind}]
 * that the toolbar dropdown's "Latest workspace changes" accordion
 * renders inline under each record.
 *
 * What this is NOT
 * ────────────────
 * This is intentionally simpler than TYPO3 core's
 * `\TYPO3\CMS\Workspaces\Service\GridDataService::getRowDetails()`:
 *  - No stage/comment/history hydration (Latest accordion only needs
 *    what changed, not the workflow context)
 *  - No HTML diff rendering — we ship plain before/after strings and
 *    let the frontend display them as side-by-side chips. The
 *    accordion is a dropdown, not a full diff view; word-by-word
 *    inline markup doesn't fit the space and would have to be
 *    sanitized for XSS anyway.
 *  - No event dispatching. Latest is read-only and one-shot.
 *
 * Field exclusions match what TYPO3 admins expect in a diff:
 * primary key, parent, ordering, soft-delete and version metadata
 * are technical, not editorial.
 */
final readonly class RecordDiffService
{
    /**
     * Fields whose changes are technical rather than editorial — we
     * never surface them in the change feed. l10n_diffsource and
     * tx_impexp_origuid are also implicit (set by the import/
     * localization machinery, never by an editor in the form).
     *
     * @var list<string>
     */
    private const SKIP_FIELDS = [
        'uid', 'pid', 'tstamp', 'crdate', 'sorting',
        't3ver_wsid', 't3ver_oid', 't3ver_state', 't3ver_stage', 't3ver_count',
        'l10n_diffsource', 'l10n_source', 'l10n_parent',
        'tx_impexp_origuid',
    ];

    /**
     * Per-field truncation for the inline "before / after" chips —
     * long bodytexts would otherwise overflow the dropdown. We keep
     * full values on the server but ship trimmed strings to the
     * client; the JS displays them with the full value in `title=""`
     * so editors can hover for context.
     */
    private const VALUE_TRUNCATE_CHARS = 160;

    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * Diff a single workspace-version row against its live record.
     *
     * @return list<array{field: string, label: string, before: string, after: string, beforeFull: string, afterFull: string, type: string, kind: string}>
     */
    public function diff(string $table, array $versionRow): array
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }
        $schema = $this->tcaSchemaFactory->get($table);
        $languageService = $this->getLanguageService();

        $versionState = VersionState::tryFrom((int)($versionRow['t3ver_state'] ?? 0)) ?? VersionState::DEFAULT_STATE;
        $isNew = $versionState === VersionState::NEW_PLACEHOLDER;
        $isDelete = $versionState === VersionState::DELETE_PLACEHOLDER;

        // The "before" snapshot. For modify edits this is the live
        // record (t3ver_oid); for fresh new-placeholder versions
        // there's no live counterpart yet — empty array, every
        // populated field reads as "added".
        $liveUid = (int)($versionRow['t3ver_oid'] ?? 0);
        $liveRow = ($liveUid > 0) ? (array)BackendUtility::getRecord($table, $liveUid) : [];

        // Union of field names; ensures fields only set in one of
        // the two snapshots still get diffed.
        $fields = array_unique(array_merge(array_keys($liveRow), array_keys($versionRow)));
        sort($fields);

        $diffs = [];
        foreach ($fields as $field) {
            if (in_array($field, self::SKIP_FIELDS, true)) {
                continue;
            }
            if (!$schema->hasField($field)) {
                continue;
            }

            $beforeRaw = (string)($liveRow[$field] ?? '');
            $afterRaw = (string)($versionRow[$field] ?? '');

            // For modify edits, skip fields that didn't actually
            // change. For new/delete placeholders, every populated
            // field is meaningful (added on new, removed on delete).
            if (!$isNew && !$isDelete && $beforeRaw === $afterRaw) {
                continue;
            }

            $fieldType = $schema->getField($field);
            $rawLabel = $fieldType->getLabel() ?: $field;
            $label = $languageService->sL($rawLabel);
            if ($label === '') {
                $label = $field;
            }

            $configuration = $fieldType->getConfiguration();
            $beforeFormatted = $this->formatValue($table, $field, $beforeRaw, $liveUid, $configuration, $liveRow);
            $afterFormatted = $this->formatValue($table, $field, $afterRaw, (int)($versionRow['uid'] ?? 0), $configuration, $versionRow);

            // After format normalization, an unchanged display value
            // means the difference is purely cosmetic (e.g. trailing
            // whitespace) — don't bother the editor.
            if (!$isNew && !$isDelete && $beforeFormatted === $afterFormatted) {
                continue;
            }

            $diffs[] = [
                'field' => $field,
                'label' => $label,
                'before' => $this->truncate($beforeFormatted),
                'after' => $this->truncate($afterFormatted),
                'beforeFull' => $beforeFormatted,
                'afterFull' => $afterFormatted,
                'type' => (string)($configuration['type'] ?? 'string'),
                'kind' => $isNew ? 'added' : ($isDelete ? 'removed' : 'changed'),
            ];
        }

        return $diffs;
    }

    /**
     * Resolve a single field value to its human-readable form.
     *
     * Mostly delegates to `BackendUtility::getProcessedValue()`
     * (which honors itemsProcFunc, select item labels, file
     * references, datetime formatting, etc.). For RTE fields we
     * strip HTML so the inline chip stays readable.
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
                (int)($row['pid'] ?? 0),
                $row,
            );
            $formatted = (string)($processed ?? $value);
        } catch (\Throwable) {
            // Some field types (FAL, complex flex configs) can blow
            // up if the relationship is broken on this version row
            // — fall back to the raw stored value rather than 500.
            $formatted = $value;
        }

        // Strip HTML for RTE/text fields. The diff is shown in a
        // tight chip; raw markup would render as live HTML or get
        // eaten by Lit's text-content escaping anyway, so we
        // normalize early.
        if (($configuration['type'] ?? '') === 'text' || str_contains($formatted, '<')) {
            $formatted = strip_tags(html_entity_decode($formatted, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Collapse whitespace so the chip doesn't render a
        // multi-line fragment for an inline change.
        $formatted = trim((string)preg_replace('/\s+/u', ' ', $formatted));

        return $formatted;
    }

    private function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::VALUE_TRUNCATE_CHARS) {
            return $value;
        }
        return mb_substr($value, 0, self::VALUE_TRUNCATE_CHARS - 1) . '…';
    }

    private function getLanguageService(): LanguageService
    {
        if (isset($GLOBALS['LANG']) && $GLOBALS['LANG'] instanceof LanguageService) {
            return $GLOBALS['LANG'];
        }
        return $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
    }
}
