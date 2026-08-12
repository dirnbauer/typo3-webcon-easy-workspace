<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\ChatOps;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconWorkspaceChatops\Utility\Value;

final class ValueTest extends UnitTestCase
{
    /**
     * @return array<string, array{mixed, int}>
     */
    public static function integerDataProvider(): array
    {
        return [
            'integer' => [42, 42],
            'numeric string' => ['7', 7],
            'float' => [1.9, 1],
            'boolean' => [true, 1],
            'non-numeric string' => ['nope', 0],
            'array' => [['1'], 0],
            'object' => [new \stdClass(), 0],
        ];
    }

    #[Test]
    #[DataProvider('integerDataProvider')]
    public function intSafelyNormalizesValues(mixed $input, int $expected): void
    {
        self::assertSame($expected, Value::int($input));
    }

    #[Test]
    public function stringRejectsNonScalarValues(): void
    {
        self::assertSame('12', Value::string(12));
        self::assertSame('', Value::string(['unsafe']));
        self::assertSame('', Value::string(new \stdClass()));
    }

    #[Test]
    public function stringKeyArrayDropsNumericKeys(): void
    {
        self::assertSame(['kept' => 1], Value::stringKeyArray(['kept' => 1, 0 => 'dropped']));
    }

    #[Test]
    public function stringListSafelyNormalizesEveryValue(): void
    {
        self::assertSame([' one ', '2', '', '', ''], Value::stringList([' one ', 2, '', [], new \stdClass()]));
    }
}
