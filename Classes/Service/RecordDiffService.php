<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\DiffGranularity;
use TYPO3\CMS\Core\Utility\DiffUtility;
use TYPO3\CMS\Core\Versioning\VersionState;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

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
        // Both spellings: l18n_diffsource is the v14 canonical name,
        // l10n_diffsource is the legacy field name still present on
        // some older schemas. Same JSON-blob field in either case —
        // editors should never see it in a "what changed" view.
        'l18n_diffsource', 'l10n_diffsource',
        'l10n_state', 'l10n_source', 'l10n_parent',
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
        private DiffUtility $diffUtility,
        private LocalizationService $localizationService,
    ) {}

    /**
     * Same diff as ::diff() above, but each entry also carries a
     * pre-rendered `html` string produced by TYPO3 core's
     * DiffUtility — the same `<ins>`/`<del>` inline-diff format used
     * in the standalone Workspaces module. Suitable for direct
     * rendering inside a Fluid template via `<f:format.raw>`.
     *
     * Returns the metadata needed to title the diff dialog as well,
     * so a single controller call can fully populate the overlay.
     *
     * @param array<string, mixed> $versionRow
     * @return array{title: string, tableLabel: string, table: string, workspaceUid: int, liveUid: int, kind: string, diffs: list<array{field: string, label: string, before: string, after: string, beforeFull: string, afterFull: string, type: string, html: string, kind: string}>}
     */
    public function diffWithHtml(string $table, array $versionRow): array
    {
        $entries = $this->diff($table, $versionRow);
        $kind = $entries[0]['kind'] ?? 'changed';

        $diffs = [];
        foreach ($entries as $entry) {
            // DiffGranularity::WORD matches TYPO3's Workspaces module
            // ("Show changes") — splits on word boundaries, ins/del
            // are word-level rather than character noise. For very
            // long bodies (200+ words) this can still be visually
            // dense; falls within the modal's `large` size budget.
            $diffs[] = $entry + [
                'html' => $this->diffUtility->diff($entry['before'], $entry['after'], DiffGranularity::WORD),
            ];
        }

        return [
            'title' => $this->resolveTitle($table, $versionRow),
            'tableLabel' => $this->resolveTableLabelHint($table),
            'table' => $table,
            'workspaceUid' => Value::int($versionRow['uid'] ?? null),
            'liveUid' => Value::int($versionRow['t3ver_oid'] ?? null),
            'kind' => $kind,
            'diffs' => $diffs,
        ];
    }

    /**
     * Best-effort record title for the modal heading. Used only as
     * presentation — the canonical title-field resolution lives in
     * PendingItemsService::resolveTitle(); we duplicate a minimal
     * version here so the diff service stays standalone.
     */
    /**
     * @param array<string, mixed> $row
     */
    private function resolveTitle(string $table, array $row): string
    {
        $candidates = [
            $row['title'] ?? null,
            $row['header'] ?? null,
            $row['name'] ?? null,
            $row['subject'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim(Value::string($candidate));
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return $this->localizationService->translate('diff.noTitle');
    }

    private function resolveTableLabelHint(string $table): string
    {
        return match ($table) {
            'pages'                       => $this->localizationService->translate('table.pages'),
            'tt_content'                  => $this->localizationService->translate('table.tt_content'),
            'tx_news_domain_model_news'   => $this->localizationService->translate('table.tx_news_domain_model_news'),
            default                       => $table,
        };
    }

    /**
     * Diff a single workspace-version row against its live record.
     *
     * @param array<string, mixed> $versionRow
     * @return list<array{field: string, label: string, before: string, after: string, beforeFull: string, afterFull: string, type: string, kind: string}>
     */
    public function diff(string $table, array $versionRow): array
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }
        $schema = $this->tcaSchemaFactory->get($table);
        $languageService = $this->getLanguageService();

        $versionState = VersionState::tryFrom(Value::int($versionRow['t3ver_state'] ?? null)) ?? VersionState::DEFAULT_STATE;
        $isNew = $versionState === VersionState::NEW_PLACEHOLDER;
        $isDelete = $versionState === VersionState::DELETE_PLACEHOLDER;

        // The "before" snapshot. For modify edits this is the live
        // record (t3ver_oid); for fresh new-placeholder versions
        // there's no live counterpart yet — empty array, every
        // populated field reads as "added".
        $liveUid = Value::int($versionRow['t3ver_oid'] ?? null);
        $liveRow = Value::stringKeyArray($liveUid > 0 ? BackendUtility::getRecord($table, $liveUid) : null);

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

            $beforeRaw = Value::string($liveRow[$field] ?? null);
            $afterRaw = Value::string($versionRow[$field] ?? null);

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

            $configuration = Value::stringKeyArray($fieldType->getConfiguration());
            $beforeFormatted = $this->formatValue($table, $field, $beforeRaw, $liveUid, $configuration, $liveRow);
            $afterFormatted = $this->formatValue($table, $field, $afterRaw, Value::int($versionRow['uid'] ?? null), $configuration, $versionRow);

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
                'type' => Value::string($configuration['type'] ?? 'string'),
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
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof AbstractUserAuthentication ? $GLOBALS['BE_USER'] : null;
        return $this->languageServiceFactory->createFromUserPreferences($backendUser);
    }
}
