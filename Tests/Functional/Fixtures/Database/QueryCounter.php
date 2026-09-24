<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Fixtures\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Doctrine driver middleware that records every executed statement, so a
 * functional test can pin how many queries a collection costs and which
 * tables they touch. Register it through configurationToUseInTestInstance.
 */
final class QueryCounter implements Middleware
{
    /**
     * @var list<string>
     */
    public static array $statements = [];

    public static bool $enabled = false;

    public static function start(): void
    {
        self::$statements = [];
        self::$enabled = true;
    }

    /**
     * @return list<string>
     */
    public static function stop(): array
    {
        self::$enabled = false;
        return self::$statements;
    }

    /**
     * Recorded statements that select from $table (their first FROM; a
     * UNION over many tables counts for the first one only), optionally
     * only those whose SQL contains $fragment. Identifier quotes (`"` for
     * SQLite, backticks for MySQL/MariaDB) are ignored in the comparison.
     *
     * @return list<string>
     */
    public static function from(string $table, string $fragment = ''): array
    {
        return array_values(array_filter(
            self::$statements,
            static fn(string $sql): bool => preg_match('/^\s*SELECT\b.*?\bFROM\s+[`"]?([A-Za-z0-9_]+)/is', $sql, $match) === 1
                && $match[1] === $table
                && ($fragment === '' || str_contains(str_replace(['"', '`'], '', $sql), $fragment)),
        ));
    }

    public static function countFrom(string $table, string $fragment = ''): int
    {
        return count(self::from($table, $fragment));
    }

    public static function record(string $sql): void
    {
        if (self::$enabled) {
            self::$statements[] = $sql;
        }
    }

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            #[\Override]
            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                    #[\Override]
                    public function prepare(string $sql): Statement
                    {
                        return new class (parent::prepare($sql), $sql) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly string $sql)
                            {
                                parent::__construct($statement);
                            }

                            #[\Override]
                            public function execute(): Result
                            {
                                QueryCounter::record($this->sql);
                                return parent::execute();
                            }
                        };
                    }

                    #[\Override]
                    public function query(string $sql): Result
                    {
                        QueryCounter::record($sql);
                        return parent::query($sql);
                    }

                    #[\Override]
                    public function exec(string $sql): int|string
                    {
                        QueryCounter::record($sql);
                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
