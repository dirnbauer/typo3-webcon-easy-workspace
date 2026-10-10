<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CompanionResolver;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CoreWorkspaceChanges;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;

/**
 * Core lists the versions an editor may see — a file reference whose file is
 * outside the editor's file mounts, or missing, is left out — while its
 * publish and discard act on every version nested below a record. An entry
 * with such a version below it is never a copy or a companion, and a write
 * never takes a record along whose versions below it the list did not hold.
 */
final class UnlistedDescendantsTest extends FunctionalTestCase
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
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 1, 'pid' => 0, 'title' => 'Editors',
            'tables_select' => 'pages,tt_content,sys_file_reference,tx_easyws_item',
            'tables_modify' => 'pages,tt_content,sys_file_reference,tx_easyws_item',
            'db_mountpoints' => '1',
            'pagetypes_select' => '1',
            'non_exclude_fields' => 'tt_content:sys_language_uid,tt_content:l18n_parent,tt_content:hidden,tt_content:starttime,sys_file_reference:alternative,sys_file_reference:sys_language_uid,sys_file_reference:l10n_parent,sys_file_reference:hidden',
            'allowed_languages' => '0,1,2,3',
            'explicit_allowdeny' => 'tt_content:CType:text,tt_content:CType:easyws_collection',
            'workspace_perms' => 1,
        ]);
        $pool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '1', 'workspace_perms' => 1], ['uid' => 5]);
        $pool->getConnectionForTable('pages')->executeStatement('UPDATE pages SET perms_everybody = 31, perms_user = 31, perms_group = 31');
    }

    /**
     * A restricted editor who may read and write files but has no file mount
     * covering hero.jpg — what core's StoragePermissionsAspect sets up for a
     * non-admin in a backend request.
     */
    private function editorWithoutTheFileMount(): BackendUserAuthentication
    {
        $editor = $this->setUpBackendUser(5);
        $editor->setWorkspace(1);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $storage = $this->get(StorageRepository::class)->getStorageObject(1);
        $storage->setEvaluatePermissions(true);
        $storage->setUserPermissions(['readFile' => true, 'writeFile' => true, 'editMeta' => true]);

        return $editor;
    }

    private function admin(): BackendUserAuthentication
    {
        $admin = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $admin);

        return $admin;
    }

    /**
     * @return array<string, WorkspaceChange> by table:liveUid
     */
    private function entries(): array
    {
        $entries = [];
        foreach ($this->get(CoreWorkspaceChanges::class)->entries(1, 2) as $entry) {
            $entries[$entry->table . ':' . $entry->liveUid] = $entry;
        }

        return $entries;
    }

    #[Test]
    public function anEditBelowACopyTheEditorCannotSeeIsNeitherOfferedNorCleanedUp(): void
    {
        // An administrator edits the hero image's alternative text.
        $this->editInWorkspace([
            'tt_content' => [100 => ['header' => 'Hero']],
            'sys_file_reference' => [200 => ['alternative' => 'New alt text']],
        ]);
        $reference = $this->versionOf('sys_file_reference', 200);
        self::assertGreaterThan(0, $reference);
        self::assertSame(ChangeRole::Change, $this->entries()['tt_content:100']->role ?? null);

        $editor = $this->editorWithoutTheFileMount();
        $entries = $this->entries();

        $hero = $entries['tt_content:100'] ?? null;
        self::assertInstanceOf(WorkspaceChange::class, $hero);
        self::assertTrue($hero->hasUnlistedDescendants);
        self::assertSame([], $hero->children, 'the editor does not see the reference');
        self::assertSame(ChangeRole::Change, $hero->role, 'never a copy offered for cleanup');
        self::assertSame([], $hero->companions);

        // A cleanup request naming it anyway (a list of an earlier release,
        // an API caller) is refused on the server.
        $asCopy = $hero->withClassification(ChangeRole::Copy, [], [], 'tt_content:100');
        $result = $this->get(PublishSelectedService::class)->discardCopies([$asCopy], $editor);

        self::assertSame(0, $result['discarded']);
        self::assertSame(1, $result['skipped']);
        self::assertSame($reference, $this->versionOf('sys_file_reference', 200), 'the administrator\'s edit stays');
    }

    #[Test]
    public function aTranslationWithAnEditBelowItTheEditorCannotSeeIsNeverTakenAlong(): void
    {
        // An administrator edits the German image's alternative text, then
        // the English header.
        $this->editInWorkspace([
            'tt_content' => [101 => ['header' => 'Held']],
            'sys_file_reference' => [201 => ['alternative' => 'Neuer Alternativtext']],
        ]);
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $germanReference = $this->versionOf('sys_file_reference', 201);
        $adminHero = $this->entries()['tt_content:100'] ?? null;
        self::assertInstanceOf(WorkspaceChange::class, $adminHero);

        $editor = $this->editorWithoutTheFileMount();
        $entries = $this->entries();
        self::assertSame(ChangeRole::Change, $entries['tt_content:101']->role ?? null, 'the German translation is no companion for the editor');
        self::assertNotContains(101, array_map(static fn(WorkspaceChange $companion): int => $companion->liveUid, $entries['tt_content:100']->companions ?? []));

        // The check before a write: a companion list that claims the German
        // copy (as the editor's list would without its version below it) is
        // not trusted.
        $claimed = $adminHero->withClassification(ChangeRole::Change, [$entries['tt_content:101']], [], '');
        $verification = $this->get(CompanionResolver::class)->verifiedCompanions(1, $claimed);
        self::assertSame([], $verification['verified']);
        self::assertSame([101], array_map(static fn(WorkspaceChange $companion): int => $companion->liveUid, $verification['skipped']));

        $result = $this->get(PublishSelectedService::class)->discard('tt_content', $this->versionOf('tt_content', 100), $editor);

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertGreaterThan(0, $this->versionOf('tt_content', 101));
        self::assertSame($germanReference, $this->versionOf('sys_file_reference', 201), 'the administrator\'s German edit stays');
    }

    #[Test]
    public function aReferenceToAMissingFileKeepsItsTranslationOutOfPublishAndDiscard(): void
    {
        $this->editInWorkspace([
            'tt_content' => [101 => ['header' => 'Held']],
            'sys_file_reference' => [201 => ['alternative' => 'Neuer Alternativtext']],
        ]);
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);
        $germanReference = $this->versionOf('sys_file_reference', 201);
        // The file is gone: core lists none of its references, an
        // administrator's included.
        unlink(Environment::getPublicPath() . '/fileadmin/hero.jpg');
        $this->get(CacheManager::class)->getCache('webcon_easy_workspace')->flush();

        $entries = $this->entries();
        foreach (['tt_content:100', 'tt_content:101', 'tt_content:102', 'tt_content:103'] as $key) {
            self::assertSame(ChangeRole::Change, $entries[$key]->role ?? null, $key);
            self::assertSame([], $entries[$key]->companions, $key);
        }

        $result = $this->get(PublishSelectedService::class)->publish(
            [['table' => 'tt_content', 'workspaceUid' => $this->versionOf('tt_content', 100)]],
            $this->admin(),
        );

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(0, $result['companions']);
        self::assertSame('Hero (edited)', $this->liveRow('tt_content', 100)['header']);
        self::assertGreaterThan(0, $this->versionOf('tt_content', 101), 'the German translation did not go live along');
        self::assertSame($germanReference, $this->versionOf('sys_file_reference', 201));
        self::assertNotSame('Neuer Alternativtext', $this->liveRow('sys_file_reference', 201)['alternative']);
    }
}
