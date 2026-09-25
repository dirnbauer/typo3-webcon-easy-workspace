<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Utility\WorkspaceTablePolicy;

/**
 * The toolbar acts on the same tables the Workspaces module lists: every
 * workspace-aware one, collection and other child tables included.
 */
final class WorkspaceTablePolicyTest extends UnitTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    #[Test]
    public function everyWorkspaceAwareTableIsAllowed(): void
    {
        $GLOBALS['TCA'] = [
            'tt_content' => ['ctrl' => ['versioningWS' => true]],
            'feature_items' => ['ctrl' => ['versioningWS' => true, 'hideTable' => true]],
            'sys_file_reference' => ['ctrl' => ['versioningWS' => true, 'hideTable' => true]],
            'tx_some_record' => ['ctrl' => ['versioningWS' => true]],
        ];
        $subject = new WorkspaceTablePolicy();

        self::assertTrue($subject->isAllowed('tt_content'));
        self::assertTrue($subject->isAllowed('feature_items'));
        self::assertTrue($subject->isAllowed('sys_file_reference'));
        self::assertTrue($subject->isAllowed('tx_some_record'));
    }

    #[Test]
    public function tablesWithoutVersioningAndUnknownTablesAreNot(): void
    {
        $GLOBALS['TCA'] = [
            'sys_file' => ['ctrl' => []],
            'be_users' => ['ctrl' => ['versioningWS' => false]],
        ];
        $subject = new WorkspaceTablePolicy();

        self::assertFalse($subject->isAllowed('sys_file'));
        self::assertFalse($subject->isAllowed('be_users'));
        self::assertFalse($subject->isAllowed('tx_unknown_table'));
        self::assertFalse($subject->isAllowed(''));
    }
}
