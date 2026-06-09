<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final readonly class WorkspaceChangeStampService
{
    private const SESSION_KEY = 'webcon_easy_workspace.workspaceChangeStamp';

    /**
     * @return array{revision: int, workspaceId: int, changedAt: int}
     */
    public function current(?BackendUserAuthentication $backendUser = null): array
    {
        $backendUser ??= $this->backendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return ['revision' => 0, 'workspaceId' => 0, 'changedAt' => 0];
        }

        return $this->normalizeStamp($backendUser->getSessionData(self::SESSION_KEY));
    }

    public function bump(int $workspaceId = 0, ?BackendUserAuthentication $backendUser = null): int
    {
        $backendUser ??= $this->backendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 0;
        }

        $stamp = $this->normalizeStamp($backendUser->getSessionData(self::SESSION_KEY));
        $stamp['revision']++;
        $stamp['workspaceId'] = max(0, $workspaceId);
        $stamp['changedAt'] = time();
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, $stamp);

        return $stamp['revision'];
    }

    /**
     * @return array{revision: int, workspaceId: int, changedAt: int}
     */
    private function normalizeStamp(mixed $stamp): array
    {
        $stamp = is_array($stamp) ? $stamp : [];

        return [
            'revision' => max(0, Value::int($stamp['revision'] ?? null)),
            'workspaceId' => max(0, Value::int($stamp['workspaceId'] ?? null)),
            'changedAt' => max(0, Value::int($stamp['changedAt'] ?? null)),
        ];
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        return ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
    }
}
