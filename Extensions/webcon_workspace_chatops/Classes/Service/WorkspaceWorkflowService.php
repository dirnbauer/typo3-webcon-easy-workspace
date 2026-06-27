<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\WebconWorkspaceChatops\Configuration\ChatOpsConfiguration;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceRecordSelection;

final readonly class WorkspaceWorkflowService
{
    public function __construct(
        private ChatOpsConfiguration $configuration,
        private ConnectionPool $connectionPool,
        private Context $context,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @param list<WorkspaceRecordSelection> $selections
     * @return array{success: bool, changed: int, errors: list<string>}
     */
    public function requestApproval(array $selections, BackendUserAuthentication $backendUser, string $comment = ''): array
    {
        return $this->sendToStage($selections, $this->configuration->approvalStageId(), $backendUser, $comment);
    }

    /**
     * @param list<WorkspaceRecordSelection> $selections
     * @return array{success: bool, changed: int, errors: list<string>}
     */
    public function approveAndPublish(array $selections, BackendUserAuthentication $backendUser, string $comment = ''): array
    {
        $stageResult = $this->sendToStage($selections, $this->configuration->publishStageId(), $backendUser, $comment);
        if (!$stageResult['success']) {
            return $stageResult;
        }

        return $this->publish($selections, $backendUser, $comment);
    }

    /**
     * @param list<WorkspaceRecordSelection> $selections
     * @return array{success: bool, changed: int, errors: list<string>}
     */
    public function sendToStage(array $selections, int $stageId, BackendUserAuthentication $backendUser, string $comment = ''): array
    {
        $workspaceId = $this->activeWorkspaceId($backendUser);
        if ($workspaceId <= 0 || $backendUser->checkWorkspace($workspaceId) === false) {
            return ['success' => false, 'changed' => 0, 'errors' => ['No accessible workspace is active.']];
        }

        $cmd = [];
        $count = 0;
        foreach ($this->groupWorkspaceUidsByTable($selections) as $table => $workspaceUids) {
            if (!$backendUser->check('tables_modify', $table)) {
                continue;
            }
            foreach ($this->filterWorkspaceUids($table, $workspaceUids, $workspaceId) as $workspaceUid) {
                $cmd[$table][$workspaceUid]['version'] = [
                    'action' => 'setStage',
                    'stageId' => $stageId,
                    'comment' => $comment,
                ];
                ++$count;
            }
        }
        if ($cmd === []) {
            return ['success' => false, 'changed' => 0, 'errors' => ['No selected records can be staged.']];
        }

        $dataHandler = $this->processCmdMap($cmd, $workspaceId, $backendUser);

        return [
            'success' => $dataHandler->errorLog === [],
            'changed' => $count,
            'errors' => array_values(array_map('strval', $dataHandler->errorLog)),
        ];
    }

    /**
     * @param list<WorkspaceRecordSelection> $selections
     * @return array{success: bool, changed: int, errors: list<string>}
     */
    public function publish(array $selections, BackendUserAuthentication $backendUser, string $comment = ''): array
    {
        $workspaceId = $this->activeWorkspaceId($backendUser);
        if ($workspaceId <= 0 || $backendUser->checkWorkspace($workspaceId) === false) {
            return ['success' => false, 'changed' => 0, 'errors' => ['No accessible workspace is active.']];
        }

        $cmd = [];
        $count = 0;
        foreach ($this->groupWorkspaceUidsByTable($selections) as $table => $workspaceUids) {
            if (!$backendUser->check('tables_modify', $table)) {
                continue;
            }
            foreach ($this->mapWorkspaceUidsToLive($table, $workspaceUids, $workspaceId) as $workspaceUid => $liveUid) {
                $cmd[$table][$liveUid]['version'] = [
                    'action' => 'publish',
                    'swapWith' => $workspaceUid,
                    'comment' => $comment,
                ];
                ++$count;
            }
        }
        if ($cmd === []) {
            return ['success' => false, 'changed' => 0, 'errors' => ['No selected records can be published.']];
        }

        $dataHandler = $this->processCmdMap($cmd, $workspaceId, $backendUser);

        return [
            'success' => $dataHandler->errorLog === [],
            'changed' => $count,
            'errors' => array_values(array_map('strval', $dataHandler->errorLog)),
        ];
    }

    /**
     * @param list<WorkspaceRecordSelection> $selections
     * @return array<string, list<int>>
     */
    private function groupWorkspaceUidsByTable(array $selections): array
    {
        $grouped = [];
        foreach ($selections as $selection) {
            if (!$this->isWorkspaceAware($selection->table)) {
                continue;
            }
            $grouped[$selection->table][$selection->workspaceUid] = true;
        }

        return array_map(static fn(array $uidSet): array => array_map('intval', array_keys($uidSet)), $grouped);
    }

    /**
     * @param list<int> $uids
     * @return list<int>
     */
    private function filterWorkspaceUids(string $table, array $uids, int $workspaceId): array
    {
        return array_keys($this->mapWorkspaceUidsToLive($table, $uids, $workspaceId));
    }

    /**
     * @param list<int> $uids
     * @return array<int, int> workspace uid => live uid
     */
    private function mapWorkspaceUidsToLive(string $table, array $uids, int $workspaceId): array
    {
        if ($uids === [] || !$this->isWorkspaceAware($table)) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $select = ['uid', 't3ver_oid', 't3ver_wsid'];
        $deletedField = $this->softDeleteField($table);
        if ($deletedField !== null) {
            $select[] = $deletedField;
        }
        $rows = $queryBuilder
            ->select(...$select)
            ->from($table)
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            if ($deletedField !== null && (int)($row[$deletedField] ?? 0) !== 0) {
                continue;
            }
            $workspaceUid = (int)($row['uid'] ?? 0);
            if ($workspaceUid <= 0) {
                continue;
            }
            $map[$workspaceUid] = (int)($row['t3ver_oid'] ?? 0) ?: $workspaceUid;
        }

        return $map;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmd
     */
    private function processCmdMap(array $cmd, int $workspaceId, BackendUserAuthentication $backendUser): DataHandler
    {
        $savedBackendUser = $GLOBALS['BE_USER'] ?? null;
        $savedWorkspace = $backendUser->workspace;
        $savedWorkspaceRec = $backendUser->workspaceRec;
        $savedWorkspaceContext = $this->context->getAspect('workspace');

        $workspaceRecord = $backendUser->checkWorkspace($workspaceId);
        if (is_array($workspaceRecord)) {
            $backendUser->workspaceRec = $workspaceRecord;
        }
        $backendUser->workspace = $workspaceId;
        $GLOBALS['BE_USER'] = $backendUser;
        $this->context->setAspect('workspace', new WorkspaceAspect($workspaceId));

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $cmd, $backendUser);
            $dataHandler->process_cmdmap();
        } finally {
            $backendUser->workspace = $savedWorkspace;
            $backendUser->workspaceRec = $savedWorkspaceRec;
            if ($savedBackendUser instanceof BackendUserAuthentication) {
                $GLOBALS['BE_USER'] = $savedBackendUser;
            } else {
                unset($GLOBALS['BE_USER']);
            }
            $this->context->setAspect('workspace', $savedWorkspaceContext);
        }

        return $dataHandler;
    }

    private function activeWorkspaceId(BackendUserAuthentication $backendUser): int
    {
        if ($backendUser->workspace > 0) {
            return $backendUser->workspace;
        }
        $userWorkspace = (int)($backendUser->user['workspace_id'] ?? 0);
        if ($userWorkspace > 0) {
            return $userWorkspace;
        }

        return $this->configuration->defaultWorkspaceId();
    }

    private function isWorkspaceAware(string $table): bool
    {
        return $this->tcaSchemaFactory->has($table) && $this->tcaSchemaFactory->get($table)->isWorkspaceAware();
    }

    private function softDeleteField(string $table): ?string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return null;
        }
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->hasCapability(TcaSchemaCapability::SoftDelete)) {
            return null;
        }

        return $schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName();
    }
}
