<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Controller\Backend\EasyWorkspaceModuleController;
use Webconsulting\WebconEasyWorkspace\Tests\Functional\Service\TranslationCompanionScenario;

/**
 * The module lists a change once, with its translation copies named below
 * the title, and its checkbox publishes them along; an unchanged copy is
 * listed for what it is, without a checkbox.
 */
final class EasyWorkspaceModuleControllerCompanionTest extends FunctionalTestCase
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
     * @param array<string, int|string> $query
     * @param array<string, mixed>|null $body
     */
    private function request(string $moduleIdentifier, array $query, ?array $body = null): ServerRequestInterface
    {
        $module = $this->get(ModuleProvider::class)->getModule($moduleIdentifier, $GLOBALS['BE_USER']);
        self::assertNotNull($module);
        $uri = 'https://typo3-testing.local' . $module->getPath() . '?' . http_build_query($query);
        $request = new ServerRequest($uri, $body === null ? 'GET' : 'POST')
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('backend.user', $GLOBALS['BE_USER'])
            ->withAttribute('module', $module)
            ->withAttribute('route', $this->get(Router::class)->getRoute($moduleIdentifier));
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function render(string $moduleIdentifier): string
    {
        $response = $this->get(EasyWorkspaceModuleController::class)->handleRequest($this->request($moduleIdentifier, ['id' => 2]));
        self::assertSame(200, $response->getStatusCode());

        return (string)$response->getBody();
    }

    #[Test]
    public function aChangeIsListedOnceWithItsTranslationCopiesNamed(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $html = $this->render('webcon_easy_workspace_pending');

        self::assertSame(1, substr_count($html, 'data-wew-row-check=""'));
        self::assertMatchesRegularExpression('/data-wew-companions="">.*?DE, ZH, HU\s*</s', $html);
        self::assertMatchesRegularExpression('/data-wew-publishbar-summary="">\s*1 record selected for approval\s*</', $html);
        self::assertStringNotContainsString('Held', $html, 'the German copy is no row of its own');
    }

    #[Test]
    public function theRecordsSectionDoesNotListACopyAgainAsAChange(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $html = $this->render('webcon_easy_workspace_records');

        // The page's elements in every language are listed; the copies of
        // the hero's translations are live rows there, never changed ones.
        self::assertStringContainsString('Hero (edited)', $html);
        self::assertStringContainsString('Zweites', $html);
        self::assertSame(1, substr_count($html, 'wew-record-row--changed'));
    }

    #[Test]
    public function anUnchangedCopyIsListedWithoutACheckboxAndNotCounted(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero']]]);
        $this->editInWorkspace(['tt_content' => [110 => ['header' => 'Second (edited)']]]);

        $html = $this->render('webcon_easy_workspace_pending');

        self::assertSame(1, substr_count($html, 'data-wew-row-check=""'), 'only the change can be selected');
        self::assertSame(1, substr_count($html, 'data-wew-copy-badge=""'));
        self::assertMatchesRegularExpression('/data-wew-publishbar-summary="">\s*1 record selected for approval\s*</', $html);
    }

    #[Test]
    public function theDiscardOfAMoveNamesTheTranslatorsEditCoreTakesAlong(): void
    {
        $this->editInWorkspace([], ['tt_content' => [110 => ['move' => -120]]]);
        $this->editInWorkspace(['tt_content' => [111 => ['header' => 'Zweites (Übersetzer)']]]);
        $moved = $this->versionOf('tt_content', 110);

        $html = $this->render('webcon_easy_workspace_pending');

        self::assertMatchesRegularExpression(
            '/data-wew-workspace-uid="' . $moved . '"[^>]*data-wew-dependents=""[^>]*data-wew-dependent-edits="German"/s',
            $html,
        );
        // The German edit's own discard takes nothing along.
        self::assertMatchesRegularExpression(
            '/data-wew-workspace-uid="' . $this->versionOf('tt_content', 111) . '"[^>]*data-wew-dependents=""[^>]*data-wew-dependent-edits=""/s',
            $html,
        );
        self::assertStringContainsString('discard.modal.dependentEdits', $html, 'the confirm\'s label is in the module\'s label map');
    }

    #[Test]
    public function theDiscardOfANewElementNamesItsNewTranslation(): void
    {
        $this->editInWorkspace(['tt_content' => ['NEW1' => ['pid' => 2, 'CType' => 'text', 'header' => 'Brand new', 'colPos' => 0]]]);
        $new = 0;
        foreach ($this->workspaceRows() as $row) {
            $new = max($new, (int)explode(':', $row)[1]);
        }
        $this->editInWorkspace([], ['tt_content' => [$new => ['localize' => 1]]]);

        $html = $this->render('webcon_easy_workspace_pending');

        self::assertMatchesRegularExpression(
            '/data-wew-workspace-uid="' . $new . '"[^>]*data-wew-dependents="German"[^>]*data-wew-dependent-edits=""/s',
            $html,
        );
    }

    #[Test]
    public function theChangesCheckboxPublishesItsCopiesAlong(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $response = $this->get(EasyWorkspaceModuleController::class)->handleRequest($this->request(
            'webcon_easy_workspace_pending',
            ['id' => 2],
            ['_action' => 'approve-publish', 'selections' => ['tt_content:' . $this->versionOf('tt_content', 100)]],
        ));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame([], $this->workspaceRows());
        self::assertSame('Hero (edited)', $this->liveRow('tt_content', 100)['header']);
    }
}
