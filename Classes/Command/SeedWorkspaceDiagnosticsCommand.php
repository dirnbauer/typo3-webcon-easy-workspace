<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

#[AsCommand(
    name: 'webcon-easy-workspace:seed-diagnostics',
    description: 'Seed deliberately broken workspace records for the Easy Workspace diagnostics module.',
)]
final class SeedWorkspaceDiagnosticsCommand extends Command
{
    private const MARKER = '[WEW diagnostics seed]';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually write the seed records. Without this flag the command is a dry run.')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page uid used as pid for seeded rows.', '1')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace uid used for seeded rows.', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool)$input->getOption('execute');
        $pageUid = max(1, Value::int($input->getOption('page')));
        $workspaceId = max(1, Value::int($input->getOption('workspace')));

        $planned = [
            'orphan workspace version',
            'duplicate workspace versions for one live row',
            'modified workspace row without live identity',
            'unsupported t3ver_state',
            'live row polluted with version fields',
            'file reference with missing owner record',
        ];
        if (TcaUtility::table('article_grid_items') !== []) {
            $planned[] = 'inline child row with missing parent';
        }

        if (!$execute) {
            $io->title('Easy Workspace diagnostics seed dry run');
            $io->listing($planned);
            $io->note('Run again with --execute to write these deliberately broken records.');
            return Command::SUCCESS;
        }

        $this->deletePreviousSeeds();
        $this->seedContentRows($pageUid, $workspaceId);
        $this->seedFileReferenceRow($pageUid, $workspaceId);
        if (TcaUtility::table('article_grid_items') !== []) {
            $this->seedArticleGridChildRow($pageUid, $workspaceId);
        }

        $io->success('Seeded workspace diagnostic problems. Open Easy Workspace > Diagnostics or run the diagnostic scanner before repairing/discarding them.');
        return Command::SUCCESS;
    }

    private function deletePreviousSeeds(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->executeStatement(
            'DELETE FROM tt_content WHERE header LIKE :marker',
            ['marker' => self::MARKER . '%'],
            ['marker' => Connection::PARAM_STR],
        );
        if (TcaUtility::table('article_grid_items') !== []) {
            $childConnection = $this->connectionPool->getConnectionForTable('article_grid_items');
            $childConnection->executeStatement(
                'DELETE FROM article_grid_items WHERE title LIKE :marker',
                ['marker' => self::MARKER . '%'],
                ['marker' => Connection::PARAM_STR],
            );
        }
        $referenceConnection = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $referenceConnection->executeStatement(
            'DELETE FROM sys_file_reference WHERE title LIKE :marker',
            ['marker' => self::MARKER . '%'],
            ['marker' => Connection::PARAM_STR],
        );
    }

    private function seedContentRows(int $pageUid, int $workspaceId): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $now = time();

        $connection->insert('tt_content', $this->contentRow($pageUid, self::MARKER . ' orphan workspace version', [
            't3ver_oid' => 999999999,
            't3ver_wsid' => $workspaceId,
        ]));

        $connection->insert('tt_content', $this->contentRow($pageUid, self::MARKER . ' duplicate live'));
        $duplicateLiveUid = (int)$connection->lastInsertId();
        $connection->insert('tt_content', $this->contentRow($pageUid, self::MARKER . ' duplicate workspace A', [
            't3ver_oid' => $duplicateLiveUid,
            't3ver_wsid' => $workspaceId,
            'tstamp' => $now - 60,
        ]));
        $connection->insert('tt_content', $this->contentRow($pageUid, self::MARKER . ' duplicate workspace B', [
            't3ver_oid' => $duplicateLiveUid,
            't3ver_wsid' => $workspaceId,
            'tstamp' => $now,
        ]));

        $connection->insert('tt_content', $this->contentRow($pageUid, self::MARKER . ' no live identity', [
            't3ver_oid' => 0,
            't3ver_wsid' => $workspaceId,
            't3ver_state' => 0,
        ]));

        $connection->insert('tt_content', $this->contentRow($pageUid, self::MARKER . ' unsupported state', [
            't3ver_oid' => $duplicateLiveUid,
            't3ver_wsid' => $workspaceId,
            't3ver_state' => 99,
        ]));

        $connection->insert('tt_content', $this->contentRow($pageUid, self::MARKER . ' polluted live row', [
            't3ver_oid' => $duplicateLiveUid,
            't3ver_wsid' => 0,
            't3ver_state' => 4,
        ]));
    }

    /**
     * @param array<string, int|string> $overrides
     * @return array<string, int|string>
     */
    private function contentRow(int $pageUid, string $header, array $overrides = []): array
    {
        $now = time();
        return array_replace([
            'pid' => $pageUid,
            'CType' => 'text',
            'header' => $header,
            'bodytext' => '<p>' . $header . '</p>',
            'colPos' => 0,
            'sorting' => 999999,
            'deleted' => 0,
            'hidden' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            't3ver_oid' => 0,
            't3ver_wsid' => 0,
            't3ver_state' => 0,
            't3ver_stage' => 0,
        ], $overrides);
    }

    private function seedArticleGridChildRow(int $pageUid, int $workspaceId): void
    {
        $connection = $this->connectionPool->getConnectionForTable('article_grid_items');
        $now = time();
        $connection->insert('article_grid_items', [
            'pid' => $pageUid,
            'title' => self::MARKER . ' missing parent child',
            'category' => 'Diagnostics',
            'description' => 'Child row points to a missing tt_content parent.',
            'link' => '',
            'foreign_table_parent_uid' => 999999999,
            'deleted' => 0,
            'hidden' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            't3ver_oid' => 0,
            't3ver_wsid' => $workspaceId,
            't3ver_state' => 1,
            't3ver_stage' => 0,
        ]);
    }

    private function seedFileReferenceRow(int $pageUid, int $workspaceId): void
    {
        $fileUid = $this->firstFileUid();
        if ($fileUid <= 0) {
            return;
        }

        $now = time();
        $connection = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', [
            'pid' => $pageUid,
            'uid_local' => $fileUid,
            'uid_foreign' => 999999999,
            'tablenames' => 'pages',
            'fieldname' => 'media',
            'title' => self::MARKER . ' missing owner file reference',
            'sorting_foreign' => 999999,
            'deleted' => 0,
            'hidden' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            't3ver_oid' => 0,
            't3ver_wsid' => $workspaceId,
            't3ver_state' => 1,
            't3ver_stage' => 0,
        ]);
    }

    private function firstFileUid(): int
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_file');
        return Value::int($connection->createQueryBuilder()
            ->select('uid')
            ->from('sys_file')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne());
    }
}
