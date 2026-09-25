<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceChangeCounter;

final class WorkspaceChangeCounterTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/news_stub',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/ChangeCounterScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        // Core lists what the signed-in editor may see.
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function countsTheRowsTheWorkspacesModuleListsByTable(): void
    {
        $count = $this->get(WorkspaceChangeCounter::class)->count(4);

        self::assertSame(4, $count->workspaceId);
        self::assertSame(3, $count->total);
        self::assertSame(['pages' => 1, 'tt_content' => 1, 'tx_news_domain_model_news' => 1], $count->byTable);
        // A new record is told apart; a change and a deletion are both changes.
        self::assertSame(['new' => 1, 'changed' => 2, 'deleted' => 0, 'moved' => 0], $count->byState);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $count->stamp);
    }

    #[Test]
    public function ignoresLegacyStatesSoftDeletedRowsAndForeignWorkspaces(): void
    {
        $counter = $this->get(WorkspaceChangeCounter::class);

        // Workspace 1 only holds the version of tt_content#1 (uid 23).
        self::assertSame(1, $counter->count(1)->total);
        self::assertSame(['tt_content' => 1], $counter->count(1)->byTable);
        // Live and unknown workspaces never report changes.
        self::assertSame(0, $counter->count(0)->total);
        self::assertSame(0, $counter->count(99)->total);
    }

    #[Test]
    public function stampIsStableUntilSomethingChanges(): void
    {
        $counter = $this->get(WorkspaceChangeCounter::class);

        self::assertSame($counter->count(4)->stamp, $counter->count(4)->stamp);
        self::assertNotSame($counter->count(4)->stamp, $counter->count(1)->stamp);
    }

    #[Test]
    public function stampChangesAfterADataHandlerEditInTheWorkspace(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(4);
        $counter = $this->get(WorkspaceChangeCounter::class);
        $before = $counter->count(4);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [10 => ['title' => 'Changed page, edited again']]], [], $backendUser);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);

        $after = $counter->count(4);
        self::assertSame(3, $after->total, 'Editing an already versioned page must not add a row');
        self::assertNotSame($before->stamp, $after->stamp);
    }

    #[Test]
    public function countGrowsWhenANewVersionIsCreated(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(4);
        $counter = $this->get(WorkspaceChangeCounter::class);
        $before = $counter->count(4);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [1 => ['header' => 'Edited in workspace four']]], [], $backendUser);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);

        $after = $counter->count(4);
        self::assertSame(4, $after->total);
        self::assertSame(2, $after->byTable['tt_content']);
        self::assertSame(3, $after->byState['changed'], 'the page, the news deletion and this edit');
        self::assertNotSame($before->stamp, $after->stamp);
    }
}
