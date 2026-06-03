<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Utility;

/**
 * Canonical allow-list for workspace publish/discard/diff operations.
 *
 * Primary tables are always permitted. Inline child tables are accepted
 * when TCA marks them as workspace-aware children of a workspace-aware parent.
 */
final class WorkspaceTablePolicy
{
    /**
     * Top-level tables the dropdown operates on directly.
     *
     * @var list<string>
     */
    public const PRIMARY_TABLES = [
        'pages',
        'tt_content',
        'tx_news_domain_model_news',
        'sys_file_metadata',
    ];

    /**
     * Cmdmap order: parents before children.
     *
     * @var list<string>
     */
    public const PUBLISH_ORDER = [
        'pages',
        'tx_news_domain_model_news',
        'tt_content',
        'sys_file_metadata',
    ];

    public function isPrimary(string $table): bool
    {
        return in_array($table, self::PRIMARY_TABLES, true);
    }

    public function isAllowed(string $table): bool
    {
        if ($this->isPrimary($table)) {
            return true;
        }
        if ($table === 'sys_file_metadata' || $table === 'sys_file_reference') {
            $ctrl = Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null);
            return !empty($ctrl['versioningWS']);
        }
        if (!TcaUtility::isWorkspaceAwareHiddenTable($table)) {
            return false;
        }
        if (TcaUtility::hasColumn($table, 'foreign_table_parent_uid')) {
            return true;
        }
        foreach (TcaUtility::tables() as $parentTca) {
            $ctrl = Value::stringKeyArray($parentTca['ctrl'] ?? null);
            if (empty($ctrl['versioningWS'])) {
                continue;
            }
            foreach (TcaUtility::extractInlineFieldConfigs($parentTca) as $fieldConfig) {
                if (($fieldConfig['foreign_table'] ?? '') === $table && !empty($fieldConfig['foreign_field'])) {
                    return true;
                }
            }
        }
        return false;
    }
}
