<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Security;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\WebconWorkspaceChatops\Enum\ChatProvider;
use Webconsulting\WebconWorkspaceChatops\Utility\Value;

final readonly class BackendUserImpersonator
{
    public function __construct(private ConnectionPool $connectionPool) {}

    public function byUid(int $backendUserId, int $workspaceId = 0): ?BackendUserAuthentication
    {
        if ($backendUserId <= 0) {
            return null;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($backendUserId, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        return $this->fromUserRow($row, $workspaceId);
    }

    public function byExternalIdentity(ChatProvider $provider, string $externalId, int $workspaceId = 0): ?BackendUserAuthentication
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return null;
        }
        $settingKey = match ($provider) {
            ChatProvider::Slack => 'webconWorkspaceChatopsSlackUserId',
            ChatProvider::Teams => 'webconWorkspaceChatopsTeamsUserId',
            ChatProvider::WhatsApp => 'webconWorkspaceChatopsWhatsappPhone',
        };

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $settings = $this->decodeUserSettings($row['user_settings'] ?? null);
            if (trim(Value::string($settings[$settingKey] ?? null)) === $externalId) {
                return $this->fromUserRow($row, $workspaceId);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromUserRow(array $row, int $workspaceId): BackendUserAuthentication
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = $row;
        $backendUser->fetchGroupData();
        $backendUser->backendSetUC();
        if ($workspaceId > 0) {
            $backendUser->setTemporaryWorkspace($workspaceId);
        } elseif (Value::int($row['workspace_id'] ?? null) > 0) {
            $backendUser->setTemporaryWorkspace(Value::int($row['workspace_id']));
        }

        return $backendUser;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeUserSettings(mixed $value): array
    {
        if (is_array($value)) {
            return Value::stringKeyArray($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return Value::stringKeyArray($decoded);
    }
}
