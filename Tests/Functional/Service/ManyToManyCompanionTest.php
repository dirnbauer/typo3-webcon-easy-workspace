<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CompanionResolver;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CoreWorkspaceChanges;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Relations kept in an MM table (here: categories) are part of a version:
 * core moves them live on publish and deletes them on discard. A version
 * whose only change is in its MM rows — one category swapped for another, or
 * the same categories in another order — is a change, never a copy to clean
 * up or a companion taken along.
 */
final class ManyToManyCompanionTest extends FunctionalTestCase
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
        $pool = $this->get(ConnectionPool::class);
        foreach ([1 => 'News', 2 => 'Events'] as $uid => $title) {
            $pool->getConnectionForTable('sys_category')->insert('sys_category', ['uid' => $uid, 'pid' => 1, 'title' => $title]);
        }
        // The second element and its German translation are in "News".
        foreach ([110, 111] as $element) {
            $this->relate($element, [1]);
        }
    }

    /**
     * Live categories of an element, as the form writes them.
     *
     * @param list<int> $categories
     */
    private function relate(int $element, array $categories): void
    {
        $pool = $this->get(ConnectionPool::class);
        foreach ($categories as $position => $category) {
            $pool->getConnectionForTable('sys_category_record_mm')->insert('sys_category_record_mm', [
                'uid_local' => $category, 'uid_foreign' => $element, 'tablenames' => 'tt_content', 'fieldname' => 'categories',
                'sorting' => 1, 'sorting_foreign' => $position + 1,
            ]);
        }
        $pool->getConnectionForTable('tt_content')->update('tt_content', ['categories' => count($categories)], ['uid' => $element]);
    }

    /**
     * @return list<int> The categories of an element (a live uid or a version uid), in their order.
     */
    private function categoriesOf(int $element): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('sys_category_record_mm');

        return array_map(Value::int(...), $queryBuilder->select('uid_local')->from('sys_category_record_mm')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $element),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('categories')),
            )
            ->orderBy('sorting_foreign')
            ->executeQuery()->fetchFirstColumn());
    }

    private function user(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }

    /**
     * @return array<string, WorkspaceChange> by table:liveUid
     */
    private function entries(): array
    {
        $entries = [];
        foreach ($this->get(CoreWorkspaceChanges::class)->entries(1, 2) as $entry) {
            $entries[$entry->table . ':' . $entry->liveUid] = $entry;
        }

        return $entries;
    }

    /**
     * @return list<WorkspaceChange>
     */
    private function copyEntries(): array
    {
        return array_values(array_filter($this->entries(), static fn(WorkspaceChange $entry): bool => $entry->role === ChangeRole::Copy));
    }

    #[Test]
    public function swappingOneCategoryForAnotherIsAChangeNotACopy(): void
    {
        $this->editInWorkspace(['tt_content' => [110 => ['categories' => '2']]]);
        $version = $this->versionOf('tt_content', 110);
        self::assertSame([2], $this->categoriesOf($version));

        $entry = $this->entries()['tt_content:110'] ?? null;

        self::assertInstanceOf(WorkspaceChange::class, $entry);
        self::assertSame(ChangeRole::Change, $entry->role);
        self::assertArrayHasKey('categories', $entry->changedFields);
        self::assertSame([], $this->copyEntries());
        self::assertSame([], $this->get(CompanionResolver::class)->verifiedCopies(1, [$entry])['discardable']);

        $result = $this->get(PublishSelectedService::class)->discardCopies([$entry], $this->user());

        self::assertSame(0, $result['discarded']);
        self::assertSame($version, $this->versionOf('tt_content', 110), 'the category change stays');
    }

    #[Test]
    public function theSameCategoriesInAnotherOrderAreAChange(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_category_record_mm')
            ->delete('sys_category_record_mm', ['uid_foreign' => 110]);
        $this->relate(110, [1, 2]);

        $this->editInWorkspace(['tt_content' => [110 => ['categories' => '2,1']]]);

        self::assertSame([2, 1], $this->categoriesOf($this->versionOf('tt_content', 110)));
        $entry = $this->entries()['tt_content:110'] ?? null;
        self::assertInstanceOf(WorkspaceChange::class, $entry);
        self::assertSame(ChangeRole::Change, $entry->role);
        self::assertArrayHasKey('categories', $entry->changedFields);
    }

    #[Test]
    public function aTranslatorsCategorySwapIsNeitherDiscardedNorPublishedWithTheEditedElement(): void
    {
        // A translator swaps the German translation's category …
        $this->editInWorkspace(['tt_content' => [111 => ['categories' => '2']]]);
        $german = $this->versionOf('tt_content', 111);
        // … and an editor edits the English element.
        $this->editInWorkspace(['tt_content' => [110 => ['header' => 'Second (edited)']]]);
        $english = $this->versionOf('tt_content', 110);

        $entries = $this->entries();
        self::assertSame(ChangeRole::Change, $entries['tt_content:111']->role ?? null, 'the German swap is a change of its own');
        self::assertSame(
            ['tt_content:112', 'tt_content:113'],
            array_map(static fn(WorkspaceChange $companion): string => $companion->table . ':' . $companion->liveUid, $entries['tt_content:110']->companions ?? []),
        );

        $discarded = $this->get(PublishSelectedService::class)->discard('tt_content', $english, $this->user());
        self::assertTrue($discarded['success'], implode(' / ', $discarded['errors']));
        self::assertSame(2, $discarded['companionsDiscarded']);
        self::assertSame($german, $this->versionOf('tt_content', 111), 'the German swap stays');
        self::assertSame([2], $this->categoriesOf($german));

        $this->editInWorkspace(['tt_content' => [110 => ['header' => 'Second (edited again)']]]);
        $published = $this->get(PublishSelectedService::class)->publish(
            [['table' => 'tt_content', 'workspaceUid' => $this->versionOf('tt_content', 110)]],
            $this->user(),
        );
        self::assertTrue($published['success'], implode(' / ', $published['errors']));
        self::assertSame('Second (edited again)', $this->liveRow('tt_content', 110)['header']);
        self::assertSame([1], $this->categoriesOf(111), 'the unreviewed German swap did not go live');
        self::assertSame($german, $this->versionOf('tt_content', 111));
    }

    #[Test]
    public function aHeaderEditStillFoldsTheTranslationsWithTheirCopiedCategories(): void
    {
        $this->editInWorkspace(['tt_content' => [110 => ['header' => 'Second (edited)']]]);

        // Core copied the German translation's relation to its copy.
        self::assertSame([1], $this->categoriesOf($this->versionOf('tt_content', 111)));
        $entries = $this->entries();
        self::assertSame(['tt_content:110'], array_keys($entries));
        self::assertSame(ChangeRole::Change, $entries['tt_content:110']->role);
        self::assertArrayNotHasKey('categories', $entries['tt_content:110']->changedFields);
        self::assertSame(
            ['tt_content:111', 'tt_content:112', 'tt_content:113'],
            array_map(static fn(WorkspaceChange $companion): string => $companion->table . ':' . $companion->liveUid, $entries['tt_content:110']->companions),
        );
        self::assertCount(3, $this->get(CompanionResolver::class)->verifiedCompanions(1, $entries['tt_content:110'])['verified']);
    }

    #[Test]
    public function aCategoryTableThatCannotBeReadCountsAsChanged(): void
    {
        $this->editInWorkspace(['tt_content' => [110 => ['header' => 'Second']]]);
        self::assertCount(1, $this->copyEntries());
        $GLOBALS['TCA']['tt_content']['columns']['categories']['config']['MM'] = 'tx_easyws_missing_mm';
        $this->get(\TYPO3\CMS\Core\Schema\TcaSchemaFactory::class)->rebuild($GLOBALS['TCA']);

        $fresh = $this->get(CoreWorkspaceChanges::class)->refreshFacts(1, [new WorkspaceChange('tt_content', $this->versionOf('tt_content', 110), 110)]);

        self::assertArrayHasKey('categories', array_values($fresh)[0]->changedFields ?? []);
    }
}
