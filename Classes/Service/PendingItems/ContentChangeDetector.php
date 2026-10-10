<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Webconsulting\WebconEasyWorkspace\Utility\Value;

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
     * SYS_LASTCHANGED is the frontend's: each live render that finds a newer
     * record writes it into the live page row (and its translations) without
     * DataHandler, so a page version keeps the value it was copied with.
     *
     * @var list<string>
     */
    private const array SYSTEM_FIELDS = [
        'uid', 'tstamp', 'crdate', 'cruser_id',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_tstamp', 't3ver_move_id',
        't3_origuid', 'tx_impexp_origuid',
        'l18n_diffsource', 'l10n_diffsource', 'l10n_state',
        'SYS_LASTCHANGED',
    ];

    /**
     * @param array<string, mixed> $version The version row.
     * @param array<string, mixed>|null $live Its live row; null for a record new in the workspace.
     */
    public static function differs(array $version, ?array $live): bool
    {
        return $live === null || self::changedFields($version, $live) !== [];
    }

    /**
     * The fields a version changes, each with a hash of its new value.
     *
     * Two versions that change a field to the same value carry the same
     * hash, which is how a translation copy is told to mirror its source:
     * TYPO3 writes the synchronized fields (and hook-written ones) into
     * every translation with the value the source got.
     *
     * Pointer fields — the translation parent and source — are compared by
     * the live uid they stand for: when a record is moved or deleted in a
     * workspace, core points its translations (and their file references)
     * at the version of the default record instead of the live one.
     *
     * @param array<string, mixed> $version The version row.
     * @param array<string, mixed>|null $live Its live row; null for a record new in the workspace (no fields: what is new is decided by its version state).
     * @param list<string> $pointerFields Columns holding the uid of another record of the same table.
     * @param (\Closure(int): int)|null $liveOf Maps a version uid to its live uid, any other uid to itself.
     * @return array<string, string> field => sha1 of the normalized version value
     */
    public static function changedFields(array $version, ?array $live, array $pointerFields = [], ?\Closure $liveOf = null): array
    {
        if ($live === null) {
            return [];
        }
        $changed = [];
        foreach ($version as $field => $value) {
            if (in_array($field, self::SYSTEM_FIELDS, true)) {
                continue;
            }
            $liveValue = $live[$field] ?? null;
            if ($liveOf !== null && in_array($field, $pointerFields, true)) {
                $value = $liveOf(Value::int($value));
                $liveValue = $liveOf(Value::int($liveValue));
            }
            $normalized = self::normalized($value);
            if ($normalized !== self::normalized($liveValue)) {
                $changed[$field] = sha1($normalized);
            }
        }

        return $changed;
    }

    private static function normalized(mixed $value): string
    {
        $string = is_scalar($value) ? trim((string)$value) : '';

        return $string === '0' ? '' : $string;
    }
}
