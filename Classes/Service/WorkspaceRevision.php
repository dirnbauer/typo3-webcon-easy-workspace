<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Registry;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * A token that changes whenever DataHandler wrote something a workspace's
 * pending changes can depend on.
 *
 * The badge stamp used to fingerprint only pages, tt_content and news
 * (row count and newest tstamp), so editing a Content Blocks collection item,
 * a file reference or file metadata left it unchanged: other tabs were not
 * told, and an open list did not refresh. WorkspaceRevisionHook replaces the
 * workspace's token after every DataHandler run that touches a
 * workspace-aware table, and the token of Live (0) whenever live data
 * changed — a live edit or a publish — because a workspace overlays Live.
 *
 * The badge stamp includes both tokens, which makes it exact for everything
 * written through DataHandler; the row fingerprint stays in the stamp for
 * writes that bypass it.
 */
final readonly class WorkspaceRevision
{
    private const string NAMESPACE = 'webcon_easy_workspace';

    public function __construct(private Registry $registry) {}

    /**
     * Combined token of the workspace and of Live.
     */
    public function current(int $workspaceId): string
    {
        $live = $this->token(0);

        return $workspaceId > 0 ? $this->token($workspaceId) . '.' . $live : $live;
    }

    public function bump(int $workspaceId): void
    {
        $this->registry->set(self::NAMESPACE, self::key($workspaceId), bin2hex(random_bytes(8)));
    }

    private function token(int $workspaceId): string
    {
        return Value::string($this->registry->get(self::NAMESPACE, self::key($workspaceId), ''));
    }

    private static function key(int $workspaceId): string
    {
        return 'revision.' . max(0, $workspaceId);
    }
}
