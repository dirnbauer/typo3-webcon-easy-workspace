<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\WorkspaceRecordQuery;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\WorkspaceVersionPresence;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use Webconsulting\WebconEasyWorkspace\Tests\Functional\Fixtures\Database\QueryCounter;

/**
 * What a page collection costs, in queries.
 *
 * Content Blocks makes every collection field a base tt_content column, so
 * each element carries every inline field of the installation. Up to 1.7.2
 * each of them was asked separately for each element — on production 15,000
 * queries (2.3 s) for one badge of a page without a single change. The
 * fixture has 26 inline fields; these tests pin that children are fetched
 * once per relation, not once per element and relation, and that tables
 * without a row of the workspace are not asked at all.
 */
final class PageCollectionCostTest extends FunctionalTestCase
{
    /**
     * Inline relations of the fixture into tx_easyws_item: easyws_items plus
     * 24 extra fields, told apart by foreign_match_fields.
     */
    private const int ITEM_RELATIONS = 25;

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/inline_stub',
    ];

    protected array $configurationToUseInTestInstance = [
        'DB' => [
            'Connections' => [
                'Default' => [
                    'driverMiddlewares' => [
                        'webcon-easy-workspace-query-counter' => [
                            'target' => QueryCounter::class,
                        ],
                    ],
                ],
            ],
        ],
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

    #[Test]
    public function childrenAreFetchedOncePerRelationNotOncePerElement(): void
    {
        $this->enterWorkspace(1);
        $subject = $this->get(PendingItemsService::class);

        QueryCounter::start();
        $count = $subject->countChangesForContext(2, 0, []);
        QueryCounter::stop();

        self::assertSame(12, $count);
        // One query per relation for the children of all 13 elements …
        self::assertSame(self::ITEM_RELATIONS, QueryCounter::countFrom('tx_easyws_item', 'foreign_table_parent_uid IN'));
        // … one per relation for changed children whose parent is not listed …
        self::assertSame(self::ITEM_RELATIONS, QueryCounter::countFrom('tx_easyws_item', '(pid = '));
        // … and record lookups for the 4 changed items only (diff, live row).
        self::assertLessThanOrEqual(4 * 3, QueryCounter::countFrom('tx_easyws_item', '(uid = '));
        // tx_easyws_other holds no row of the workspace, so nobody asks it.
        self::assertSame(0, QueryCounter::countFrom('tx_easyws_other'));
    }

    #[Test]
    public function aWorkspaceWithoutChildChangesNeverQueriesChildTables(): void
    {
        // Workspace Two changed a single element on page 3 — nothing on
        // page 2, and no collection item or file reference anywhere.
        $this->enterWorkspace(2);
        $subject = $this->get(PendingItemsService::class);

        QueryCounter::start();
        $count = $subject->countChangesForContext(2, 0, []);
        $hasChanges = $subject->hasChangesForPage(2)['hasChanges'];
        $items = $subject->payloadForPage(2, PendingItemsMode::Changed)->items;
        $statements = QueryCounter::stop();

        self::assertSame(0, $count);
        self::assertFalse($hasChanges);
        self::assertSame([], $items);
        self::assertSame(0, QueryCounter::countFrom('tx_easyws_item'));
        self::assertSame(0, QueryCounter::countFrom('tx_easyws_other'));
        self::assertSame(0, QueryCounter::countFrom('sys_file_reference'));
        // Presence scan, page, elements, overlay lookup, standalone metadata —
        // per call, not per element or field.
        self::assertLessThan(40, count($statements));
    }

    #[Test]
    public function presenceIgnoresSoftDeletedRowsAndOtherWorkspaces(): void
    {
        $presence = $this->get(WorkspaceVersionPresence::class);

        self::assertTrue($presence->hasVersions('tx_easyws_item', 1));
        self::assertFalse($presence->hasVersions('tx_easyws_item', 2));
        self::assertTrue($presence->hasVersions('tt_content', 2));
        self::assertFalse($presence->hasVersions('tx_easyws_other', 1));
        self::assertFalse($presence->hasVersions('tt_content', 0));

        // A discarded (soft-deleted) draft is no pending row.
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_easyws_other')->insert('tx_easyws_other', [
            'uid' => 90,
            'pid' => 2,
            'header' => 'Discarded',
            'foreign_table_parent_uid' => 20,
            'deleted' => 1,
            't3ver_oid' => 0,
            't3ver_wsid' => 1,
            't3ver_state' => 1,
        ]);
        $presence->reset();
        self::assertFalse($presence->hasVersions('tx_easyws_other', 1));
    }

    #[Test]
    public function batchedOverlayReturnsExactlyWhatWorkspaceOverlayPerRowReturns(): void
    {
        $subject = $this->get(WorkspaceRecordQuery::class);
        foreach ([1, 2] as $workspaceId) {
            foreach (['tt_content', 'tx_easyws_item', 'pages'] as $table) {
                $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable($table);
                $queryBuilder->getRestrictions()->removeAll();
                $rows = $queryBuilder->select('*')->from($table)->orderBy('uid')->executeQuery()->fetchAllAssociative();

                $expected = [];
                foreach ($rows as $row) {
                    BackendUtility::workspaceOL($table, $row, $workspaceId);
                    if (is_array($row)) {
                        $expected[] = $row;
                    }
                }

                self::assertSame($expected, $subject->overlayRows($table, $rows, $workspaceId), $table . ' in workspace ' . $workspaceId);
            }
        }
    }
}
