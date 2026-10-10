<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Controller\SetupModuleController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\UserSettingsSchema;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Backend\ToolbarItem\EasyWorkspaceToolbarItem;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The toolbar markup carries the server count.
 *
 * BadgeSync seeds itself from these attributes, so the badge is right in
 * the first paint and a failing badge request cannot blank it. The count
 * is the current page's — which the server only knows when the backend
 * request names one (?id=).
 */
final class EasyWorkspaceToolbarItemTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/news_stub',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/ChangeCounterScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    private function toolbarItemInWorkspace(int $workspaceId, int $pageUid = 0): EasyWorkspaceToolbarItem
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace($workspaceId);
        $GLOBALS['BE_USER'] = $backendUser;
        // Core's BackendUserAuthenticator middleware does this for a real request.
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($workspaceId));

        $toolbarItem = $this->get(EasyWorkspaceToolbarItem::class);
        $toolbarItem->setRequest(
            new ServerRequest('https://typo3-testing.local/typo3/main')
                ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
                ->withAttribute('backend.user', $backendUser)
                ->withQueryParams($pageUid > 0 ? ['id' => $pageUid] : []),
        );

        return $toolbarItem;
    }

    #[Test]
    public function rendersThePageCountIntoTheBadgeMarkup(): void
    {
        $markup = $this->toolbarItemInWorkspace(4, 10)->getItem();

        self::assertStringContainsString('data-wew-count="3"', $markup);
        self::assertStringContainsString('data-wew-workspace="4"', $markup);
        self::assertStringNotContainsString('toolbar-item-badge badge badge-pill badge-warning hidden', $markup);
        self::assertMatchesRegularExpression('/data-wew-workspace-badge[^>]*>\\s*3\\s*</', $markup);
    }

    #[Test]
    public function countsOnlyTheCurrentPageNotTheWholeWorkspace(): void
    {
        // Workspace 4 has three pending changes, all of them on page 10.
        // Page 1 therefore reports nothing even though the workspace is busy.
        $markup = $this->toolbarItemInWorkspace(4, 1)->getItem();

        self::assertStringContainsString('data-wew-count="0"', $markup);
        self::assertStringContainsString('badge-warning hidden', $markup);
    }

    #[Test]
    public function rendersAnEmptyBadgeWithoutAPageOrInLive(): void
    {
        // No ?id=: the page is only knowable client-side, so the badge stays
        // empty until BadgeSync reports the page it detected.
        $withoutPage = $this->toolbarItemInWorkspace(4)->getItem();
        self::assertStringContainsString('data-wew-count="0"', $withoutPage);
        self::assertStringContainsString('badge-warning hidden', $withoutPage);

        $live = $this->toolbarItemInWorkspace(0, 10)->getItem();
        self::assertStringContainsString('data-wew-count="0"', $live);
        self::assertStringContainsString('data-wew-workspace="0"', $live);
        self::assertStringContainsString('badge-warning hidden', $live);
    }

    #[Test]
    public function theDropdownConfigCarriesTheEditorsLayout(): void
    {
        $toolbarItem = $this->toolbarItemInWorkspace(4, 10);
        // What saving the User Settings form does.
        $backendUser = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        $backendUser->uc['webconEasyWorkspaceLayout'] = 'efficiency';
        $backendUser->writeUC();

        self::assertSame('efficiency', $this->dropdownConfig($toolbarItem)['layout'] ?? null);
    }

    #[Test]
    public function withoutAChoiceTheLayoutIsTheTsConfigDefault(): void
    {
        self::assertSame('clarity', $this->dropdownConfig($this->toolbarItemInWorkspace(4, 10))['layout'] ?? null);
    }

    #[Test]
    public function theLayoutIsAUserSettingFirstInTheEasyWorkspaceTab(): void
    {
        $schema = $this->get(UserSettingsSchema::class);

        self::assertTrue($schema->isJsonFieldSetting('webconEasyWorkspaceLayout'));
        // Core sends a persistent update as 0/1: a checkbox's, never a select's.
        self::assertNotContains('webconEasyWorkspaceLayout', $schema->getPersistentUpdateFieldNames());
        $items = $GLOBALS['TCA']['be_users']['columns']['user_settings']['columns']['webconEasyWorkspaceLayout']['config']['items'] ?? [];
        self::assertSame(['', 'clarity', 'efficiency', 'core'], array_column(is_array($items) ? $items : [], 'value'));
        // Right after the switch that turns Easy Workspace on.
        $showitem = array_map('trim', explode(',', $schema->getRawShowitem()));
        $enabled = array_search('webconEasyWorkspaceEnabled', $showitem, true);
        self::assertIsInt($enabled);
        self::assertSame('webconEasyWorkspaceLayout', $showitem[$enabled + 1] ?? null);
    }

    #[Test]
    public function aLayoutSavedInTheUserSettingsFormSticksAndSiteDefaultFollowsTsConfig(): void
    {
        $updates = $this->saveUserSettingsForm('efficiency');

        self::assertNotContains('webconEasyWorkspaceLayout', array_column($updates, 'fieldName'));
        self::assertSame('efficiency', $this->dropdownConfig($this->toolbarItemInWorkspace(4, 10))['layout'] ?? null);

        // "Site default" (what a save without a choice stores) hands the
        // choice back to TSconfig.
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')
            ->update('be_users', ['TSconfig' => 'options.webcon_easy_workspace.layout = core'], ['uid' => 1]);
        $this->saveUserSettingsForm('');

        self::assertSame('core', $this->dropdownConfig($this->toolbarItemInWorkspace(4, 10))['layout'] ?? null);
    }

    /**
     * Saves the User Settings form as core's SetupModuleController stores
     * it, and returns the persistent updates it would send to the backend's
     * JavaScript (BackendUtility::setUpdateSignal() does nothing on CLI).
     *
     * @return list<array{fieldName: string, value: string}>
     */
    private function saveUserSettingsForm(string $layout): array
    {
        $backendUser = $this->setUpBackendUser(1);
        $request = new ServerRequest('https://typo3-testing.local/typo3/module/user/setup', 'POST')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $token = $this->get(FormProtectionFactory::class)->createFromRequest($request)->generateToken('BE user setup', 'edit');
        $request = $request->withParsedBody([
            'data' => [
                'be_users_settings' => [1 => [
                    'be_users__lang' => Value::string($backendUser->user['lang'] ?? 'default'),
                    'be_users__email' => Value::string($backendUser->user['email'] ?? ''),
                    'user_settings__webconEasyWorkspaceEnabled' => '1',
                    'user_settings__webconEasyWorkspaceLayout' => $layout,
                ]],
                'save' => '1',
            ],
            'formToken' => $token,
        ]);

        $controller = $this->get(SetupModuleController::class);
        $reflection = new \ReflectionObject($controller);
        $reflection->getMethod('storeIncomingData')->invoke($controller, $request);
        $updates = $reflection->getProperty('persistentUpdate')->getValue($controller);
        $reflection->getProperty('persistentUpdate')->setValue($controller, []);

        $persistentUpdates = [];
        foreach (is_array($updates) ? $updates : [] as $update) {
            if (is_array($update)) {
                $persistentUpdates[] = ['fieldName' => Value::string($update['fieldName'] ?? null), 'value' => Value::string($update['value'] ?? null)];
            }
        }

        return $persistentUpdates;
    }

    #[Test]
    public function everyLayoutsStylesShipAfterTheSharedOnes(): void
    {
        $this->toolbarItemInWorkspace(4, 10)->getItem();

        $cssFiles = $this->get(PageRenderer::class)->getState()['cssFiles'] ?? [];
        // The registered resources, by the path below Resources/Public/Css/.
        $files = array_map(
            static fn(string $file): string => preg_replace('#^.*Resources/Public/Css/#', '', $file) ?? $file,
            array_map('strval', array_keys(is_array($cssFiles) ? $cssFiles : [])),
        );
        $shared = array_search('toolbar-menu.css', $files, true);
        self::assertIsInt($shared, implode(', ', $files));
        foreach (['clarity', 'efficiency', 'core'] as $layout) {
            $position = array_search('layouts/' . $layout . '.css', $files, true);
            self::assertIsInt($position, $layout . ' in ' . implode(', ', $files));
            self::assertGreaterThan($shared, $position, $layout);
        }
    }

    /**
     * The JSON the dropdown's web component reads from its `config` attribute.
     *
     * @return array<string, mixed>
     */
    private function dropdownConfig(EasyWorkspaceToolbarItem $toolbarItem): array
    {
        $markup = $toolbarItem->getDropDown();
        self::assertMatchesRegularExpression('/<webcon-easy-workspace-menu-v2 config="([^"]*)"/', $markup);
        preg_match('/<webcon-easy-workspace-menu-v2 config="([^"]*)"/', $markup, $matches);

        return Value::stringKeyArray(json_decode(htmlspecialchars_decode($matches[1] ?? ''), true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function marksTheItemAsLiveSoItIsNotVisibleBeforeTheScriptRuns(): void
    {
        self::assertStringContainsString(
            'webcon-easy-workspace-toolbar--live',
            $this->toolbarItemInWorkspace(0)->getAdditionalAttributes()['class'],
        );
        self::assertStringNotContainsString(
            'webcon-easy-workspace-toolbar--live',
            $this->toolbarItemInWorkspace(4)->getAdditionalAttributes()['class'],
        );
    }
}
