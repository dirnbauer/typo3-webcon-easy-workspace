<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeKind;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;
use Webconsulting\WebconEasyWorkspace\Enum\CompanionKind;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CoreWorkspaceChanges;

final class WorkspaceChangeTest extends UnitTestCase
{
    private static function owner(): WorkspaceChange
    {
        $reference = new WorkspaceChange('sys_file_reference', 204, 200, pid: 2, languageUid: 0)->withFacts(0, 0, 0, 0, []);
        // Core pointed the moved translation at the moved element's version.
        $companion = new WorkspaceChange('tt_content', 133, 101, pid: 2, isMoved: true)
            ->withFacts(4, 1, 100, 100, ['sorting' => sha1('770')], 132)
            ->withChildren([new WorkspaceChange('sys_file_reference', 205, 201, pid: 2)->withFacts(0, 1, 200, 0, [])]);

        return new WorkspaceChange('tt_content', 132, 100, pid: 2, isMoved: true)
            ->withFacts(4, 0, 0, 0, ['sorting' => sha1('768')])
            ->withChildren([$reference])
            ->withClassification(ChangeRole::Change, [$companion], [['table' => 'tt_content', 'workspaceUid' => 140, 'languageUid' => 1, 'edited' => true]], '');
    }

    #[Test]
    public function theCacheRoundTripKeepsEveryFactAndTheNestedCompanions(): void
    {
        $owner = self::owner();
        $array = CoreWorkspaceChanges::changeToArray($owner);
        $restored = CoreWorkspaceChanges::changeFromArray(json_decode(json_encode($array, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        self::assertEquals($owner, $restored);
        self::assertSame(ChangeRole::Change, $restored->role);
        self::assertSame(4, $restored->versionState);
        self::assertSame(['sorting' => sha1('768')], $restored->changedFields);
        self::assertSame(100, $restored->companions[0]->translationSource);
        self::assertSame(205, $restored->companions[0]->children[0]->workspaceUid);
        self::assertSame([['table' => 'tt_content', 'workspaceUid' => 140, 'languageUid' => 1, 'edited' => true]], $restored->dependents);
        // The stored pointer is kept where it names a version, left out
        // where it equals the mapped one.
        self::assertSame(132, $restored->companions[0]->rawTranslationParent);
        self::assertSame(132, $array['companions'][0]['rawTranslationParent'] ?? null);
        self::assertArrayNotHasKey('rawTranslationParent', $array['companions'][0]['children'][0]);
        self::assertSame(200, $restored->companions[0]->children[0]->rawTranslationParent);
    }

    #[Test]
    public function anArrayOfAnEarlierReleaseRestoresWithDefaults(): void
    {
        $restored = WorkspaceChange::fromArray(['table' => 'tt_content', 'workspaceUid' => 12, 'liveUid' => 11, 'contentChanged' => true]);

        self::assertNull($restored->role);
        self::assertSame([], $restored->companions);
        self::assertSame(0, $restored->versionState);
        self::assertSame('', $restored->copyGroup);
    }

    #[Test]
    public function aDependentOfAnEarlierPayloadIsANewTranslation(): void
    {
        $restored = WorkspaceChange::fromArray([
            'table' => 'tt_content',
            'workspaceUid' => 500,
            'liveUid' => 500,
            'translationParent' => 0,
            'dependents' => [['table' => 'tt_content', 'workspaceUid' => 501, 'languageUid' => 1]],
        ]);

        self::assertSame([['table' => 'tt_content', 'workspaceUid' => 501, 'languageUid' => 1, 'edited' => false]], $restored->dependents);
        self::assertSame(0, $restored->rawTranslationParent);
    }

    #[Test]
    public function theKindFollowsTheVersionStateThenTheChangedFields(): void
    {
        $base = new WorkspaceChange('tt_content', 2, 1);

        self::assertSame(ChangeKind::New, $base->withFacts(1, 0, 0, 0, [])->kind());
        self::assertSame(ChangeKind::Deleted, $base->withFacts(2, 0, 0, 0, ['header' => 'x'])->kind());
        self::assertSame(ChangeKind::Moved, $base->withFacts(4, 0, 0, 0, [])->kind());
        self::assertSame(ChangeKind::Edited, $base->withFacts(0, 0, 0, 0, ['header' => 'x'])->kind());
        self::assertSame(ChangeKind::Identical, $base->withFacts(0, 0, 0, 0, [])->kind());
        // contentChanged keeps its meaning: anything but an identical copy.
        self::assertFalse($base->withFacts(0, 0, 0, 0, [])->contentChanged);
        self::assertTrue($base->withFacts(2, 0, 0, 0, [])->contentChanged);
        self::assertSame(CompanionKind::Synced, CompanionKind::fromChangeKind(ChangeKind::Edited));
    }

    #[Test]
    public function aNewRecordIsNeverACompanion(): void
    {
        $this->expectException(\LogicException::class);
        CompanionKind::fromChangeKind(ChangeKind::New);
    }

    #[Test]
    public function aMovePointerMatchesTheLanguageOfItsRecordOnly(): void
    {
        $owner = self::owner();

        self::assertTrue($owner->matchesLanguage(0));
        self::assertFalse($owner->matchesLanguage(1));
        self::assertTrue($owner->companions[0]->matchesLanguage(1));
        self::assertFalse($owner->companions[0]->matchesLanguage(0));
        // A table without a language field belongs to the default language.
        self::assertTrue(new WorkspaceChange('sys_file_metadata', 61, 60)->matchesLanguage(0));
        self::assertFalse(new WorkspaceChange('sys_file_metadata', 61, 60)->matchesLanguage(1));
    }

    #[Test]
    public function aFlatRowIsCachedWithItsIdentityAndTheFactsItHasOnly(): void
    {
        $row = new WorkspaceChange('tt_content', 134, 110, pid: 2)->withFacts(0, 0, 0, 0, ['header' => sha1('Second (edited)')]);

        $array = CoreWorkspaceChanges::changeToArray($row);

        self::assertSame(
            ['table' => 'tt_content', 'workspaceUid' => 134, 'liveUid' => 110, 'pid' => 2, 'languageUid' => 0, 'changedFields' => ['header' => sha1('Second (edited)')]],
            $array,
        );
        foreach (['children', 'companions', 'role', 'dependents', 'copyGroup', 'hasUnlistedDescendants', 'hasUnlistedAncestors'] as $default) {
            self::assertArrayNotHasKey($default, $array);
        }
        self::assertEquals($row, CoreWorkspaceChanges::changeFromArray($array));
        // A nested row and an entry with companions round-trip the same way.
        $nested = new WorkspaceChange('tt_content', 134, 110)->withChildren([new WorkspaceChange('sys_file_reference', 204, 200)->withFacts(0, 0, 0, 0, [])]);
        self::assertEquals($nested, CoreWorkspaceChanges::changeFromArray(CoreWorkspaceChanges::changeToArray($nested)));
        $owner = self::owner()->withUnlistedDescendants(true);
        self::assertEquals($owner, CoreWorkspaceChanges::changeFromArray(CoreWorkspaceChanges::changeToArray($owner)));
        self::assertTrue(CoreWorkspaceChanges::changeFromArray(CoreWorkspaceChanges::changeToArray($owner))->hasUnlistedDescendants);
        $below = self::owner()->withUnlistedAncestors(true);
        self::assertEquals($below, CoreWorkspaceChanges::changeFromArray(CoreWorkspaceChanges::changeToArray($below)));
        self::assertTrue(CoreWorkspaceChanges::changeFromArray(CoreWorkspaceChanges::changeToArray($below))->hasUnlistedAncestors);
        self::assertTrue($below->reachesUnlisted());
        self::assertFalse(self::owner()->reachesUnlisted());
    }
}
