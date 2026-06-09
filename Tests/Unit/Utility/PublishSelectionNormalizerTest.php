<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Utility\PublishSelectionNormalizer;
use Webconsulting\WebconEasyWorkspace\Utility\WorkspaceTablePolicy;

final class PublishSelectionNormalizerTest extends UnitTestCase
{
    private PublishSelectionNormalizer $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA'] = [];
        $this->subject = new PublishSelectionNormalizer(new WorkspaceTablePolicy());
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    #[Test]
    public function fromModuleFormParsesColonSeparatedPairs(): void
    {
        $result = $this->subject->fromModuleForm(['tt_content:5', 'pages:12']);

        self::assertSame([
            ['table' => 'tt_content', 'workspaceUid' => 5],
            ['table' => 'pages', 'workspaceUid' => 12],
        ], $result);
    }

    #[Test]
    public function fromModuleFormDropsInvalidEntries(): void
    {
        $result = $this->subject->fromModuleForm([
            '',                    // empty
            'tt_content',          // missing uid
            'tt_content:0',        // non-positive uid
            'tt_content:-4',       // negative uid
            'be_users:3',          // table not allowed by policy
            42,                    // not a string
            'tt_content:7',        // valid
        ]);

        self::assertSame([['table' => 'tt_content', 'workspaceUid' => 7]], $result);
    }

    #[Test]
    public function fromAjaxJsonParsesStructuredEntries(): void
    {
        $result = $this->subject->fromAjaxJson([
            ['table' => 'tt_content', 'workspaceUid' => 9],
            ['table' => 'pages', 'workspaceUid' => '3'],
        ]);

        self::assertSame([
            ['table' => 'tt_content', 'workspaceUid' => 9],
            ['table' => 'pages', 'workspaceUid' => 3],
        ], $result);
    }

    #[Test]
    public function fromAjaxJsonDropsInvalidEntries(): void
    {
        $result = $this->subject->fromAjaxJson([
            'not an array',
            ['table' => 'tt_content'],                       // missing uid
            ['table' => 'be_users', 'workspaceUid' => 1],    // not allowed
            ['workspaceUid' => 5],                           // missing table
            ['table' => 'tt_content', 'workspaceUid' => 11], // valid
        ]);

        self::assertSame([['table' => 'tt_content', 'workspaceUid' => 11]], $result);
    }
}
