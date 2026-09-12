<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChangeCount;

final class WorkspaceChangeCountTest extends UnitTestCase
{
    #[Test]
    public function stampIsDeterministicForIdenticalInput(): void
    {
        self::assertSame(
            WorkspaceChangeCount::stamp(4, 3, 1700000600),
            WorkspaceChangeCount::stamp(4, 3, 1700000600),
        );
        self::assertSame(sha1('4|3|1700000600'), WorkspaceChangeCount::stamp(4, 3, 1700000600));
    }

    #[Test]
    public function stampChangesWhenAnyComponentChanges(): void
    {
        $reference = WorkspaceChangeCount::stamp(4, 3, 1700000600);

        self::assertNotSame($reference, WorkspaceChangeCount::stamp(5, 3, 1700000600));
        self::assertNotSame($reference, WorkspaceChangeCount::stamp(4, 2, 1700000600));
        self::assertNotSame($reference, WorkspaceChangeCount::stamp(4, 3, 1700000601));
    }

    #[Test]
    public function emptyCountSerializesWithAllStateKeys(): void
    {
        $payload = WorkspaceChangeCount::empty(7)->toArray();

        self::assertSame(7, $payload['workspaceId']);
        self::assertSame(0, $payload['changedCount']);
        self::assertSame([], $payload['byTable']);
        self::assertSame(['new' => 0, 'changed' => 0, 'deleted' => 0, 'moved' => 0], $payload['byState']);
        self::assertSame(WorkspaceChangeCount::stamp(7, 0, 0), $payload['stamp']);
    }
}
