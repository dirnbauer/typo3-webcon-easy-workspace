<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Service\RecordSchemaInspector;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;

/**
 * Guards against the trap this service exists for.
 *
 * TYPO3 v14 dropped `t3ver_*`, `deleted` and `tstamp` from TCA `columns`.
 * A query guarded by `TcaUtility::hasColumn($table, 't3ver_wsid')` is
 * therefore never executed, and a `deleted = 0` constraint added behind
 * the same check is never applied — both fail silently.
 */
final class RecordSchemaInspectorTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/news_stub',
    ];

    #[Test]
    public function theSystemFieldsAreNotTcaColumns(): void
    {
        foreach (['pages', 'tt_content', 'tx_news_domain_model_news'] as $table) {
            foreach (['t3ver_wsid', 't3ver_oid', 'deleted', 'tstamp'] as $field) {
                self::assertFalse(
                    TcaUtility::hasColumn($table, $field),
                    sprintf('%s.%s is not a TCA column in v14 — do not guard queries with hasColumn()', $table, $field),
                );
            }
        }
    }

    #[Test]
    public function resolvesWorkspaceAwarenessAndSystemFieldsFromTheSchema(): void
    {
        $subject = $this->get(RecordSchemaInspector::class);

        foreach (['pages', 'tt_content', 'tx_news_domain_model_news'] as $table) {
            self::assertTrue($subject->isWorkspaceAware($table), $table . ' is workspace aware');
            self::assertSame('deleted', $subject->softDeleteField($table), $table . ' soft-delete field');
            self::assertSame('tstamp', $subject->updatedAtField($table), $table . ' updated-at field');
        }
    }

    #[Test]
    public function reportsNothingForAnUnknownTable(): void
    {
        $subject = $this->get(RecordSchemaInspector::class);

        self::assertFalse($subject->isWorkspaceAware('tx_not_a_table'));
        self::assertNull($subject->softDeleteField('tx_not_a_table'));
        self::assertNull($subject->updatedAtField('tx_not_a_table'));
    }
}
