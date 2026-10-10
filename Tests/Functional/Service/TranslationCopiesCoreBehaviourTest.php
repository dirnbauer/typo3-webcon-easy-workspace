<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\ContentChangeDetector;

/**
 * Pins what core does with translations when the default record is saved,
 * moved, deleted or discarded in a workspace — the behaviour the companion
 * folding and the dependents are built on. When one of these starts to
 * fail, core changed: re-check CompanionClassifier and
 * PublishSelectedService::discard().
 */
final class TranslationCopiesCoreBehaviourTest extends FunctionalTestCase
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
    public function aHeaderEditOfTheDefaultElementVersionsEveryTranslationIdentically(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        foreach ([101, 102, 103] as $liveUid) {
            $version = $this->versionOf('tt_content', $liveUid);
            self::assertGreaterThan(0, $version, 'translation ' . $liveUid . ' is versioned along');
            self::assertFalse(ContentChangeDetector::differs($this->liveRow('tt_content', $version), $this->liveRow('tt_content', $liveUid)));
        }
        foreach ([201, 202, 203] as $liveUid) {
            self::assertGreaterThan(0, $this->versionOf('sys_file_reference', $liveUid), 'image reference ' . $liveUid . ' is versioned along');
        }
    }

    #[Test]
    public function discardingAnEditedDefaultElementLeavesTheTranslationCopiesBehind(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $this->editInWorkspace([], ['tt_content' => [$this->versionOf('tt_content', 100) => ['discard' => true]]]);

        self::assertSame(0, $this->versionOf('tt_content', 100));
        foreach ([101, 102, 103] as $liveUid) {
            self::assertGreaterThan(0, $this->versionOf('tt_content', $liveUid), 'copy of ' . $liveUid . ' is left behind');
        }
    }

    #[Test]
    public function discardingAMovedOrDeletedDefaultElementRemovesItsTranslations(): void
    {
        $this->editInWorkspace([], ['tt_content' => [100 => ['move' => -110]]]);
        $moved = $this->versionOf('tt_content', 100);
        // Core points the moved translations at the default element's version.
        self::assertSame($moved, (int)$this->liveRow('tt_content', $this->versionOf('tt_content', 101))['l18n_parent']);

        $this->editInWorkspace([], ['tt_content' => [$moved => ['discard' => true]]]);
        self::assertSame([], $this->workspaceRows());

        $this->editInWorkspace([], ['tt_content' => [100 => ['delete' => 1]]]);
        self::assertGreaterThan(0, $this->versionOf('tt_content', 101), 'the translation is deleted along');
        $this->editInWorkspace([], ['tt_content' => [$this->versionOf('tt_content', 100) => ['discard' => true]]]);
        self::assertSame([], $this->workspaceRows());
    }

    #[Test]
    public function discardingAMoveRemovesATranslationEditedAfterTheMoveButNotOneEditedBefore(): void
    {
        // Edited after the move: the translation keeps its pointer at the
        // English version, and core's discard looks translations up by it.
        $this->editInWorkspace([], ['tt_content' => [110 => ['move' => -120]]]);
        $this->editInWorkspace(['tt_content' => [111 => ['header' => 'Zweites (Übersetzer)']]]);
        $german = $this->versionOf('tt_content', 111);
        self::assertSame($this->versionOf('tt_content', 110), (int)$this->liveRow('tt_content', $german)['l18n_parent']);

        $this->editInWorkspace([], ['tt_content' => [$this->versionOf('tt_content', 110) => ['discard' => true]]]);
        self::assertSame([], $this->workspaceRows(), 'the edit goes with the move');

        // Edited before the move: its pointer stays at the live element.
        $this->editInWorkspace(['tt_content' => [111 => ['header' => 'Zweites (Übersetzer)']]]);
        $german = $this->versionOf('tt_content', 111);
        $this->editInWorkspace([], ['tt_content' => [110 => ['move' => -120]]]);
        self::assertSame(110, (int)$this->liveRow('tt_content', $german)['l18n_parent']);

        $this->editInWorkspace([], ['tt_content' => [$this->versionOf('tt_content', 110) => ['discard' => true]]]);
        self::assertContains('tt_content:' . $german, $this->workspaceRows(), 'the edit stays');
    }

    #[Test]
    public function discardingANewElementRemovesItsNewTranslations(): void
    {
        $this->editInWorkspace(['tt_content' => ['NEW1' => ['pid' => 2, 'CType' => 'text', 'header' => 'Brand new', 'colPos' => 0]]]);
        $new = $this->newElement('Brand new');
        $this->editInWorkspace([], ['tt_content' => [$new => ['localize' => 1]]]);
        self::assertCount(2, $this->workspaceRows());

        $this->editInWorkspace([], ['tt_content' => [$new => ['discard' => true]]]);

        self::assertSame([], $this->workspaceRows());
    }

    private function newElement(string $header): int
    {
        foreach ($this->workspaceRows() as $row) {
            [$table, $uid] = explode(':', $row);
            if ($table === 'tt_content' && $this->liveRow($table, (int)$uid)['header'] === $header) {
                return (int)$uid;
            }
        }

        return 0;
    }
}
