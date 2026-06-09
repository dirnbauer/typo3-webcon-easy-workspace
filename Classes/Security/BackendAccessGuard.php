<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Security;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Single place to resolve the acting backend user and run the
 * permission checks shared by the AJAX endpoints, the backend module
 * and the DataHandler-facing services.
 *
 * Prefers the PSR-7 `backend.user` request attribute; the BE_USER
 * global is only consulted where no request is in scope (toolbar
 * rendering, DataHandler hook, CLI). All other classes go through
 * this guard instead of touching the global directly.
 */
final readonly class BackendAccessGuard
{
    public function __construct(private Context $context) {}

    public function user(?ServerRequestInterface $request = null): ?BackendUserAuthentication
    {
        $user = $request?->getAttribute('backend.user');
        if ($user instanceof BackendUserAuthentication) {
            return $user;
        }
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }

    /**
     * Workspace mutations happen in the context aspect's workspace if
     * one is set, otherwise in the user's session workspace. 0 = live
     * (no Easy Workspace operations allowed).
     */
    public function activeWorkspaceId(?ServerRequestInterface $request = null): int
    {
        $user = $this->user($request);
        if ($user === null || $user->workspace <= 0) {
            return 0;
        }
        $contextWorkspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));

        return $contextWorkspaceId > 0 ? $contextWorkspaceId : $user->workspace;
    }

    /**
     * Page-show access check for page-scoped reads. A non-positive
     * uid means "no page scope requested" and passes; an unknown or
     * inaccessible page fails.
     */
    public function canReadPage(int $pageUid, ?ServerRequestInterface $request = null): bool
    {
        $user = $this->user($request);
        if ($user === null) {
            return false;
        }
        if ($pageUid <= 0) {
            return true;
        }

        return is_array(BackendUtility::readPageAccess($pageUid, $user->getPagePermsClause(Permission::PAGE_SHOW)));
    }

    /**
     * Page-show access for the page containing a record. For `pages`
     * rows the record itself is checked.
     *
     * @param array<string, mixed> $row
     */
    public function canReadRecordPage(string $table, array $row, ?ServerRequestInterface $request = null): bool
    {
        $pageUid = $table === 'pages'
            ? (Value::int($row['t3ver_oid'] ?? null) ?: Value::int($row['uid'] ?? null))
            : Value::int($row['pid'] ?? null);

        return $this->canReadPage($pageUid, $request);
    }

    public function canModifyTable(string $table, ?ServerRequestInterface $request = null): bool
    {
        $user = $this->user($request);

        return $user !== null && $table !== '' && $user->check('tables_modify', $table);
    }

    /**
     * Workspace membership check — DataHandler and the workspaces
     * DataHandlerHook remain the enforcement layer; this is the
     * fail-fast pre-flight for clean error responses.
     */
    public function hasWorkspaceAccess(int $workspaceId, ?ServerRequestInterface $request = null): bool
    {
        $user = $this->user($request);
        if ($user === null || $workspaceId <= 0) {
            return false;
        }

        return $user->isAdmin() || $user->checkWorkspace($workspaceId) !== false;
    }
}
