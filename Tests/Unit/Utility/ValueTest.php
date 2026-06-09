<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final class ValueTest extends UnitTestCase
{
    /**
     * @return array<string, array{mixed, int}>
     */
    public static function intCoercionDataProvider(): array
    {
        return [
            'int passes through' => [42, 42],
            'numeric string' => ['7', 7],
            'negative numeric string' => ['-3', -3],
            'float is truncated' => [1.9, 1],
            'bool true' => [true, 1],
            'non-numeric string' => ['abc', 0],
            'null' => [null, 0],
            'array' => [['1'], 0],
        ];
    }

    #[Test]
    #[DataProvider('intCoercionDataProvider')]
    public function intCoercesValues(mixed $input, int $expected): void
    {
        self::assertSame($expected, Value::int($input));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function stringCoercionDataProvider(): array
    {
        return [
            'string passes through' => ['foo', 'foo'],
            'int' => [5, '5'],
            'float' => [1.5, '1.5'],
            'bool true' => [true, '1'],
            'null' => [null, ''],
            'array' => [['x'], ''],
            'object' => [new \stdClass(), ''],
        ];
    }

    #[Test]
    #[DataProvider('stringCoercionDataProvider')]
    public function stringCoercesValues(mixed $input, string $expected): void
    {
        self::assertSame($expected, Value::string($input));
    }

    #[Test]
    public function stringKeyArrayKeepsOnlyStringKeys(): void
    {
        $input = ['a' => 1, 0 => 'dropped', 'b' => ['nested'], 5 => 'dropped too'];

        self::assertSame(['a' => 1, 'b' => ['nested']], Value::stringKeyArray($input));
    }

    #[Test]
    public function stringKeyArrayReturnsEmptyArrayForNonArray(): void
    {
        self::assertSame([], Value::stringKeyArray('not an array'));
        self::assertSame([], Value::stringKeyArray(null));
    }

    #[Test]
    public function scalarStringKeyArrayDropsNonScalarValues(): void
    {
        $input = ['a' => 1, 'b' => ['array dropped'], 'c' => 'kept', 0 => 'dropped'];

        self::assertSame(['a' => 1, 'c' => 'kept'], Value::scalarStringKeyArray($input));
    }

    #[Test]
    public function stringListCoercesEveryItem(): void
    {
        self::assertSame(['a', '1', ''], Value::stringList(['a', 1, null]));
        self::assertSame([], Value::stringList('no array'));
    }
}
