<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CoreWorkspaceChanges;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;
use Webconsulting\WebconEasyWorkspace\Utility\PublishSelectionNormalizer;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Publishing, staging and discarding a change take its translation copies
 * along — added on the server, whoever calls, and checked again on fresh
 * rows so a translation someone edited is never published or discarded
 * along. Unchanged copies are cleaned up only while they still are.
 */
final class PublishSelectedServiceCompanionTest extends FunctionalTestCase
{
    use TranslationCompanionScenario;

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/inline_stub',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCompanionScenario();
    }

    private function user(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }

    private function subject(): PublishSelectedService
    {
        return $this->get(PublishSelectedService::class);
    }

    /**
     * @return list<WorkspaceChange>
     */
    private function copyEntries(): array
    {
        return array_values(array_filter(
            $this->get(CoreWorkspaceChanges::class)->entries(1, 2),
            static fn(WorkspaceChange $entry): bool => $entry->role === ChangeRole::Copy,
        ));
    }

    #[Test]
    public function publishingTheChangeTakesItsTranslationCopiesLive(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $hero = $this->versionOf('tt_content', 100);

        // What the dropdown sends: the row and the records nested below it.
        $result = $this->subject()->publish(
            [['table' => 'tt_content', 'workspaceUid' => $hero], ['table' => 'sys_file_reference', 'workspaceUid' => $this->versionOf('sys_file_reference', 200)]],
            $this->user(),
        );

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        // The editor's selection, not what was taken along.
        self::assertSame(2, $result['published']);
        self::assertSame(3, $result['companions']);
        self::assertSame([], $result['skippedCompanions']);
        self::assertSame([], $result['warnings']);
        self::assertSame([], $this->workspaceRows(), 'nothing is left behind');
        self::assertSame('Hero (edited)', $this->liveRow('tt_content', 100)['header']);
        self::assertSame('Held', $this->liveRow('tt_content', 101)['header']);
    }

    #[Test]
    public function theModulesCheckboxPublishesTheCopiesToo(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['starttime' => 1767225600]]]);
        $selections = $this->get(PublishSelectionNormalizer::class)->fromModuleForm(['tt_content:' . $this->versionOf('tt_content', 100)]);

        $result = $this->subject()->publish($selections, $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['published']);
        self::assertSame(3, $result['companions']);
        self::assertSame([], $this->workspaceRows());
        // The synchronized value reaches the translations with their change.
        foreach ([100, 101, 102, 103] as $uid) {
            self::assertSame(1767225600, Value::int($this->liveRow('tt_content', $uid)['starttime']));
        }
    }

    #[Test]
    public function aTranslationEditedByHandIsNeitherPublishedNorDiscardedAlong(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $this->editInWorkspace(['tt_content' => [101 => ['header' => 'Held (bearbeitet)']]]);
        $german = $this->versionOf('tt_content', 101);

        $result = $this->subject()->discard('tt_content', $this->versionOf('tt_content', 100), $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['discarded']);
        self::assertSame(2, $result['companionsDiscarded'], 'the Chinese and Hungarian copies');
        self::assertSame(['tt_content:' . $german, 'sys_file_reference:' . $this->versionOf('sys_file_reference', 201)], $this->workspaceRows());
    }

    #[Test]
    public function discardingTheChangeDiscardsItsCopiesOnTheServer(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        // The Visual Editor's decline button sends the live uid.
        $result = $this->subject()->discard('tt_content', 100, $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['discarded']);
        self::assertSame(3, $result['companionsDiscarded']);
        self::assertSame([], $result['warnings']);
        self::assertSame([], $this->workspaceRows());
        self::assertSame('Hero', $this->liveRow('tt_content', 100)['header']);
    }

    #[Test]
    public function aCopyEditedSinceTheListWasReadIsNotDiscardedAlong(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        // The list is read and cached for this revision …
        self::assertCount(3, $this->get(CoreWorkspaceChanges::class)->entries(1)[0]->companions);
        // … then the Hungarian copy changes behind DataHandler's back.
        $hungarian = $this->versionOf('tt_content', 103);
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['header' => 'Hős (kézzel)'], ['uid' => $hungarian]);

        $result = $this->subject()->discard('tt_content', $this->versionOf('tt_content', 100), $this->user());

        self::assertSame(2, $result['companionsDiscarded']);
        self::assertGreaterThan(0, $this->versionOf('tt_content', 103), 'the edited copy stays');
    }

    /**
     * The Hungarian element translated from the German one; after an
     * English header edit, both translations are edited behind
     * DataHandler's back (the revision does not move) with the same text.
     */
    private function editTheGermanSourceOfTheHungarianTranslationMeanwhile(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['l10n_source' => 111], ['uid' => 113]);
        $this->editInWorkspace(['tt_content' => [110 => ['header' => 'Second (edited)']]]);
        $entries = $this->get(CoreWorkspaceChanges::class)->entries(1);
        self::assertCount(1, $entries);
        self::assertCount(3, $entries[0]->companions);
        foreach ([111, 113] as $liveUid) {
            $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')
                ->update('tt_content', ['bodytext' => 'Unreviewed German text'], ['uid' => $this->versionOf('tt_content', $liveUid)]);
        }
    }

    #[Test]
    public function aTranslationOfAnEditedCompanionIsNotPublishedAlong(): void
    {
        $this->editTheGermanSourceOfTheHungarianTranslationMeanwhile();
        $german = $this->versionOf('tt_content', 111);
        $hungarian = $this->versionOf('tt_content', 113);

        $result = $this->subject()->publish([['table' => 'tt_content', 'workspaceUid' => $this->versionOf('tt_content', 110)]], $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['companions'], 'the Chinese copy');
        self::assertSame([$german, $hungarian], array_column($result['skippedCompanions'], 'workspaceUid'));
        self::assertSame('', (string)($this->liveRow('tt_content', 113)['bodytext'] ?? ''));
        self::assertSame($german, $this->versionOf('tt_content', 111));
        self::assertSame($hungarian, $this->versionOf('tt_content', 113));
    }

    #[Test]
    public function aTranslationOfAnEditedCompanionIsNotDiscardedAlong(): void
    {
        $this->editTheGermanSourceOfTheHungarianTranslationMeanwhile();
        $hungarian = $this->versionOf('tt_content', 113);

        $result = $this->subject()->discard('tt_content', $this->versionOf('tt_content', 110), $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['companionsDiscarded'], 'the Chinese copy');
        self::assertSame($hungarian, $this->versionOf('tt_content', 113));
    }

    #[Test]
    public function discardingAMoveTakesATranslationEditedAfterTheMoveAlongAsCoreDoes(): void
    {
        // An editor moves the English element; core moves its translations
        // along and points them at the English version. Then a translator
        // edits the German one.
        $this->editInWorkspace([], ['tt_content' => [110 => ['move' => -120]]]);
        $moved = $this->versionOf('tt_content', 110);
        $german = $this->versionOf('tt_content', 111);
        self::assertSame($moved, Value::int($this->liveRow('tt_content', $german)['l18n_parent']), 'core points the moved translation at the version');
        $this->editInWorkspace(['tt_content' => [111 => ['header' => 'Zweites (Übersetzer)']]]);
        // The list names the edit on the move before anyone clicks.
        self::assertSame(ChangeRole::Change, $this->entry('tt_content', 111)?->role);
        self::assertSame(
            [['table' => 'tt_content', 'workspaceUid' => $german, 'languageUid' => 1, 'edited' => true]],
            $this->entry('tt_content', 110)?->dependents,
        );

        $result = $this->subject()->discard('tt_content', $moved, $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(2, $result['companionsDiscarded'], 'the Chinese and Hungarian copies, discarded by core along with the move');
        self::assertSame(1, $result['dependentsDiscarded'], 'the German edit');
        // Core's discard, untouched: the translation pointing at the version
        // goes with it, edit included — as in the Workspaces module.
        self::assertSame([], $this->workspaceRows());
        self::assertSame('Zweites', $this->liveRow('tt_content', 111)['header']);
        self::assertSame(110, Value::int($this->liveRow('tt_content', 111)['l18n_parent']), 'the live translation is untouched');
    }

    #[Test]
    public function discardingADeletionTakesATranslationEditedAfterItAlongAsCoreDoes(): void
    {
        $this->editInWorkspace([], ['tt_content' => [110 => ['delete' => 1]]]);
        $deleted = $this->versionOf('tt_content', 110);
        // Edited behind DataHandler's back: core lets no one edit a
        // translation it is deleting, but an earlier edit has the same shape.
        $german = $this->versionOf('tt_content', 111);
        self::assertSame($deleted, Value::int($this->liveRow('tt_content', $german)['l18n_parent']), 'core points the deleted translation at the version');
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['header' => 'Zweites (Übersetzer)'], ['uid' => $german]);
        self::assertSame(
            [['table' => 'tt_content', 'workspaceUid' => $german, 'languageUid' => 1, 'edited' => true]],
            $this->entry('tt_content', 110)?->dependents,
        );

        $result = $this->subject()->discard('tt_content', $deleted, $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(2, $result['companionsDiscarded']);
        self::assertSame(1, $result['dependentsDiscarded']);
        self::assertSame([], $this->workspaceRows());
    }

    #[Test]
    public function discardingAMovedCollectionTakesATranslatedItemEditedAfterTheMoveAlongAsCoreDoes(): void
    {
        // The collection's items are versioned with the move; their
        // translations point at the items' versions.
        $this->editInWorkspace([], ['tt_content' => [130 => ['move' => -100]]]);
        $englishItem = $this->versionOf('tx_easyws_item', 310);
        $germanItem = $this->versionOf('tx_easyws_item', 311);
        self::assertSame($englishItem, Value::int($this->liveRow('tx_easyws_item', $germanItem)['l10n_parent']));
        $this->editInWorkspace(['tx_easyws_item' => [311 => ['header' => 'Zweiter Eintrag (Übersetzer)']]]);
        // The German collection holds the edit: a change of its own, named
        // on the move.
        $germanCollection = $this->versionOf('tt_content', 131);
        self::assertSame(ChangeRole::Change, $this->entry('tt_content', 131)?->role);
        self::assertSame(
            [['table' => 'tt_content', 'workspaceUid' => $germanCollection, 'languageUid' => 1, 'edited' => true]],
            $this->entry('tt_content', 130)?->dependents,
        );

        $result = $this->subject()->discard('tt_content', $this->versionOf('tt_content', 130), $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['dependentsDiscarded']);
        self::assertSame([], $this->workspaceRows(), 'core takes the German collection and its edited item along');
        self::assertSame('Zweiter Eintrag', $this->liveRow('tx_easyws_item', 311)['header']);
    }

    #[Test]
    public function aTranslationEditedBeforeTheMoveIsNoDependentAndStays(): void
    {
        // The reverse order: the translation's version exists before the
        // move, its pointer stays at the live element, and core's discard of
        // the move leaves it alone.
        $this->editInWorkspace(['tt_content' => [111 => ['header' => 'Zweites (Übersetzer)']]]);
        $german = $this->versionOf('tt_content', 111);
        $this->editInWorkspace([], ['tt_content' => [110 => ['move' => -120]]]);
        self::assertSame(110, Value::int($this->liveRow('tt_content', $german)['l18n_parent']));
        self::assertSame([], $this->entry('tt_content', 110)?->dependents);

        $result = $this->subject()->discard('tt_content', $this->versionOf('tt_content', 110), $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(0, $result['dependentsDiscarded']);
        self::assertSame($german, $this->versionOf('tt_content', 111));
    }

    #[Test]
    public function discardingANewElementStillTakesItsNewTranslations(): void
    {
        $this->editInWorkspace(['tt_content' => ['NEW1' => ['pid' => 2, 'CType' => 'text', 'header' => 'Brand new', 'colPos' => 0]]]);
        $new = 0;
        foreach ($this->workspaceRows() as $row) {
            $new = (int)explode(':', $row)[1];
        }
        $this->editInWorkspace([], ['tt_content' => [$new => ['localize' => 1]]]);
        self::assertCount(2, $this->workspaceRows());

        self::assertSame(
            [['table' => 'tt_content', 'workspaceUid' => $new + 1, 'languageUid' => 1, 'edited' => false]],
            $this->entry('tt_content', $new)?->dependents,
            'a new translation, not an edit',
        );

        $result = $this->subject()->discard('tt_content', $new, $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['dependentsDiscarded']);
        self::assertSame([], $this->workspaceRows());
    }

    private function entry(string $table, int $liveUid): ?WorkspaceChange
    {
        foreach ($this->get(CoreWorkspaceChanges::class)->entries(1) as $entry) {
            if ($entry->table === $table && $entry->liveUid === $liveUid) {
                return $entry;
            }
        }

        return null;
    }

    #[Test]
    public function discardingACopyAloneDiscardsOnlyThatCopy(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $result = $this->subject()->discard('tt_content', $this->versionOf('tt_content', 102), $this->user());

        self::assertTrue($result['success']);
        self::assertSame(0, $result['companionsDiscarded']);
        self::assertSame(0, $this->versionOf('tt_content', 102));
        self::assertGreaterThan(0, $this->versionOf('tt_content', 100));
        self::assertGreaterThan(0, $this->versionOf('tt_content', 101));
    }

    #[Test]
    public function requestingAReviewMovesTheCopiesToTheChangesStage(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $result = $this->subject()->requestReview([['table' => 'tt_content', 'workspaceUid' => $this->versionOf('tt_content', 100)]], $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['changed']);
        foreach ([100, 101, 102, 103] as $liveUid) {
            self::assertSame(1, Value::int($this->liveRow('tt_content', $this->versionOf('tt_content', $liveUid))['t3ver_stage']), 'stage of ' . $liveUid);
        }
    }

    #[Test]
    public function inAPublishStageOnlyWorkspaceTheCopiesFollowTheChangeThroughApproval(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_workspace')->update('sys_workspace', ['publish_access' => 1], ['uid' => 1]);
        $user = $this->setUpBackendUser(1);
        $user->setWorkspace(1);
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $result = $this->subject()->approveAndPublish([['table' => 'tt_content', 'workspaceUid' => $this->versionOf('tt_content', 100)]], $user);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame([], $result['warnings']);
        self::assertSame([], $this->workspaceRows());
    }

    #[Test]
    public function approvingAndPublishingWorksOutTheCompanionsOnceBeforeAnythingIsWritten(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_workspace')->update('sys_workspace', ['publish_access' => 1], ['uid' => 1]);
        $user = $this->setUpBackendUser(1);
        $user->setWorkspace(1);
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        // What the dropdown read; the whole workspace's scan is cached for
        // this revision.
        $this->get(CoreWorkspaceChanges::class)->entries(1, 2);
        $cache = $this->get(CacheManager::class)->getCache('webcon_easy_workspace');
        $rowsIdentifier = sha1(implode('|', ['rows', new \ReflectionClassConstant(CoreWorkspaceChanges::class, 'CACHE_VERSION')->getValue(), 1, 1]));
        $scannedAt = Value::stringKeyArray($cache->get($rowsIdentifier))['revision'] ?? null;
        self::assertIsString($scannedAt);

        $result = $this->subject()->approveAndPublish([['table' => 'tt_content', 'workspaceUid' => $this->versionOf('tt_content', 100)]], $user);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(3, $result['companions']);
        self::assertSame([], $this->workspaceRows());
        // The stage change moved the revision; no second scan followed it.
        self::assertSame($scannedAt, Value::stringKeyArray($cache->get($rowsIdentifier))['revision'] ?? null);
    }

    #[Test]
    public function aCopyCoreRefusesToPublishIsAWarningNotAFailure(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_workspace')->update('sys_workspace', ['publish_access' => 1], ['uid' => 1]);
        $user = $this->setUpBackendUser(1);
        $user->setWorkspace(1);
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $hero = $this->versionOf('tt_content', 100);
        // Only the change was moved to the publish stage (in the core
        // module, say); its copies are still in editing.
        $this->editInWorkspace([], ['tt_content' => [$hero => ['version' => ['action' => 'setStage', 'stageId' => -10, 'comment' => '']]]]);

        $result = $this->subject()->publish([['table' => 'tt_content', 'workspaceUid' => $hero]], $user);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(1, $result['published']);
        self::assertNotSame([], $result['warnings']);
        self::assertSame('Hero (edited)', $this->liveRow('tt_content', 100)['header']);
        self::assertGreaterThan(0, $this->versionOf('tt_content', 101), 'core kept the copy');
    }

    #[Test]
    public function unchangedCopiesAreDiscardedAndNothingGoesLive(): void
    {
        // A save without changes: one copy, with the copies of its translations.
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero']]]);
        $copies = $this->copyEntries();
        self::assertCount(1, $copies);

        $result = $this->subject()->discardCopies($copies, $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(4, $result['discarded']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $this->workspaceRows());
        self::assertSame('Hero', $this->liveRow('tt_content', 100)['header']);
        self::assertSame('Held', $this->liveRow('tt_content', 101)['header']);
    }

    #[Test]
    public function aCopyGroupWithAnyNodeEditedMeanwhileIsKept(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero']]]);
        $copies = $this->copyEntries();
        // The German copy's image caption, edited behind DataHandler's back.
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['title' => 'Bildtitel'], ['uid' => $this->versionOf('sys_file_reference', 201)]);

        $result = $this->subject()->discardCopies($copies, $this->user());

        self::assertSame(0, $result['discarded']);
        self::assertSame(4, $result['skipped']);
        self::assertCount(8, $this->workspaceRows());
    }

    #[Test]
    public function aCopyWhoseDiscardWouldTakeATranslatorsEditAlongIsKept(): void
    {
        // Core never points a translation at an unchanged copy; should a row
        // do so anyway, core's discard of the copy would take the edit along
        // without the confirmation the cleanup does not show.
        $this->editInWorkspace(['tt_content' => [110 => ['header' => 'Second']]]);
        $this->editInWorkspace(['tt_content' => [111 => ['header' => 'Zweites (Übersetzer)']]]);
        $copy = $this->versionOf('tt_content', 110);
        $german = $this->versionOf('tt_content', 111);
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['l18n_parent' => $copy], ['uid' => $german]);
        $copies = $this->copyEntries();
        self::assertCount(1, $copies);
        self::assertSame([['table' => 'tt_content', 'workspaceUid' => $german, 'languageUid' => 1, 'edited' => true]], $copies[0]->dependents);

        $result = $this->subject()->discardCopies($copies, $this->user());

        self::assertSame(0, $result['discarded']);
        self::assertSame(1 + count($copies[0]->companions), $result['skipped']);
        self::assertSame($copy, $this->versionOf('tt_content', 110));
        self::assertSame($german, $this->versionOf('tt_content', 111), 'the translator\'s edit stays');
    }

    #[Test]
    public function aHeaderEditNamesNoDependentsAndLeavesATranslatorsEditAlone(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $this->editInWorkspace(['tt_content' => [101 => ['header' => 'Held (bearbeitet)']]]);
        $german = $this->versionOf('tt_content', 101);
        self::assertSame(100, Value::int($this->liveRow('tt_content', $german)['l18n_parent']), 'an edit keeps the pointer at the live element');
        self::assertSame([], $this->entry('tt_content', 100)?->dependents);

        $result = $this->subject()->discard('tt_content', $this->versionOf('tt_content', 100), $this->user());

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(0, $result['dependentsDiscarded']);
        self::assertSame($german, $this->versionOf('tt_content', 101));
    }
}
