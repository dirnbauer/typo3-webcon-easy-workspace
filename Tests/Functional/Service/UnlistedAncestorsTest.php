<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Enum\ChangeRole;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CompanionResolver;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CoreWorkspaceChanges;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;

/**
 * Core's publish and stage change go up as well as down: they start at a
 * record's outermost parent and act on everything nested below it. A
 * translated collection item below a translated element someone else edited
 * is, for an editor whose list leaves elements out, a top-level row that
 * mirrors the edited default item — and publishing it would take the other
 * editor's element and its other items live. Such a row is never a
 * companion, and a write never takes it along.
 */
final class UnlistedAncestorsTest extends FunctionalTestCase
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
        // Collection items, no content elements.
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 1, 'pid' => 0, 'title' => 'Item editors',
            'tables_select' => 'pages,tx_easyws_item',
            'tables_modify' => 'pages,tx_easyws_item',
            'db_mountpoints' => '1',
            'pagetypes_select' => '1',
            'non_exclude_fields' => 'tx_easyws_item:sys_language_uid,tx_easyws_item:l10n_parent,tx_easyws_item:hidden',
            'allowed_languages' => '0,1,2,3',
            'workspace_perms' => 1,
        ]);
        $pool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '1', 'workspace_perms' => 1], ['uid' => 5]);
        $pool->getConnectionForTable('pages')->executeStatement('UPDATE pages SET perms_everybody = 31, perms_user = 31, perms_group = 31');

        // A translator edits the German collection and its second item (core
        // versions its items along), an administrator the English first item.
        $this->editInWorkspace(['tt_content' => [131 => ['header' => 'Sammlung (Übersetzer)']]]);
        $this->editInWorkspace(['tx_easyws_item' => [311 => ['header' => 'Zweiter Eintrag (Übersetzer)']]]);
        $this->editInWorkspace(['tx_easyws_item' => [300 => ['header' => 'Item (edited)']]]);
    }

    private function itemEditor(): BackendUserAuthentication
    {
        $editor = $this->setUpBackendUser(5);
        $editor->setWorkspace(1);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));

        return $editor;
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
    public function anAdministratorSeesTheTranslatedItemsBelowTheTranslatedElement(): void
    {
        $entries = $this->entries();

        self::assertSame(['tt_content:131', 'tx_easyws_item:300'], array_keys($entries));
        self::assertFalse($entries['tt_content:131']->hasUnlistedAncestors);
        self::assertSame(['tx_easyws_item:301', 'tx_easyws_item:311'], array_map(
            static fn(WorkspaceChange $child): string => $child->table . ':' . $child->liveUid,
            $entries['tt_content:131']->children,
        ));
        self::assertSame([], $entries['tx_easyws_item:300']->companions);
    }

    #[Test]
    public function anItemBelowAVersionTheEditorCannotSeeIsNoCompanion(): void
    {
        $this->itemEditor();
        $entries = $this->entries();

        $german = $entries['tx_easyws_item:301'] ?? null;
        self::assertInstanceOf(WorkspaceChange::class, $german, 'a row of its own for the editor');
        self::assertTrue($german->hasUnlistedAncestors);
        self::assertSame(ChangeRole::Change, $german->role, 'never folded in, never a copy');
        self::assertSame([], $entries['tx_easyws_item:300']->companions ?? null);
    }

    #[Test]
    public function publishingTheEditedItemLeavesTheOtherEditorsElementInTheWorkspace(): void
    {
        $editor = $this->itemEditor();

        $result = $this->get(PublishSelectedService::class)->publish(
            [['table' => 'tx_easyws_item', 'workspaceUid' => $this->versionOf('tx_easyws_item', 300)]],
            $editor,
        );

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(0, $result['companions']);
        self::assertSame('Item (edited)', $this->liveRow('tx_easyws_item', 300)['header']);
        self::assertGreaterThan(0, $this->versionOf('tt_content', 131), 'the translator\'s element stays in the workspace');
        self::assertSame('Sammlung', $this->liveRow('tt_content', 131)['header']);
        self::assertGreaterThan(0, $this->versionOf('tx_easyws_item', 301));
        self::assertGreaterThan(0, $this->versionOf('tx_easyws_item', 311));
        self::assertSame('Zweiter Eintrag', $this->liveRow('tx_easyws_item', 311)['header']);
    }

    /**
     * The German item as the editor's list has it: a row of its own, or —
     * in a list that folded it in — a companion of the English one.
     */
    private function germanItem(): WorkspaceChange
    {
        $entries = $this->entries();
        $german = $entries['tx_easyws_item:301'] ?? null;
        foreach ($entries['tx_easyws_item:300']->companions ?? [] as $companion) {
            $german ??= $companion->liveUid === 301 ? $companion : null;
        }
        self::assertInstanceOf(WorkspaceChange::class, $german);

        return $german->withUnlistedAncestors(false);
    }

    #[Test]
    public function aCompanionListClaimingTheItemIsNotTrustedBeforeAWrite(): void
    {
        $this->itemEditor();
        $german = $this->germanItem();
        $owner = $this->entries()['tx_easyws_item:300'] ?? null;
        self::assertInstanceOf(WorkspaceChange::class, $owner);
        // What a list without the ancestor check folded in.
        $claimed = $owner->withClassification(ChangeRole::Change, [$german], [], '');

        $verification = $this->get(CompanionResolver::class)->verifiedCompanions(1, $claimed);

        self::assertSame([], $verification['verified']);
        self::assertSame([301], array_map(static fn(WorkspaceChange $companion): int => $companion->liveUid, $verification['skipped']));
    }

    #[Test]
    public function aCleanupRequestNamingTheItemIsRefused(): void
    {
        $editor = $this->itemEditor();
        $asCopy = $this->germanItem()->withClassification(ChangeRole::Copy, [], [], 'tx_easyws_item:300');

        $result = $this->get(PublishSelectedService::class)->discardCopies([$asCopy], $editor);

        self::assertSame(0, $result['discarded']);
        self::assertSame(1, $result['skipped']);
        self::assertGreaterThan(0, $this->versionOf('tx_easyws_item', 301));
    }
}
