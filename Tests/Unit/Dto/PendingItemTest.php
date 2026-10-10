<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\PendingCompanion;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Dto\PendingRecordReference;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;
use Webconsulting\WebconEasyWorkspace\Enum\CompanionKind;

final class PendingItemTest extends UnitTestCase
{
    private static function item(): PendingItem
    {
        return new PendingItem(
            table: 'tt_content',
            liveUid: 158035,
            workspaceUid: 161266,
            title: 'Everything a TYPO3 client site needs',
            kindKey: 'modified',
            kindLabel: 'Modified',
            badge: 'info',
            iconIdentifier: 'mimetypes-x-content-text',
            thumbnailUrl: null,
            isPrimary: false,
            isChanged: true,
            isHidden: false,
            tableLabel: 'Page Content',
            typeLabel: 'Hero',
            editUrl: '/edit',
            publishRecords: [new PendingRecordReference('tt_content', 158035, 161266), new PendingRecordReference('sys_file_reference', 103400, 103440)],
            latestChangeUserUid: 9,
            latestChangeUser: 'Kurt',
            role: ChangeRole::Change,
            companions: [
                new PendingCompanion('tt_content', 161267, 158633, 1, CompanionKind::Identical, [], '/edit/de', '/contextual/de'),
                new PendingCompanion('tt_content', 161268, 158634, 2, CompanionKind::Synced, ['Start'], null, null),
            ],
            dependents: [['table' => 'tt_content', 'workspaceUid' => 161300, 'languageUid' => 1, 'edited' => true]],
        );
    }

    #[Test]
    public function theToolbarPayloadCarriesTheClassification(): void
    {
        $payload = self::item()->toToolbarArray();

        self::assertSame('change', $payload['role']);
        self::assertSame(9, $payload['latestChangeUserUid']);
        self::assertSame('', $payload['copyGroup']);
        self::assertSame([['table' => 'tt_content', 'workspaceUid' => 161300, 'languageUid' => 1, 'edited' => true]], $payload['dependents']);
        self::assertSame(
            [
                ['table' => 'tt_content', 'workspaceUid' => 161267, 'liveUid' => 158633, 'languageUid' => 1, 'kind' => 'identical', 'fields' => [], 'editUrl' => '/edit/de', 'contextualEditUrl' => '/contextual/de'],
                ['table' => 'tt_content', 'workspaceUid' => 161268, 'liveUid' => 158634, 'languageUid' => 2, 'kind' => 'synced', 'fields' => ['Start'], 'editUrl' => null, 'contextualEditUrl' => null],
            ],
            $payload['companions'],
        );
        self::assertSame($payload['companions'], self::item()->toClientArray()['companions']);
        self::assertSame('change', self::item()->toClientArray()['role']);
    }

    #[Test]
    public function thePublishRecordsAreTheItemAndItsNestedRecordsWithoutCompanions(): void
    {
        $records = array_map(
            static fn(array $record): string => $record['table'] . ':' . $record['workspaceUid'],
            self::item()->toToolbarArray()['publishRecords'],
        );

        self::assertSame(['tt_content:161266', 'sys_file_reference:103440'], $records);
    }

    #[Test]
    public function aLiveRecordHasNoRole(): void
    {
        $live = self::item()->with(['isChanged' => false, 'role' => null, 'companions' => [], 'dependents' => []]);

        self::assertNull($live->toToolbarArray()['role']);
        self::assertFalse($live->isChangeRole());
        self::assertTrue(self::item()->isChangeRole());
        self::assertSame('copy', self::item()->with(['role' => ChangeRole::Copy, 'copyGroup' => 'tt_content:158035'])->toToolbarArray()['role']);
    }
}
