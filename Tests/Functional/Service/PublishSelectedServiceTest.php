<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final class PublishSelectedServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = ['webconsulting/webcon-easy-workspace'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/PublishScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    private function backendUserInWorkspace(int $userUid, int $workspaceId): BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($userUid);
        $backendUser->setWorkspace($workspaceId);

        return $backendUser;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchContentRow(int $uid): array|false
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'header', 'deleted', 't3ver_wsid', 't3ver_oid', 't3ver_stage')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative();
    }

    #[Test]
    public function discardRejectsAnUninstalledOptionalTable(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $result = $this->get(PublishSelectedService::class)->discard('tx_news_domain_model_news', 1, $backendUser);

        self::assertFalse($result['success']);
        self::assertSame(0, $result['discarded']);
    }

    #[Test]
    public function requestReviewMovesSelectedWorkspaceVersionToApprovalStage(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(PublishSelectedService::class);

        $result = $subject->requestReview([['table' => 'tt_content', 'workspaceUid' => 2]], $backendUser);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['changed']);
        $versionRow = $this->fetchContentRow(2);
        self::assertIsArray($versionRow);
        self::assertSame(1, Value::int($versionRow['t3ver_stage'] ?? null));
    }

    #[Test]
    public function approveAndPublishSelectedWorkspaceVersionToLive(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(PublishSelectedService::class);

        $result = $subject->approveAndPublish([['table' => 'tt_content', 'workspaceUid' => 2]], $backendUser);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['published']);
        $liveRow = $this->fetchContentRow(1);
        self::assertIsArray($liveRow);
        self::assertSame('Workspace header', $liveRow['header']);
    }

    #[Test]
    public function publishesSelectedWorkspaceVersionToLive(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(PublishSelectedService::class);

        $result = $subject->publish([['table' => 'tt_content', 'workspaceUid' => 2]], $backendUser);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['published']);
        $liveRow = $this->fetchContentRow(1);
        self::assertIsArray($liveRow);
        self::assertSame('Workspace header', $liveRow['header']);
    }

    #[Test]
    public function rejectsSelectionFromForeignWorkspace(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(PublishSelectedService::class);

        // uid 5 belongs to workspace 2 while workspace 1 is active.
        $result = $subject->publish([['table' => 'tt_content', 'workspaceUid' => 5]], $backendUser);

        self::assertFalse($result['success']);
        self::assertSame(0, $result['published']);
        self::assertNotSame([], $result['errors']);
        $otherLiveRow = $this->fetchContentRow(4);
        self::assertIsArray($otherLiveRow);
        self::assertSame('Other live', $otherLiveRow['header']);
    }

    #[Test]
    public function deniesPublishWithoutTableModifyPermission(): void
    {
        // Editor (uid 2) is a workspace member but has no
        // tables_modify permission for tt_content.
        $backendUser = $this->backendUserInWorkspace(2, 1);
        $subject = $this->get(PublishSelectedService::class);

        $result = $subject->publish([['table' => 'tt_content', 'workspaceUid' => 2]], $backendUser);

        self::assertFalse($result['success']);
        self::assertSame(0, $result['published']);
        self::assertNotSame([], $result['errors']);
        $liveRow = $this->fetchContentRow(1);
        self::assertIsArray($liveRow);
        self::assertSame('Live header', $liveRow['header']);
    }

    #[Test]
    public function refusesToPublishFromLiveWorkspace(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $subject = $this->get(PublishSelectedService::class);

        $result = $subject->publish([['table' => 'tt_content', 'workspaceUid' => 2]], $backendUser);

        self::assertFalse($result['success']);
        self::assertSame(0, $result['published']);
        self::assertNotSame([], $result['errors']);
    }

    #[Test]
    public function discardRemovesWorkspaceVersion(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(PublishSelectedService::class);

        $result = $subject->discard('tt_content', 2, $backendUser);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['discarded']);
        $versionRow = $this->fetchContentRow(2);
        self::assertTrue(
            $versionRow === false
            || Value::int($versionRow['deleted'] ?? null) === 1
            || Value::int($versionRow['t3ver_wsid'] ?? null) !== 1,
            'Workspace version row must be gone after discard',
        );
        $liveRow = $this->fetchContentRow(1);
        self::assertIsArray($liveRow);
        self::assertSame('Live header', $liveRow['header']);
    }

    #[Test]
    public function canEditWorkspaceContentWithoutABrowserSession(): void
    {
        $admin = $this->backendUserInWorkspace(1, 1);
        $user = GeneralUtility::makeInstance(CommandLineUserAuthentication::class);
        $user->user = $admin->user;
        $user->fetchGroupData();
        $user->setTemporaryWorkspace(1);
        $GLOBALS['BE_USER'] = $user;

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [1 => ['header' => 'CLI workspace edit']]], [], $user);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);
        $draft = $this->fetchContentRow(2);
        self::assertIsArray($draft);
        self::assertSame('CLI workspace edit', $draft['header']);
        $live = $this->fetchContentRow(1);
        self::assertIsArray($live);
        self::assertSame('Live header', $live['header']);
    }

    #[Test]
    public function discardRejectsForeignWorkspaceEvenForAdministrators(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $result = $this->get(PublishSelectedService::class)->discard('tt_content', 5, $backendUser);

        self::assertFalse($result['success']);
        self::assertSame(0, $result['discarded']);
        self::assertIsArray($this->fetchContentRow(5));
    }

    #[Test]
    public function discardFromLiveCannotRemoveWorkspaceChanges(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $result = $this->get(PublishSelectedService::class)->discard('tt_content', 2, $backendUser);

        self::assertFalse($result['success']);
        self::assertSame(0, $result['discarded']);
        self::assertIsArray($this->fetchContentRow(2));
    }

    #[Test]
    public function discardAcceptsLiveUidInActiveWorkspace(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $result = $this->get(PublishSelectedService::class)->discard('tt_content', 1, $backendUser);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['discarded']);
        self::assertFalse($this->fetchContentRow(2));
        $liveRow = $this->fetchContentRow(1);
        self::assertIsArray($liveRow);
        self::assertSame('Live header', $liveRow['header']);
    }

    #[Test]
    public function discardOfLiveUidCannotFallBackToAnotherWorkspace(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $result = $this->get(PublishSelectedService::class)->discard('tt_content', 4, $backendUser);

        self::assertTrue($result['success']);
        self::assertSame(0, $result['discarded']);
        self::assertIsArray($this->fetchContentRow(5));
    }

    #[Test]
    public function discardDeniesMissingTableModifyPermission(): void
    {
        $backendUser = $this->backendUserInWorkspace(2, 1);
        $result = $this->get(PublishSelectedService::class)->discard('tt_content', 2, $backendUser);

        self::assertFalse($result['success']);
        self::assertSame(0, $result['discarded']);
        self::assertIsArray($this->fetchContentRow(2));
    }

    #[Test]
    public function discardOfAlreadyLiveRecordIsIdempotent(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(PublishSelectedService::class);

        // uid 6 is a live record without any workspace version.
        $result = $subject->discard('tt_content', 6, $backendUser);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(0, $result['discarded']);
    }
}
