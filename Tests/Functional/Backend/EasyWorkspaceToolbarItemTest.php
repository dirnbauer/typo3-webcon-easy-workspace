<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Backend\ToolbarItem\EasyWorkspaceToolbarItem;

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
