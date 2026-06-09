<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Utility\WorkspaceTablePolicy;

final class WorkspaceTablePolicyTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function primaryTablesDataProvider(): array
    {
        return [
            'pages' => ['pages'],
            'tt_content' => ['tt_content'],
            'news' => ['tx_news_domain_model_news'],
            'file metadata' => ['sys_file_metadata'],
        ];
    }

    #[Test]
    #[DataProvider('primaryTablesDataProvider')]
    public function primaryTablesAreAlwaysAllowed(string $table): void
    {
        $subject = new WorkspaceTablePolicy();

        self::assertTrue($subject->isPrimary($table));
        self::assertTrue($subject->isAllowed($table));
    }

    #[Test]
    public function unknownTableIsNotAllowed(): void
    {
        $GLOBALS['TCA'] = [];

        self::assertFalse((new WorkspaceTablePolicy())->isAllowed('tx_unknown_table'));
    }

    #[Test]
    public function workspaceAwareHiddenChildTableWithParentUidColumnIsAllowed(): void
    {
        $GLOBALS['TCA'] = [
            'tx_child_table' => [
                'ctrl' => ['versioningWS' => true, 'hideTable' => true],
                'columns' => [
                    'foreign_table_parent_uid' => ['config' => ['type' => 'passthrough']],
                ],
            ],
        ];

        self::assertTrue((new WorkspaceTablePolicy())->isAllowed('tx_child_table'));
    }

    #[Test]
    public function workspaceAwareHiddenChildTableReferencedByInlineParentIsAllowed(): void
    {
        $GLOBALS['TCA'] = [
            'tx_parent_table' => [
                'ctrl' => ['versioningWS' => true],
                'columns' => [
                    'children' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'tx_child_table',
                            'foreign_field' => 'parent_uid',
                        ],
                    ],
                ],
            ],
            'tx_child_table' => [
                'ctrl' => ['versioningWS' => true, 'hideTable' => true],
                'columns' => [],
            ],
        ];

        self::assertTrue((new WorkspaceTablePolicy())->isAllowed('tx_child_table'));
    }

    #[Test]
    public function visibleNonChildTableIsNotAllowed(): void
    {
        $GLOBALS['TCA'] = [
            'tx_some_table' => [
                'ctrl' => ['versioningWS' => true],
                'columns' => [],
            ],
        ];

        self::assertFalse((new WorkspaceTablePolicy())->isAllowed('tx_some_table'));
    }

    #[Test]
    public function sysFileReferenceIsAllowedWhenWorkspaceAware(): void
    {
        $GLOBALS['TCA'] = [
            'sys_file_reference' => [
                'ctrl' => ['versioningWS' => true],
                'columns' => [],
            ],
        ];

        self::assertTrue((new WorkspaceTablePolicy())->isAllowed('sys_file_reference'));
    }

    #[Test]
    public function isAllowedMemoizesResultPerInstance(): void
    {
        $GLOBALS['TCA'] = [
            'tx_child_table' => [
                'ctrl' => ['versioningWS' => true, 'hideTable' => true],
                'columns' => [
                    'foreign_table_parent_uid' => ['config' => ['type' => 'passthrough']],
                ],
            ],
        ];
        $subject = new WorkspaceTablePolicy();
        self::assertTrue($subject->isAllowed('tx_child_table'));

        // TCA changes within a request do not happen; the memoized
        // verdict must survive a (synthetic) TCA mutation.
        $GLOBALS['TCA'] = [];

        self::assertTrue($subject->isAllowed('tx_child_table'));
        self::assertFalse((new WorkspaceTablePolicy())->isAllowed('tx_child_table'));
    }
}
