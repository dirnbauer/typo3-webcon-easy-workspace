<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;

/**
 * The page's list in "all" mode for one language: the translation copies of
 * another language's change travel with that change, so they are this
 * language's records as they are live — listed, unchanged, not counted.
 */
final class PendingItemsServiceCompanionTest extends FunctionalTestCase
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

    #[Test]
    public function inAllModeForALanguageTheCopiesOfAnotherLanguagesChangeAreUnchangedRecords(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $service = $this->get(PendingItemsService::class);

        $all = $service->payloadForPage(2, PendingItemsMode::All);
        self::assertSame(1, PendingItemsService::changeSummary($all->items)['count']);

        $german = $service->payloadForPage(2, PendingItemsMode::All, [], 1);
        $hero = array_values(array_filter(
            $german->items,
            static fn(PendingItem $item): bool => $item->table === 'tt_content' && $item->liveUid === 101,
        ));

        self::assertCount(1, $hero, 'the German hero is still listed');
        self::assertFalse($hero[0]->isChanged);
        self::assertSame(101, $hero[0]->workspaceUid);
        self::assertSame([], array_values(array_filter($german->items, static fn(PendingItem $item): bool => $item->isChanged)));
        self::assertSame(0, PendingItemsService::changeSummary($german->items)['count']);
    }
}
