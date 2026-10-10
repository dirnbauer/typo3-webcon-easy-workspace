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
        // What is new is decided by the version state, not by fields.
        self::assertSame([], ContentChangeDetector::changedFields(self::LIVE, null));
    }

    public function testChangedFieldsNameEachEditedFieldWithAHashOfItsValue(): void
    {
        $changed = ContentChangeDetector::changedFields(
            ['header' => 'Thank you (edited)', 'starttime' => 1767225600, 'uid' => 1, 'tstamp' => 2] + self::LIVE,
            self::LIVE,
        );

        self::assertSame(['header' => sha1('Thank you (edited)'), 'starttime' => sha1('1767225600')], $changed);
    }

    public function testSystemFieldsAndLooseEmptyValuesAreNoChange(): void
    {
        $version = ['uid' => 112964, 'tstamp' => 1790457105, 't3ver_oid' => 84566, 't3ver_wsid' => 4, 't3ver_state' => 0,
            'l10n_state' => '{"header":"custom"}', 'fe_group' => '', 'selected_categories' => '0'] + self::LIVE;

        self::assertSame([], ContentChangeDetector::changedFields($version, self::LIVE));
    }

    public function testTheFrontendsLastChangedStampIsNoChange(): void
    {
        // A live render writes SYS_LASTCHANGED into the live page row without
        // DataHandler; the page's version keeps the value it was copied with.
        $live = ['uid' => 2, 'title' => 'Translated page', 'SYS_LASTCHANGED' => 1791100000];
        $version = ['uid' => 7, 't3ver_oid' => 2, 't3ver_wsid' => 1, 'title' => 'Translated page', 'SYS_LASTCHANGED' => 1791056338];

        self::assertSame([], ContentChangeDetector::changedFields($version, $live));
        self::assertFalse(ContentChangeDetector::differs($version, $live));
    }

    public function testEqualValuesGiveEqualHashes(): void
    {
        // TYPO3 writes the synchronized value into each translation: the
        // default element's version and a translation's carry the same hash.
        $default = ContentChangeDetector::changedFields(['starttime' => '1767225600 '] + self::LIVE, self::LIVE);
        $translation = ContentChangeDetector::changedFields(['starttime' => 1767225600, 'header' => 'Danke'] + self::LIVE, ['header' => 'Danke'] + self::LIVE);

        self::assertSame($default, $translation);
    }

    public function testPointerFieldsAreComparedByTheLiveRecordTheyStandFor(): void
    {
        // Moving or deleting the default element (live 26233, version 26300)
        // points its translations at the version.
        $version = ['l18n_parent' => 26300, 'l10n_source' => 26300] + self::LIVE;
        $live = ['l10n_source' => 26233] + self::LIVE;
        $liveOf = static fn(int $uid): int => [26300 => 26233][$uid] ?? $uid;

        self::assertSame([], ContentChangeDetector::changedFields($version, $live, ['l18n_parent', 'l10n_source'], $liveOf));
        // Without the mapping the pointers read as edits.
        self::assertSame(
            ['l18n_parent' => sha1('26300'), 'l10n_source' => sha1('26300')],
            ContentChangeDetector::changedFields($version, $live),
        );
        // A pointer at another record is an edit, mapped or not.
        self::assertSame(
            ['l18n_parent' => sha1('99')],
            ContentChangeDetector::changedFields(['l18n_parent' => 99] + $live, $live, ['l18n_parent'], $liveOf),
        );
    }

    public function testDiffersIsWhetherAnyFieldChanged(): void
    {
        foreach ([['header' => 'Edited'] + self::LIVE, ['fe_group' => ''] + self::LIVE, ['sorting' => 512] + self::LIVE] as $version) {
            self::assertSame(
                ContentChangeDetector::changedFields($version, self::LIVE) !== [],
                ContentChangeDetector::differs($version, self::LIVE),
            );
        }
    }
}
