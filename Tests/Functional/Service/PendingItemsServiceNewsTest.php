<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;

/**
 * A news article scopes the dropdown to itself: its own record plus every
 * content element linked through `tx_news_domain_model_news.content_elements`
 * (foreign field `tt_content.tx_news_related_news`).
 *
 * An article carries an arbitrary number of those, so the list, the count
 * and the publish selection must cover all of them — not just the first.
 * Articles without any content element (the common case in EXT:news, where
 * the body is `bodytext`) still list their own record.
 */
final class PendingItemsServiceNewsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/news_stub',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/NewsBundleScenario.csv');
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(1);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    private function subject(): PendingItemsService
    {
        return $this->get(PendingItemsService::class);
    }

    /**
     * @param list<PendingItem> $items
     * @return list<string>
     */
    private static function titles(array $items): array
    {
        return array_map(static fn(PendingItem $item): string => $item->title, $items);
    }

    #[Test]
    public function listsEveryChangedContentElementOfTheArticle(): void
    {
        $payload = $this->subject()->payloadForNews(30, PendingItemsMode::Changed);

        // The article itself plus both changed elements; the third element
        // has no workspace version and is not pending.
        self::assertSame(
            ['Article with elements (draft)', 'Element one (draft)', 'Element two (draft)'],
            self::titles($payload->items),
        );
    }

    #[Test]
    public function listsUnchangedContentElementsInAllMode(): void
    {
        $payload = $this->subject()->payloadForNews(30, PendingItemsMode::All);

        self::assertSame(
            ['Article with elements (draft)', 'Element one (draft)', 'Element two (draft)', 'Element three'],
            self::titles($payload->items),
        );
        // The page's own content element is not part of the article.
        self::assertNotContains('Unrelated page content', self::titles($payload->items));
    }

    #[Test]
    public function contentElementsOfAnArticleCarryNoBackendLayoutColumn(): void
    {
        $payload = $this->subject()->payloadForNews(30, PendingItemsMode::Changed);

        foreach ($payload->items as $item) {
            self::assertNull($item->colPos, $item->title . ' colPos');
            self::assertNull($item->colPosLabel, $item->title . ' colPosLabel');
        }

        // …and therefore land in one group, not in per-column ones.
        self::assertSame(['records'], array_column($payload->changedItemGroups, 'key'));
    }

    #[Test]
    public function countsEveryChangedRecordOfTheArticle(): void
    {
        self::assertSame(3, $this->subject()->countChangesForContext(0, 30, []));
        self::assertTrue($this->subject()->hasChangesForNews(30)['hasChanges']);
    }

    #[Test]
    public function publishSelectionCoversEveryContentElement(): void
    {
        $payload = $this->subject()->payloadForNews(30, PendingItemsMode::Changed);

        $records = [];
        foreach ($payload->items as $item) {
            foreach ($item->publishRecords as $record) {
                $records[] = $record->table . ':' . $record->workspaceUid;
            }
        }

        self::assertSame(
            ['tx_news_domain_model_news:31', 'tt_content:110', 'tt_content:111'],
            $records,
        );
    }

    #[Test]
    public function softDeletedDraftsAreNotPending(): void
    {
        // Article 50 and its one content element both have a workspace
        // version, but both versions are soft-deleted (discarded). Nothing
        // about this article is pending.
        $payload = $this->subject()->payloadForNews(50, PendingItemsMode::Changed);

        self::assertSame([], self::titles($payload->items));
        self::assertSame(0, $this->subject()->countChangesForContext(0, 50, []));
        self::assertFalse($this->subject()->hasChangesForNews(50)['hasChanges']);
    }

    #[Test]
    public function anArticleWithoutContentElementsStillListsItsOwnRecord(): void
    {
        $payload = $this->subject()->payloadForNews(40, PendingItemsMode::Changed);

        self::assertSame(['Article without elements (draft)'], self::titles($payload->items));
        self::assertSame(1, $this->subject()->countChangesForContext(0, 40, []));
    }
}
