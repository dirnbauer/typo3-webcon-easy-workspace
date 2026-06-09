<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Hook;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceChangeStampService;

final readonly class WorkspaceChangeInvalidationHook
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private WorkspaceChangeStampService $stampService,
    ) {}

    /**
     * DataHandler still exposes hooks for generic record writes in TYPO3 v14.
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($this->isWorkspaceRelevant($table, $dataHandler)) {
            $this->stampService->bump((int)$dataHandler->BE_USER->workspace, $dataHandler->BE_USER);
        }
    }

    /**
     * @param array<string, mixed>|null $pasteUpdate
     * @param array<string, mixed>|null $pasteDatamap
     */
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        int $uid,
        mixed $value,
        DataHandler $dataHandler,
        ?array $pasteUpdate = null,
        ?array $pasteDatamap = null,
    ): void {
        if ($this->isWorkspaceRelevant($table, $dataHandler)) {
            $this->stampService->bump((int)$dataHandler->BE_USER->workspace, $dataHandler->BE_USER);
        }
    }

    private function isWorkspaceRelevant(string $table, DataHandler $dataHandler): bool
    {
        return ($dataHandler->BE_USER->workspace ?? 0) > 0
            && $this->tcaSchemaFactory->has($table)
            && $this->tcaSchemaFactory->get($table)->isWorkspaceAware();
    }
}
