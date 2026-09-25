<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository;
use TYPO3\CMS\Workspaces\Service\GridDataService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceChangeCounter;

/**
 * The toolbar shows the Workspaces module's data: the same number of
 * changes, the same records. The fixture has what used to tell them apart —
 * a changed collection item of an unchanged element, a new file reference
 * of an unchanged element, a version identical to live.
 */
final class WorkspacesModuleParityTest extends FunctionalTestCase
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
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(1);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
    }

    #[Test]
    public function theWorkspaceCountIsTheModulesCount(): void
    {
        self::assertSame(
            $this->moduleGrid(-1)['total'],
            $this->get(WorkspaceChangeCounter::class)->count(1)->total,
        );
    }

    #[Test]
    public function aPageListsTheModulesRowsForThatPage(): void
    {
        $grid = $this->moduleGrid(2);
        $moduleRows = [];
        foreach ($grid['data'] as $row) {
            if ((int)($row['Workspaces_CollectionLevel'] ?? 0) === 0) {
                $moduleRows[] = $row['table'] . ':' . $row['uid'];
            }
        }

        $items = $this->get(PendingItemsService::class)->payloadForPage(2, PendingItemsMode::Changed)->items;
        $toolbarRows = array_map(static fn($item): string => $item->table . ':' . $item->workspaceUid, $items);

        sort($moduleRows);
        sort($toolbarRows);
        self::assertSame($moduleRows, $toolbarRows);
        // The changes that did not count before: collection item A's draft
        // and element 27's new image, both of elements without a draft.
        self::assertContains('tx_easyws_item:31', $toolbarRows);
        self::assertContains('sys_file_reference:72', $toolbarRows);
    }

    /**
     * What the Workspaces module's list request computes.
     *
     * @return array{total: int, data: list<array<string, mixed>>}
     */
    private function moduleGrid(int $pageUid): array
    {
        $backendUser = $GLOBALS['BE_USER'];
        $versions = $this->get(WorkspaceService::class)->selectVersionsInWorkspace(1, -99, $pageUid, 0, 'tables_select');
        $stages = $this->get(WorkspaceStageRepository::class)->findAllStagesByWorkspace(
            $backendUser,
            $this->get(WorkspaceRepository::class)->findByUid(1),
        );
        /** @var array{total: int, data: list<array<string, mixed>>} $grid */
        $grid = $this->get(GridDataService::class)->generateGridListFromVersions(
            $stages,
            $versions,
            (object)['start' => 0, 'limit' => 1000, 'id' => $pageUid, 'depth' => 0],
        );

        return $grid;
    }
}
