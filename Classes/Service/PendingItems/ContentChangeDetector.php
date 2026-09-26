<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

/**
 * Whether a workspace version differs from its live record in anything an
 * editor wrote.
 *
 * TYPO3 versions more than the editor touched: editing an element in a
 * workspace also versions its translations and its collection items and
 * their translations, as copies of the live rows. The Workspaces module
 * lists those copies like any change; the toolbar marks them, so a
 * translation nobody edited does not read as edited.
 *
 * Measured on such copies: they differ from live only in the columns TYPO3
 * keeps for itself (uid, timestamps, the version pointers). Empty values are
 * compared loosely, because copying a row turns a '0' into '' in a few group
 * fields (fe_group, selected_categories) without anyone editing them.
 */
final class ContentChangeDetector
{
    /**
     * Columns TYPO3 maintains itself, never an editor.
     *
     * @var list<string>
     */
    private const array SYSTEM_FIELDS = [
        'uid', 'tstamp', 'crdate', 'cruser_id',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_tstamp', 't3ver_move_id',
        't3_origuid', 'tx_impexp_origuid',
        'l18n_diffsource', 'l10n_diffsource', 'l10n_state',
    ];

    /**
     * @param array<string, mixed> $version The version row.
     * @param array<string, mixed>|null $live Its live row; null for a record new in the workspace.
     */
    public static function differs(array $version, ?array $live): bool
    {
        if ($live === null) {
            return true;
        }
        foreach ($version as $field => $value) {
            if (in_array($field, self::SYSTEM_FIELDS, true)) {
                continue;
            }
            if (self::normalized($value) !== self::normalized($live[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function normalized(mixed $value): string
    {
        $string = is_scalar($value) ? trim((string)$value) : '';

        return $string === '0' ? '' : $string;
    }
}
