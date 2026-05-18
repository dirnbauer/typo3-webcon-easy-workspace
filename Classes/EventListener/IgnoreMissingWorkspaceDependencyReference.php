<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Workspaces\Event\IsReferenceConsideredForDependencyEvent;
use TYPO3\CMS\Workspaces\EventListener\WorkspaceDependencyReferenceListener;

final readonly class IgnoreMissingWorkspaceDependencyReference
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    #[AsEventListener(
        identifier: 'webcon-easy-workspace/ignore-missing-workspace-dependency-reference',
        after: WorkspaceDependencyReferenceListener::class,
    )]
    public function __invoke(IsReferenceConsideredForDependencyEvent $event): void
    {
        if (!$event->isDependency()) {
            return;
        }

        if (
            !$this->recordExists($event->getTableName(), $event->getRecordId())
            || !$this->recordExists($event->getReferenceTable(), $event->getReferenceId())
        ) {
            $event->setDependency(false);
        }
    }

    private function recordExists(string $table, int $uid): bool
    {
        if ($table === '' || $uid <= 0 || !$this->tcaSchemaFactory->has($table)) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (bool)$queryBuilder
            ->count('uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }
}
