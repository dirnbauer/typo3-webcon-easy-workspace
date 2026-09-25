<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Utility;

/**
 * Which tables publish, discard, diff and history may act on: every
 * workspace-aware table, as in the Workspaces module. Whether the editor
 * may modify a table is DataHandler's and core's decision, not this one's.
 */
final class WorkspaceTablePolicy
{
    /**
     * Cmdmap order: parents before children.
     *
     * @var list<string>
     */
    public const array PUBLISH_ORDER = [
        'pages',
        'tx_news_domain_model_news',
        'tt_content',
        'sys_file_metadata',
    ];

    public function isAllowed(string $table): bool
    {
        return $table !== '' && !empty(Value::stringKeyArray(TcaUtility::table($table)['ctrl'] ?? null)['versioningWS']);
    }
}
