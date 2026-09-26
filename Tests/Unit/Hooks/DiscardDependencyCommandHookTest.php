<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Webconsulting\WebconEasyWorkspace\Hooks\DiscardDependencyCommandHook;

final class DiscardDependencyCommandHookTest extends TestCase
{
    public function testEmptyVersionCommandsGoAndTheDiscardStays(): void
    {
        $rebuilt = [
            'tt_content' => [
                104525 => ['version' => [], 'discard' => true],
                104526 => ['version' => []],
            ],
            'stats_stats' => [
                846 => ['version' => []],
            ],
        ];

        self::assertSame(
            ['tt_content' => [104525 => ['discard' => true]]],
            DiscardDependencyCommandHook::withoutEmptyVersionCommands($rebuilt),
        );
    }

    public function testVersionCommandsWithAnActionAreKept(): void
    {
        $commandMap = [
            'tt_content' => [
                12 => ['version' => ['action' => 'publish', 'swapWith' => 13]],
                14 => ['version' => ['action' => 'clearWSID']],
                15 => ['version' => ['action' => 'setStage', 'stageId' => 1]],
            ],
            'pages' => [3 => ['delete' => 1]],
        ];

        self::assertSame($commandMap, DiscardDependencyCommandHook::withoutEmptyVersionCommands($commandMap));
    }

    public function testANonArrayVersionValueIsTreatedAsEmpty(): void
    {
        self::assertSame(
            ['tt_content' => [7 => ['discard' => true]]],
            DiscardDependencyCommandHook::withoutEmptyVersionCommands(['tt_content' => [7 => ['version' => '', 'discard' => true]]]),
        );
    }
}
