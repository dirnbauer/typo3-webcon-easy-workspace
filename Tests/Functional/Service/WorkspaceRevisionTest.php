<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChangeCount;
use Webconsulting\WebconEasyWorkspace\Service\ContextChangeSummary;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceChangeCounter;

/**
 * The badge stamp moves with every DataHandler write that can change a
 * count — including writes the row fingerprint of pages/tt_content/news
 * cannot see — and the cached page summary lives exactly as long as it.
 */
final class WorkspaceRevisionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/inline_stub',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/PageInlineScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    private function enterWorkspace(int $workspaceId): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace($workspaceId);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($workspaceId));
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $datamap
     */
    private function write(array $datamap): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, implode(' / ', $dataHandler->errorLog));
    }

    private function changeCount(int $workspaceId): WorkspaceChangeCount
    {
        return $this->get(WorkspaceChangeCounter::class)->count($workspaceId);
    }

    #[Test]
    public function editingACollectionItemMovesTheStamp(): void
    {
        $this->enterWorkspace(1);
        $before = $this->changeCount(1);

        // Item A already has a draft (31): the edit changes neither the number
        // of pending pages/tt_content/news rows nor their newest tstamp.
        $this->write(['tx_easyws_item' => [30 => ['header' => 'Item A (second draft)']]]);
        $after = $this->changeCount(1);

        self::assertSame($before->total, $after->total);
        self::assertSame($before->latestChangeAt, $after->latestChangeAt);
        self::assertSame(
            WorkspaceChangeCount::stamp(1, $before->total, $before->latestChangeAt),
            WorkspaceChangeCount::stamp(1, $after->total, $after->latestChangeAt),
            'the row fingerprint alone does not see the edit',
        );
        self::assertNotSame($before->stamp, $after->stamp);
    }

    #[Test]
    public function aLiveEditMovesTheStampOfEveryWorkspace(): void
    {
        $this->enterWorkspace(0);
        $before = [1 => $this->changeCount(1)->stamp, 2 => $this->changeCount(2)->stamp];

        $this->write(['tt_content' => [10 => ['header' => 'Unchanged text, edited live']]]);

        self::assertNotSame($before[1], $this->changeCount(1)->stamp);
        self::assertNotSame($before[2], $this->changeCount(2)->stamp);
    }

    #[Test]
    public function anEditInOneWorkspaceLeavesTheOtherAlone(): void
    {
        $this->enterWorkspace(1);
        $otherBefore = $this->changeCount(2)->stamp;

        $this->write(['tt_content' => [10 => ['header' => 'Unchanged text (draft)']]]);

        self::assertSame($otherBefore, $this->changeCount(2)->stamp);
    }

    #[Test]
    public function writesToTablesOutsideWorkspacesDoNotMoveTheStamp(): void
    {
        $this->enterWorkspace(0);
        $before = $this->changeCount(1)->stamp;

        $this->write(['be_users' => [1 => ['realName' => 'Administrator']]]);

        self::assertSame($before, $this->changeCount(1)->stamp);
    }

    #[Test]
    public function thePageSummaryIsCachedUntilTheStampMoves(): void
    {
        $this->enterWorkspace(1);
        $summary = $this->get(ContextChangeSummary::class);
        $liveUids = static fn(?array $result): array => array_column($result['records'] ?? [], 'liveUid');
        $stamp = $this->changeCount(1)->stamp;

        $first = $summary->forContext(1, $stamp, 2, 0, []);
        self::assertSame(12, $first['count'] ?? null);
        self::assertContains(18, $liveUids($first));

        // A write that bypasses DataHandler — here the draft of collection
        // item A, which made element 18 pending — moves nothing the stamp is
        // made of, so the cached summary stands …
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_easyws_item')->update('tx_easyws_item', ['deleted' => 1], ['uid' => 31]);
        self::assertSame($stamp, $this->changeCount(1)->stamp);
        self::assertSame($first, $summary->forContext(1, $stamp, 2, 0, []));

        // … until the next DataHandler write in the workspace moves it.
        $this->write(['tt_content' => [13 => ['header' => 'Touched, now for real']]]);
        $newStamp = $this->changeCount(1)->stamp;
        self::assertNotSame($stamp, $newStamp);
        $second = $summary->forContext(1, $newStamp, 2, 0, []);
        self::assertSame(12, $second['count'] ?? null, 'element 18 dropped out, element 13 came in');
        self::assertNotContains(18, $liveUids($second));
        self::assertContains(13, $liveUids($second));
    }
}
