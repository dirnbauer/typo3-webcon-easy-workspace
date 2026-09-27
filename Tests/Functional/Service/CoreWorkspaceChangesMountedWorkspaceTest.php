<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CoreWorkspaceChanges;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceChangeCounter;

/**
 * In a workspace with mount points the editor's mounts are the workspace's,
 * an administrator's as well. Core then selects the whole workspace below
 * the mounts only, as many levels deep as it is asked to; the toolbar asks
 * for every level, as the module's actions for the entire workspace do.
 * Asked for none, it found the drafts on the mount pages alone, and a page
 * below a mount with drafts read "0 changes on this page".
 */
final class CoreWorkspaceChangesMountedWorkspaceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/MountedWorkspaceScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function theDraftsOnEveryLevelBelowAMountAreListed(): void
    {
        $this->logIn(1, 1, [2]);

        self::assertSame(
            ['tt_content:11', 'tt_content:13', 'tt_content:14'],
            self::keys($this->get(CoreWorkspaceChanges::class)->rows(1)),
            'The mount page, one and two levels below it; not the page outside the mount.',
        );
        self::assertSame(3, $this->get(WorkspaceChangeCounter::class)->count(1)->total);
        self::assertSame(['tt_content:13'], $this->pageItems(3));
        self::assertSame(['tt_content:14'], $this->pageItems(4));
    }

    #[Test]
    public function aMountPointWithoutAPageIsLoggedAndTheOthersAreListed(): void
    {
        $writer = new class implements WriterInterface {
            /** @var list<LogRecord> */
            public array $records = [];

            #[\Override]
            public function writeLog(LogRecord $record): self
            {
                $this->records[] = $record;

                return $this;
            }
        };
        $logger = $this->get(LogManager::class)->getLogger(CoreWorkspaceChanges::class);
        self::assertInstanceOf(Logger::class, $logger);
        $logger->addWriter(LogLevel::WARNING, $writer);

        // Page 99 does not exist. Core fails on its tree ("Undefined array
        // key 99", an exception in the Development context).
        $this->logIn(2, 2, [2, 99]);

        self::assertSame(['tt_content:17'], self::keys($this->get(CoreWorkspaceChanges::class)->rows(2)));
        self::assertCount(1, $writer->records);
        self::assertSame(LogLevel::WARNING, $writer->records[0]->getLevel());
        self::assertSame(['workspace' => 2, 'pages' => '99'], $writer->records[0]->getData());
    }

    /**
     * @param list<int> $mounts
     */
    private function logIn(int $userUid, int $workspaceId, array $mounts): void
    {
        // The login sets up the workspace of the user record, mounts included.
        $backendUser = $this->setUpBackendUser($userUid);
        self::assertSame($workspaceId, $backendUser->workspace);
        self::assertSame($mounts, $backendUser->getWebmounts());
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($workspaceId));
    }

    /**
     * @return list<string>
     */
    private function pageItems(int $pageUid): array
    {
        return self::keys($this->get(PendingItemsService::class)->payloadForPage($pageUid, PendingItemsMode::Changed)->items);
    }

    /**
     * @param list<WorkspaceChange|PendingItem> $rows
     * @return list<string>
     */
    private static function keys(array $rows): array
    {
        $keys = array_map(static fn(WorkspaceChange|PendingItem $row): string => $row->table . ':' . $row->workspaceUid, $rows);
        sort($keys);

        return $keys;
    }
}
