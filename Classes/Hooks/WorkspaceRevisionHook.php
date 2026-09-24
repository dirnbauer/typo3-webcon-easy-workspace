<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\WorkspaceVersionPresence;
use Webconsulting\WebconEasyWorkspace\Service\RecordSchemaInspector;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceRevision;

/**
 * Moves the workspace revision after every DataHandler run that wrote to a
 * workspace-aware table, so the badge stamp changes exactly when a count
 * can have changed (see WorkspaceRevision).
 *
 * - A run in a workspace moves that workspace's token.
 * - A run in Live, or one that publishes (version swap), moves the Live
 *   token, which every workspace's stamp includes.
 *
 * Registered for processDatamapClass and processCmdmapClass in
 * ext_localconf.php.
 */
final readonly class WorkspaceRevisionHook
{
    /**
     * Version commands that change Live.
     *
     * @var list<string>
     */
    private const array PUBLISHING_ACTIONS = ['swap', 'publish'];

    public function __construct(
        private WorkspaceRevision $revision,
        private RecordSchemaInspector $schema,
        private WorkspaceVersionPresence $presence,
    ) {}

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->afterWrite($dataHandler, $dataHandler->datamap, false);
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        $this->afterWrite($dataHandler, $dataHandler->cmdmap, $this->publishes($dataHandler->cmdmap));
    }

    /**
     * @param array<mixed> $map datamap or cmdmap, keyed by table
     */
    private function afterWrite(DataHandler $dataHandler, array $map, bool $publishes): void
    {
        if (!$this->touchesWorkspaceAwareTable($map)) {
            return;
        }
        $workspaceId = (int)($dataHandler->BE_USER->workspace ?? 0);
        if ($workspaceId > 0) {
            $this->revision->bump($workspaceId);
        }
        if ($workspaceId <= 0 || $publishes) {
            $this->revision->bump(0);
        }
        $this->presence->reset();
    }

    /**
     * @param array<mixed> $map
     */
    private function touchesWorkspaceAwareTable(array $map): bool
    {
        foreach ($map as $table => $records) {
            if (is_string($table) && is_array($records) && $records !== [] && $this->schema->isWorkspaceAware($table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $cmdmap
     */
    private function publishes(array $cmdmap): bool
    {
        foreach ($cmdmap as $records) {
            foreach (is_array($records) ? $records : [] as $commands) {
                $action = is_array($commands) && is_array($commands['version'] ?? null) ? ($commands['version']['action'] ?? null) : null;
                if (is_string($action) && in_array($action, self::PUBLISHING_ACTIONS, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
