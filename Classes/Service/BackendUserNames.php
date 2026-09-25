<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Display names of backend users ("Real Name (username)"), read once per
 * request: a page's history names the same few editors on every row.
 */
final class BackendUserNames
{
    /**
     * @var array<int, string>
     */
    private array $names = [];

    public function __construct(private readonly LocalizationService $localizationService) {}

    public function name(int $userId): string
    {
        if ($userId <= 0) {
            return $this->localizationService->translate('history.user.system');
        }

        return $this->names[$userId] ??= $this->resolve($userId);
    }

    private function resolve(int $userId): string
    {
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
}
