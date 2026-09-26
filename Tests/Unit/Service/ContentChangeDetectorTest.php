<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\ContentChangeDetector;

final class ContentChangeDetectorTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array LIVE = [
        'uid' => 84566, 'pid' => 933, 'header' => 'Thank you for being there.', 'sorting' => 256,
        'fe_group' => '0', 'selected_categories' => '', 'sys_language_uid' => 1, 'l18n_parent' => 26233,
        'tstamp' => 1790165297, 'crdate' => 1790164752, 't3ver_oid' => 0, 't3ver_wsid' => 0, 't3ver_state' => 0,
        'l18n_diffsource' => 'a:1:{s:6:"header";s:4:"old";}',
    ];

    public function testACopyTypo3MadeAlongWithAnEditElsewhereIsNoChange(): void
    {
        // What TYPO3 writes for a translation of an edited element: new uid,
        // timestamps and version pointers, and '' for a '0' group field.
        $version = ['uid' => 112964, 'tstamp' => 1790457105, 'crdate' => 1790457105, 't3ver_oid' => 84566, 't3ver_wsid' => 4,
            'fe_group' => '', 'selected_categories' => '0', 'l18n_diffsource' => 'a:1:{s:6:"header";s:3:"new";}'] + self::LIVE;

        self::assertFalse(ContentChangeDetector::differs($version, self::LIVE));
    }

    public function testAnEditedFieldIsAChange(): void
    {
        self::assertTrue(ContentChangeDetector::differs(['header' => 'Thank you (edited)'] + self::LIVE, self::LIVE));
        // Reordering is an edit too.
        self::assertTrue(ContentChangeDetector::differs(['sorting' => 512] + self::LIVE, self::LIVE));
        // So is taking a group restriction off: '1' is not an empty value.
        self::assertTrue(ContentChangeDetector::differs(['fe_group' => '1'] + self::LIVE, self::LIVE));
    }

    public function testARecordWithoutLiveRowIsAChange(): void
    {
        self::assertTrue(ContentChangeDetector::differs(self::LIVE, null));
    }
}
