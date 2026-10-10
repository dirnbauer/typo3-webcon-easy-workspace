<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository;
use TYPO3\CMS\Workspaces\Service\GridDataService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeKind;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CompanionResolver;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CoreWorkspaceChanges;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceChangeCounter;
use Webconsulting\WebconEasyWorkspace\Service\WorkspaceRevision;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The classified entries for what an editor really does: the translation
 * copies TYPO3 makes along with a save fold into the edited entry, on a page
 * and in the whole workspace alike, with the module's rows all accounted for.
 */
final class CoreWorkspaceChangesCompanionTest extends FunctionalTestCase
{
    use TranslationCompanionScenario;

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/inline_stub',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCompanionScenario();
    }

    /**
     * @param list<WorkspaceChange> $entries
     * @return array<string, array{role: string|null, kind: string, companions: list<string>}>
     */
    private static function summary(array $entries): array
    {
        $summary = [];
        foreach ($entries as $entry) {
            $summary[$entry->table . ':' . $entry->liveUid] = [
                'role' => $entry->role?->value,
                'kind' => $entry->kind()->value,
                'companions' => array_map(
                    static fn(WorkspaceChange $companion): string => $companion->table . ':' . $companion->liveUid . '/' . $companion->kind()->value,
                    $entry->companions,
                ),
            ];
        }

        return $summary;
    }

    #[Test]
    public function aHeaderEditIsOneChangeWithItsThreeTranslations(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $entries = $this->get(CoreWorkspaceChanges::class)->entries(1, 2);

        self::assertSame(
            ['tt_content:100' => ['role' => 'change', 'kind' => 'edited', 'companions' => ['tt_content:101/identical', 'tt_content:102/identical', 'tt_content:103/identical']]],
            self::summary($entries),
        );
        // Each copy keeps its image reference copy, the change its own.
        self::assertSame('sys_file_reference', $entries[0]->children[0]->table);
        self::assertSame(201, $entries[0]->companions[0]->children[0]->liveUid);
    }

    #[Test]
    public function synchronizedValuesMovesDeletionsAndPagesFoldTheSameWay(): void
    {
        $changes = $this->get(CoreWorkspaceChanges::class);

        $this->editInWorkspace(['tt_content' => [110 => ['starttime' => 1767225600]]]);
        self::assertSame(
            ['role' => 'change', 'kind' => 'edited', 'companions' => ['tt_content:111/edited', 'tt_content:112/edited', 'tt_content:113/edited']],
            self::summary($changes->entries(1, 2))['tt_content:110'],
        );

        $this->editInWorkspace([], ['tt_content' => [100 => ['move' => -120]]]);
        self::assertSame(
            ['role' => 'change', 'kind' => 'moved', 'companions' => ['tt_content:101/moved', 'tt_content:102/moved', 'tt_content:103/moved']],
            self::summary($changes->entries(1, 2))['tt_content:100'],
        );

        $this->editInWorkspace([], ['tt_content' => [120 => ['delete' => 1]]]);
        self::assertSame(
            ['role' => 'change', 'kind' => 'deleted', 'companions' => ['tt_content:121/deleted']],
            self::summary($changes->entries(1, 2))['tt_content:120'],
        );

        $this->editInWorkspace(['pages' => [2 => ['title' => 'Translated page (edited)']]]);
        self::assertSame(
            ['role' => 'change', 'kind' => 'edited', 'companions' => ['pages:3/identical', 'pages:4/identical', 'pages:5/identical']],
            self::summary($changes->entries(1, 2))['pages:2'],
        );
        self::assertCount(4, $changes->entries(1, 2), 'four changes, no translation of its own');
    }

    #[Test]
    public function movePointersCarryTheirLanguageParentAndState(): void
    {
        $this->editInWorkspace([], ['tt_content' => [100 => ['move' => -120]]]);

        $rows = [];
        foreach ($this->get(CoreWorkspaceChanges::class)->rows(1) as $row) {
            $rows[$row->table . ':' . $row->liveUid] = $row;
        }

        $german = $rows['tt_content:101'];
        self::assertTrue($german->isMoved);
        self::assertSame(1, $german->languageUid);
        self::assertSame(4, $german->versionState);
        // Core points it at the default element's version; the fact is the live uid.
        self::assertSame(100, $german->translationParent);
        self::assertSame(100, $german->translationSource);
        // Its image reference, pointed at the version of the default
        // element's reference, is no change after that mapping either.
        self::assertSame(ChangeKind::Identical, $rows['sys_file_reference:201']->kind());
        self::assertFalse($rows['sys_file_reference:201']->contentChanged);
    }

    #[Test]
    public function theWholeWorkspaceAndThePageAgree(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $this->editInWorkspace(['tt_content' => [111 => ['header' => 'Zweites (bearbeitet)']]]);
        $changes = $this->get(CoreWorkspaceChanges::class);

        self::assertSame(self::summary($changes->entries(1)), self::summary($changes->entries(1, 2)));
        self::assertSame([], $changes->entries(1, 6));
        // A language filter keeps the copies with their change.
        self::assertSame(['tt_content:100'], array_keys(self::summary($changes->entries(1, 2, 0))));
        self::assertSame(['tt_content:111'], array_keys(self::summary($changes->entries(1, 2, 1))));
        self::assertSame([], $changes->entries(1, 2, 2), 'the Chinese copy belongs to the English change');
    }

    #[Test]
    public function everyRowTheModuleListsIsAChangeACompanionOrACopy(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $this->editInWorkspace(['tt_content' => [110 => ['header' => 'Second']]]);
        $this->editInWorkspace(['tt_content' => [111 => ['header' => 'Zweites (bearbeitet)']]]);
        $this->editInWorkspace(['tt_content' => [130 => ['header' => 'Collection']], 'tx_easyws_item' => [300 => ['header' => 'Item (edited)']]]);

        $toolbarRows = [];
        $roles = [];
        foreach ($this->get(CoreWorkspaceChanges::class)->entries(1) as $entry) {
            $toolbarRows[] = $entry->table . ':' . $entry->workspaceUid;
            $roles[$entry->table . ':' . $entry->liveUid] = $entry->role;
            foreach ($entry->companions as $companion) {
                $toolbarRows[] = $companion->table . ':' . $companion->workspaceUid;
            }
        }
        $moduleRows = $this->moduleRows();
        sort($toolbarRows);
        sort($moduleRows);

        self::assertSame($moduleRows, $toolbarRows);
        self::assertSame(ChangeRole::Change, $roles['tt_content:100']);
        // The no-op save of "Second" is a copy, with the copies of its
        // Chinese and Hungarian translations; the German one was edited.
        self::assertSame(ChangeRole::Copy, $roles['tt_content:110']);
        self::assertSame(ChangeRole::Change, $roles['tt_content:111']);
        self::assertSame(ChangeRole::Change, $roles['tt_content:130'], 'its item changed');

        $count = $this->get(WorkspaceChangeCounter::class)->count(1);
        self::assertSame(3, $count->total);
        self::assertSame(count($moduleRows), $count->versionCount);
        self::assertSame(3, $count->copyCount, 'Second and its Chinese and Hungarian copies');
    }

    #[Test]
    public function aLiveFrontendRenderLeavesThePageTranslationsFoldedIntoTheirPage(): void
    {
        $pool = $this->get(ConnectionPool::class);
        // Each language of the live page with the stamp its last render left.
        $pool->getConnectionForTable('pages')->update('pages', ['SYS_LASTCHANGED' => 1791056338], ['uid' => 2]);
        foreach ([3, 4, 5] as $uid) {
            $pool->getConnectionForTable('pages')->update('pages', ['SYS_LASTCHANGED' => 1791056462], ['uid' => $uid]);
        }
        $this->editInWorkspace(['pages' => [2 => ['title' => 'Translated page (edited)']]]);
        // A live render writes the stamp into the live rows without
        // DataHandler (RequestHandler::updateSysLastChangedInPageRecord()):
        // the revision does not move.
        $pool->getConnectionForTable('pages')->update('pages', ['SYS_LASTCHANGED' => 1791100000], ['uid' => 2]);
        foreach ([3, 4, 5] as $uid) {
            $pool->getConnectionForTable('pages')->update('pages', ['SYS_LASTCHANGED' => 1791100005], ['uid' => $uid]);
        }
        $this->get(CacheManager::class)->getCache('webcon_easy_workspace')->flush();

        $pages = array_values(array_filter(
            $this->get(CoreWorkspaceChanges::class)->entries(1, 2),
            static fn(WorkspaceChange $entry): bool => $entry->table === 'pages',
        ));

        self::assertCount(1, $pages, 'no page translation on its own');
        self::assertSame(['title'], array_keys($pages[0]->changedFields));
        self::assertCount(3, $pages[0]->companions);
        self::assertCount(3, $this->get(CompanionResolver::class)->verifiedCompanions(1, $pages[0])['verified']);
    }

    #[Test]
    public function freshFactsAreReadForMoreVersionsThanOneChunk(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $live = [];
        $versions = [];
        $nodes = [];
        foreach (range(1, 520) as $offset) {
            $live[] = [1000 + $offset, 2, 'Element ' . $offset, 0, 0, 0, 0];
            $versions[] = [5000 + $offset, 2, $offset === 520 ? 'Element 520 (edited)' : 'Element ' . $offset, 1000 + $offset, 1, 0, 0];
            $nodes[] = new WorkspaceChange('tt_content', 5000 + $offset, 1000 + $offset);
        }
        $columns = ['uid', 'pid', 'header', 't3ver_oid', 't3ver_wsid', 't3ver_state', 'deleted'];
        $pool->getConnectionForTable('tt_content')->bulkInsert('tt_content', $live, $columns);
        $pool->getConnectionForTable('tt_content')->bulkInsert('tt_content', $versions, $columns);

        $fresh = $this->get(CoreWorkspaceChanges::class)->refreshFacts(1, $nodes);

        self::assertCount(520, $fresh);
        self::assertSame(['header'], array_keys($fresh['tt_content:5520']->changedFields));
        self::assertSame(ChangeKind::Identical, $fresh['tt_content:5001']->kind());
    }

    #[Test]
    public function anEntriesCacheOfAnEarlierReleaseIsIgnored(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        // What 1.10 cached under its identifier: four unclassified rows.
        $legacy = array_map(
            static fn(int $uid): array => ['table' => 'tt_content', 'workspaceUid' => $uid, 'liveUid' => $uid, 'contentChanged' => true, 'children' => []],
            [1, 2, 3, 4],
        );
        $this->get(CacheManager::class)->getCache('webcon_easy_workspace')->set(
            sha1(implode('|', ['entries', 1, 2, 'all', Value::int($user->user['uid'] ?? null)])),
            ['revision' => $this->get(WorkspaceRevision::class)->current(1), 'expires' => time() + 300, 'entries' => $legacy],
        );

        self::assertSame(['tt_content:100'], array_keys(self::summary($this->get(CoreWorkspaceChanges::class)->entries(1, 2))));
    }

    /**
     * The Workspaces module's top-level rows for the whole workspace.
     *
     * @return list<string>
     */
    private function moduleRows(): array
    {
        $backendUser = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        $versions = $this->get(WorkspaceService::class)->selectVersionsInWorkspace(1, -99, -1, 0, 'tables_select');
        $stages = $this->get(WorkspaceStageRepository::class)->findAllStagesByWorkspace(
            $backendUser,
            $this->get(WorkspaceRepository::class)->findByUid(1),
        );
        /** @var array{total: int, data: list<array<string, mixed>>} $grid */
        $grid = $this->get(GridDataService::class)->generateGridListFromVersions(
            $stages,
            $versions,
            (object)['start' => 0, 'limit' => 1000, 'id' => -1, 'depth' => 0],
        );
        $rows = [];
        foreach ($grid['data'] as $row) {
            if (Value::int($row['Workspaces_CollectionLevel'] ?? null) === 0) {
                $rows[] = Value::string($row['table'] ?? null) . ':' . Value::int($row['uid'] ?? null);
            }
        }

        return $rows;
    }
}
