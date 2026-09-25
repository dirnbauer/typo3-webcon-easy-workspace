<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Controller\Backend\EasyWorkspaceModuleController;

/**
 * The module renders through Core's `Module` layout: one h1 for the section,
 * the Core flash message container, and a selection table whose checkboxes
 * carry the `.form-check` wrapper that defines Core's checkbox tokens.
 */
final class EasyWorkspaceModuleControllerTest extends FunctionalTestCase
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

    /**
     * @param array<string, int|string> $query
     */
    private function render(string $moduleIdentifier, int $workspaceId, array $query = []): string
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace($workspaceId);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($workspaceId));

        $response = $this->get(EasyWorkspaceModuleController::class)
            ->handleRequest($this->moduleRequest($moduleIdentifier, $query));

        self::assertSame(200, $response->getStatusCode());

        return (string)$response->getBody();
    }

    /**
     * @param array<string, int|string> $query
     */
    private function moduleRequest(string $moduleIdentifier, array $query): ServerRequestInterface
    {
        $module = $this->get(ModuleProvider::class)->getModule($moduleIdentifier, $GLOBALS['BE_USER']);
        self::assertNotNull($module);
        $uri = 'https://typo3-testing.local' . $module->getPath() . ($query !== [] ? '?' . http_build_query($query) : '');
        $request = new ServerRequest($uri, 'GET')
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('backend.user', $GLOBALS['BE_USER'])
            ->withAttribute('module', $module)
            ->withAttribute('route', $this->get(Router::class)->getRoute($moduleIdentifier));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    #[Test]
    public function thePendingListRendersOneHeadingAndCheckboxesCoreCanStyle(): void
    {
        $html = $this->render('webcon_easy_workspace_pending', 4, ['id' => 10]);

        self::assertSame(1, substr_count($html, '<h1'));
        self::assertStringContainsString('New content', $html);
        self::assertMatchesRegularExpression(
            '/<span class="form-check[^"]*">\s*<input type="checkbox"[^>]*class="form-check-input t3js-multi-record-selection-check"/',
            $html,
        );
    }

    #[Test]
    public function withoutAPageTheListAsksForOne(): void
    {
        $html = $this->render('webcon_easy_workspace_pending', 4);

        self::assertStringNotContainsString('<table', $html);
        self::assertStringContainsString('role="status"', $html);
    }

    #[Test]
    public function queuedMessagesAreRenderedOnceByCoresModuleLayout(): void
    {
        // Publish, review and discard redirect back with a flash message.
        // Core's `Module` layout renders the queue (as `.typo3-messages` in a
        // browser; the testing framework runs on the CLI, where Core picks
        // its plain-text renderer), so the module must not flush it itself.
        $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage('Published 2 changes.', 'Published', ContextualFeedbackSeverity::OK),
        );

        $html = $this->render('webcon_easy_workspace_pending', 4, ['id' => 10]);

        self::assertSame(1, substr_count($html, 'Published 2 changes.'));
        self::assertStringNotContainsString('class="alert alert-', $html);
    }

    #[Test]
    public function theSelectionSummaryIsFormattedOnTheServer(): void
    {
        $html = $this->render('webcon_easy_workspace_pending', 4, ['id' => 10]);

        // The raw ICU message only travels to JavaScript in data-wew-labels;
        // the summary itself must arrive formatted. Four: the page, the new
        // element, and the deleted news record stored on the page — core
        // lists every workspace-aware record of the page.
        self::assertMatchesRegularExpression('/data-wew-publishbar-summary="">\s*4 records selected for approval\s*</', $html);
    }

    #[Test]
    public function theRecordsSectionAlsoListsUnchangedRecords(): void
    {
        $html = $this->render('webcon_easy_workspace_records', 4, ['id' => 10]);

        self::assertStringContainsString('New content', $html);
        self::assertStringContainsString('Live content', $html);
    }
}
