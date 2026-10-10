<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Unit\Service\PendingItems;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CompanionClassifier;

/**
 * The cases measured on the lab (page 2228: an English hero with German,
 * Chinese and Hungarian translations; page 933: a collection element), as
 * the rows core lists for them, plus the ones derived from core's code.
 */
final class CompanionClassifierTest extends UnitTestCase
{
    private const array SORT_FIELDS = ['tt_content' => 'sorting', 'sys_file_reference' => 'sorting_foreign', 'pages' => 'sorting', 'stats_stats' => 'sorting'];

    private CompanionClassifier $subject;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new CompanionClassifier();
    }

    /**
     * @param array<string, string> $changed field => value (hashed like ContentChangeDetector does)
     * @param list<WorkspaceChange> $children
     * @param int|null $pointer The parent pointer as stored, when it names a version (null: the live parent).
     */
    private static function node(
        string $table,
        int $workspaceUid,
        int $liveUid,
        int $language = 0,
        int $parent = 0,
        int $source = 0,
        int $state = 0,
        array $changed = [],
        array $children = [],
        ?int $pointer = null,
    ): WorkspaceChange {
        return new WorkspaceChange(
            table: $table,
            workspaceUid: $workspaceUid,
            liveUid: $liveUid,
            pid: 2228,
            languageUid: $language,
            translationParent: $parent,
            isMoved: $state === 4,
        )->withFacts($state, $language, $parent, $source, array_map(sha1(...), $changed), $pointer)->withChildren($children);
    }

    /**
     * @param list<WorkspaceChange> $entries
     * @return array<string, list<array{table: string, workspaceUid: int, languageUid: int, edited: bool}>>
     */
    private static function dependents(array $entries): array
    {
        $dependents = [];
        foreach ($entries as $entry) {
            if ($entry->dependents !== []) {
                $dependents[$entry->table . ':' . $entry->workspaceUid] = $entry->dependents;
            }
        }

        return $dependents;
    }

    /**
     * The English element moved (or deleted) by an editor: core moves (or
     * deletes) the translations along and points them at the element's
     * version (161266). The German one is then edited by a translator.
     *
     * @return list<WorkspaceChange>
     */
    private static function movedWithTranslatorEdit(int $state = 4, int $germanPointer = 161266): array
    {
        $position = static fn(string $sorting): array => $state === 4 ? ['sorting' => $sorting] : [];

        return [
            self::node('tt_content', 161266, 158035, state: $state, changed: $position('768')),
            self::node('tt_content', 161267, 158633, 1, 158035, 158035, $state, [...$position('770'), 'header' => 'Alles (Übersetzer)'], pointer: $germanPointer),
            self::node('tt_content', 161268, 158634, 2, 158035, 158035, $state, $position('771'), pointer: 161266),
            self::node('tt_content', 161269, 158635, 3, 158035, 158035, $state, $position('772'), pointer: 161266),
        ];
    }

    /**
     * The English hero (live 158035) and its translations (158633 DE,
     * 158634 ZH, 158635 HU), each with its hero image reference.
     *
     * @param array<string, string> $ownerChanges
     * @param array<int, array<string, string>> $translationChanges language => changed fields
     * @return list<WorkspaceChange>
     */
    private static function hero(array $ownerChanges = ['header' => 'Everything (probe)'], array $translationChanges = [], int $state = 0): array
    {
        $entries = [self::node('tt_content', 161266, 158035, state: $state, changed: $ownerChanges, children: [
            self::node('sys_file_reference', 103440, 103400, state: $state === 4 ? 0 : $state),
        ])];
        foreach ([1 => 158633, 2 => 158634, 3 => 158635] as $language => $liveUid) {
            $entries[] = self::node(
                'tt_content',
                161266 + $language,
                $liveUid,
                $language,
                158035,
                158035,
                $state,
                $translationChanges[$language] ?? [],
                [self::node('sys_file_reference', 103440 + $language, 103400 + $language, $language, 103400, state: $state === 4 ? 0 : $state)],
            );
        }

        return $entries;
    }

    /**
     * @param list<WorkspaceChange> $entries
     * @return array<string, array{role: string|null, companions: list<string>}>
     */
    private static function summary(array $entries): array
    {
        $summary = [];
        foreach ($entries as $entry) {
            $summary[$entry->table . ':' . $entry->workspaceUid] = [
                'role' => $entry->role?->value,
                'companions' => array_map(static fn(WorkspaceChange $companion): string => $companion->table . ':' . $companion->workspaceUid, $entry->companions),
            ];
        }

        return $summary;
    }

    #[Test]
    public function aHeaderEditFoldsTheIdenticalTranslationsIntoTheEditedElement(): void
    {
        $entries = $this->subject->classify(self::hero(), self::SORT_FIELDS);

        self::assertSame(
            ['tt_content:161266' => ['role' => 'change', 'companions' => ['tt_content:161267', 'tt_content:161268', 'tt_content:161269']]],
            self::summary($entries),
        );
        // The companions keep their reference copies, the owner its own.
        self::assertSame(['sys_file_reference:103440'], array_map(static fn(WorkspaceChange $c): string => $c->table . ':' . $c->workspaceUid, $entries[0]->children));
        self::assertSame(103441, $entries[0]->companions[0]->children[0]->workspaceUid);
        self::assertSame('', $entries[0]->copyGroup);
        self::assertSame([], $entries[0]->dependents);
    }

    #[Test]
    public function aTranslatorsEditStaysAChangeOfItsOwn(): void
    {
        $entries = $this->subject->classify(self::hero(translationChanges: [1 => ['header' => 'Alles (Probe)']]), self::SORT_FIELDS);

        self::assertSame(
            [
                'tt_content:161266' => ['role' => 'change', 'companions' => ['tt_content:161268', 'tt_content:161269']],
                'tt_content:161267' => ['role' => 'change', 'companions' => []],
            ],
            self::summary($entries),
        );
    }

    #[Test]
    public function aSynchronizedValueEqualToTheOwnersIsACompanionAnotherValueIsAChange(): void
    {
        $synced = $this->subject->classify(
            self::hero(['starttime' => '1767225600'], [1 => ['starttime' => '1767225600'], 2 => ['starttime' => '1767225600'], 3 => ['starttime' => '1767225600']]),
            self::SORT_FIELDS,
        );
        self::assertCount(1, $synced);
        self::assertCount(3, $synced[0]->companions);

        $different = $this->subject->classify(
            self::hero(['starttime' => '1767225600'], [1 => ['starttime' => '1767225600'], 2 => ['starttime' => '1798761600']]),
            self::SORT_FIELDS,
        );
        self::assertSame(
            [
                'tt_content:161266' => ['role' => 'change', 'companions' => ['tt_content:161267', 'tt_content:161269']],
                'tt_content:161268' => ['role' => 'change', 'companions' => []],
            ],
            self::summary($different),
        );
    }

    #[Test]
    public function aMoveTakesItsTranslationsAlongWhateverTheirPositions(): void
    {
        // Core points the moved translations at the default element's
        // version; CoreWorkspaceChanges maps that to the live uid before.
        $entries = $this->subject->classify(
            self::hero(['sorting' => '768'], [1 => ['sorting' => '770'], 2 => ['sorting' => '771'], 3 => ['sorting' => '772']], 4),
            self::SORT_FIELDS,
        );
        self::assertSame(['tt_content:161267', 'tt_content:161268', 'tt_content:161269'], self::summary($entries)['tt_content:161266']['companions']);

        // The position is only skipped when both moved.
        $edited = self::node('tt_content', 1, 10, changed: ['sorting' => '768']);
        $moved = self::node('tt_content', 2, 11, 1, 10, state: 4, changed: ['sorting' => '770']);
        self::assertFalse(CompanionClassifier::mirrors($moved, $edited, 'sorting'));
        $editedTranslation = self::node('tt_content', 2, 11, 1, 10, changed: ['sorting' => '770']);
        self::assertFalse(CompanionClassifier::mirrors($editedTranslation, self::node('tt_content', 1, 10, state: 4, changed: ['sorting' => '768']), 'sorting'));
    }

    #[Test]
    public function aDeletionTakesItsTranslationsAlongButNotUnderAnEditedOwner(): void
    {
        $entries = $this->subject->classify(self::hero([], [], 2), self::SORT_FIELDS);
        self::assertSame(['tt_content:161267', 'tt_content:161268', 'tt_content:161269'], self::summary($entries)['tt_content:161266']['companions']);

        // A translation deleted on its own, next to an edited default element.
        [$owner, , $chinese, $hungarian] = self::hero();
        $edited = [$owner, self::node('tt_content', 161267, 158633, 1, 158035, 158035, 2), $chinese, $hungarian];
        self::assertSame(['role' => 'change', 'companions' => []], self::summary($this->subject->classify($edited, self::SORT_FIELDS))['tt_content:161267']);
    }

    #[Test]
    public function aSaveWithoutChangesIsOneCopyWithItsTranslations(): void
    {
        $entries = $this->subject->classify(self::hero([]), self::SORT_FIELDS);

        self::assertSame(
            ['tt_content:161266' => ['role' => 'copy', 'companions' => ['tt_content:161267', 'tt_content:161268', 'tt_content:161269']]],
            self::summary($entries),
        );
        self::assertSame('tt_content:158035', $entries[0]->copyGroup);
    }

    #[Test]
    public function anEntryWithVersionsTheListLeavesOutIsNeitherACopyNorACompanion(): void
    {
        // A restricted editor's list: the image references (edited by
        // someone else, outside their file mounts) are not listed.
        $entries = array_map(
            static fn(WorkspaceChange $entry): WorkspaceChange => $entry->withChildren([])->withUnlistedDescendants(true),
            self::hero([]),
        );

        self::assertSame(
            [
                'tt_content:161266' => ['role' => 'change', 'companions' => []],
                'tt_content:161267' => ['role' => 'change', 'companions' => []],
                'tt_content:161268' => ['role' => 'change', 'companions' => []],
                'tt_content:161269' => ['role' => 'change', 'companions' => []],
            ],
            self::summary($this->subject->classify($entries, self::SORT_FIELDS)),
        );
        // Only below the German translation is something left out: it stays
        // a change of its own, the other two still fold into the edit.
        $hero = self::hero();
        $hero = [$hero[0], $hero[1]->withUnlistedDescendants(true), $hero[2], $hero[3]];
        self::assertSame(
            ['tt_content:161268', 'tt_content:161269'],
            self::summary($this->subject->classify($hero, self::SORT_FIELDS))['tt_content:161266']['companions'],
        );
    }

    #[Test]
    public function anEntryBelowAVersionTheListLeavesOutIsNeitherACopyNorACompanion(): void
    {
        // The German translation lies below a version the editor's list
        // leaves out (core's publish of it would start there).
        $hero = self::hero();
        $hero = [$hero[0], $hero[1]->withUnlistedAncestors(true), $hero[2], $hero[3]];

        $summary = self::summary($this->subject->classify($hero, self::SORT_FIELDS));

        self::assertSame(['tt_content:161268', 'tt_content:161269'], $summary['tt_content:161266']['companions']);
        self::assertSame(['role' => 'change', 'companions' => []], $summary['tt_content:161267']);

        // Nothing changes anywhere, yet it is no copy for the cleanup either.
        $copies = array_map(
            static fn(WorkspaceChange $entry): WorkspaceChange => $entry->withUnlistedAncestors(true),
            self::hero([]),
        );
        foreach (self::summary($this->subject->classify($copies, self::SORT_FIELDS)) as $entry) {
            self::assertSame('change', $entry['role']);
        }
    }

    #[Test]
    public function pageTranslationsCarryingTheOwnersHookValuesAreCompanions(): void
    {
        // EXT:blog writes publish_date and crdate_month into every page
        // version, translations included, with the default page's values.
        $hook = ['publish_date' => '1790553600', 'crdate_month' => '1790553600'];
        $entries = $this->subject->classify([
            self::node('pages', 2424, 2228, changed: ['title' => 'Product (probe)'] + $hook),
            self::node('pages', 2425, 2229, 1, 2228, 2228, changed: $hook),
            self::node('pages', 2426, 2230, 2, 2228, 2228, changed: $hook),
            self::node('pages', 2427, 2231, 3, 2228, 2228, changed: $hook),
        ], self::SORT_FIELDS);

        self::assertSame(['pages:2424' => ['role' => 'change', 'companions' => ['pages:2425', 'pages:2426', 'pages:2427']]], self::summary($entries));
    }

    #[Test]
    public function aChangedItemOfAnUnchangedElementMakesItAChangeAndItsTranslationsCompanions(): void
    {
        $entries = [self::node('tt_content', 900, 26234, children: [
            self::node('stats_stats', 950, 367, changed: ['value' => '4 Tage']),
            self::node('stats_stats', 951, 368),
        ])];
        foreach ([1, 2, 3] as $language) {
            $entries[] = self::node('tt_content', 900 + $language, 26300 + $language, $language, 26234, 26234, children: [
                self::node('stats_stats', 960 + $language, 370 + $language, $language, 367),
                self::node('stats_stats', 970 + $language, 380 + $language, $language, 368),
            ]);
        }

        self::assertSame(
            ['tt_content:900' => ['role' => 'change', 'companions' => ['tt_content:901', 'tt_content:902', 'tt_content:903']]],
            self::summary($this->subject->classify($entries, self::SORT_FIELDS)),
        );
    }

    #[Test]
    public function aNewTranslationOfANewElementIsAChangeNamedOnTheElement(): void
    {
        $entries = $this->subject->classify([
            self::node('tt_content', 500, 500, state: 1),
            self::node('tt_content', 501, 501, 1, 500, 500, 1),
        ], self::SORT_FIELDS);

        self::assertSame(
            ['tt_content:500' => ['role' => 'change', 'companions' => []], 'tt_content:501' => ['role' => 'change', 'companions' => []]],
            self::summary($entries),
        );
        self::assertSame([['table' => 'tt_content', 'workspaceUid' => 501, 'languageUid' => 1, 'edited' => false]], $entries[0]->dependents);
        self::assertSame([], $entries[1]->dependents);
    }

    #[Test]
    public function aTranslationEditedAfterAMoveIsADependentWithItsEdit(): void
    {
        $entries = $this->subject->classify(self::movedWithTranslatorEdit(), self::SORT_FIELDS);

        self::assertSame(
            [
                'tt_content:161266' => ['role' => 'change', 'companions' => ['tt_content:161268', 'tt_content:161269']],
                'tt_content:161267' => ['role' => 'change', 'companions' => []],
            ],
            self::summary($entries),
        );
        // Core's discard of the move takes the German edit along: named on
        // the move, never on the translation itself.
        self::assertSame(
            ['tt_content:161266' => [['table' => 'tt_content', 'workspaceUid' => 161267, 'languageUid' => 1, 'edited' => true]]],
            self::dependents($entries),
        );
    }

    #[Test]
    public function aTranslationEditedAfterADeletionIsADependentWithItsEdit(): void
    {
        $entries = $this->subject->classify(self::movedWithTranslatorEdit(2), self::SORT_FIELDS);

        self::assertSame(['tt_content:161268', 'tt_content:161269'], self::summary($entries)['tt_content:161266']['companions']);
        self::assertSame(
            ['tt_content:161266' => [['table' => 'tt_content', 'workspaceUid' => 161267, 'languageUid' => 1, 'edited' => true]]],
            self::dependents($entries),
        );
    }

    #[Test]
    public function aTranslationPointingAtTheLiveElementIsNoDependent(): void
    {
        // Edited before the move: core leaves its pointer at the live
        // element, and its discard of the move leaves the translation alone.
        $entries = $this->subject->classify(self::movedWithTranslatorEdit(4, 158035), self::SORT_FIELDS);

        self::assertSame([], self::dependents($entries));
        // Nor does an edit's version take its translations along.
        self::assertSame([], self::dependents($this->subject->classify(self::hero(translationChanges: [1 => ['header' => 'Alles (Probe)']]), self::SORT_FIELDS)));
    }

    #[Test]
    public function aTranslatedItemEditedAfterItsCollectionMovedMakesItsCollectionADependent(): void
    {
        // The collection's items are versioned with the move, and the
        // translated items point at the items' versions.
        $entries = $this->subject->classify([
            self::node('tt_content', 900, 26234, state: 4, changed: ['sorting' => '768'], children: [
                self::node('stats_stats', 950, 367),
            ]),
            self::node('tt_content', 901, 26301, 1, 26234, 26234, 4, ['sorting' => '770'], pointer: 900, children: [
                self::node('stats_stats', 961, 371, 1, 367, changed: ['value' => '4 Tage (Übersetzer)'], pointer: 950),
            ]),
            self::node('tt_content', 902, 26302, 2, 26234, 26234, 4, ['sorting' => '771'], pointer: 900, children: [
                self::node('stats_stats', 962, 372, 2, 367, pointer: 950),
            ]),
        ], self::SORT_FIELDS);

        self::assertSame(
            [
                'tt_content:900' => ['role' => 'change', 'companions' => ['tt_content:902']],
                'tt_content:901' => ['role' => 'change', 'companions' => []],
            ],
            self::summary($entries),
        );
        self::assertSame(
            ['tt_content:900' => [['table' => 'tt_content', 'workspaceUid' => 901, 'languageUid' => 1, 'edited' => true]]],
            self::dependents($entries),
        );
    }

    #[Test]
    public function aTranslatedItemPointingAtAnItemsVersionMakesItsElementADependentOnItsOwn(): void
    {
        // The German element was edited before the move (its pointer stays
        // at the live element); its item, versioned with the move, points
        // at the English item's version and carries a translator's edit.
        $entries = $this->subject->classify([
            self::node('tt_content', 900, 26234, state: 4, changed: ['sorting' => '768'], children: [
                self::node('stats_stats', 950, 367),
            ]),
            self::node('tt_content', 901, 26301, 1, 26234, 26234, changed: ['header' => 'Zahlen'], children: [
                self::node('stats_stats', 961, 371, 1, 367, changed: ['value' => '4 Tage (Übersetzer)'], pointer: 950),
            ]),
        ], self::SORT_FIELDS);

        self::assertSame(
            ['tt_content:900' => [['table' => 'tt_content', 'workspaceUid' => 901, 'languageUid' => 1, 'edited' => true]]],
            self::dependents($entries),
        );
    }

    #[Test]
    public function leftoverTranslationCopiesAreCopiesGroupedByTheirOriginAndLeftoverValuesAreChanges(): void
    {
        $entries = $this->subject->classify([
            self::node('tt_content', 161267, 158633, 1, 158035, 158035),
            self::node('tt_content', 161268, 158634, 2, 158035, 158035),
            // Its starttime is the discarded default element's: stale or a
            // pending sync, which cannot be told without the owner.
            self::node('tt_content', 161269, 158635, 3, 158035, 158035, changed: ['starttime' => '1767225600']),
        ], self::SORT_FIELDS);

        self::assertSame(
            [
                'tt_content:161267' => ['role' => 'copy', 'companions' => []],
                'tt_content:161268' => ['role' => 'copy', 'companions' => []],
                'tt_content:161269' => ['role' => 'change', 'companions' => []],
            ],
            self::summary($entries),
        );
        self::assertSame(['tt_content:158035', 'tt_content:158035', ''], array_map(static fn(WorkspaceChange $e): string => $e->copyGroup, $entries));
    }

    #[Test]
    public function aTranslationOfATranslationFollowsItsSourceToTheOwner(): void
    {
        // Swiss German (4) translated from German (1), which mirrors English.
        $chain = [
            self::node('tt_content', 10, 100, changed: ['header' => 'Hero (edited)']),
            self::node('tt_content', 11, 101, 1, 100, 100),
            self::node('tt_content', 14, 104, 4, 100, 101),
        ];
        self::assertSame(
            ['tt_content:10' => ['role' => 'change', 'companions' => ['tt_content:11', 'tt_content:14']]],
            self::summary($this->subject->classify($chain, self::SORT_FIELDS)),
        );

        // The source comes first: Swiss German mirrors the German translator's edit.
        $sourceEdited = [
            self::node('tt_content', 10, 100, changed: ['header' => 'Hero (edited)']),
            self::node('tt_content', 11, 101, 1, 100, 100, changed: ['bodytext' => 'Neu']),
            self::node('tt_content', 14, 104, 4, 100, 101, changed: ['bodytext' => 'Neu']),
        ];
        self::assertSame(
            ['tt_content:10' => ['role' => 'change', 'companions' => []], 'tt_content:11' => ['role' => 'change', 'companions' => ['tt_content:14']]],
            self::summary($this->subject->classify($sourceEdited, self::SORT_FIELDS)),
        );

        // Two translations made from each other: neither knows which one
        // carries the edit, so neither folds into the other.
        $cycle = [
            self::node('tt_content', 21, 201, 1, 0, 202),
            self::node('tt_content', 22, 202, 2, 0, 201),
        ];
        self::assertSame(
            ['tt_content:21' => ['role' => 'copy', 'companions' => []], 'tt_content:22' => ['role' => 'copy', 'companions' => []]],
            self::summary($this->subject->classify($cycle, self::SORT_FIELDS)),
        );
    }

    #[Test]
    public function aTranslatedItemOfAnUnversionedTranslationFindsItsOwnerThroughTheNestedItem(): void
    {
        // Only the item of the default element changed; the German element
        // was not versioned, so its item copy is a top-level row.
        $entries = $this->subject->classify([
            self::node('tt_content', 900, 26234, children: [self::node('stats_stats', 950, 367, changed: ['value' => '4 Tage'])]),
            self::node('stats_stats', 961, 371, 1, 367, changed: ['value' => '4 Tage']),
        ], self::SORT_FIELDS);

        self::assertSame(['tt_content:900' => ['role' => 'change', 'companions' => ['stats_stats:961']]], self::summary($entries));
    }

    #[Test]
    public function aTranslationWithANewOrEditedItemOfItsOwnIsAChange(): void
    {
        $owner = self::node('tt_content', 10, 100, changed: ['header' => 'Hero (edited)'], children: [self::node('sys_file_reference', 50, 500)]);
        $withNewReference = self::node('tt_content', 11, 101, 1, 100, 100, children: [self::node('sys_file_reference', 51, 51, 1, 500, state: 1)]);
        $withEditedItem = self::node('tt_content', 12, 102, 2, 100, 100, children: [self::node('sys_file_reference', 52, 502, 2, 500, changed: ['title' => 'Bild'])]);

        self::assertSame(
            [
                'tt_content:10' => ['role' => 'change', 'companions' => []],
                'tt_content:11' => ['role' => 'change', 'companions' => []],
                'tt_content:12' => ['role' => 'change', 'companions' => []],
            ],
            self::summary($this->subject->classify([$owner, $withNewReference, $withEditedItem], self::SORT_FIELDS)),
        );

        // An origin uid of another table never matches.
        $page = self::node('pages', 7, 100, changed: ['title' => 'Edited']);
        $element = self::node('tt_content', 11, 101, 1, 100, 100);
        self::assertSame(
            ['pages:7' => ['role' => 'change', 'companions' => []], 'tt_content:11' => ['role' => 'copy', 'companions' => []]],
            self::summary($this->subject->classify([$page, $element], self::SORT_FIELDS)),
        );
    }

    #[Test]
    public function filteringTheClassifiedEntriesByLanguageKeepsTheCompanionsWithTheirOwner(): void
    {
        $entries = $this->subject->classify(self::hero(translationChanges: [1 => ['header' => 'Alles (Probe)']]), self::SORT_FIELDS);

        $english = array_values(array_filter($entries, static fn(WorkspaceChange $e): bool => $e->matchesLanguage(0)));
        $german = array_values(array_filter($entries, static fn(WorkspaceChange $e): bool => $e->matchesLanguage(1)));
        $chinese = array_values(array_filter($entries, static fn(WorkspaceChange $e): bool => $e->matchesLanguage(2)));

        self::assertSame(['tt_content:161266' => ['role' => 'change', 'companions' => ['tt_content:161268', 'tt_content:161269']]], self::summary($english));
        self::assertSame(['tt_content:161267' => ['role' => 'change', 'companions' => []]], self::summary($german));
        // The Chinese copy belongs to the English change: no orphan copy.
        self::assertSame([], $chinese);
        self::assertSame(ChangeRole::Change, $english[0]->role);
    }
}
