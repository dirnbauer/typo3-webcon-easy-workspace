<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Service;

use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconWorkspaceChatops\Configuration\ChatOpsConfiguration;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceRecordSelection;

final readonly class WorkspaceRecordSelectionNormalizer
{
    public function __construct(
        private ChatOpsConfiguration $configuration,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @param mixed $rawSelections
     * @return list<WorkspaceRecordSelection>
     */
    public function normalize(mixed $rawSelections): array
    {
        if (!is_array($rawSelections)) {
            return [];
        }

        $allowedTables = array_flip($this->configuration->allowedTables());
        $selections = [];
        foreach ($rawSelections as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $selection = WorkspaceRecordSelection::fromArray($entry);
            if ($selection === null || !isset($allowedTables[$selection->table])) {
                continue;
            }
            if (!$this->tcaSchemaFactory->has($selection->table) || !$this->tcaSchemaFactory->get($selection->table)->isWorkspaceAware()) {
                continue;
            }
            $selections[$selection->table . ':' . $selection->workspaceUid] = $selection;
        }

        return array_values($selections);
    }
}
